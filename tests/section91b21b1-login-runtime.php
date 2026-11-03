<?php
declare(strict_types=1);
/** Disposable databases only. Exercise production functions, not simulated lock/count SQL. */
$source=dirname(__DIR__);$child=($argv[1]??'')==='child';$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true)||!extension_loaded('pdo_'.$driver))throw new RuntimeException('Required database driver unavailable.');
$tmp=$child?(string)$argv[2]:sys_get_temp_dir().'/sf-login-'.bin2hex(random_bytes(6));
if(!$child){mkdir($tmp,0700,true);mkdir($tmp.'/config',0700);mkdir($tmp.'/storage',0700);mkdir($tmp.'/sessions',0700);}
define('SF_ROOT',$tmp);
if(!$child){
    $cfg=$driver==='sqlite'?['driver'=>'sqlite','path'=>$tmp.'/test.sqlite']:['driver'=>'mysql','host'=>'127.0.0.1','port'=>33306,'database'=>'stonefellow_login_test','username'=>'root','password'=>getenv('SF_TEST_MYSQL_PASSWORD')?:''];
    file_put_contents($tmp.'/config/database.php','<?php return '.var_export($cfg,true).';');
}
require $source.'/api/database.php';require $source.'/api/operations.php';
$db=sf_db();if($driver==='sqlite')$db->exec('PRAGMA busy_timeout=15000');
$_SERVER['REMOTE_ADDR']=$child?(string)$argv[4]:'192.0.2.1';
if($child){
    sf_login_security_schema();file_put_contents($tmp.'/ready-'.$argv[3],'ready');
    $end=microtime(true)+20;while(!is_file($tmp.'/go')&&microtime(true)<$end)usleep(10000);
    if(!is_file($tmp.'/go'))exit(3);
    echo json_encode(sf_login_claim((string)$argv[5]));exit;
}
ob_start();session_save_path($tmp.'/sessions');$passed=0;
function check(bool $ok,string $why): void {global $passed;if(!$ok)throw new RuntimeException('FAIL '.$why);$passed++;echo 'PASS '.$why.PHP_EOL;}
function rejected(callable $fn,string $why): void {$thrown=false;try{$fn();}catch(Throwable $e){$thrown=true;}check($thrown,$why);}
function clearAttempts(): void {sf_db()->exec('DELETE FROM auth_attempts');sf_db()->exec('DELETE FROM auth_login_locks');}
function race(string $tmp,array $requests): array {
    @unlink($tmp.'/go');foreach(glob($tmp.'/ready-*')?:[] as $p)unlink($p);
    $jobs=[];
    foreach($requests as $i=>$r){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'child',$tmp,(string)$i,$r[0],$r[1]],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker launch failed.');fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
    $end=microtime(true)+20;while(count(glob($tmp.'/ready-*')?:[])<count($jobs)&&microtime(true)<$end)usleep(10000);
    check(count(glob($tmp.'/ready-*')?:[])===count($jobs),'Independent PHP workers reached admission barrier');touch($tmp.'/go');$out=[];
    foreach($jobs as [$proc,$pipes]){$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);if($code!==0)throw new RuntimeException('Child failed: '.$err);$out[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}
    return $out;
}
try{
    foreach(['user_sessions','auth_login_locks','auth_attempts','users'] as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
    // Deliberately only original account fields: no lifecycle migration dependencies.
    $db->exec('CREATE TABLE users(id '.($driver==='sqlite'?'INTEGER':'BIGINT UNSIGNED').' PRIMARY KEY,email VARCHAR(254) NOT NULL,display_name VARCHAR(100) NOT NULL,role VARCHAR(30) NOT NULL,status VARCHAR(30) NOT NULL,password_hash VARCHAR(255) NOT NULL)'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $pw=password_hash('valid-test-password',PASSWORD_BCRYPT,['cost'=>4]);
    $q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?)');
    $q->execute([1,'admin@example.test','Admin','admin','active',$pw]);$q->execute([2,'fan@example.test','Fan','user','active',$pw]);$q->execute([3,'disabled@example.test','Disabled','admin','disabled',$pw]);
    // Legacy attempts survive upgrade; the old schema lacks the IP index.
    $db->exec($driver==='sqlite'?'CREATE TABLE auth_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT,identifier_hash TEXT NOT NULL,ip_hash TEXT NOT NULL,success INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL)':"CREATE TABLE auth_attempts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,identifier_hash CHAR(64) NOT NULL,ip_hash CHAR(64) NOT NULL,success TINYINT NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,INDEX idx_auth_attempt_lookup(identifier_hash,created_at)) ENGINE=InnoDB");
    $db->prepare('INSERT INTO auth_attempts(identifier_hash,ip_hash,success,created_at) VALUES(?,?,0,?)')->execute([sf_auth_attempt_hash('legacy@example.test'),sf_auth_ip_hash(),gmdate('c')]);
    sf_login_security_schema();sf_login_security_schema();
    check((int)$db->query('SELECT COUNT(*) FROM auth_attempts')->fetchColumn()===1,'Legacy failures preserved by repeat-safe security initialization');
    $indices=$driver==='sqlite'?$db->query('PRAGMA index_list(auth_attempts)')->fetchAll():$db->query('SHOW INDEX FROM auth_attempts')->fetchAll();
    check(str_contains(json_encode($indices),'idx_auth_attempt_ip'),'Existing installations receive IP lookup index');
    check(!$db->query($driver==='sqlite'?"SELECT name FROM sqlite_master WHERE name='subscription_packages'":"SHOW TABLES LIKE 'subscription_packages'")->fetch(),'Limiter does not initialize subscription or lifecycle tables');
    clearAttempts();$surfaces=['public','admin','upgrade'];
    for($i=0;$i<8;$i++){ $r=sf_auth_password_check($i%2?' ADMIN@EXAMPLE.TEST ':'admin@example.test','wrong',$surfaces[$i%3]);check(($r['status']??0)===401,'Shared failure '.($i+1).' is generic'); }
    foreach($surfaces as $s){$r=sf_auth_password_check('admin@example.test','valid-test-password',$s);check(($r['status']??0)===429,'Threshold applies to '.$s.' including correct credentials');}
    check((int)$db->query('SELECT COUNT(*) FROM auth_attempts')->fetchColumn()===8,'Blocked requests do not extend cooldown or append attempts');
    check($db->query('SELECT status FROM users WHERE id=1')->fetchColumn()==='active','Throttle never permanently disables the account');
    $db->prepare('UPDATE auth_attempts SET created_at=?')->execute([gmdate('c',time()-850)]);
    $r=sf_auth_login_allowed('admin@example.test');check($r['retry_after_seconds']>0&&$r['retry_after_seconds']<=50,'Retry interval reflects remaining time instead of resetting to 900');
    $db->prepare('UPDATE auth_attempts SET created_at=?')->execute([gmdate('c',time()-900)]);
    $r=sf_auth_password_check('admin@example.test','valid-test-password','admin');check($r['ok']&&!isset($r['user']['password_hash']),'Exact-window expiry restores valid login without returning password hash');
    check((int)$db->query('SELECT COUNT(*) FROM auth_attempts WHERE success=1')->fetchColumn()===1,'Successful attempts are recorded separately from failures');
    clearAttempts();
    foreach($surfaces as $s){check(sf_auth_password_check('admin@example.test','valid-test-password',$s)['ok'],'Active admin accepted on '.$s);}
    check(sf_auth_password_check('fan@example.test','valid-test-password','public')['ok'],'Fan remains able to use public login');
    foreach(['admin','upgrade'] as $s)check(!sf_auth_password_check('fan@example.test','valid-test-password',$s)['ok'],'Fan cannot use '.$s.' with a correct password');
    $unknown=sf_auth_password_check('unknown@example.test','wrong','admin');$disabled=sf_auth_password_check('disabled@example.test','valid-test-password','admin');
    check($unknown===$disabled,'Unknown and disabled accounts receive identical result shape and message');
    check(!sf_auth_password_check('admin@example.test',str_repeat('a',4097),'public')['ok'],'Oversized password cannot authenticate');
    rejected(fn()=>sf_auth_password_check('admin@example.test','valid-test-password','invented'),'Login surface is server allowlisted');
    clearAttempts();
    for($i=0;$i<30;$i++)check(sf_login_claim('spray'.$i.'@example.test')['ok'],'IP admission slot '.($i+1));
    $before=(int)$db->query('SELECT COUNT(*) FROM auth_login_locks')->fetchColumn();
    $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.3';$_SERVER['HTTP_X_REAL_IP']='203.0.113.2';
    check((sf_login_claim('new@example.test')['status']??0)===429,'Untrusted forwarding headers cannot bypass IP limit');
    check((int)$db->query('SELECT COUNT(*) FROM auth_login_locks')->fetchColumn()===$before,'Exhausted IP does not create new account-lock rows');
    $_SERVER['REMOTE_ADDR']='::ffff:192.0.2.1';check((sf_login_claim('new@example.test')['status']??0)===429,'Mapped IPv6 and IPv4 share their source bucket');
    $_SERVER['REMOTE_ADDR']='192.0.2.200';check(sf_login_claim('other@example.test')['ok'],'Independent source and account retain capacity');
    clearAttempts();$_SERVER['REMOTE_ADDR']='192.0.2.1';
    $req=[];for($i=0;$i<12;$i++)$req[]=['192.0.2.'.($i+10),'admin@example.test'];
    $out=race($tmp,$req);check(count(array_filter($out,fn($r)=>$r['ok']))===8,'Concurrent cross-IP attempts cannot exceed account limit');
    clearAttempts();for($i=0;$i<28;$i++)sf_login_claim('pre'.$i.'@example.test');
    $out=race($tmp,array_map(fn($i)=>['192.0.2.1','race'.$i.'@example.test'],range(1,6)));
    check(count(array_filter($out,fn($r)=>$r['ok']))===2,'Concurrent accounts cannot exceed source-IP limit');
    clearAttempts();
    $db->exec($driver==='sqlite'?"CREATE TRIGGER fail_attempt BEFORE INSERT ON auth_attempts BEGIN SELECT RAISE(ABORT,'injected'); END":"CREATE TRIGGER fail_attempt BEFORE INSERT ON auth_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
    rejected(fn()=>sf_auth_password_check('admin@example.test','valid-test-password','public'),'Failed admission persistence blocks authentication');
    check((int)$db->query('SELECT COUNT(*) FROM auth_login_locks')->fetchColumn()===0,'Admission failure rolls back lock changes');$db->exec('DROP TRIGGER fail_attempt');
    $db->exec($driver==='sqlite'?"CREATE TRIGGER fail_success BEFORE UPDATE ON auth_attempts BEGIN SELECT RAISE(ABORT,'injected'); END":"CREATE TRIGGER fail_success BEFORE UPDATE ON auth_attempts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
    rejected(fn()=>sf_auth_password_check('admin@example.test','valid-test-password','admin'),'Failure to record successful authentication fails closed');$db->exec('DROP TRIGGER fail_success');
    check((int)$db->query('SELECT COUNT(*) FROM auth_attempts WHERE success=0')->fetchColumn()===1,'Unsettled attempt remains bounded by the normal window');
    clearAttempts();$upgrade=file_get_contents($source.'/upgrade.php');$a=strpos($upgrade,'function sf_upgrade_admin_from_session');$b=strpos($upgrade,'function sf_upgrade_lock_acquire');eval(substr($upgrade,$a,$b-$a));
    $r=sf_upgrade_admin_login('admin@example.test','valid-test-password');check($r['ok'],'Original-schema upgrade login succeeds');
    check((sf_upgrade_admin_from_session()['id']??0)===1,'Upgrade login issues and validates persistent identity');
    $token=sf_auth_cookie_value();sf_db()->prepare('DELETE FROM user_sessions WHERE token_hash=?')->execute([hash('sha256',$token)]);
    check(($_SESSION['sf_user_id']??0)===1,'Cached upgrade identity exists before revocation check');
    check(sf_upgrade_admin_from_session()===null,'Revoked upgrade token cannot fall back to cached PHP identity');
    clearAttempts();sf_login_claim('cleanup@example.test');
    $db->prepare('UPDATE auth_attempts SET created_at=?')->execute([gmdate('c',time()-31*86400)]);$db->exec('UPDATE auth_login_locks SET updated_at=0');sf_login_security_prune();
    check((int)$db->query('SELECT COUNT(*) FROM auth_attempts')->fetchColumn()===0,'Expired security history is pruned');
    check((int)$db->query('SELECT COUNT(*) FROM auth_login_locks')->fetchColumn()===0,'Abandoned lock rows are pruned');
    sf_login_claim('private@example.test');$dump=json_encode($db->query('SELECT * FROM auth_attempts')->fetchAll());
    check(!str_contains($dump,'private@example.test')&&!str_contains($dump,'192.0.2.1')&&!str_contains($dump,'password'),'Security records contain hashes, not credentials or raw identifiers');
    echo "91B21B1 $driver: $passed assertions passed\n";
} finally {if(session_status()===PHP_SESSION_ACTIVE)session_write_close();if(!$child){foreach(['user_sessions','auth_login_locks','auth_attempts','users'] as $table)$db->exec('DROP TABLE IF EXISTS '.$table);}}
ob_end_flush();
