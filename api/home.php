<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
$u=sf_require_user(false,false);$uid=(int)$u['id'];
sf_json_response(['ok'=>true,'home'=>sf_home_state($uid),'csrf'=>sf_user_csrf()]);
