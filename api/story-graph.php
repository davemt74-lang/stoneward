<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-scene-core.php';
require_once __DIR__.'/story-transcript-core.php';
require_once __DIR__.'/story-graph-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_require_user(false,$method==='POST');$id=(int)$me['id'];
if(!sf_team_can($id,'team.assign_roles'))sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
 if($method==='GET'){
  $item=sf_story_graph_read_entity($id,(int)($_GET['entity_id']??0));
  if(!$item)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
  sf_json_response(['ok'=>true,'entity'=>$item]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $data=sf_request_json();$action=(string)($data['action']??'create');
 if($action==='create'){
  $entityId=sf_story_graph_create_entity($id,$data);
  sf_json_response(['ok'=>true,'entity_id'=>$entityId,'review_status'=>'draft']);
 }
 if($action==='review'){
  sf_story_graph_review_entity($id,(int)($data['entity_id']??0),(string)($data['decision']??''));
  sf_json_response(['ok'=>true]);
 }
 if($action==='link'){
  $linkId=sf_story_graph_add_link($id,(int)($data['entity_id']??0),
   (int)($data['submission_id']??0),(string)($data['source_type']??''),
   (int)($data['source_id']??0),(string)($data['relation_type']??''));
  sf_json_response(['ok'=>true,'link_id'=>$linkId]);
 }
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_story_graph','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'story_graph_denied','message'=>$e->getMessage()],403);
}catch(Throwable $e){
 error_log('[Stonefellow story graph] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'story_graph_failed'],503);
}
