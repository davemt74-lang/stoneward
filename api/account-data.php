<?php
declare(strict_types=1);

/**
 * Restore missing columns on legacy user_library tables without dropping or replacing data.
 * Old installations may already have user_library, so CREATE TABLE IF NOT EXISTS cannot
 * add the columns expected by saved-draft and player-module account requests.
 */
function sf_account_library_repair_schema(PDO $pdo, string $driver): void {
    if($driver==='sqlite'){
        $rows=$pdo->query('PRAGMA table_info(user_library)')->fetchAll();
        $existing=array_fill_keys(array_map(static fn($r)=>(string)$r['name'],$rows),true);
        $spec=[
            'item_type'=>"TEXT NOT NULL DEFAULT ''",
            'item_key'=>"TEXT NOT NULL DEFAULT ''",
            'label'=>"TEXT NOT NULL DEFAULT ''",
            'source_order_id'=>"TEXT NOT NULL DEFAULT ''",
            'metadata_json'=>"TEXT NOT NULL DEFAULT '{}'",
            'acquired_at'=>"TEXT NOT NULL DEFAULT ''",
        ];
    }else{
        $rows=$pdo->query('SHOW COLUMNS FROM `user_library`')->fetchAll();
        $existing=array_fill_keys(array_map(static fn($r)=>(string)$r['Field'],$rows),true);
        $spec=[
            'item_type'=>"VARCHAR(40) NOT NULL DEFAULT ''",
            'item_key'=>"VARCHAR(160) NOT NULL DEFAULT ''",
            'label'=>"VARCHAR(255) NOT NULL DEFAULT ''",
            'source_order_id'=>"VARCHAR(100) NOT NULL DEFAULT ''",
            'metadata_json'=>"LONGTEXT NULL",
            'acquired_at'=>"VARCHAR(40) NOT NULL DEFAULT ''",
        ];
    }
    foreach($spec as $column=>$definition){
        if(isset($existing[$column]))continue;
        // Names/definitions above are constants, never user-controlled SQL.
        $pdo->exec('ALTER TABLE user_library ADD COLUMN `'.$column.'` '.$definition);
    }
    // A legacy table can contain multiple rows with no item key. Preserve each
    // row and assign a stable, distinct fallback rather than failing a new unique index.
    if((int)$pdo->query("SELECT COUNT(*) FROM user_library WHERE item_key='' OR item_key IS NULL")->fetchColumn()===0)return;
    if($driver==='sqlite'){
        $pdo->exec("UPDATE user_library SET item_key='legacy-' || id WHERE item_key='' OR item_key IS NULL");
    }else{
        $pdo->exec("UPDATE user_library SET item_key=CONCAT('legacy-',id) WHERE item_key='' OR item_key IS NULL");
    }
}

/**
 * Older installations can contain user_saved_builds without the columns used by
 * today's record builder. CREATE TABLE IF NOT EXISTS does not upgrade such tables.
 * Repair columns in place; retain IDs, account ownership and existing draft JSON.
 */
