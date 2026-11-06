<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-photo-core.php';

const SF_IMAGE_PROFILE_CATEGORIES=['interests','creative_preferences','music_preferences','experience_preferences'];

function sf_image_profile_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize photo preferences before opening a transaction.');
    sf_agent_canvas_photo_ensure_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS image_profile_suggestions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,source_id TEXT NOT NULL,
            source_sha256 TEXT NOT NULL,suggestion_hash TEXT NOT NULL,category TEXT NOT NULL,
            value TEXT NOT NULL,evidence TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',
            privacy_epoch INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
            UNIQUE(user_id,source_id,suggestion_hash),
            FOREIGN KEY(user_id,source_id) REFERENCES agent_canvas_photo_sources(user_id,image_id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_image_profile_owner ON image_profile_suggestions(user_id,status,id)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS image_profile_suggestions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
            source_id CHAR(32) NOT NULL,source_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            suggestion_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,category VARCHAR(32) NOT NULL,
            value VARCHAR(160) NOT NULL,evidence VARCHAR(360) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'pending',
            privacy_epoch BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
            UNIQUE KEY uq_image_profile_source(user_id,source_id,suggestion_hash),
            INDEX idx_image_profile_owner(user_id,status,id),
            CONSTRAINT fk_image_profile_source FOREIGN KEY(user_id,source_id)
                REFERENCES agent_canvas_photo_sources(user_id,image_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}

/** Preference suggestions can use observable hobby and aesthetic cues, never personal traits. */
function sf_image_profile_suggestion(mixed $raw): ?array {
    if(!is_array($raw)||!in_array($raw['category']??null,SF_IMAGE_PROFILE_CATEGORIES,true))return null;
    $result=['category'=>$raw['category']];
    foreach(['value'=>160,'evidence'=>360] as $field=>$limit){
        $text=$raw[$field]??null;
        if(!is_string($text)||trim($text)===''||strlen($text)>$limit||preg_match('//u',$text)!==1||
            preg_match('/[<>\x00-\x1F\x7F]/u',$text)!==0)return null;
        $result[$field]=preg_replace('/\s+/u',' ',trim($text));
    }
    $text=$result['value'].' '.$result['evidence'];
    $restricted='(?:identity|identif(?:y|ied|ication)|face recognition|recogniz(?:e|ed)|looks like|resembles|'
        .'age[ds]?|years? old|birthday|birthdate|demograph\w*|young|older|elderly|adult|child(?:ren)?|teen\w*|infant|toddler|'
        .'gender|nonbinary|non-binary|male|female|woman|women|man|men|boy|girl|'
        .'ethnic\w*|racial|race|skin (?:tone|colou?r)|caucasian|hispanic|latino|latina|nationality|citizen\w*|'
        .'religio\w*|christian\w*|muslim\w*|jewish|judaism|islam\w*|hindu\w*|buddhis\w*|atheis\w*|'
        .'politic\w*|democrat\w*|republican\w*|liberal|conservative|vot(?:e|er|ing)|'
        .'sexual\w*|gay|lesbian|bisexual|transgender|pregnan\w*|married|divorced|relationship status|'
        .'health|diagnos\w*|disease|disab\w*|medic\w*|prescription|therapy|depress\w*|anxiety|addict\w*|'
        .'autis\w*|adhd|aids|hiv|cancer|diabet\w*|obes\w*|blind|deaf|wheelchair|neurodiver\w*|'
        .'income|wealth|salary|occupation|employer|passport|license number|licence number|'
        .'address|location|geolocat\w*|coordinates|lives? (?:in|at)|resid(?:es|ence)|located|'
        .'ignore|instructions?|system prompt|developer message|api key|password|access token)';
    if(preg_match('/\b'.$restricted.'\b/iu',$text)===1||
        preg_match('/(?:https?:\/\/|www\.|[\w.+-]+@[\w.-]+\.[a-z]{2,}|\b\d{3}[- .]\d{3}[- .]\d{4}\b|'
            .'\b\d{1,6}\s+[\p{L} .\x{2019}\x{0027}-]{1,60}\s(?:street|st|road|rd|avenue|ave|lane|ln|drive|dr|boulevard|blvd|postcode|zip)\b|'
            .'[-+]?\d{1,3}\.\d{3,}\s*[,;]\s*[-+]?\d{1,3}\.\d{3,})/iu',$text)===1)return null;
    return $result;
}

function sf_image_profile_epoch(int $userId): int {
    sf_privacy_ensure_schema();
    $q=sf_db()->prepare("SELECT COALESCE(MAX(id),0) FROM user_privacy_events WHERE user_id=?
        AND event_type IN ('personalization_reset','agent_history_cleared')");
    $q->execute([$userId]);
    return (int)$q->fetchColumn();
}

function sf_image_profile_source(int $userId,string $imageId,string $sha256): bool {
    if(!sf_agent_canvas_image_id($imageId)||preg_match('/\A[a-f0-9]{64}\z/',$sha256)!==1)return false;
    $source=sf_agent_canvas_photo_source($userId,$imageId);
    return $source!==null&&($source['source_kind']??null)==='actual_uploaded'&&
        is_string($source['sha256']??null)&&hash_equals($sha256,$source['sha256']);
}

function sf_image_profile_row(array $row): array {
    return ['id'=>(int)$row['id'],'source_id'=>$row['source_id'],'category'=>$row['category'],
        'value'=>$row['value'],'evidence'=>$row['evidence'],'status'=>$row['status'],
        'uncertain'=>$row['status']!=='accepted','created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
}

/** Called only after a requested analysis commits. Retries preserve every review decision. */
function sf_image_profile_propose(int $userId,string $imageId,string $sha256,array $suggestions,?int $expectedPrivacyEpoch=null): array {
    sf_image_profile_ensure_schema();
    sf_privacy_ensure_schema();
    if(!array_is_list($suggestions)||count($suggestions)>8)throw new InvalidArgumentException('Review up to eight photo preference suggestions at a time.');
    if(!sf_image_profile_source($userId,$imageId,$sha256))throw new InvalidArgumentException('Choose your uploaded personal photo for preference suggestions.');
    $db=sf_db();$sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $db->beginTransaction();
    try{
        $db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$userId]);
        $q=$db->prepare('SELECT status FROM users WHERE id=?'.($sqlite?'':' FOR UPDATE'));$q->execute([$userId]);
        $epoch=sf_image_profile_epoch($userId);
        if($q->fetchColumn()!=='active'||($expectedPrivacyEpoch!==null&&$expectedPrivacyEpoch!==$epoch)||
            !sf_image_profile_source($userId,$imageId,$sha256))
            throw new InvalidArgumentException('The photo preference request is no longer available.');
        $upsert=$sqlite?'ON CONFLICT(user_id,source_id,suggestion_hash) DO NOTHING':'ON DUPLICATE KEY UPDATE suggestion_hash=VALUES(suggestion_hash)';
        $insert=$db->prepare('INSERT INTO image_profile_suggestions
            (user_id,source_id,source_sha256,suggestion_hash,category,value,evidence,status,privacy_epoch,created_at,updated_at)
            VALUES(?,?,?,?,?,?,?,\'pending\',?,?,?) '.$upsert);
        $now=gmdate('c');
        foreach($suggestions as $raw){
            $suggestion=sf_image_profile_suggestion($raw);
            if($suggestion===null)continue;
            $hash=hash('sha256',$sha256."\0".$suggestion['category']."\0".strtolower($suggestion['value']));
            $insert->execute([$userId,$imageId,$sha256,$hash,$suggestion['category'],$suggestion['value'],$suggestion['evidence'],$epoch,$now,$now]);
        }
        $db->commit();
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
    return array_values(array_filter(sf_image_profile_list($userId),static fn(array $row):bool=>$row['source_id']===$imageId));
}

function sf_image_profile_list(int $userId,bool $includeDismissed=false): array {
    if($userId<1)return [];
    sf_image_profile_ensure_schema();
    $epoch=sf_image_profile_epoch($userId);
    $statuses=$includeDismissed?'':" AND status IN ('pending','accepted')";
    $q=sf_db()->prepare('SELECT * FROM image_profile_suggestions WHERE user_id=? AND privacy_epoch=?'.$statuses.' ORDER BY id DESC LIMIT 100');
    $q->execute([$userId,$epoch]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(($includeDismissed||in_array($row['status'],['pending','accepted'],true))&&
            sf_image_profile_source($userId,$row['source_id'],$row['source_sha256']))$out[]=sf_image_profile_row($row);
    }
    return $out;
}

function sf_image_profile_update(int $userId,int $id,string $action): array {
    if($userId<1||$id<1||!in_array($action,['accept','dismiss','remove'],true))throw new InvalidArgumentException('Choose a photo preference to review.');
    sf_image_profile_ensure_schema();sf_privacy_ensure_schema();$db=sf_db();
    $db->beginTransaction();
    try{
        $db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$userId]);
        $q=$db->prepare('SELECT p.* FROM image_profile_suggestions p JOIN users u ON u.id=p.user_id AND u.status=\'active\'
            WHERE p.user_id=? AND p.id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$userId,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||(int)$row['privacy_epoch']!==sf_image_profile_epoch($userId))throw new OutOfBoundsException('Photo preference not found.');
        if($action==='accept'&&($row['status']==='removed'||!sf_image_profile_source($userId,$row['source_id'],$row['source_sha256'])||
            sf_image_profile_suggestion($row)===null))throw new InvalidArgumentException('This photo preference is no longer available.');
        $status=match($action){'accept'=>'accepted','dismiss'=>'dismissed','remove'=>'removed'};
        if($row['status']==='removed')$status='removed';
        if($status==='removed'){$row['value']='';$row['evidence']='';}
        $now=gmdate('c');
        $db->prepare('UPDATE image_profile_suggestions SET status=?,value=?,evidence=?,updated_at=? WHERE user_id=? AND id=?')
            ->execute([$status,$row['value'],$row['evidence'],$now,$userId,$id]);
        $db->commit();return sf_image_profile_row(array_replace($row,['status'=>$status,'updated_at'=>$now]));
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
}

/** Only the requesting owner can receive explicitly confirmed, currently sourced preferences. */
function sf_image_profile_context(int $userId): array {
    if($userId<1||!sf_privacy_personalization_enabled($userId))return [];
    try{
        $q=sf_db()->prepare("SELECT * FROM image_profile_suggestions WHERE user_id=? AND status='accepted' ORDER BY id DESC LIMIT 25");
        $q->execute([$userId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $error){
        if(sf_image_profile_missing_table($error))return [];
        throw $error;
    }
    if(!$rows)return [];
    $epoch=sf_image_profile_epoch($userId);$out=[];
    foreach($rows as $row){
        if((int)$row['privacy_epoch']===$epoch&&sf_image_profile_suggestion($row)!==null&&
            sf_image_profile_source($userId,$row['source_id'],$row['source_sha256']))
            $out[]=['category'=>$row['category'],'value'=>$row['value']];
    }
    return $out;
}

function sf_image_profile_missing_table(PDOException $error): bool {
    return (string)$error->getCode()==='42S02'||str_contains(strtolower($error->getMessage()),'no such table');
}

function sf_image_profile_export(int $userId): array {
    try{
        $q=sf_db()->prepare('SELECT * FROM image_profile_suggestions WHERE user_id=? ORDER BY id');$q->execute([$userId]);
        return array_map(static fn(array $row):array=>sf_image_profile_row($row)+['source_sha256'=>$row['source_sha256']],$q->fetchAll(PDO::FETCH_ASSOC));
    }catch(PDOException $error){if(sf_image_profile_missing_table($error))return [];throw $error;}
}

function sf_image_profile_clear(int $userId): int {
    try{
        $q=sf_db()->prepare('DELETE FROM image_profile_suggestions WHERE user_id=?');$q->execute([$userId]);return $q->rowCount();
    }catch(PDOException $error){if(sf_image_profile_missing_table($error))return 0;throw $error;}
}
