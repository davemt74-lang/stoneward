<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(!sf_installed()) sf_json_response(['ok'=>false,'error'=>'configuration_required','message'=>'Stonefellow database configuration is unavailable.'],503);
sf_customer_lifecycle_ensure_schema();
sf_entitlements_ensure_schema();
sf_billing_ensure_schema();
sf_user_session();
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $u=sf_current_user();
    sf_json_response(['ok'=>true,'authenticated'=>(bool)$u,'user'=>sf_user_public($u),'csrf'=>sf_user_csrf()]);
}
if($method!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$body=sf_request_json();
sf_require_csrf();
$action=(string)($body['action']??'status');
if($action==='register'){
    $name=sf_clean_text($body['display_name']??'',100);
    $email=sf_email((string)($body['email']??''));
    $pw=(string)($body['password']??'');
    $confirm=(string)($body['confirm']??'');
    if($name==='') sf_json_response(['ok'=>false,'message'=>'Display name is required.'],422);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) sf_json_response(['ok'=>false,'message'=>'Enter a valid email address.'],422);
    if(!sf_valid_password($pw)) sf_json_response(['ok'=>false,'message'=>'Use a password of at least 10 characters.'],422);
    if($pw!==$confirm) sf_json_response(['ok'=>false,'message'=>'Passwords do not match.'],422);
    if(sf_user_by_email($email)) sf_json_response(['ok'=>false,'message'=>'An account already exists for that email.'],409);
    $planId=(int)($body['plan_id']??0);
    $now=gmdate('c');
    $q=sf_db()->prepare("INSERT INTO users(email,display_name,password_hash,role,status,created_at,updated_at,last_login_at) VALUES(?,?,?,?,?,?,?,NULL)");
    $q->execute([$email,$name,password_hash($pw,PASSWORD_DEFAULT),'user','active',$now,$now]);
    $u=sf_user_by_email($email);
    try{$entitlement=sf_enroll_user((int)$u['id'],$planId?:null);}catch(Throwable $e){$entitlement=sf_enroll_user((int)$u['id'],null);}
    $verificationStatus='queued';try{$mail=sf_send_email_verification((int)$u['id'],$email);$verificationStatus=(string)($mail['status']??'queued');}catch(Throwable $e){$verificationStatus='failed';}
    sf_login_user($u);
    sf_log_user_activity((int)$u['id'],'account_created','Created Stonefellow account','account',(string)$u['id']);
    sf_notify_user((int)$u['id'],'welcome','Welcome to Stonefellow','Your account is ready. Explore the catalog, talk with the agent, and build a custom release.','?view=account');
    sf_json_response(['ok'=>true,'authenticated'=>true,'user'=>sf_user_public(sf_current_user()),'entitlement'=>$entitlement,'verification_status'=>$verificationStatus,'csrf'=>sf_user_csrf()],201);
}
if($action==='login'){
    $email=sf_email((string)($body['email']??''));
    $pw=(string)($body['password']??'');
    $gate=sf_auth_login_allowed($email);
    if(!$gate['ok'])sf_json_response(['ok'=>false,'error'=>'login_throttled','message'=>'Too many unsuccessful login attempts. Try again later.','retry_after_seconds'=>$gate['retry_after_seconds']],429);
    $u=sf_user_by_email($email);
    if(!$u||($u['status']??'')!=='active'||!password_verify($pw,(string)$u['password_hash'])){
        sf_auth_record_attempt($email,false);usleep(250000);
        sf_json_response(['ok'=>false,'message'=>'Invalid email or password.'],401);
    }
    sf_auth_record_attempt($email,true);
    sf_login_user($u);
    sf_log_user_activity((int)$u['id'],'login','Signed in to Stonefellow','account',(string)$u['id']);
    sf_json_response(['ok'=>true,'authenticated'=>true,'user'=>sf_user_public(sf_current_user()),'csrf'=>sf_user_csrf()]);
}
if($action==='request_password_reset'){
    $email=sf_email((string)($body['email']??''));
    if(filter_var($email,FILTER_VALIDATE_EMAIL)) sf_request_password_reset($email);
    // Always return the same result so this endpoint does not disclose account existence.
    sf_json_response(['ok'=>true,'message'=>'If that email belongs to an active Stonefellow account, a reset link has been sent.']);
}
if($action==='reset_password'){
    try{sf_reset_password_token((string)($body['token']??''),(string)($body['password']??''),(string)($body['confirm']??''));sf_json_response(['ok'=>true,'message'=>'Password updated. You can now log in.']);}
    catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='verify_email'){
    try{$result=sf_verify_email_token((string)($body['token']??''));$u=sf_current_user();sf_json_response(['ok'=>true,'verified'=>true,'user'=>$u?sf_user_public($u):null,'result'=>$result,'csrf'=>sf_user_csrf()]);}
    catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='resend_verification'){
    $u=sf_require_user(false,true);$full=sf_user_by_email((string)$u['email']);if(!$full)sf_json_response(['ok'=>false,'message'=>'Account not found.'],404);
    if(!empty($full['email_verified_at']))sf_json_response(['ok'=>true,'message'=>'Your email is already verified.','user'=>sf_user_public(sf_current_user()),'csrf'=>sf_user_csrf()]);
    try{$mail=sf_send_email_verification((int)$u['id'],(string)$u['email']);sf_json_response(['ok'=>true,'message'=>'Verification email queued.','delivery_status'=>$mail['status']??'queued','csrf'=>sf_user_csrf()]);}
    catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],429);}
}
if($action==='change_email'){
    $u=sf_require_user(false,true);try{$updated=sf_change_customer_email($u,(string)($body['email']??''),(string)($body['current_password']??''));sf_json_response(['ok'=>true,'message'=>'Email updated. Verify the new address.','user'=>sf_user_public($updated),'csrf'=>sf_user_csrf()]);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='revoke_session'){
    $u=sf_require_user(false,true);$sessionId=(string)($body['session_id']??'');$ok=sf_auth_revoke_session((int)$u['id'],$sessionId);
    if(!$ok)sf_json_response(['ok'=>false,'message'=>'Session not found.'],404);
    // If the current persistent session was revoked, fully log out the browser.
    if(sf_auth_cookie_value()===''){sf_logout_user();sf_json_response(['ok'=>true,'authenticated'=>false,'user'=>null,'csrf'=>null]);}
    sf_json_response(['ok'=>true,'sessions'=>sf_auth_sessions((int)$u['id']),'csrf'=>sf_user_csrf()]);
}
if($action==='revoke_other_sessions'){
    $u=sf_require_user(false,true);$count=sf_auth_revoke_other_sessions((int)$u['id']);
    sf_json_response(['ok'=>true,'revoked'=>$count,'sessions'=>sf_auth_sessions((int)$u['id']),'csrf'=>sf_user_csrf()]);
}
if($action==='logout'){
    sf_require_user(false,true);
    sf_logout_user();
    sf_json_response(['ok'=>true,'authenticated'=>false,'user'=>null,'csrf'=>null]);
}
if($action==='profile'){
    $u=sf_require_user(false,true);
    $name=sf_clean_text($body['display_name']??'',100);
    if($name==='') sf_json_response(['ok'=>false,'message'=>'Display name is required.'],422);
    $q=sf_db()->prepare('UPDATE users SET display_name=?,updated_at=? WHERE id=?');
    $q->execute([$name,gmdate('c'),(int)$u['id']]);
    sf_log_user_activity((int)$u['id'],'profile_updated','Updated profile','account',(string)$u['id']);
    sf_json_response(['ok'=>true,'user'=>sf_user_public(sf_current_user()),'csrf'=>sf_user_csrf()]);
}
if($action==='password'){
    $u=sf_require_user(false,true);
    $current=(string)($body['current_password']??'');
    $next=(string)($body['new_password']??'');
    $confirm=(string)($body['confirm']??'');
    $full=sf_user_by_email((string)$u['email']);
    if(!$full||!password_verify($current,(string)$full['password_hash'])) sf_json_response(['ok'=>false,'message'=>'Current password is incorrect.'],401);
    if(!sf_valid_password($next)) sf_json_response(['ok'=>false,'message'=>'Use a new password of at least 10 characters.'],422);
    if($next!==$confirm) sf_json_response(['ok'=>false,'message'=>'New passwords do not match.'],422);
    $q=sf_db()->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?');
    $q->execute([password_hash($next,PASSWORD_DEFAULT),gmdate('c'),(int)$u['id']]);
    sf_auth_revoke_all_user_sessions((int)$u['id']);
    session_regenerate_id(true);
    $_SESSION['sf_user_id']=(int)$u['id'];
    $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
    sf_auth_issue_persistent_session((int)$u['id']);
    sf_log_user_activity((int)$u['id'],'password_changed','Changed account password','account',(string)$u['id']);
    sf_notify_user((int)$u['id'],'security','Password changed','Your Stonefellow password was changed and other sessions were signed out.','?view=account');
    sf_json_response(['ok'=>true,'csrf'=>sf_user_csrf()]);
}
sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
