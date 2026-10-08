<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$u=sf_require_user(false,$write); $uid=(int)$u['id'];
if(!$write) sf_json_response(['ok'=>true,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
$body=sf_request_json(); $action=(string)($body['action']??'save');
if($action==='save'){
    try{$row=sf_account_save_build($uid,is_array($body['build']??null)?$body['build']:[],(int)($body['id']??0));sf_json_response(['ok'=>true,'build'=>$row,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);}
    catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
}
if($action==='delete'){
    $id=(int)($body['id']??0); if($id<1) sf_json_response(['ok'=>false,'message'=>'Invalid saved build.'],422);
    sf_account_delete_build($uid,$id); sf_json_response(['ok'=>true,'builds'=>sf_account_saved_builds($uid),'csrf'=>sf_user_csrf()]);
}
sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
