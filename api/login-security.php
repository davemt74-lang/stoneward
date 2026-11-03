<?php
declare(strict_types=1);

/** 91B21B1: shared, upgrade-safe password admission. No user/role data in throttle keys. */
const SF_LOGIN_WINDOW_SECONDS = 900;
const SF_LOGIN_ACCOUNT_LIMIT = 8;
const SF_LOGIN_IP_LIMIT = 30;

function sf_login_security_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new RuntimeException('Login security cannot initialize inside a transaction.');
    if((sf_db_config()['driver']??'')==='sqlite'){
        $db->exec("CREATE TABLE IF NOT EXISTS auth_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,identifier_hash TEXT NOT NULL,ip_hash TEXT NOT NULL,success INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_auth_attempt_lookup ON auth_attempts(identifier_hash,created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_auth_attempt_ip ON auth_attempts(ip_hash,created_at)');
        $db->exec('CREATE TABLE IF NOT EXISTS auth_login_locks (lock_key TEXT PRIMARY KEY,updated_at INTEGER NOT NULL)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_login_lock_updated ON auth_login_locks(updated_at)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS auth_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,identifier_hash CHAR(64) NOT NULL,ip_hash CHAR(64) NOT NULL,success TINYINT(1) NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,INDEX idx_auth_attempt_lookup(identifier_hash,created_at),INDEX idx_auth_attempt_ip(ip_hash,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Existing installations predate the IP lookup index. Concurrent first requests may both add it.
        if(!$db->query("SHOW INDEX FROM auth_attempts WHERE Key_name='idx_auth_attempt_ip'")->fetch()){
            try{$db->exec('CREATE INDEX idx_auth_attempt_ip ON auth_attempts(ip_hash,created_at)');}
            catch(PDOException $e){if((int)($e->errorInfo[1]??0)!==1061)throw $e;}
        }
        $db->exec('CREATE TABLE IF NOT EXISTS auth_login_locks (lock_key VARCHAR(80) NOT NULL PRIMARY KEY,updated_at BIGINT NOT NULL,INDEX idx_login_lock_updated(updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}
function sf_login_source_ip(): string {
    // Forwarded headers are deliberately untrusted; configure a trusted proxy at the web-server layer.
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $packed=@inet_pton($ip);
    if($packed===false)return 'unknown';
    if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")$packed=substr($packed,12);
    return (string)inet_ntop($packed);
}
function sf_login_bucket_delay(PDO $db,string $column,string $hash,int $limit,int $now): int {
    if(!in_array($column,['identifier_hash','ip_hash'],true))throw new InvalidArgumentException('Invalid login bucket.');
    // The threshold-th newest failure determines when this bucket next has capacity.
    $q=$db->prepare('SELECT created_at FROM auth_attempts WHERE '.$column.'=? AND success=0 AND created_at>? ORDER BY created_at DESC,id DESC LIMIT '.(int)$limit);
    $q->execute([$hash,gmdate('c',$now-SF_LOGIN_WINDOW_SECONDS)]);$times=$q->fetchAll(PDO::FETCH_COLUMN);
    if(count($times)<$limit)return 0;
    return max(1,min(SF_LOGIN_WINDOW_SECONDS,(int)strtotime((string)$times[$limit-1])+SF_LOGIN_WINDOW_SECONDS-$now));
}
function sf_login_retry_result(int $seconds): array {
    return ['ok'=>false,'error'=>'login_throttled','status'=>429,'retry_after_seconds'=>$seconds,
        'message'=>'Too many sign-in attempts. Try again in '.$seconds.' seconds.'];
}
function sf_login_bucket_lock(PDO $db,string $key,int $now): void {
    $sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->prepare('INSERT OR IGNORE INTO auth_login_locks(lock_key,updated_at) VALUES(?,?)')->execute([$key,$now]);
        $db->prepare('UPDATE auth_login_locks SET updated_at=? WHERE lock_key=?')->execute([$now,$key]);
    }else{
        // Acquire an exclusive record lock directly: INSERT IGNORE can deadlock on shared-lock upgrades.
        $db->prepare('INSERT INTO auth_login_locks(lock_key,updated_at) VALUES(?,?) ON DUPLICATE KEY UPDATE updated_at=?')->execute([$key,$now,$now]);
    }
}
function sf_login_claim(string $email): array {
    sf_login_security_schema();$db=sf_db();
    if($db->inTransaction())throw new RuntimeException('Login admission requires its own transaction.');
    $now=time();$account=sf_auth_attempt_hash($email);$ip=sf_auth_ip_hash();
    // A count before waiting on another account lock must not freeze a REPEATABLE READ snapshot.
    if((sf_db_config()['driver']??'')==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $db->beginTransaction();
    try{
        // All workers take IP then account; no worker requests a second IP lock.
        sf_login_bucket_lock($db,'ip:'.$ip,$now);
        $retry=sf_login_bucket_delay($db,'ip_hash',$ip,SF_LOGIN_IP_LIMIT,$now);
        if($retry>0){$db->commit();return sf_login_retry_result($retry);}
        sf_login_bucket_lock($db,'account:'.$account,$now);
        $retry=sf_login_bucket_delay($db,'identifier_hash',$account,SF_LOGIN_ACCOUNT_LIMIT,$now);
        if($retry>0){$db->commit();return sf_login_retry_result($retry);}
        // A pending verification counts before the expensive hash operation, closing check-then-write races.
        $q=$db->prepare('INSERT INTO auth_attempts(identifier_hash,ip_hash,success,created_at) VALUES(?,?,0,?)');
        $q->execute([$account,$ip,gmdate('c',$now)]);$id=(int)$db->lastInsertId();$db->commit();
        return ['ok'=>true,'attempt_id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_login_security_prune(): void {
    $db=sf_db();if($db->inTransaction())return;
    // Bounded batches, including abandoned admission locks. Never delete recent in-flight attempts.
    $q=$db->prepare('SELECT id FROM auth_attempts WHERE created_at<? ORDER BY id LIMIT 500');
    $q->execute([gmdate('c',time()-30*86400)]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id)$db->prepare('DELETE FROM auth_attempts WHERE id=?')->execute([$id]);
    $cut=time()-2*86400;$q=$db->prepare('SELECT lock_key FROM auth_login_locks WHERE updated_at<? LIMIT 500');$q->execute([$cut]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $key)$db->prepare('DELETE FROM auth_login_locks WHERE lock_key=? AND updated_at<?')->execute([$key,$cut]);
}
function sf_auth_password_check(string $email,string $password,string $surface): array {
    if(!in_array($surface,['public','admin','upgrade'],true))throw new InvalidArgumentException('Invalid login surface.');
    $email=strtolower(trim($email));$ticket=sf_login_claim($email);
    if(!$ticket['ok'])return $ticket;
    // Original installer fields only: upgrade login must work before lifecycle migrations.
    $q=sf_db()->prepare('SELECT id,email,display_name,role,status,password_hash FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]);$user=$q->fetch();
    $validInput=strlen($email)<=254&&filter_var($email,FILTER_VALIDATE_EMAIL)&&strlen($password)>0&&strlen($password)<=4096;
    $dummy='$2y$12$ymsdWSy1Lv7GTJ1NC1gCTu3QEQZHc6RSF38xjehNOAVMGi8tPPxVi';
    $verified=password_verify($validInput?$password:'invalid-login-input',($user&&$validInput)?(string)$user['password_hash']:$dummy);
    $success=$validInput&&$verified&&$user&&($user['status']??'')==='active'&&($surface==='public'||($user['role']??'')==='admin');
    if($success){
        sf_db()->prepare('UPDATE auth_attempts SET success=1 WHERE id=? AND success=0')->execute([$ticket['attempt_id']]);
        unset($user['password_hash']);
    }
    // Cleanup failure must not undo authentication or remove its rate-limit record.
    try{if(random_int(1,50)===1)sf_login_security_prune();}catch(Throwable $e){error_log('Stonefellow login housekeeping failed: '.get_class($e));}
    if(!$success)return ['ok'=>false,'error'=>'invalid_credentials','status'=>401,'message'=>'Invalid email or password.','retry_after_seconds'=>0];
    return ['ok'=>true,'user'=>$user];
}
function sf_login_error_headers(array $result): void {
    http_response_code((int)($result['status']??503));header('Cache-Control: no-store');
    if(($result['status']??0)===429)header('Retry-After: '.max(1,(int)$result['retry_after_seconds']));
}
function sf_login_unavailable(): array {
    return ['ok'=>false,'error'=>'login_unavailable','status'=>503,'message'=>'Sign-in is temporarily unavailable. Please try again shortly.','retry_after_seconds'=>0];
}
