<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-scene-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_require_user(false,$method==='POST');$id=(int)$me['id'];
try{
 if($method==='GET'){
  $sceneId=(int)($_GET['id']??0);
  if($sceneId<1)sf_json_response(['ok'=>false,'error'=>'scene_id_required'],422);
  $scene=sf_scene_read($id,$sceneId);
  if(!$scene)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
  sf_json_response(['ok'=>true,'scene'=>$scene]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $data=sf_request_json();$action=(string)($data['action']??'create');
 if($action==='create'){
  $sceneId=sf_scene_create($id,(int)($data['submission_id']??0),$data);
  sf_json_response(['ok'=>true,'scene_id'=>$sceneId,'review_status'=>'draft']);
 }
 if($action==='review'){
  sf_scene_review($id,(int)($data['scene_id']??0),(string)($data['decision']??''));
  sf_json_response(['ok'=>true]);
 }
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_scene','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'scene_access_denied','message'=>$e->getMessage()],403);
}catch(Throwable $e){
 error_log('[Stonefellow scene] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'scene_failed'],503);
}
