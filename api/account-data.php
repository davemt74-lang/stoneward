<?php
declare(strict_types=1);

function sf_account_data_ensure_schema(): void {
    static $done=false;
    if($done) return;
    $pdo=sf_db();
    $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_builds (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,name TEXT NOT NULL,format TEXT NOT NULL,build_json TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_saved_builds_user ON user_saved_builds(user_id,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_library (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,item_type TEXT NOT NULL,item_key TEXT NOT NULL,label TEXT NOT NULL,source_order_id TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '{}',acquired_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_user_library_unique ON user_library(user_id,item_type,item_key,source_order_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_library_user ON user_library(user_id,acquired_at)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_builds (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(160) NOT NULL,format VARCHAR(24) NOT NULL,build_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_user_saved_builds_user(user_id,updated_at),CONSTRAINT fk_saved_build_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_library (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,item_type VARCHAR(40) NOT NULL,item_key VARCHAR(160) NOT NULL,label VARCHAR(255) NOT NULL,source_order_id VARCHAR(100) NOT NULL DEFAULT '',metadata_json LONGTEXT NOT NULL,acquired_at VARCHAR(40) NOT NULL,UNIQUE KEY idx_user_library_unique(user_id,item_type,item_key,source_order_id),INDEX idx_user_library_user(user_id,acquired_at),CONSTRAINT fk_library_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}

function sf_account_normalize_build(array $raw): array {
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
    return ['version'=>4,'format'=>$format,'title'=>$title,'theme'=>$theme,'A'=>$sides['A'],'B'=>$sides['B'],'savedAt'=>gmdate('c')];
}

function sf_account_build_fill_suggestions(int $userId,array $raw,string $side='both'): array {
    $build=sf_account_normalize_build($raw);$cfg=sf_store_config();$limit=(int)($cfg['limits'][$build['format']]??0);if($limit<1)throw new RuntimeException('Unsupported build format.');
    $side=in_array($side,['A','B','both'],true)?$side:'both';$map=sf_track_map();$used=array_fill_keys(array_merge($build['A'],$build['B']),true);$profile=sf_personalization_recommendation_profile($userId);
    $ranked=sf_personalization_rank_catalog(sf_catalog(),$profile,60,array_keys($used));$ordered=[];
    foreach($ranked as $r){$id=(string)($r['track_id']??'');$t=$map[$id]??null;if(!$t||empty($t['podEligible']))continue;$ordered[]=$id;}
    foreach(sf_catalog() as $t){$id=(string)($t['id']??'');if($id===''||isset($used[$id])||empty($t['podEligible'])||in_array($id,$ordered,true))continue;$ordered[]=$id;}
    $duration=function(array $ids)use($map): int {$sum=0;foreach($ids as $id)$sum+=(int)($map[$id]['duration']??0);return $sum;};
    $sides=['A'=>$build['A'],'B'=>$build['B']];$targets=$side==='both'?['A','B']:[$side];$added=['A'=>[],'B'=>[]];
    foreach($targets as $target){$remain=max(0,$limit-$duration($sides[$target]));foreach($ordered as $k=>$id){if(isset($used[$id]))continue;$dur=(int)($map[$id]['duration']??0);if($dur<1||$dur>$remain)continue;$sides[$target][]=$id;$added[$target][]=$id;$used[$id]=true;$remain-=$dur;if($remain<30)break;}}
    return ['build'=>['version'=>4,'format'=>$build['format'],'title'=>$build['title'],'theme'=>$build['theme'],'A'=>$sides['A'],'B'=>$sides['B'],'savedAt'=>gmdate('c')],'added'=>$added,'limit_seconds'=>$limit,'personalized'=>(bool)($profile['personalized']??false)];
}

function sf_account_saved_builds(int $userId): array {
    sf_account_data_ensure_schema();
    $q=sf_db()->prepare('SELECT id,name,format,build_json,created_at,updated_at FROM user_saved_builds WHERE user_id=? ORDER BY updated_at DESC,id DESC');
    $q->execute([$userId]); $rows=[];
    foreach($q->fetchAll() as $r){
        $build=json_decode((string)$r['build_json'],true);
        if(!is_array($build)) $build=[];
        $rows[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'format'=>(string)$r['format'],'build'=>$build,'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at']];
    }
    return $rows;
}

function sf_account_save_build(int $userId,array $raw,int $id=0): array {
    sf_account_data_ensure_schema();
    $build=sf_account_normalize_build($raw); $now=gmdate('c'); $pdo=sf_db();
    if($id>0){
        $q=$pdo->prepare('SELECT id,created_at FROM user_saved_builds WHERE id=? AND user_id=? LIMIT 1'); $q->execute([$id,$userId]); $old=$q->fetch();
        if(!$old) throw new RuntimeException('Saved build not found.');
        $u=$pdo->prepare('UPDATE user_saved_builds SET name=?,format=?,build_json=?,updated_at=? WHERE id=? AND user_id=?');
        $u->execute([$build['title'],$build['format'],json_encode($build,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$now,$id,$userId]);
    } else {
        $i=$pdo->prepare('INSERT INTO user_saved_builds(user_id,name,format,build_json,created_at,updated_at) VALUES(?,?,?,?,?,?)');
        $i->execute([$userId,$build['title'],$build['format'],json_encode($build,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$now,$now]); $id=(int)$pdo->lastInsertId();
    }
    foreach(sf_account_saved_builds($userId) as $row) if((int)$row['id']===$id) return $row;
    throw new RuntimeException('Saved build could not be reloaded.');
}

function sf_account_delete_build(int $userId,int $id): void {
    sf_account_data_ensure_schema();
    $q=sf_db()->prepare('DELETE FROM user_saved_builds WHERE id=? AND user_id=?'); $q->execute([$id,$userId]);
}

function sf_account_library(int $userId): array {
    sf_account_data_ensure_schema();
    $q=sf_db()->prepare('SELECT id,item_type,item_key,label,source_order_id,metadata_json,acquired_at FROM user_library WHERE user_id=? ORDER BY acquired_at DESC,id DESC');
    $q->execute([$userId]); $rows=[];
    foreach($q->fetchAll() as $r){$m=json_decode((string)$r['metadata_json'],true);$rows[]=['id'=>(int)$r['id'],'item_type'=>(string)$r['item_type'],'item_key'=>(string)$r['item_key'],'label'=>(string)$r['label'],'source_order_id'=>(string)$r['source_order_id'],'metadata'=>is_array($m)?$m:[],'acquired_at'=>(string)$r['acquired_at']];}
    return $rows;
}

function sf_account_library_add_order(int $userId,array $order): void {
    if($userId<1) return; sf_account_data_ensure_schema(); $pdo=sf_db(); $orderId=(string)($order['id']??''); $when=(string)($order['created_at']??gmdate('c'));
    foreach((array)($order['quote']['items']??[]) as $item){
        $type=(string)($item['type']??'item');
        $key=(string)($item['track_id']??$item['package_id']??'');
        if($key==='') $key=$type==='custom_media'?$orderId.':'.(string)($item['format']??'custom'):$orderId.':'.substr(hash('sha256',json_encode($item)?:''),0,16);
        $label=sf_clean_text($item['label']??ucfirst(str_replace('_',' ',$type)),240);
        $meta=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
        try{
            $q=$pdo->prepare('INSERT INTO user_library(user_id,item_type,item_key,label,source_order_id,metadata_json,acquired_at) VALUES(?,?,?,?,?,?,?)');
            $q->execute([$userId,$type,$key,$label,$orderId,$meta,$when]);
        }catch(PDOException $e){
            // Duplicate order import/library write is idempotent.
            if((string)$e->getCode()!=='23000' && (string)$e->getCode()!=='19') throw $e;
        }
    }
}
