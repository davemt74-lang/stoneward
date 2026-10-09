<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(empty(sf_site_settings()['fan_community_enabled']))sf_json_response(['ok'=>false,'error'=>'community_disabled','message'=>'The Stonefellow fan community is not open yet.'],404);
if($method==='GET')sf_json_response(['ok'=>true,'posts'=>sf_community_posts(80),'authenticated'=>(bool)sf_current_user()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$u=sf_require_user(false,true);$b=sf_request_json();$action=(string)($b['action']??'create');
try{
    if($action==='create')sf_json_response(['ok'=>true,'post'=>sf_community_create((int)$u['id'],(string)($b['body']??''))],201);
    if($action==='delete')sf_json_response(['ok'=>sf_community_delete((int)$u['id'],(int)($b['id']??0))]);
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
