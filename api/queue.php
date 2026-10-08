<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];sf_queue_ensure_schema();
if(!$write)sf_json_response(['ok'=>true,'queue'=>sf_queue_payload($uid),'csrf'=>sf_user_csrf()]);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='add'){$queue=sf_queue_add($uid,(string)($b['track_id']??''),(string)($b['placement']??'end'));sf_json_response(['ok'=>true,'queue'=>$queue,'csrf'=>sf_user_csrf()]);}
    if($action==='remove'){$queue=sf_queue_remove($uid,(int)($b['item_id']??0),(string)($b['track_id']??''));sf_json_response(['ok'=>true,'queue'=>$queue,'csrf'=>sf_user_csrf()]);}
    if($action==='reorder'){$queue=sf_queue_reorder($uid,(array)($b['item_ids']??[]));sf_json_response(['ok'=>true,'queue'=>$queue,'csrf'=>sf_user_csrf()]);}
    if($action==='clear'){$queue=sf_queue_clear($uid);sf_json_response(['ok'=>true,'queue'=>$queue,'csrf'=>sf_user_csrf()]);}
    if($action==='take'){$result=sf_queue_take($uid,(int)($b['item_id']??0));sf_json_response(['ok'=>true,...$result,'csrf'=>sf_user_csrf()]);}
    if($action==='pop'){$result=sf_queue_pop_next($uid);sf_json_response(['ok'=>true,...$result,'csrf'=>sf_user_csrf()]);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported queue action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
