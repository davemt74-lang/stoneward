<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-director-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$admin=sf_admin_require_auth($method!=='GET');
$id=(int)$admin['id'];
if(!sf_team_can($id,'team.assign_roles'))sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
 if($method==='GET'){
  sf_json_response(['ok'=>true,'coverage'=>sf_director_coverage($id)]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $data=sf_request_json();$action=(string)($data['action']??'');
 if($action==='create_draft'){
  $requestId=sf_director_create_draft($id,(string)($data['recommendation_code']??''));
  sf_json_response(['ok'=>true,'request_id'=>$requestId,'review_status'=>'draft',
   'message'=>'Private AI Director shot request created; editor approval required to open.']);
 }
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_recommendation','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'director_access_denied','message'=>$e->getMessage()],403);
}catch(Throwable $e){
 error_log('[Stonefellow Director] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'director_unavailable'],503);
}
