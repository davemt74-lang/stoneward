<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/artist-world-vinyl.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');
if($method==='GET')sf_json_response(['ok'=>true,'enabled_gallery_ids'=>array_map('intval',array_keys(sf_world_vinyl_enabled_ids())),'csrf'=>sf_admin_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$id=(int)($b['gallery_id']??0);$enabled=($b['enabled']??null)===true;
if(($b['enabled']??null)!==true&&($b['enabled']??null)!==false)sf_json_response(['ok'=>false,'message'=>'Explicit approval choice required.'],422);
if(empty($b['confirmed']))sf_json_response(['ok'=>false,'message'=>'Physical artwork reuse requires Admin confirmation.'],422);
try{
 sf_world_vinyl_set_enabled($id,$enabled,(int)$me['id']);
 sf_log_admin_action((int)$me['id'],$enabled?'vinyl_photo_collection_enabled':'vinyl_photo_collection_disabled','photo_gallery',(string)$id,['enabled'=>$enabled]);
 sf_json_response(['ok'=>true,'enabled'=>$enabled,'gallery_id'=>$id]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
