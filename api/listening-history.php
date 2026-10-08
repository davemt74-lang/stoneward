<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];
if(!$write){$limit=(int)($_GET['limit']??80);$before=max(0,(int)($_GET['before_id']??0));sf_json_response(['ok'=>true,'history'=>sf_personalization_history_page($uid,$limit,$before),'csrf'=>sf_user_csrf()]);}
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='clear_all'){$r=sf_personalization_clear_history($uid);sf_json_response(['ok'=>true,'result'=>$r,'history'=>sf_personalization_history_page($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='clear_track'){$r=sf_personalization_clear_track_history($uid,(string)($b['track_id']??''));sf_json_response(['ok'=>true,'result'=>$r,'history'=>sf_personalization_history_page($uid),'csrf'=>sf_user_csrf()]);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported listening-history action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
