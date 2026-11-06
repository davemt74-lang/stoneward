<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-music-core.php';
require_once __DIR__.'/agent-scene-palette.php';
require_once __DIR__.'/canvas-resource-core.php';

function sf_agent_scene_kind(mixed $value): string {
    if(!in_array($value,['listening_room','space_eq','custom'],true))throw new InvalidArgumentException('Choose a supported canvas type.');
    return $value;
}

function sf_agent_scene_lyric_section(mixed $value): ?string {
    if($value===null)return null;
    if(!in_array($value,['whole','verse','chorus','bridge'],true))throw new InvalidArgumentException('Choose a supported lyric section.');
    return $value;
}

/** Validate the optional source before persistence and exact-byte CSP hashing. */
function sf_agent_scene_javascript(mixed $value): ?string {
    if(!is_string($value)||strlen($value)>12000||preg_match('//u',$value)!==1||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)!==0)return null;
    $value=str_replace(["\r\n","\r"],"\n",$value);
    return trim($value)===''?'':$value;
}

/** Store only an actual submitted natural-language brief, never inferred scene copy. */
function sf_agent_scene_design_brief(mixed $value): ?string {
    if($value===null)return null;
    if(!is_string($value)||preg_match('//u',$value)!==1||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)!==0)
        throw new InvalidArgumentException('Invalid canvas design brief.');
    $value=trim(str_replace(["\r\n","\r"],"\n",$value));
    if(strlen($value)>1800)throw new InvalidArgumentException('Canvas design brief is too long.');
    return $value===''?null:$value;
}

/** Trusted public provenance only; account identifiers never enter attribution. */
function sf_agent_scene_gallery_attribution(mixed $value): ?array {
    if($value===null)return null;
    if(!is_array($value)||array_is_list($value)||count($value)!==2||array_diff(array_keys($value),['source','ancestors'])||
        !array_key_exists('source',$value)||!is_array($value['ancestors']??null)||!array_is_list($value['ancestors']))
        throw new InvalidArgumentException('Invalid canvas gallery attribution.');
    $entry=static function(mixed $raw):array {
        if(!is_array($raw)||array_is_list($raw)||count($raw)!==3||array_diff(array_keys($raw),['id','title','creator_label'])||
            !is_string($raw['id']??null)||preg_match('/\A[a-f0-9]{32}\z/',$raw['id'])!==1)
            throw new InvalidArgumentException('Invalid canvas gallery attribution.');
        $record=['id'=>$raw['id']];
        foreach(['title'=>240,'creator_label'=>120] as $field=>$limit){
            $text=$raw[$field]??null;
            if(!is_string($text)||preg_match('//u',$text)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$text)!==0||
                trim($text)===''||strlen($text)>$limit)throw new InvalidArgumentException('Invalid canvas gallery attribution.');
            $record[$field]=trim(str_replace(["\r\n","\r"],"\n",$text));
        }
        return $record;
    };
    $source=$entry($value['source']);$seen=[$source['id']=>true];$ancestors=[];
    foreach($value['ancestors'] as $raw){
        $ancestor=$entry($raw);
        if(isset($seen[$ancestor['id']]))continue;
        $seen[$ancestor['id']]=true;$ancestors[]=$ancestor;
    }
    $normalized=['source'=>$source,'ancestors'=>$ancestors];
    if(strlen(json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))>16384)
        throw new InvalidArgumentException('Canvas gallery attribution is too large.');
    return $normalized;
}

