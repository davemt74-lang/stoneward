<?php
declare(strict_types=1);
/** 87D2. Consent-gated interest evidence, deliberately separate from payments. */
function sf_87d_intent_schema():void{
    static $ready=false;if($ready)return;$pdo=sf_db();
    if((string)(sf_db_config()['driver']??'')==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_conversion_intents
          (event_key TEXT PRIMARY KEY,user_id INTEGER NOT NULL,stage TEXT NOT NULL,cart_kind TEXT NOT NULL,created_at TEXT NOT NULL,
           FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fan_conversion_intents_time ON fan_conversion_intents(created_at,stage)');
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_conversion_intents
          (event_key CHAR(64) NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
           stage VARCHAR(24) NOT NULL,cart_kind VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,
           INDEX idx_fan_conversion_intents_time(created_at,stage),
           CONSTRAINT fk_fan_conversion_intent_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)
           ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_87d_intent_clear(int $uid):void{
    if($uid<1)return;sf_87d_intent_schema();$q=sf_db()->prepare('DELETE FROM fan_conversion_intents WHERE user_id=?');$q->execute([$uid]);
}
function sf_87d_intent_record(int $uid,string $stage,array $quote):bool {
    if($uid<1||!in_array($stage,['cart_review','checkout_review'],true)||empty($quote['ok']))return false;
    if(!sf_privacy_personalization_enabled($uid)){sf_87d_intent_clear($uid);return false;}
    $items=(array)($quote['items']??[]);if(!$items||count($items)>30)return false;
    $digital=false;$vinyl=false;$keys=[];
    foreach($items as $item){
        if(!is_array($item))return false;$type=(string)($item['type']??'');
        if(in_array($type,['track','release'],true))$digital=true;
        if(in_array($type,['custom_media','album_vinyl','vinyl_license_pack','album_vinyl_license_pack'],true))$vinyl=true;
        if($type===''||strlen($type)>50)return false;
        // Non-identifying, normalized item mix from server quote. Never store
        // customer fields, shipping, private masters, notes, or raw cart JSON.
        $keys[]=$type.':'.hash('sha256',json_encode([
          (string)($item['track_id']??''),(string)($item['release_id']??''),
          (string)($item['edition_id']??''),(int)($item['quantity']??1)
        ],JSON_THROW_ON_ERROR));
    }
    $kind=$digital&&$vinyl?'mixed':($vinyl?'vinyl':($digital?'digital':'other'));
    sort($keys,SORT_STRING);
    $today=gmdate('Y-m-d');
    $event=hash('sha256',$uid.'|'.$today.'|'.$stage.'|'.$kind.'|'.implode('|',$keys));
    sf_87d_intent_schema();$pdo=sf_db();
    $sql=(string)(sf_db_config()['driver']??'')==='sqlite'
      ?'INSERT OR IGNORE INTO fan_conversion_intents(event_key,user_id,stage,cart_kind,created_at) VALUES(?,?,?,?,?)'
      :'INSERT IGNORE INTO fan_conversion_intents(event_key,user_id,stage,cart_kind,created_at) VALUES(?,?,?,?,?)';
    $q=$pdo->prepare($sql);$q->execute([$event,$uid,$stage,$kind,gmdate('c')]);
    $pdo->prepare('DELETE FROM fan_conversion_intents WHERE created_at<?')->execute([gmdate('c',time()-90*86400)]);
    return $q->rowCount()>0;
}
function sf_87d_intent_aggregate(int $days=30):array {
    $days=max(1,min(90,$days));sf_87d_intent_schema();sf_privacy_ensure_schema();
    $q=sf_db()->prepare("SELECT i.stage,i.cart_kind,COUNT(*) AS events,COUNT(DISTINCT i.user_id) AS accounts
      FROM fan_conversion_intents i LEFT JOIN user_profiles p ON p.user_id=i.user_id
      WHERE i.created_at>=? AND (p.personalization_enabled IS NULL OR p.personalization_enabled=1)
      GROUP BY i.stage,i.cart_kind");
    $q->execute([gmdate('c',time()-$days*86400)]);
    $rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
        // Small cohorts are suppressed instead of revealing individual activity.
        $accounts=(int)$r['accounts'];$rows[]=['stage'=>(string)$r['stage'],
          'kind'=>(string)$r['cart_kind'],'accounts'=>$accounts>=5?$accounts:null,
          'events'=>$accounts>=5?(int)$r['events']:null,'insufficient_sample'=>$accounts<5];
    }
    return ['days'=>$days,'segments'=>$rows,'source'=>'server_requoted_signed_in_consenting_accounts',
      'cart_to_payment_conversion_rate'=>null,'is_payment_evidence'=>false,
      'notes'=>'Review signals are not purchases. Stage groups are independent, deduped by account/day/cart and suppressed below five accounts.'];
}
