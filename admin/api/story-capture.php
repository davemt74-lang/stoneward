<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');$id=(int)$me['id'];
if($method==='GET')sf_json_response(['ok'=>true,'requests'=>sf_capture_requests($id),
 'review_queue'=>sf_capture_review_queue($id),'role_types'=>sf_capture_role_types()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
try{
 $action=(string)($b['action']??'');
 if($action==='create_request'){
   $requestId=sf_capture_create_request($id,$b);
   sf_json_response(['ok'=>true,'request_id'=>$requestId]);
 }
 if($action==='publish_request'){
   sf_capture_publish_request($id,(int)($b['request_id']??0),($b['open']??null)===true);
   sf_json_response(['ok'=>true]);
 }
 if($action==='review_submission'){
   sf_capture_review($id,(int)($b['submission_id']??0),(string)($b['decision']??''));
   sf_json_response(['ok'=>true]);
 }
 sf_json_response(['ok'=>false,'error'=>'invalid_action'],422);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