function sf_agent_scene_history_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas history before opening a transaction.');
    $sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS agent_scene_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            track_id TEXT NOT NULL, title TEXT NOT NULL, html TEXT NOT NULL, css TEXT NOT NULL,js TEXT NOT NULL DEFAULT '',
            previous_id INTEGER NULL, verification_parent_id INTEGER NULL, created_at TEXT NOT NULL,
            canvas_kind TEXT NOT NULL DEFAULT 'listening_room', lyric_section TEXT NULL, catalog_refs TEXT NULL, agent_design TEXT NULL,agent_palette TEXT NULL,music_inputs TEXT NULL,design_brief TEXT NULL,artist_inputs TEXT NULL,gallery_attribution TEXT NULL,workflow_required INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_scene_history_user ON agent_scene_history(user_id,id)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS agent_scene_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            track_id VARCHAR(180) NOT NULL, title VARCHAR(240) NOT NULL, html TEXT NOT NULL, css TEXT NOT NULL,js VARCHAR(12000) NOT NULL DEFAULT '',
            previous_id BIGINT UNSIGNED NULL, verification_parent_id BIGINT UNSIGNED NULL, created_at VARCHAR(40) NOT NULL,
            canvas_kind VARCHAR(24) NOT NULL DEFAULT 'listening_room', lyric_section VARCHAR(12) NULL, catalog_refs TEXT NULL, agent_design VARCHAR(8) NULL,agent_palette TEXT NULL,music_inputs MEDIUMTEXT NULL,design_brief TEXT NULL,artist_inputs MEDIUMTEXT NULL,gallery_attribution TEXT NULL,workflow_required TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_agent_scene_history_user(user_id,id),
            CONSTRAINT fk_agent_scene_history_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $columns=static fn():array=>$db->query($sqlite?'PRAGMA table_info(agent_scene_history)':'SHOW COLUMNS FROM agent_scene_history')
        ->fetchAll(PDO::FETCH_COLUMN,$sqlite?1:0);
    $existing=$columns();
    foreach(['canvas_kind'=>($sqlite?'TEXT':'VARCHAR(24)')." NOT NULL DEFAULT 'listening_room'",
        'lyric_section'=>($sqlite?'TEXT':'VARCHAR(12)').' NULL','catalog_refs'=>'TEXT NULL',
        'agent_design'=>($sqlite?'TEXT':'VARCHAR(8)').' NULL',
        'agent_palette'=>'TEXT NULL','design_brief'=>'TEXT NULL','gallery_attribution'=>'TEXT NULL',
        'js'=>($sqlite?'TEXT':'VARCHAR(12000)')." NOT NULL DEFAULT ''",'music_inputs'=>($sqlite?'TEXT':'MEDIUMTEXT').' NULL',
        'artist_inputs'=>($sqlite?'TEXT':'MEDIUMTEXT').' NULL','workflow_required'=>($sqlite?'INTEGER':'TINYINT(1)').' NOT NULL DEFAULT 0',
        'access_root_id'=>($sqlite?'INTEGER':'BIGINT UNSIGNED').' NULL',
        'verification_parent_id'=>($sqlite?'INTEGER':'BIGINT UNSIGNED').' NULL'] as $field=>$definition){
        if(in_array($field,$existing,true))continue;
        try{$db->exec("ALTER TABLE agent_scene_history ADD COLUMN $field $definition");}
        catch(PDOException $error){
            // Another request can apply the same additive change concurrently.
            if(!in_array($field,$columns(),true))throw $error;
        }
    }
    $ready=true;
}

function sf_agent_scene_history_row(array $row): array {
    if((int)($row['workflow_required']??0)===1)$row['workflow_required']=true;else unset($row['workflow_required']);
    if(isset($row['verification_parent_id'])&&(int)$row['verification_parent_id']>0)$row['verification_parent_id']=(int)$row['verification_parent_id'];else unset($row['verification_parent_id']);
    $brief=sf_agent_scene_design_brief($row['design_brief']??null);
    if($brief!==null)$row['design_brief']=$brief;else unset($row['design_brief']);
    if(isset($row['agent_palette']))$row['agent_palette']=sf_agent_scene_palette(json_decode($row['agent_palette'],true,4,JSON_THROW_ON_ERROR));
    else unset($row['agent_palette']);
    if(isset($row['music_inputs']))$row['music_inputs']=sf_agent_canvas_music_inputs(json_decode($row['music_inputs'],true,4,JSON_THROW_ON_ERROR));
    else unset($row['music_inputs']);
    $artist=sf_canvas_resource_artist_inputs(isset($row['artist_inputs'])?json_decode($row['artist_inputs'],true,4,JSON_THROW_ON_ERROR):null);
    if($artist!==null)$row['artist_inputs']=$artist;else unset($row['artist_inputs']);
    $attribution=sf_agent_scene_gallery_attribution(isset($row['gallery_attribution'])?json_decode($row['gallery_attribution'],true,8,JSON_THROW_ON_ERROR):null);
    if($attribution!==null)$row['gallery_attribution']=$attribution;else unset($row['gallery_attribution']);
    if(($row['js']??'')==='')unset($row['js']);
    else $row['js_hash']=base64_encode(hash('sha256',$row['js'],true));
    if(array_key_exists('catalog_refs',$row)){
        $refs=json_decode((string)$row['catalog_refs'],true,4);
        $row['catalog_refs']=is_array($refs)&&array_is_list($refs)?array_slice(array_values(array_filter($refs,'is_string')),0,6):[];
    }
    if(!in_array($row['agent_design']??null,['hidden','square'],true))unset($row['agent_design']);
    return ['id'=>(int)$row['id'],'previous_id'=>$row['previous_id']===null?null:(int)$row['previous_id'],'created_at'=>$row['created_at']]+$row;
}

