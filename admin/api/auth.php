<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_admin_session();
if(!sf_installed()) sf_json_response(['ok'=>false,'error'=>'configuration_required','message'=>'Stonefellow database configuration is unavailable.'],503);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $u=sf_current_user();
    $admin=$u&&($u['role']??'')==='admin';
    sf_json_response(['ok'=>true,'configured'=>true,'authenticated'=>$admin,'user'=>sf_user_public($admin?$u:null),'csrf'=>$admin?sf_admin_csrf():null]);
}
if($method!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$body=sf_request_json();
sf_require_csrf();
$action=(string)($body['action']??'login');
if($action==='login'){
    $email=sf_email((string)($body['email']??''));
    $pw=(string)($body['password']??'');
    $u=sf_user_by_email($email);
    if(!$u||($u['role']??'')!=='admin'||($u['status']??'')!=='active'||!password_verify($pw,(string)$u['password_hash'])){
        usleep(250000);
        sf_json_response(['ok'=>false,'message'=>'Invalid administrator email or password.'],401);
    }
    sf_login_user($u);
    sf_json_response(['ok'=>true,'configured'=>true,'authenticated'=>true,'user'=>sf_user_public(sf_current_user()),'csrf'=>sf_admin_csrf()]);
}
if($action==='change_password'){
    $u=sf_admin_require_auth(true);
    $full=sf_user_by_email((string)$u['email']);
    $current=(string)($body['current_password']??'');
    $new=(string)($body['new_password']??'');
    $confirm=(string)($body['confirm']??'');
    if(!$full||!password_verify($current,(string)$full['password_hash'])) sf_json_response(['ok'=>false,'message'=>'Current password is incorrect.'],401);
    if(!sf_valid_password($new)) sf_json_response(['ok'=>false,'message'=>'Use a new password of at least 10 characters.'],422);
    if($new!==$confirm) sf_json_response(['ok'=>false,'message'=>'New passwords do not match.'],422);
    $q=sf_db()->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?');
    $q->execute([password_hash($new,PASSWORD_DEFAULT),gmdate('c'),(int)$u['id']]);
    sf_auth_revoke_all_user_sessions((int)$u['id']);
    session_regenerate_id(true);
    $_SESSION['sf_user_id']=(int)$u['id'];
    $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
    sf_auth_issue_persistent_session((int)$u['id']);
    sf_json_response(['ok'=>true,'csrf'=>sf_admin_csrf()]);
}
if($action==='logout'){
    sf_admin_require_auth(true);
    sf_logout_user();
    sf_json_response(['ok'=>true]);
}
sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
