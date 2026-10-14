<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-link-core.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: private, no-store');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
try{
    $uid=sf_homeserver_bearer($auth,'fan.library.read');
    $items=sf_homeserver_user_library($uid);
    sf_json_response(['ok'=>true,'contract'=>'stonefellow.fan-music.v1','tracks'=>$items,
        'total'=>count($items),'account_scoped'=>true,'can_manage_purchases'=>false,
        'can_publish_kunaki'=>false,'can_manage_admin'=>false]);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'link_revoked_or_unauthorized'],401);
}
