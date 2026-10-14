<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-link-core.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$user=sf_require_user(false,$write);
header('Cache-Control: no-store, private');
$uid=(int)$user['id'];
try{
    if(!$write)sf_json_response(['ok'=>true,'links'=>sf_homeserver_link_owner($uid),
        'scopes'=>['fan.library.read',...(($user['role']??'')==='admin'?['admin.presence.read']:[])],
        'csrf'=>sf_user_csrf()]);
    $input=sf_request_json();$action=(string)($input['action']??'');
    if($action==='issue'){
        $grant=sf_homeserver_link_issue($uid,(string)($input['scope']??''),
            (string)($user['role']??''),(string)($input['label']??''));
        sf_json_response(['ok'=>true,'credential_once'=>$grant,
            'links'=>sf_homeserver_link_owner($uid)]);
    }
    if($action==='revoke'){
        $revoked=sf_homeserver_link_revoke($uid,(int)($input['id']??0));
        sf_json_response(['ok'=>true,'revoked'=>$revoked,
            'links'=>sf_homeserver_link_owner($uid)]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(Throwable $e){
    error_log('HomeServer link control rejected: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'homeserver_link_denied'],422);
}
