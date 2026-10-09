<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];sf_library_ensure_schema();
if(!$write)sf_json_response(['ok'=>true,'library'=>sf_library_snapshot($uid),'csrf'=>sf_user_csrf()]);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='create_collection')sf_json_response(['ok'=>true,'collection'=>sf_library_collection_create($uid,(string)($b['name']??''),(string)($b['description']??'')),'library'=>sf_library_snapshot($uid)]);
    if($action==='update_collection')sf_json_response(['ok'=>true,'collection'=>sf_library_collection_update($uid,(int)($b['collection_id']??0),(string)($b['name']??''),(string)($b['description']??'')),'library'=>sf_library_snapshot($uid)]);
    if($action==='delete_collection'){sf_library_collection_delete($uid,(int)($b['collection_id']??0));sf_json_response(['ok'=>true,'library'=>sf_library_snapshot($uid)]);}
    if($action==='add_to_collection')sf_json_response(['ok'=>true,'collection'=>sf_library_collection_add($uid,(int)($b['collection_id']??0),(string)($b['item_type']??''),(string)($b['item_key']??'')),'library'=>sf_library_snapshot($uid)]);
    if($action==='remove_from_collection')sf_json_response(['ok'=>true,'collection'=>sf_library_collection_remove($uid,(int)($b['collection_id']??0),(int)($b['collection_item_id']??0)),'library'=>sf_library_snapshot($uid)]);
    if($action==='move_collection_item')sf_json_response(['ok'=>true,'collection'=>sf_library_collection_move($uid,(int)($b['collection_id']??0),(int)($b['collection_item_id']??0),(int)($b['direction']??1)),'library'=>sf_library_snapshot($uid)]);
}catch(InvalidArgumentException|RuntimeException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
sf_json_response(['ok'=>false,'message'=>'Unsupported library action.'],422);
