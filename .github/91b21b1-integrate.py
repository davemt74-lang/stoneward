from pathlib import Path
root=Path('.')
def patch(file, old, new):
 p=root/file;s=p.read_text();assert s.count(old)==1,(file,old[:90],s.count(old));p.write_text(s.replace(old,new))
def replace_between(file,start,end,new):
 p=root/file;s=p.read_text();a=s.index(start);b=s.index(end,a);p.write_text(s[:a]+new+s[b:])
patch('api/operations.php',"declare(strict_types=1);", "declare(strict_types=1);\nrequire_once __DIR__.'/login-security.php';")
patch('api/operations.php',"function sf_auth_ip_hash(): string {return hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));}","function sf_auth_ip_hash(): string {return hash('sha256',sf_login_source_ip());}")
replace_between('api/operations.php','function sf_auth_login_allowed(', 'function sf_order_request_claim(', '''/** Compatibility read only; password endpoints use atomic sf_auth_password_check admission. */
function sf_auth_login_allowed(string $email): array {
    sf_login_security_schema();$db=sf_db();$now=time();
    $retry=max(sf_login_bucket_delay($db,'identifier_hash',sf_auth_attempt_hash($email),SF_LOGIN_ACCOUNT_LIMIT,$now),
        sf_login_bucket_delay($db,'ip_hash',sf_auth_ip_hash(),SF_LOGIN_IP_LIMIT,$now));
    return $retry>0?sf_login_retry_result($retry):['ok'=>true,'retry_after_seconds'=>0];
}
function sf_auth_record_attempt(string $email,bool $success): void {
    sf_login_security_schema();$q=sf_db()->prepare('INSERT INTO auth_attempts(identifier_hash,ip_hash,success,created_at) VALUES(?,?,?,?)');
    $q->execute([sf_auth_attempt_hash($email),sf_auth_ip_hash(),$success?1:0,gmdate('c')]);
}
''')
replace_between('api/auth.php',"    $gate=sf_auth_login_allowed($email);",'    sf_login_user($u);', '''    try{$result=sf_auth_password_check($email,$pw,'public');}
    catch(Throwable $e){error_log('Stonefellow public login failed: '.get_class($e));$result=sf_login_unavailable();}
    if(!$result['ok']){sf_login_error_headers($result);sf_json_response($result,(int)$result['status']);}
    $u=$result['user'];
''')
replace_between('admin/login.php',"    $u=sf_user_by_email($email);",'    }\n}\nfunction h', '''    try{$result=sf_auth_password_check($email,$password,'admin');}
    catch(Throwable $e){error_log('Stonefellow Admin login failed: '.get_class($e));$result=sf_login_unavailable();}
    if(!$result['ok']){sf_login_error_headers($result);$error=$result['message'];}
    else{sf_login_user($result['user']);header('Location: index.php',true,303);exit;}
''')
replace_between('upgrade.php','function sf_upgrade_admin_from_session()', 'function sf_upgrade_lock_acquire()', '''function sf_upgrade_admin_from_session(): ?array {
    sf_user_session();
    // Upgrade must also honor persistent-token revocation, without newer lifecycle columns.
    $id=sf_auth_restore_user_id();
    if($id<1){unset($_SESSION['sf_user_id']);return null;}
    try{
        $q=sf_db()->prepare('SELECT id,email,display_name,role,status FROM users WHERE id=? LIMIT 1');$q->execute([$id]);$u=$q->fetch();
        if(!$u||($u['status']??'')!=='active'||($u['role']??'')!=='admin')return null;
        if((int)($_SESSION['sf_user_id']??0)!==$id){session_regenerate_id(true);$_SESSION['sf_user_id']=$id;$_SESSION['sf_upgrade_csrf']=bin2hex(random_bytes(32));}
        return $u;
    }catch(Throwable $e){return null;}
}
function sf_upgrade_admin_login(string $email,string $password): array {
    $result=sf_auth_password_check($email,$password,'upgrade');
    if(!$result['ok'])return $result;
    $u=$result['user'];sf_user_session();session_regenerate_id(true);
    sf_auth_issue_persistent_session((int)$u['id']);
    $_SESSION['sf_user_id']=(int)$u['id'];$_SESSION['sf_csrf']=bin2hex(random_bytes(32));$_SESSION['sf_upgrade_csrf']=bin2hex(random_bytes(32));
    return $result;
}
''')
patch('upgrade.php',"        try{$admin=sf_upgrade_admin_login((string)($_POST['email']??''),(string)($_POST['password']??''));if(!$admin){usleep(250000);$message='Administrator email or password is incorrect.';$messageType='error';}}\n        catch(Throwable $e){$message='Administrator authentication could not be completed. Review protected server logs.';$messageType='error';}","        try{$result=sf_upgrade_admin_login((string)($_POST['email']??''),(string)($_POST['password']??''));}\n        catch(Throwable $e){error_log('Stonefellow upgrade login failed: '.get_class($e));$result=sf_login_unavailable();}\n        if($result['ok']){$admin=$result['user'];}\n        else{sf_login_error_headers($result);$message=$result['message'];$messageType='error';}")
patch('upgrade.php',"        sf_user_session();unset($_SESSION['sf_user_id'],$_SESSION['sf_csrf'],$_SESSION['sf_upgrade_csrf']);session_regenerate_id(true);$admin=null;$message='Upgrade session signed out.';$messageType='info';","        sf_logout_user();$admin=null;$message='Upgrade session signed out.';$messageType='info';")
for file in ['version.php','stonefellow-v120.php','admin/index.php','service-worker.js','.htaccess','assets/js/app.js','assets/css/turntable-builder.css']:
 p=root/file;s=p.read_text();assert '1.3.236' in s,file;p.write_text(s.replace('1.3.236','1.3.237'))
for file in ['tests/section91b14c-ios-lunar-mobile.cjs','tests/section91b20h-visual-style-library.test.cjs']:
 p=root/file;s=p.read_text();assert '1.3.236' in s or r'1\.3\.236' in s,file;p.write_text(s.replace('1.3.236','1.3.237').replace(r'1\.3\.236',r'1\.3\.237'))
