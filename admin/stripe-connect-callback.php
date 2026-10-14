<?php
declare(strict_types=1);
require __DIR__.'/api/bootstrap.php';
require_once SF_ROOT.'/api/stripe-connect.php';
$success=false;$notice='Stripe connection was not completed.';
try{
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new RuntimeException('Only callback GET is permitted.');
 $admin=sf_admin_require_auth(false);
 sf_user_session();
 $state=(string)($_GET['state']??'');
 if(isset($_GET['error'])){
    sf_connect_consume_state($state,(int)$admin['id']);
    throw new RuntimeException('Stripe authorization was declined or canceled.');
 }
 sf_connect_finish((int)$admin['id'],$state,(string)($_GET['code']??''));
 $success=true;$notice='Stripe authorization recorded. Return to Payments to review webhook and checkout readiness.';
}catch(Throwable $e){
 error_log('Stonefellow Stripe OAuth callback '.get_class($e).': '.substr(hash('sha256',$e->getMessage()),0,12));
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Content-Security-Policy: default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Stripe connection · Stonefellow</title>
<style>body{margin:0;background:#12151b;color:#f4f6fb;font:16px system-ui,sans-serif;display:grid;place-items:center;min-height:100vh}.panel{padding:32px;max-width:480px;border:1px solid #3b4253;border-radius:16px;background:#1c2230}p{color:#bdc6d8;line-height:1.6}a{color:#a7cbff}</style></head><body><main class="panel"><h1><?= $success?'Stripe connection received':'Stripe connection incomplete' ?></h1>
<p><?=htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p><a href="index.php?stripe=return">Return to Stonefellow Payments</a></main>
<script>if(window.opener&&window.opener!==window){window.opener.postMessage({type:"stonefellow-stripe-oauth-result",ok:<?= $success?'true':'false' ?>},window.location.origin);window.close();}</script></body></html>