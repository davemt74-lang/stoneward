<?php
declare(strict_types=1);
/** Section 84B6: device-style, explicit Stonefellow login + consent.
 * HomeServer keeps verifier private; the browser receives ONLY the request ID.
 * A token is minted once, on the approved verifier's redemption.
 */
require_once __DIR__.'/homeserver-link-core.php';
function sf_hs_pair_schema():void {
    $db=sf_db();$sqlite=(string)(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS homeserver_pair_requests (
          request_id TEXT PRIMARY KEY, verifier_hash TEXT NOT NULL,
          scope TEXT NOT NULL, label TEXT NOT NULL, member_binding TEXT NOT NULL,
          created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
          approved_user_id INTEGER NOT NULL DEFAULT 0,
          state TEXT NOT NULL DEFAULT 'pending', authorized_at INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_hs_pair_exp ON homeserver_pair_requests(expires_at,state)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS homeserver_pair_requests (
          request_id CHAR(48) NOT NULL PRIMARY KEY, verifier_hash CHAR(64) NOT NULL,
          scope VARCHAR(32) NOT NULL,label VARCHAR(120) NOT NULL,
          member_binding VARCHAR(90) NOT NULL,
          created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL,
          approved_user_id BIGINT NOT NULL DEFAULT 0,
          state VARCHAR(20) NOT NULL DEFAULT 'pending',authorized_at BIGINT NOT NULL DEFAULT 0,
          INDEX idx_hs_pair_exp (expires_at,state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_hs_pair_start(string $scope,string $verifier,string $label,string $memberBinding,
    int $now=0,string $originIp=''):array {
    $now=$now?:time();
    if(!in_array($scope,['fan.library.read','admin.presence.read'],true)
       ||!preg_match('/^[a-f0-9]{64}$/D',$verifier)
       ||!preg_match('/^[A-Za-z0-9._-]{12,90}$/D',$memberBinding))
        throw new InvalidArgumentException('Unrecognized HomeServer pairing request.');
    $label=trim(substr(preg_replace('/[^\pL\pN ._-]+/u','',$label)??'',0,80));
    sf_hs_pair_schema();
    $db=sf_db();
    // Bound pending request volume even for unauthenticated initiators.
    $db->prepare("DELETE FROM homeserver_pair_requests WHERE expires_at<?")
       ->execute([$now-86400]);
    $count=(int)$db->query("SELECT COUNT(*) FROM homeserver_pair_requests WHERE state='pending' AND expires_at>".($now))->fetchColumn();
    if($count>=100)throw new RuntimeException('Too many pending HomeServer requests.');
    $id=bin2hex(random_bytes(24));$expiry=$now+600;
    $q=$db->prepare('INSERT INTO homeserver_pair_requests(request_id,verifier_hash,scope,label,member_binding,created_at,expires_at) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$id,hash('sha256',$verifier),$scope,$label,$memberBinding,$now,$expiry]);
    return ['contract'=>'stonefellow.homeserver-pair.v1','request_id'=>$id,
        'authorization_path'=>'/homeserver-connect.php?request_id='.$id,
        'expires_at'=>gmdate('c',$expiry),'scope'=>$scope,'status'=>'pending'];
}
function sf_hs_pair_view(string $id,int $now=0):?array {
    $now=$now?:time();
    if(!preg_match('/^[a-f0-9]{48}$/D',$id))return null;
    sf_hs_pair_schema();
    $q=sf_db()->prepare('SELECT request_id,scope,label,state,expires_at,member_binding FROM homeserver_pair_requests WHERE request_id=? LIMIT 1');
    $q->execute([$id]);$r=$q->fetch();
    if(!$r)return null;
    return ['request_id'=>$r['request_id'],'scope'=>$r['scope'],
        'label'=>$r['label'],'member_binding'=>$r['member_binding'],
        'status'=>(int)$r['expires_at']<=$now?'expired':$r['state'],
        'expires_at'=>gmdate('c',(int)$r['expires_at'])];
}
function sf_hs_pair_decide(string $id,int $uid,string $role,bool $approve,int $now=0):array {
    $now=$now?:time();
    if($uid<1)throw new RuntimeException('Stonefellow login is required for pairing.');
    sf_hs_pair_schema();
    $db=sf_db();$db->beginTransaction();
    try{
        $sql="SELECT * FROM homeserver_pair_requests WHERE request_id=?".((string)(sf_db_config()['driver']??'')==='mysql'?' FOR UPDATE':'');
        $q=$db->prepare($sql);$q->execute([$id]);$r=$q->fetch();
        if(!$r||$r['state']!=='pending'||(int)$r['expires_at']<=$now)
            throw new RuntimeException('HomeServer request expired or already decided.');
        if($approve&&$r['scope']==='admin.presence.read'&&$role!=='admin')
            throw new RuntimeException('Stonefellow administrator login required.');
        $state=$approve?'approved':'denied';
        $q=$db->prepare('UPDATE homeserver_pair_requests SET state=?,approved_user_id=?,authorized_at=? WHERE request_id=? AND state=?');
        $q->execute([$state,$uid,$now,$id,'pending']);
        if($q->rowCount()!==1)throw new RuntimeException('Pairing decision conflict.');
        $db->commit();
        return ['request_id'=>$id,'status'=>$state,'scope'=>$r['scope'],
            'authorized_account_id'=>$uid];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_hs_pair_redeem(string $id,string $verifier,int $now=0):array {
    $now=$now?:time();
    if(!preg_match('/^[a-f0-9]{48}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$verifier))
        throw new RuntimeException('Bad HomeServer pairing verifier.');
    sf_hs_pair_schema();sf_homeserver_link_schema();$db=sf_db();$db->beginTransaction();
    try{
        $sql="SELECT * FROM homeserver_pair_requests WHERE request_id=?".((string)(sf_db_config()['driver']??'')==='mysql'?' FOR UPDATE':'');
        $q=$db->prepare($sql);$q->execute([$id]);$r=$q->fetch();
        if(!$r||!hash_equals((string)$r['verifier_hash'],hash('sha256',$verifier)))
            throw new RuntimeException('Unknown HomeServer pairing verifier.');
        if((int)$r['expires_at']<=$now)throw new RuntimeException('HomeServer request expired.');
        if($r['state']==='pending'||$r['state']==='denied')
            return ['status'=>$r['state'],'request_id'=>$id];
        if($r['state']!=='approved'||(int)$r['approved_user_id']<1)
            throw new RuntimeException('Pairing request cannot be redeemed again.');
        $uid=(int)$r['approved_user_id'];$role='';
        if($r['scope']==='admin.presence.read'){
            $q=$db->prepare('SELECT role FROM users WHERE id=? AND status=?');
            $q->execute([$uid,'active']);$role=(string)$q->fetchColumn();
            if($role!=='admin')throw new RuntimeException('Administrator permission has been revoked.');
        }
        $grant=sf_homeserver_link_issue($uid,(string)$r['scope'],$role,
            (string)$r['label'],$now);
        $q=$db->prepare('UPDATE homeserver_pair_requests SET state=? WHERE request_id=? AND state=?');
        $q->execute(['redeemed',$id,'approved']);
        if($q->rowCount()!==1)throw new RuntimeException('Duplicate HomeServer pairing redemption.');
        $db->commit();
        return ['status'=>'connected','request_id'=>$id,'scope'=>$r['scope'],
            'member_binding'=>$r['member_binding'],'credential_once'=>$grant];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
