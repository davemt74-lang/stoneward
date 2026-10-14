<?php
declare(strict_types=1);
/**
 * 84B6 candidate: Stonefellow remains the authority for user identities,
 * purchased music, print rights and Admin operations. HomeServer may obtain
 * read-only fan or admin credentials ONLY with the Stonefellow owner's consent.
 */
function sf_homeserver_link_schema():void {
    $db=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS homeserver_links (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          user_id INTEGER NOT NULL,
          token_sha256 TEXT NOT NULL UNIQUE,
          scope TEXT NOT NULL,
          label TEXT NOT NULL,
          created_at INTEGER NOT NULL,
          expires_at INTEGER NOT NULL,
          revoked_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_hs_links_owner ON homeserver_links(user_id,scope,expires_at)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS homeserver_links (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          user_id BIGINT UNSIGNED NOT NULL,
          token_sha256 CHAR(64) NOT NULL UNIQUE,
          scope VARCHAR(32) NOT NULL,
          label VARCHAR(120) NOT NULL,
          created_at BIGINT NOT NULL,
          expires_at BIGINT NOT NULL,
          revoked_at BIGINT NOT NULL DEFAULT 0,
          INDEX idx_hs_links_owner (user_id,scope,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_homeserver_link_issue(int $userId,string $scope,string $role,string $label='',int $now=0):array {
    $now=$now?:time();
    if($userId<1||!in_array($scope,['fan.library.read','admin.presence.read'],true))
        throw new InvalidArgumentException('Invalid HomeServer connection.');
    if($scope==='admin.presence.read'&&$role!=='admin')
        throw new RuntimeException('Only Stonefellow administrators may authorize administrative presence.');
    sf_homeserver_link_schema();
    $token='sfhs_'.bin2hex(random_bytes(32));$hash=hash('sha256',$token);
    $label=trim(substr(preg_replace('/[^\pL\pN ._-]+/u','',(string)$label)??'',0,100));
    $expires=$now+30*86400;
    // Finite credentials: require consent again on expiry. Never persist plaintext.
    sf_db()->prepare('INSERT INTO homeserver_links(user_id,token_sha256,scope,label,created_at,expires_at,revoked_at) VALUES (?,?,?,?,?,?,0)')
        ->execute([$userId,$hash,$scope,$label,$now,$expires]);
    return ['token'=>$token,'scope'=>$scope,'expires_at'=>gmdate('c',$expires),
      'label'=>$label,'rights'=>$scope==='fan.library.read'
          ?['catalog.read','purchases.read','owned_audio.stream','vinyl_balance.read']
          :['admin.status.read','publishing_jobs.read']];
}
function sf_homeserver_link_owner(int $userId,int $now=0):array {
    if($userId<1)return [];
    sf_homeserver_link_schema();
    $q=sf_db()->prepare('SELECT id,scope,label,created_at,expires_at,revoked_at FROM homeserver_links WHERE user_id=? ORDER BY id DESC LIMIT 50');
    $q->execute([$userId]);$now=$now?:time();
    return array_map(static fn($r)=>['id'=>(int)$r['id'],'scope'=>$r['scope'],
        'label'=>$r['label'],'created_at'=>gmdate('c',(int)$r['created_at']),
        'expires_at'=>gmdate('c',(int)$r['expires_at']),
        'state'=>(int)$r['revoked_at']>0?'revoked':((int)$r['expires_at']<=$now?'expired':'active')],$q->fetchAll());
}
function sf_homeserver_link_revoke(int $userId,int $linkId):bool {
    if($userId<1||$linkId<1)throw new InvalidArgumentException('Invalid link.');
    sf_homeserver_link_schema();
    $q=sf_db()->prepare('UPDATE homeserver_links SET revoked_at=? WHERE id=? AND user_id=? AND revoked_at=0');
    $q->execute([time(),$linkId,$userId]);
    return $q->rowCount()>0;
}
function sf_homeserver_bearer(string $header,string $requiredScope,int $now=0):int {
    $now=$now?:time();
    if(!preg_match('/^Bearer (sfhs_[a-f0-9]{64})$/D',trim($header),$m))
        throw new RuntimeException('HomeServer authorization required.');
    if(!in_array($requiredScope,['fan.library.read','admin.presence.read'],true))
        throw new RuntimeException('Unrecognized authorization scope.');
    sf_homeserver_link_schema();
    $q=sf_db()->prepare('SELECT user_id,scope FROM homeserver_links WHERE token_sha256=? AND revoked_at=0 AND expires_at>? LIMIT 1');
    $q->execute([hash('sha256',$m[1]),$now]);$row=$q->fetch();
    if(!$row||!hash_equals($requiredScope,(string)$row['scope']))
        throw new RuntimeException('HomeServer connection expired, revoked or unauthorized.');
    if($requiredScope==='admin.presence.read'){
        $q=sf_db()->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $q->execute([(int)$row['user_id']]);
        if((string)$q->fetchColumn()!=='admin')
            throw new RuntimeException('Stonefellow administrator role was revoked.');
    }
    return (int)$row['user_id'];
}
function sf_homeserver_owned_tracks(int $userId,array $library,array $orders,array $catalog):array {
    $owned=[];
    foreach($library as $entry){
        if(!is_array($entry))continue;
        $type=(string)($entry['item_type']??'');$key=(string)($entry['item_key']??'');
        $order=$orders[(string)($entry['source_order_id']??'')]??null;
        if(!in_array($type,['track','release'],true)||!is_array($order)
           ||(int)($order['user_id']??0)!==$userId
           ||!sf_order_paid_for_library($order))continue;
        foreach((array)($order['quote']['items']??[]) as $line){
            if(!is_array($line))continue;
            if($type==='track'&&($line['type']??'')==='track'
                &&(string)($line['track_id']??'')===$key)$owned[$key]=true;
            if($type==='release'&&($line['type']??'')==='release'
                &&(string)($line['release_id']??'')===$key)
                foreach((array)($line['track_ids']??[]) as $trackId)$owned[(string)$trackId]=true;
        }
    }
    $output=[];
    foreach($owned as $id=>$_){
        $t=$catalog[$id]??null;
        if(!is_array($t)||empty($t['audio']))continue;
        $output[]=['id'=>$id,'title'=>substr((string)($t['title']??$id),0,180),
            'artist'=>'Stonefellow','duration_seconds'=>max(0,(int)($t['duration']??0)),
            'artwork'=>(string)($t['artwork']??''),'can_stream'=>true,'can_download'=>true];
    }
    return $output;
}
function sf_homeserver_user_library(int $uid):array {
    $library=sf_account_library($uid);$orders=[];
    foreach($library as $entry){
        $id=(string)($entry['source_order_id']??'');
        if($id!==''&&!array_key_exists($id,$orders))$orders[$id]=sf_read_order($id);
    }
    return sf_homeserver_owned_tracks($uid,$library,$orders,sf_track_map());
}
