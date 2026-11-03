<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/api/operations.php';$n=0;
function ck(bool $ok,string $message):void{global $n;if(!$ok)throw new RuntimeException($message);$n++;echo "PASS $message\n";}
ck(sf_auth_attempt_hash(' ADMIN@EXAMPLE.TEST ')===sf_auth_attempt_hash('admin@example.test'),'Case/whitespace cannot create a new account bucket');
$_SERVER['REMOTE_ADDR']='2001:db8:0:0:0:0:0:1';$h=sf_auth_ip_hash();$_SERVER['REMOTE_ADDR']='2001:db8::1';ck($h===sf_auth_ip_hash(),'IPv6 text variants canonicalized');
$_SERVER['REMOTE_ADDR']='::ffff:192.0.2.1';$h=sf_auth_ip_hash();$_SERVER['REMOTE_ADDR']='192.0.2.1';ck($h===sf_auth_ip_hash(),'IPv4 mapped identity canonicalized');
$_SERVER['HTTP_X_FORWARDED_FOR']='8.8.8.8';ck($h===sf_auth_ip_hash(),'Proxy header is not accepted as identity');
foreach(['api/auth.php'=>'public','admin/login.php'=>'admin','upgrade.php'=>'upgrade'] as $file=>$surface){$s=file_get_contents($root.'/'.$file);ck(str_contains($s,"sf_auth_password_check(")&&str_contains($s,"'$surface'"),"$surface calls shared verifier");ck(str_contains($s,'sf_login_error_headers($result)'),"$surface returns unified status/retry headers");ck(!str_contains($s,'usleep(250000)'),"$surface has no blocking sleep workaround");}
$pub=file_get_contents($root.'/api/auth.php');$adm=file_get_contents($root.'/admin/login.php');$up=file_get_contents($root.'/upgrade.php');
ck(strpos($pub,'sf_require_csrf();')<strpos($pub,'sf_auth_password_check('),'Public CSRF precedes password admission');
ck(strpos($adm,'hash_equals($loginCsrf,$sent)')<strpos($adm,'sf_auth_password_check('),'Admin CSRF precedes password admission');
ck(str_contains($up,'sf_upgrade_csrf_valid')&&str_contains($up,'sf_logout_user();'),'Upgrade CSRF and real logout retained');
ck(str_contains($up,'$id=sf_auth_restore_user_id();'),'Upgrade session validates revocation');
$core=file_get_contents($root.'/api/login-security.php');ck(!str_contains($core,'sf_ops_ensure_schema('),'Limiter is independent of operational migrations');
ck(str_contains($core,"header('Retry-After: '"),'Retry header explicitly emitted');
ck(str_contains($core,"'status'=>503"),'Storage failure has an unavailable response instead of login bypass');
ck(strpos($core,'$ticket=sf_login_claim($email)')<strpos($core,'password_verify('),'Atomic admission precedes password hashing');
ck(sf_login_unavailable()['message']==='Sign-in is temporarily unavailable. Please try again shortly.','Failure message exposes no storage exception');
echo "91B21B1 contract: $n assertions passed\n";
