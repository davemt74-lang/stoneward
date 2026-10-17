<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_require_user(false,$method==='POST');$id=(int)$me['id'];
if($method==='GET')sf_json_response(['ok'=>true,'roles'=>sf_capture_roles($id),
 'role_types'=>sf_capture_role_types(),'requests'=>sf_capture_requests($id),
 'submissions'=>sf_capture_my_submissions($id),'max_daily_submissions'=>30]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
try{$submissionId=sf_capture_submit($id,$b);
 sf_json_response(['ok'=>true,'submission_id'=>$submissionId,'review_status'=>'pending_review']);}
catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],429);}