function sf_account_saved_builds_repair_schema(PDO $pdo, string $driver): void {
    if($driver==='sqlite'){
        $rows=$pdo->query('PRAGMA table_info(user_saved_builds)')->fetchAll();
        $existing=array_fill_keys(array_map(static fn($r)=>(string)$r['name'],$rows),true);
        $spec=[
            'name'=>"TEXT NOT NULL DEFAULT ''",
            'format'=>"TEXT NOT NULL DEFAULT ''",
            'build_json'=>"TEXT NOT NULL DEFAULT ''",
            'created_at'=>"TEXT NOT NULL DEFAULT ''",
            'updated_at'=>"TEXT NOT NULL DEFAULT ''",
        ];
    }else{
        $rows=$pdo->query('SHOW COLUMNS FROM `user_saved_builds`')->fetchAll();
        $existing=array_fill_keys(array_map(static fn($r)=>(string)$r['Field'],$rows),true);
        $spec=[
            'name'=>"VARCHAR(160) NOT NULL DEFAULT ''",
            'format'=>"VARCHAR(24) NOT NULL DEFAULT ''",
            'build_json'=>"LONGTEXT NULL",
            'created_at'=>"VARCHAR(40) NOT NULL DEFAULT ''",
            'updated_at'=>"VARCHAR(40) NOT NULL DEFAULT ''",
        ];
    }
    $missing=array_diff_key($spec,$existing);
    if(!$missing)return; // Already-current database: no full-table write.
    foreach($missing as $column=>$definition){
        $pdo->exec('ALTER TABLE user_saved_builds ADD COLUMN `'.$column.'` '.$definition);
    }
    $now=gmdate('c');
    $update=$pdo->prepare('UPDATE user_saved_builds SET name=?,format=?,build_json=?,created_at=?,updated_at=? WHERE id=?');
    foreach($pdo->query('SELECT * FROM user_saved_builds')->fetchAll() as $row){
        $id=(int)$row['id'];
        $originalJson=(string)($row['build_json']??'');
        $json=$originalJson;
        $payload=json_decode($json,true);
        // Some early installations used a different column for the same payload.
        if(!is_array($payload)){
            foreach(['data_json','build_data','snapshot_json'] as $legacyColumn){
                if(!isset($existing[$legacyColumn]))continue;
                $candidate=(string)($row[$legacyColumn]??'');
                $decoded=json_decode($candidate,true);
                if(is_array($decoded)){$payload=$decoded;$json=$candidate;break;}
            }
        }
        if(!is_array($payload))$payload=[];
        $name=(string)($row['name']??'');
        if(trim($name)===''){
            $name=trim((string)($row['title']??$row['legacy_title']??$payload['title']??''));
            if($name==='')$name='Saved record #'.$id;
            $name=substr($name,0,160);
        }
        $format=(string)($row['format']??'');
        if($format==='')$format=in_array((string)($payload['format']??''),['vinyl','cassette'],true)?(string)$payload['format']:'vinyl';
        if($json===''||!is_array(json_decode($json,true))){
            $payload=array_merge(['title'=>$name,'format'=>$format,'A'=>[],'B'=>[]],$payload);
            $json=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        }
        $created=(string)($row['created_at']??'');
        $updated=(string)($row['updated_at']??'');
        if($created==='')$created=$updated!==''?$updated:$now;
        if($updated==='')$updated=$created;
        if($name===(string)($row['name']??'') && $format===(string)($row['format']??'')
            && $json===$originalJson && $created===(string)($row['created_at']??'')
            && $updated===(string)($row['updated_at']??''))continue;
        $update->execute([$name,$format,$json,$created,$updated,$id]);
    }
}

