<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-production-tasks-core.php';
require_once __DIR__.'/story-production-task-lifecycle-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$user=sf_require_user(false,$method!=='GET');
$id=(int)$user['id'];header('Cache-Control: no-store, private');
try{
 if($method==='GET')sf_json_response(['ok'=>true,'tasks'=>sf_production_task_list($id),
  'task_updates'=>sf_production_task_notifications($id)]);
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $p=sf_request_json();
 if(($p['action']??'')==='progress'){
  sf_production_task_progress($id,(int)($p['task_id']??0),(string)($p['note']??''));
  sf_json_response(['ok'=>true,'status'=>'in_progress','publication_allowed'=>false]);
 }
 if(($p['action']??'')!=='submit')sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
 sf_production_task_submit($id,(int)($p['task_id']??0),(int)($p['evidence_id']??0),
  (string)($p['note']??''));
 sf_json_response(['ok'=>true,'status'=>'submitted_for_human_review',
   'publication_allowed'=>false]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_submission','message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'not_authorized_or_stale','message'=>'Task, role or submitted evidence is unavailable.'],409);}
catch(Throwable $e){error_log('[Stonefellow Contributor Tasks] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'tasks_unavailable'],503);}