function sf_agent_scene_history_list(int $uid): array {
    if($uid<1)return [];
    sf_agent_scene_history_ensure_schema();
    $q=sf_db()->prepare('SELECT id,track_id,title,previous_id,created_at,canvas_kind,lyric_section,agent_design,agent_palette,design_brief,workflow_required,verification_parent_id,gallery_attribution FROM agent_scene_history
        WHERE user_id=? ORDER BY id DESC LIMIT 30');
    $q->execute([$uid]);
    return array_map('sf_agent_scene_history_row',$q->fetchAll(PDO::FETCH_ASSOC));
}

function sf_agent_scene_history_get(int $uid,int $id): ?array {
    if($uid<1||$id<1)return null;
    sf_agent_scene_history_ensure_schema();
    $q=sf_db()->prepare('SELECT id,track_id,title,html,css,js,previous_id,created_at,canvas_kind,lyric_section,catalog_refs,agent_design,agent_palette,music_inputs,design_brief,artist_inputs,workflow_required,verification_parent_id,gallery_attribution FROM agent_scene_history
        WHERE user_id=? AND id=?');
    $q->execute([$uid,$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?sf_agent_scene_history_row($row):null;
}

/** Use the same account lock for scene creation and permanent account closure. */
function sf_agent_scene_history_lock_account(PDO $db,int $uid): void {
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return;
    $q=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $q->execute([$uid]);
    if($q->fetchColumn()===false)throw new InvalidArgumentException('Account unavailable.');
}

/** Capture before generation so a privacy reset can discard results still in flight. */
function sf_agent_scene_history_epoch(int $uid): int {
    $q=sf_db()->prepare("SELECT MAX(id) FROM user_privacy_events WHERE user_id=? AND event_type='agent_history_cleared'");
    $q->execute([$uid]);
    return (int)$q->fetchColumn();
}

function sf_agent_scene_history_save(int $uid,array $scene,?int $previousId=null,?int $expectedEpoch=null): array {
    if($uid<1||($previousId!==null&&$previousId<1)||($expectedEpoch!==null&&$expectedEpoch<0))
        throw new InvalidArgumentException('Invalid canvas history reference.');
    $kind=sf_agent_scene_kind($scene['canvas_kind']??'listening_room');
    $workflowRequired=array_key_exists('workflow_required',$scene)?$scene['workflow_required']:false;
    if(!is_bool($workflowRequired))throw new InvalidArgumentException('Invalid canvas workflow requirement.');
    $verificationParent=$scene['verification_parent_id']??null;
    if($verificationParent!==null&&(!is_int($verificationParent)||$verificationParent<1||$verificationParent!==$previousId))
        throw new InvalidArgumentException('Invalid canvas verification parent.');
    $section=sf_agent_scene_lyric_section($scene['lyric_section']??null);
    $design=$scene['agent_design']??null;
    if($design!==null&&!in_array($design,['hidden','square'],true))throw new InvalidArgumentException('Invalid canvas agent design.');
    $palette=sf_agent_scene_palette($scene['agent_palette']??null);
    $brief=sf_agent_scene_design_brief($scene['design_brief']??null);
    $attribution=sf_agent_scene_gallery_attribution($scene['gallery_attribution']??null);
    $music=sf_agent_canvas_music_inputs($scene['music_inputs']??null);
    if($music!==null&&$kind!=='custom')throw new InvalidArgumentException('Music inputs are available for custom canvases.');
    $artist=sf_canvas_resource_artist_inputs($scene['artist_inputs']??null);
    if($artist!==null&&$kind!=='custom')throw new InvalidArgumentException('Artist resource inputs are available for custom canvases.');
    $refs=$scene['catalog_refs']??[];
    if($music!==null&&is_array($refs)&&array_is_list($refs))$refs=sf_agent_canvas_music_catalog_refs($refs);
    if(!is_array($refs)||!array_is_list($refs)||count($refs)>6||($refs&&$kind!=='custom'))throw new InvalidArgumentException('Invalid canvas catalog references.');
    foreach($refs as $ref){
        if(!is_string($ref)||strlen($ref)>180||!preg_match('/\A[a-zA-Z0-9_:-]+\z/',$ref))
            throw new InvalidArgumentException('Invalid canvas catalog reference.');
    }
    if($kind==='space_eq'&&$section!==null)throw new InvalidArgumentException('Space EQ cannot store a lyric section.');
    $payload=[];
    foreach(['track_id'=>180,'title'=>960,'html'=>16000,'css'=>16000] as $field=>$limit){
        $value=$scene[$field]??null;
        $required=$field!=='css'&&($field!=='track_id'||$kind==='listening_room'||$section!==null);
        if(!is_string($value)||strlen($value)>$limit||preg_match('//u',$value)!==1||($required&&trim($value)===''))
            throw new InvalidArgumentException('Invalid canvas scene.');
        $payload[$field]=$value;
    }
    $js=sf_agent_scene_javascript(array_key_exists('js',$scene)?$scene['js']:'');
    if($js===null||($js!==''&&$kind!=='custom'))throw new InvalidArgumentException('Invalid canvas JavaScript.');
    if($js!=='')$payload['js']=$js;
    $payload['canvas_kind']=$kind;
    $payload['lyric_section']=$section;
    $payload['catalog_refs']=$refs;
    if($workflowRequired)$payload['workflow_required']=true;
    if($verificationParent!==null)$payload['verification_parent_id']=$verificationParent;
    if($design!==null)$payload['agent_design']=$design;
    if($palette!==null)$payload['agent_palette']=$palette;
    if($music!==null)$payload['music_inputs']=$music;
    if($artist!==null)$payload['artist_inputs']=$artist;
    if($brief!==null)$payload['design_brief']=$brief;
    if($attribution!==null)$payload['gallery_attribution']=$attribution;
    if($js!=='')$payload['js_hash']=base64_encode(hash('sha256',$js,true));
    if(preg_match('/\A.{1,240}\z/us',$payload['title'])!==1)throw new InvalidArgumentException('Invalid canvas scene title.');
    sf_agent_scene_history_ensure_schema();
    $db=sf_db();
    $ownTransaction=!$db->inTransaction();
    if($ownTransaction)$db->beginTransaction();
    try{
        sf_agent_scene_history_lock_account($db,$uid);
        require_once __DIR__.'/canvas-access-core.php';
        $accessRoot=$previousId===null?null:sf_canvas_access_root($uid,$previousId);
        $created=gmdate('c');
        $sql='INSERT INTO agent_scene_history(user_id,track_id,title,html,css,js,previous_id,created_at,canvas_kind,lyric_section,catalog_refs,agent_design,agent_palette,music_inputs,design_brief,artist_inputs,workflow_required,verification_parent_id,gallery_attribution)
            SELECT u.id,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? FROM users u';
        $args=[$payload['track_id'],$payload['title'],$payload['html'],$payload['css'],$js,$previousId,$created,$kind,$section,
            $refs?json_encode($refs,JSON_THROW_ON_ERROR):null,$design,$palette===null?null:json_encode($palette,JSON_THROW_ON_ERROR),$music===null?null:json_encode($music,JSON_THROW_ON_ERROR),$brief,
            $artist===null?null:json_encode($artist,JSON_THROW_ON_ERROR),$workflowRequired?1:0,$verificationParent,
            $attribution===null?null:json_encode($attribution,JSON_THROW_ON_ERROR)];
        // Test the parent and write its revision in the same statement. Compare IDs byte for byte.
        if($previousId!==null){
            $parentKinds=$kind==='custom'?"(?, 'listening_room', 'space_eq')":'(?)';
            $sql.=' JOIN agent_scene_history prior_scene
                ON prior_scene.id=? AND prior_scene.user_id=u.id AND HEX(prior_scene.track_id)=HEX(?) AND prior_scene.canvas_kind IN '.$parentKinds;
            $args[]=$previousId;
            $args[]=$payload['track_id'];
            $args[]=$kind;
        }
        $sql.=" WHERE u.id=? AND u.status='active'";
        $args[]=$uid;
        if($expectedEpoch!==null){
            $sql.=" AND COALESCE((SELECT MAX(e.id) FROM user_privacy_events e
                WHERE e.user_id=u.id AND e.event_type='agent_history_cleared'),0)=(? + 0)";
            $args[]=$expectedEpoch;
        }
        $q=$db->prepare($sql);
        $q->execute($args);
        if($q->rowCount()!==1)throw new InvalidArgumentException('The canvas scene or account is unavailable.');
        $id=(int)$db->lastInsertId();
        $db->prepare('UPDATE agent_scene_history SET access_root_id=? WHERE user_id=? AND id=?')->execute([$accessRoot??$id,$uid,$id]);
        if($ownTransaction)$db->commit();
        return ['id'=>$id,'previous_id'=>$previousId,'created_at'=>$created]+$payload;
    }catch(Throwable $error){
        if($ownTransaction&&$db->inTransaction())$db->rollBack();
        throw $error;
    }
}

function sf_agent_scene_history_export(int $uid): array {
    if($uid<1)return [];
    sf_agent_scene_history_ensure_schema();
    $q=sf_db()->prepare('SELECT id,track_id,title,html,css,js,previous_id,created_at,canvas_kind,lyric_section,catalog_refs,agent_design,agent_palette,music_inputs,design_brief,artist_inputs,workflow_required,verification_parent_id,gallery_attribution FROM agent_scene_history
        WHERE user_id=? ORDER BY id ASC');
    $q->execute([$uid]);
    return array_map('sf_agent_scene_history_row',$q->fetchAll(PDO::FETCH_ASSOC));
}

function sf_agent_scene_history_clear(int $uid): int {
    if($uid<1)return 0;
    sf_agent_scene_history_ensure_schema();
    $q=sf_db()->prepare('DELETE FROM agent_scene_history WHERE user_id=?');
    $q->execute([$uid]);
    return $q->rowCount();
}