/** Draft storage is independent of library and entitlement migrations. */
function sf_account_draft_ensure_schema(): void {
    static $done=false;
    if($done)return;
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $hasTable=(bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_saved_builds'")->fetchColumn();
        if(!$hasTable)$pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_builds (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,name TEXT NOT NULL,format TEXT NOT NULL,build_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    }elseif($driver==='mysql'){
        $hasTable=(bool)$pdo->query("SHOW TABLES LIKE 'user_saved_builds'")->fetchColumn();
        if(!$hasTable)$pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_builds (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(160) NOT NULL,format VARCHAR(24) NOT NULL,build_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_user_saved_builds_user(user_id,updated_at),CONSTRAINT fk_saved_build_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }else{throw new RuntimeException('Unsupported database driver for saved drafts.');}
    sf_account_saved_builds_repair_schema($pdo,$driver);
    $done=true;
}

function sf_account_data_ensure_schema(): void {
    static $done=false;
    if($done)return;
    sf_account_draft_ensure_schema();
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_library (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,item_type TEXT NOT NULL,item_key TEXT NOT NULL,label TEXT NOT NULL,source_order_id TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '{}',acquired_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        sf_account_library_repair_schema($pdo,$driver);
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_user_library_unique ON user_library(user_id,item_type,item_key,source_order_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_library_user ON user_library(user_id,acquired_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_library (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,item_type VARCHAR(40) NOT NULL,item_key VARCHAR(160) NOT NULL,label VARCHAR(255) NOT NULL,source_order_id VARCHAR(100) NOT NULL DEFAULT '',metadata_json LONGTEXT NOT NULL,acquired_at VARCHAR(40) NOT NULL,UNIQUE KEY idx_user_library_unique(user_id,item_type,item_key,source_order_id),INDEX idx_user_library_user(user_id,acquired_at),CONSTRAINT fk_library_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    sf_account_library_repair_schema($pdo,$driver);
    $done=true;
}

function sf_account_normalize_build(array $raw,int $userId=0): array {
    $format=in_array((string)($raw['format']??''),['vinyl','cassette'],true)?(string)$raw['format']:'vinyl';
    $title=sf_clean_text($raw['title']??'My Stonefellow Record',120);
    if($title==='') $title='My Stonefellow Record';
    $theme=sf_clean_text($raw['theme']??'',80);
    $valid=[]; foreach(sf_catalog() as $t) $valid[(string)($t['id']??'')]=true;
    $seen=[]; $sides=['A'=>[],'B'=>[]];
    foreach(['A','B'] as $side){
        foreach((array)($raw[$side]??[]) as $id){
            $id=sf_clean_text($id,100);
            if($id===''||empty($valid[$id])||isset($seen[$id])) continue;
            $seen[$id]=true; $sides[$side][]=$id;
            if(count($sides[$side])>=30) break;
        }
    }
    $requested=(array)($raw['playerModules']??[]);
    $defaults=function_exists('sf_turntable_module_defaults')?sf_turntable_module_defaults():sf_turntable_valid_selection([],$userId);
    $onlyDefaults=true;
    foreach($requested as $slot=>$value){
        if(!isset($defaults[$slot])||!is_string($value)||$value!==$defaults[$slot]){$onlyDefaults=false;break;}
    }
    $pending=[];
    if($onlyDefaults){$modules=$defaults;}
    else{
        try{$modules=sf_turntable_valid_selection($requested,$userId);}
        catch(PDOException $e){
            // A saved draft cannot grant paid modules.
            $modules=$defaults;
            foreach($defaults as $slot=>$defaultId){
                $id=$requested[$slot]??null;
                if(!is_string($id)||strlen($id)>70)continue;
                $module=function_exists('sf_turntable_module')?sf_turntable_module($id,false):null;
                if($module&&$module['slot']===$slot)$pending[$slot]=$id;
            }
            error_log('Stonefellow draft module validation deferred. SQLSTATE '.(string)$e->getCode());
        }
    }
    require_once __DIR__.'/artist-world-vinyl.php';
    foreach(['coverFront','coverBack'] as $coverKey){
      $reference=sf_clean_text($raw[$coverKey]??'stonefellow',70);
      if(!in_array($reference,['stonefellow','desert','midnight','analog'],true)
         &&!sf_world_vinyl_approved_reference($reference))
         throw new InvalidArgumentException('Chosen gallery artwork is no longer approved for vinyl. Choose another image.');
    }
    return ['version'=>4,'format'=>$format,'title'=>$title,'theme'=>$theme,'A'=>$sides['A'],'B'=>$sides['B'],
      'playerModules'=>$modules,'pendingPlayerModules'=>$pending,
      'coverFront'=>sf_clean_text($raw['coverFront']??'stonefellow',70),'coverBack'=>sf_clean_text($raw['coverBack']??'stonefellow',70),
      'customNote'=>sf_clean_text($raw['customNote']??'',280),'savedAt'=>gmdate('c')];
}

function sf_account_build_fill_suggestions(int $userId,array $raw,string $side='both'): array {
    $build=sf_account_normalize_build($raw,$userId);$cfg=sf_store_config();$limit=(int)($cfg['limits'][$build['format']]??0);if($limit<1)throw new RuntimeException('Unsupported build format.');
    $side=in_array($side,['A','B','both'],true)?$side:'both';$map=sf_track_map();$used=array_fill_keys(array_merge($build['A'],$build['B']),true);$profile=sf_personalization_recommendation_profile($userId);
    $ranked=sf_personalization_rank_catalog(sf_catalog(),$profile,60,array_keys($used));$ordered=[];
    foreach($ranked as $r){$id=(string)($r['track_id']??'');$t=$map[$id]??null;if(!$t||empty($t['podEligible']))continue;$ordered[]=$id;}
    foreach(sf_catalog() as $t){$id=(string)($t['id']??'');if($id===''||isset($used[$id])||empty($t['podEligible'])||in_array($id,$ordered,true))continue;$ordered[]=$id;}
    $duration=function(array $ids)use($map): int {$sum=0;foreach($ids as $id)$sum+=(int)($map[$id]['duration']??0);return $sum;};
    $sides=['A'=>$build['A'],'B'=>$build['B']];$targets=$side==='both'?['A','B']:[$side];$added=['A'=>[],'B'=>[]];
    foreach($targets as $target){$remain=max(0,$limit-$duration($sides[$target]));foreach($ordered as $k=>$id){if(isset($used[$id]))continue;$dur=(int)($map[$id]['duration']??0);if($dur<1||$dur>$remain)continue;$sides[$target][]=$id;$added[$target][]=$id;$used[$id]=true;$remain-=$dur;if($remain<30)break;}}
    return ['build'=>['version'=>4,'format'=>$build['format'],'title'=>$build['title'],'theme'=>$build['theme'],'A'=>$sides['A'],'B'=>$sides['B'],'playerModules'=>$build['playerModules'],'coverFront'=>$build['coverFront'],'coverBack'=>$build['coverBack'],'customNote'=>$build['customNote'],'savedAt'=>gmdate('c')],'added'=>$added,'limit_seconds'=>$limit,'personalized'=>(bool)($profile['personalized']??false)];
}

function sf_account_saved_builds(int $userId): array {
    sf_account_draft_ensure_schema();
    $q=sf_db()->prepare('SELECT id,name,format,build_json,created_at,updated_at FROM user_saved_builds WHERE user_id=? ORDER BY updated_at DESC,id DESC');
    $q->execute([$userId]); $rows=[];
    foreach($q->fetchAll() as $r){
        $build=json_decode((string)$r['build_json'],true);
        if(!is_array($build)) $build=[];
        $rows[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'format'=>(string)$r['format'],'build'=>$build,'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at']];
    }
    return $rows;
}

/** Keep recognized mandatory columns from older record builders synchronized. */
function sf_account_draft_legacy_fields(PDO $pdo,array $build,string $now,bool $creating=false): array {
    $driver=(string)(sf_db_config()['driver']??'');
    $rows=$driver==='sqlite'?$pdo->query('PRAGMA table_info(user_saved_builds)')->fetchAll()
        :$pdo->query('SHOW COLUMNS FROM `user_saved_builds`')->fetchAll();
    $present=[];
    foreach($rows as $row)$present[(string)($driver==='sqlite'?$row['name']:$row['Field'])]=true;
    $json=json_encode($build,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $fields=[];
    foreach(['title','legacy_title'] as $column)if(isset($present[$column]))$fields[$column]=$build['title'];
    foreach(['data_json','build_data','snapshot_json'] as $column)if(isset($present[$column]))$fields[$column]=$json;
    if($creating&&isset($present['created']))$fields['created']=$now;
    if(isset($present['updated']))$fields['updated']=$now;
    return $fields;
}

function sf_account_save_build(int $userId,array $raw,int $id=0): array {
    sf_account_draft_ensure_schema();
    $build=sf_account_normalize_build($raw,$userId);
    $now=gmdate('c'); $pdo=sf_db();
    if($id>0){
        $q=$pdo->prepare('SELECT id,created_at FROM user_saved_builds WHERE id=? AND user_id=? LIMIT 1'); $q->execute([$id,$userId]); $old=$q->fetch();
        if(!$old) throw new RuntimeException('Saved build not found.');
        $json=json_encode($build,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $extra=sf_account_draft_legacy_fields($pdo,$build,$now);
        $values=[$build['title'],$build['format'],$json,$now];
        $set='name=?,format=?,build_json=?,updated_at=?';
        foreach($extra as $column=>$value){$set.=',`'.$column.'`=?';$values[]=$value;}
        $values[]=$id;$values[]=$userId;
        $u=$pdo->prepare('UPDATE user_saved_builds SET '.$set.' WHERE id=? AND user_id=?');
        $u->execute($values);
    } else {
        $json=json_encode($build,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $columns=['user_id','name','format','build_json','created_at','updated_at'];
        $values=[$userId,$build['title'],$build['format'],$json,$now,$now];
        foreach(sf_account_draft_legacy_fields($pdo,$build,$now,true) as $column=>$value){$columns[]=$column;$values[]=$value;}
        $names=implode(',',array_map(static fn(string $col):string=>'`'.$col.'`',$columns));
        $marks=implode(',',array_fill(0,count($columns),'?'));
        $i=$pdo->prepare('INSERT INTO user_saved_builds('.$names.') VALUES('.$marks.')');
        $i->execute($values);$id=(int)$pdo->lastInsertId();
    }
    foreach(sf_account_saved_builds($userId) as $row) if((int)$row['id']===$id) return $row;
    throw new RuntimeException('Saved build could not be reloaded.');
}

/** Rename a stored draft without overwriting track order, cover art, or note. */
function sf_account_rename_build(int $userId,int $id,string $name): array {
    if($userId<1||$id<1)throw new InvalidArgumentException('Invalid saved draft.');
    $name=sf_clean_text($name,60);
    if($name==='')throw new InvalidArgumentException('Enter a draft name.');
    sf_account_draft_ensure_schema();$pdo=sf_db();
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT build_json FROM user_saved_builds WHERE id=? AND user_id=?');
        $q->execute([$id,$userId]);$raw=$q->fetchColumn();
        if($raw===false)throw new RuntimeException('Saved draft not found in this account.');
        $build=json_decode((string)$raw,true);
        if(!is_array($build))throw new RuntimeException('Stored draft is invalid.');
        $build['title']=$name;
        $now=gmdate('c');
        $json=json_encode($build,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $extra=sf_account_draft_legacy_fields($pdo,$build,$now);
        if(!$extra){
            $q=$pdo->prepare('UPDATE user_saved_builds SET name=?,build_json=?,updated_at=? WHERE id=? AND user_id=?');
            $q->execute([$name,$json,$now,$id,$userId]);
        }else{
            $set='name=?,build_json=?,updated_at=?';$values=[$name,$json,$now];
            foreach($extra as $column=>$value){$set.=',`'.$column.'`=?';$values[]=$value;}
            $values[]=$id;$values[]=$userId;
            $q=$pdo->prepare('UPDATE user_saved_builds SET '.$set.' WHERE id=? AND user_id=?');
            $q->execute($values);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    foreach(sf_account_saved_builds($userId) as $row)if((int)$row['id']===$id)return $row;
    throw new RuntimeException('Renamed draft could not be reloaded.');
}

function sf_account_delete_build(int $userId,int $id): void {
    sf_account_draft_ensure_schema();
    $q=sf_db()->prepare('DELETE FROM user_saved_builds WHERE id=? AND user_id=?'); $q->execute([$id,$userId]);
}

function sf_account_library(int $userId): array {
    sf_account_data_ensure_schema();
    $q=sf_db()->prepare('SELECT id,item_type,item_key,label,source_order_id,metadata_json,acquired_at FROM user_library WHERE user_id=? ORDER BY acquired_at DESC,id DESC');
    $q->execute([$userId]); $rows=[];
    foreach($q->fetchAll() as $r){$m=json_decode((string)$r['metadata_json'],true);$rows[]=['id'=>(int)$r['id'],'item_type'=>(string)$r['item_type'],'item_key'=>(string)$r['item_key'],'label'=>(string)$r['label'],'source_order_id'=>(string)$r['source_order_id'],'metadata'=>is_array($m)?$m:[],'acquired_at'=>(string)$r['acquired_at']];}
    return $rows;
}

function sf_order_paid_for_library(array $order): bool {
    // Never grant purchased tracks, preview modules or other library entries when payment
    // is still pending, even if the order itself has been created.
    return in_array((string)($order['payment']['status']??''),['paid','succeeded','simulated_paid','test_paid'],true)
        && !in_array((string)($order['status']??''),['canceled','cancelled','refunded'],true);
}
function sf_account_library_add_order(int $userId,array $order): void {
    if($userId<1 || !sf_order_paid_for_library($order))return;
    sf_account_data_ensure_schema(); $pdo=sf_db(); $orderId=(string)($order['id']??''); $when=(string)($order['created_at']??gmdate('c'));
    foreach((array)($order['quote']['items']??[]) as $item){
        $type=(string)($item['type']??'item');
        // Defense in depth: retain the module-specific check used by older integrations.
        if($type==='turntable_module'&&!in_array((string)($order['payment']['status']??''),['paid','simulated_paid','test_paid'],true) && (string)($order['payment']['status']??'')!=='succeeded')continue;
        // The paid-order gate above protects all other purchases as well.
        $key=(string)($item['module_id']??$item['track_id']??$item['release_id']??$item['package_id']??'');
        if($key==='') $key=$type==='custom_media'?$orderId.':'.(string)($item['format']??'custom'):$orderId.':'.substr(hash('sha256',json_encode($item)?:''),0,16);
        $label=sf_clean_text($item['label']??ucfirst(str_replace('_',' ',$type)),240);
        $meta=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
        try{
            $q=$pdo->prepare('INSERT INTO user_library(user_id,item_type,item_key,label,source_order_id,metadata_json,acquired_at) VALUES(?,?,?,?,?,?,?)');
            $q->execute([$userId,$type,$key,$label,$orderId,$meta,$when]);
            // A paid digital album unlocks streaming/downloading of its own
            // tracks, without multiplying its single per-song print credit.
            if($type==='release'){
                $snap=(array)($item['track_ids']??[]);
                foreach(array_unique(array_map('strval',$snap)) as $trackId){
                    if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$trackId))continue;
                    $grant=$pdo->prepare('INSERT INTO user_library(user_id,item_type,item_key,label,source_order_id,metadata_json,acquired_at) VALUES(?,?,?,?,?,?,?)');
                    try{
                        $grant->execute([$userId,'track',$trackId,$trackId,$orderId,
                            json_encode(['from_release'=>(string)($item['release_id']??''),'digital'=>true],JSON_THROW_ON_ERROR),$when]);
                    }catch(PDOException $e){
                        if(!in_array((string)$e->getCode(),['23000','19'],true))throw $e;
                    }
                }
            }
        }catch(PDOException $e){
            // Duplicate order import/library write is idempotent.
            if((string)$e->getCode()!=='23000' && (string)$e->getCode()!=='19') throw $e;
        }
    }
}
