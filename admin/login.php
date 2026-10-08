<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
require_once __DIR__.'/api/bootstrap.php';
if(!sf_installed()){http_response_code(503);exit('Stonefellow database configuration is unavailable.');}
if(sf_admin_authenticated()){header('Location: index.php',true,303);exit;}
$error='';
sf_admin_session();
$loginCsrf=sf_admin_csrf();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $sent=(string)($_POST['csrf']??'');
    if($sent===''||!hash_equals($loginCsrf,$sent)){
        $error='Your login session expired. Reload this page and try again.';
    }else{
    $email=sf_email((string)($_POST['email']??''));
    $password=(string)($_POST['password']??'');
    $u=sf_user_by_email($email);
    if(!$u||($u['role']??'')!=='admin'||($u['status']??'')!=='active'||!password_verify($password,(string)($u['password_hash']??''))){
        usleep(250000);$error='Invalid administrator email or password.';
    }else{
        sf_login_user($u);header('Location: index.php',true,303);exit;
    }
    }
}
function h(string $v): string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>Stonefellow Admin Login</title><link rel="stylesheet" href="assets/admin.css?v=1.0.4"></head>
<body><main class="auth-screen"><div class="auth-card"><div class="auth-wordmark">STONEFELLOW</div><div class="eyebrow">ADMIN LOGIN</div><h1>Administrator login.</h1><p>Use the Administrator account created by the Stonefellow installer.</p><?php if($error!==''):?><p class="form-error" role="alert"><?=h($error)?></p><?php endif?><form method="post" action="login.php"><input type="hidden" name="csrf" value="<?=h($loginCsrf)?>"><label>Email<input name="email" type="email" autocomplete="email" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="primary" type="submit">Sign in</button></form><p><a href="../">← Public site</a></p></div></main></body></html>
