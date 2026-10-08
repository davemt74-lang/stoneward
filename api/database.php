<?php
declare(strict_types=1);

require_once __DIR__ . '/install-state.php';

const SF_AUTH_COOKIE = 'stonefellow_auth';
const SF_AUTH_DAYS = 30;

function sf_db_config(): array {
    if(!is_file(SF_DB_CONFIG)) throw new RuntimeException('Stonefellow database configuration is missing.');
    $cfg=require SF_DB_CONFIG;
    if(!is_array($cfg)) throw new RuntimeException('Database configuration is invalid.');
    return $cfg;
}
function sf_db(): PDO {
    static $pdo=null;
    if($pdo instanceof PDO) return $pdo;
    $cfg=sf_db_config();
    $driver=(string)($cfg['driver']??'');
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    if($driver==='sqlite'){
        $path=(string)($cfg['path']??'');
        if($path==='') throw new RuntimeException('SQLite path is missing.');
        $pdo=new PDO('sqlite:'.$path,null,null,$opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } elseif($driver==='mysql'){
        $host=(string)($cfg['host']??'localhost');
        $port=(int)($cfg['port']??3306);
        $name=(string)($cfg['database']??'');
        $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",(string)($cfg['username']??''),(string)($cfg['password']??''),$opts);
    } else {
        throw new RuntimeException('Unsupported database driver.');
    }
    return $pdo;
}
function sf_cookie_secure(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'; }
function sf_user_session(): void {
    if(session_status()===PHP_SESSION_ACTIVE) return;
    session_name('stonefellow_session');
    @ini_set('session.use_strict_mode','1');
    @ini_set('session.use_only_cookies','1');
    @ini_set('session.gc_maxlifetime',(string)(SF_AUTH_DAYS*86400));
    session_cache_limiter('nocache');
    session_set_cookie_params([
        'lifetime'=>SF_AUTH_DAYS*86400,
        'httponly'=>true,
        'samesite'=>'Lax',
        'secure'=>sf_cookie_secure(),
        'path'=>'/'
    ]);
    session_start();
}
function sf_auth_ensure_schema(): void {
    static $done=false;
    if($done) return;
    $pdo=sf_db();
    $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (token_hash TEXT PRIMARY KEY,user_id INTEGER NOT NULL,created_at TEXT NOT NULL,last_seen_at TEXT NOT NULL,expires_at TEXT NOT NULL,user_agent_hash TEXT NOT NULL DEFAULT '',FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id,expires_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (token_hash CHAR(64) NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,last_seen_at VARCHAR(40) NOT NULL,expires_at VARCHAR(40) NOT NULL,user_agent_hash CHAR(64) NOT NULL DEFAULT '',INDEX idx_user_sessions_user(user_id,expires_at),CONSTRAINT fk_user_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    try{$q=$pdo->prepare('DELETE FROM user_sessions WHERE expires_at < ?');$q->execute([gmdate('c')]);}catch(Throwable $e){}
    $done=true;
}
function sf_auth_user_agent_hash(): string { return hash('sha256',(string)($_SERVER['HTTP_USER_AGENT']??'')); }
function sf_auth_cookie_value(): string { return (string)($_COOKIE[SF_AUTH_COOKIE]??''); }
function sf_auth_forget_cookie(): void {
    setcookie(SF_AUTH_COOKIE,'',[
        'expires'=>time()-3600,'path'=>'/','secure'=>sf_cookie_secure(),'httponly'=>true,'samesite'=>'Lax'
    ]);
    unset($_COOKIE[SF_AUTH_COOKIE]);
}
function sf_auth_revoke_cookie_token(): void {
    $token=sf_auth_cookie_value();
    if($token==='') return;
    try{
        sf_auth_ensure_schema();
        $q=sf_db()->prepare('DELETE FROM user_sessions WHERE token_hash=?');
        $q->execute([hash('sha256',$token)]);
    }catch(Throwable $e){}
    sf_auth_forget_cookie();
}
function sf_auth_revoke_all_user_sessions(int $userId): void {
    try{
        sf_auth_ensure_schema();
        $q=sf_db()->prepare('DELETE FROM user_sessions WHERE user_id=?');
        $q->execute([$userId]);
    }catch(Throwable $e){}
    sf_auth_forget_cookie();
}
function sf_auth_sessions(int $userId): array {
    sf_auth_ensure_schema();$current=sf_auth_cookie_value();$currentHash=$current!==''?hash('sha256',$current):'';
    $q=sf_db()->prepare('SELECT token_hash,created_at,last_seen_at,expires_at,user_agent_hash FROM user_sessions WHERE user_id=? AND expires_at>=? ORDER BY last_seen_at DESC');
    $q->execute([$userId,gmdate('c')]);$rows=[];
    foreach($q->fetchAll() as $r)$rows[]=['id'=>(string)$r['token_hash'],'created_at'=>$r['created_at'],'last_seen_at'=>$r['last_seen_at'],'expires_at'=>$r['expires_at'],'current'=>$currentHash!==''&&hash_equals($currentHash,(string)$r['token_hash'])];
    return $rows;
}
function sf_auth_revoke_session(int $userId,string $sessionId): bool {
    if(!preg_match('/^[a-f0-9]{64}$/i',$sessionId))return false;sf_auth_ensure_schema();$current=sf_auth_cookie_value();$currentHash=$current!==''?hash('sha256',$current):'';
    $q=sf_db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND token_hash=?');$q->execute([$userId,strtolower($sessionId)]);
    if($q->rowCount()>0&&$currentHash!==''&&hash_equals($currentHash,strtolower($sessionId)))sf_auth_forget_cookie();
    return $q->rowCount()>0;
}
function sf_auth_revoke_other_sessions(int $userId): int {
    sf_auth_ensure_schema();$current=sf_auth_cookie_value();$currentHash=$current!==''?hash('sha256',$current):'';
    if($currentHash!==''){$q=sf_db()->prepare('DELETE FROM user_sessions WHERE user_id=? AND token_hash<>?');$q->execute([$userId,$currentHash]);}
    else{$q=sf_db()->prepare('DELETE FROM user_sessions WHERE user_id=?');$q->execute([$userId]);}
    return $q->rowCount();
}
function sf_auth_issue_persistent_session(int $userId): void {
    sf_auth_ensure_schema();
    sf_auth_revoke_cookie_token();
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $now=gmdate('c');
    $expires=gmdate('c',time()+SF_AUTH_DAYS*86400);
    $q=sf_db()->prepare('INSERT INTO user_sessions(token_hash,user_id,created_at,last_seen_at,expires_at,user_agent_hash) VALUES(?,?,?,?,?,?)');
    $q->execute([$hash,$userId,$now,$now,$expires,sf_auth_user_agent_hash()]);
    setcookie(SF_AUTH_COOKIE,$token,[
        'expires'=>time()+SF_AUTH_DAYS*86400,'path'=>'/','secure'=>sf_cookie_secure(),'httponly'=>true,'samesite'=>'Lax'
    ]);
    $_COOKIE[SF_AUTH_COOKIE]=$token;
}
function sf_auth_restore_user_id(): int {
    $token=sf_auth_cookie_value();
    if($token==='') return 0;
    try{
        sf_auth_ensure_schema();
        $hash=hash('sha256',$token);
        $q=sf_db()->prepare('SELECT user_id,expires_at,user_agent_hash FROM user_sessions WHERE token_hash=? LIMIT 1');
        $q->execute([$hash]);
        $row=$q->fetch();
        if(!$row){sf_auth_forget_cookie();return 0;}
        if(strtotime((string)$row['expires_at'])<time()){
            sf_db()->prepare('DELETE FROM user_sessions WHERE token_hash=?')->execute([$hash]);
            sf_auth_forget_cookie();
            return 0;
        }
        // User-Agent is retained as session metadata, not a hard binding. Browser updates and
        // embedded webviews can legitimately change it and should not log a customer out.
        sf_db()->prepare('UPDATE user_sessions SET last_seen_at=?,user_agent_hash=? WHERE token_hash=?')->execute([gmdate('c'),sf_auth_user_agent_hash(),$hash]);
        return (int)$row['user_id'];
    }catch(Throwable $e){return 0;}
}
function sf_user_csrf(): string {
    sf_user_session();
    if(empty($_SESSION['sf_csrf'])) $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['sf_csrf'];
}
function sf_csrf_valid(string $sent): bool { return $sent!=='' && hash_equals(sf_user_csrf(),$sent); }
function sf_require_csrf(): void {
    $sent=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');
    if(!sf_csrf_valid($sent)) sf_json_response(['ok'=>false,'error'=>'csrf_failed','message'=>'Your session security token expired. Refresh and try again.'],403);
}
function sf_current_user(): ?array {
    if(!sf_installed()) return null;
    if(function_exists('sf_customer_lifecycle_ensure_schema'))sf_customer_lifecycle_ensure_schema();
    sf_user_session();
    $id=(int)($_SESSION['sf_user_id']??0);
    if($id<1){
        $id=sf_auth_restore_user_id();
        if($id>0){
            $_SESSION['sf_user_id']=$id;
            $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
        }
    }
    if($id<1) return null;
    $q=sf_db()->prepare('SELECT id,email,display_name,role,status,created_at,updated_at,last_login_at,email_verified_at,email_verification_sent_at FROM users WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $u=$q->fetch();
    if(!$u||($u['status']??'')!=='active'){
        unset($_SESSION['sf_user_id']);
        sf_auth_revoke_cookie_token();
        return null;
    }
    return $u;
}
function sf_user_public(?array $u): ?array {
    if(!$u) return null;
    return [
        'id'=>(int)$u['id'],'email'=>$u['email'],'display_name'=>$u['display_name'],'role'=>$u['role'],
        'status'=>$u['status'],'created_at'=>$u['created_at'],'last_login_at'=>$u['last_login_at'],'email_verified'=>!empty($u['email_verified_at']),'email_verified_at'=>$u['email_verified_at']??null
    ];
}
function sf_require_user(bool $admin=false,bool $write=false): array {
    if(!sf_installed()) sf_json_response(['ok'=>false,'error'=>'configuration_required','message'=>'Stonefellow database configuration is unavailable.'],503);
    $u=sf_current_user();
    if(!$u) sf_json_response(['ok'=>false,'error'=>'authentication_required'],401);
    if($admin&&($u['role']??'')!=='admin') sf_json_response(['ok'=>false,'error'=>'admin_required'],403);
    if($write) sf_require_csrf();
    return $u;
}
function sf_email(string $email): string { return strtolower(trim($email)); }
function sf_valid_password(string $pw): bool { return strlen($pw)>=10 && strlen($pw)<=4096; }
function sf_user_by_email(string $email): ?array {
    $q=sf_db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $q->execute([sf_email($email)]);
    $u=$q->fetch();
    return $u?:null;
}
function sf_count_active_admins(): int { return (int)sf_db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'")->fetchColumn(); }
function sf_login_user(array $u): void {
    sf_user_session();
    session_regenerate_id(true);
    $_SESSION['sf_user_id']=(int)$u['id'];
    $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
    sf_auth_issue_persistent_session((int)$u['id']);
    $q=sf_db()->prepare('UPDATE users SET last_login_at=?,updated_at=? WHERE id=?');
    $now=gmdate('c');
    $q->execute([$now,$now,(int)$u['id']]);
}
function sf_logout_user(): void {
    sf_user_session();
    sf_auth_revoke_cookie_token();
    $_SESSION=[];
    if(ini_get('session.use_cookies')){
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
    }
    session_destroy();
}
