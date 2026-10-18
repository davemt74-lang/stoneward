<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-media-evidence-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-inference-queue-core.php';
require_once SF_ROOT.'/api/ai.php';
require_once SF_ROOT.'/api/story-inference-worker-core.php';
require_once SF_ROOT.'/api/story-transcript-core.php';
require_once SF_ROOT.'/api/story-scene-core.php';
require_once SF_ROOT.'/api/story-production-review-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$admin=sf_admin_require_auth($method!=='GET');
$id=(int)$admin['id'];
if(!sf_team_can($id,'team.assign_roles'))
  sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
  if($method==='GET'){
    $resultId=(int)($_GET['job_id']??0);
    sf_json_response(['ok'=>true,'jobs'=>sf_inference_jobs($id),
      'review_items'=>sf_production_review_items($id),
      'captures'=>sf_capture_review_queue($id),
      'result'=>$resultId>0?sf_inference_result_admin($id,$resultId):null,
      'provider_execution_enabled'=>sf_inference_enabled(),'auto_retries'=>false,
      'requires_independent_external_ai_clearance'=>true]);
  }
  if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
  $input=sf_request_json();
  $action=(string)($input['action']??'');
  if($action==='configure_execution'){
    if(!is_bool($input['enabled']??null))sf_json_response(['ok'=>false,'error'=>'invalid_enabled'],422);
    sf_inference_execution_configure($id,$input['enabled']);
    sf_json_response(['ok'=>true,'provider_execution_enabled'=>sf_inference_enabled()]);
  }
  if($action==='import_reviewed'){
    $import=sf_production_import($id,(int)($input['job_id']??0),(array)($input['changes']??[]));
    sf_json_response(['ok'=>true,'import'=>$import,
      'message'=>'Private editorial draft imported; it is NOT published or independently verified.']);
  }
  if($action==='review_result'){
    $changed=sf_inference_review($id,(int)($input['job_id']??0),(string)($input['decision']??''));
    sf_json_response(['ok'=>true,'changed'=>$changed]);
  }
  if($action==='enqueue'){
    $job=sf_inference_enqueue($id,(int)($input['submission_id']??0),
      (string)($input['task_type']??''));
    sf_json_response(['ok'=>true,'job'=>$job,'provider_execution_enabled'=>false]);
  }
  if($action==='cancel'){
    sf_json_response(['ok'=>true,'changed'=>sf_inference_cancel($id,(int)($input['job_id']??0))]);
  }
  if($action==='recover'){
    sf_json_response(['ok'=>true,'interrupted_jobs'=>sf_inference_recover($id),
      'auto_retries'=>false]);
  }
  if($action==='retry'){
    sf_json_response(['ok'=>true,'queued'=>sf_inference_retry($id,(int)($input['job_id']??0))]);
  }
  sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
  sf_json_response(['ok'=>false,'error'=>'invalid_inference_request','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
  sf_json_response(['ok'=>false,'error'=>'inference_not_authorized','message'=>$e->getMessage()],403);
}catch(Throwable $e){
  error_log('[Stonefellow inference queue] '.get_class($e));
  sf_json_response(['ok'=>false,'error'=>'inference_queue_unavailable'],503);
}
