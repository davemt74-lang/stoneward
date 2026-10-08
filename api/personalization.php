<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];sf_personalization_ensure_schema();
if(!$write)sf_json_response(['ok'=>true,'personalization'=>sf_personalization_state($uid),'csrf'=>sf_user_csrf()]);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='set_favorite'){$favorite=sf_personalization_set_favorite($uid,(string)($b['item_type']??''),(string)($b['item_id']??''),!empty($b['favorite']));sf_log_user_activity($uid,$favorite?'favorite_added':'favorite_removed',($favorite?'Favorited ':'Removed favorite ').(string)($b['item_id']??''),(string)($b['item_type']??''),(string)($b['item_id']??''));sf_json_response(['ok'=>true,'favorite'=>$favorite,'personalization'=>sf_personalization_state($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='clear_history'){$result=sf_personalization_clear_history($uid);sf_json_response(['ok'=>true,'result'=>$result,'personalization'=>sf_personalization_state($uid),'csrf'=>sf_user_csrf()]);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported personalization action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
