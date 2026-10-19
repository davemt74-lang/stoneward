<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-director-core.php';
require_once SF_ROOT.'/api/story-transcript-core.php';
require_once SF_ROOT.'/api/story-scene-core.php';
require_once SF_ROOT.'/api/story-assembly-core.php';
require_once SF_ROOT.'/api/story-composer-core.php';
require_once SF_ROOT.'/api/story-production-tasks-core.php';
require_once SF_ROOT.'/api/story-production-task-lifecycle-core.php';
require_once SF_ROOT.'/api/story-production-scheduling-core.php';
require_once SF_ROOT.'/api/story-production-deadline-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$user=sf_admin_require_auth($method!=='GET');$editor=(int)$user['id'];
if(!sf_team_can($editor,'team.assign_roles'))
 sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
 if($method==='GET'){sf_composer_schema();sf_json_response(['ok'=>true,'tasks'=>sf_production_task_list($editor),
   'coverage'=>sf_director_coverage($editor),'roster'=>sf_team_roster($editor),
   'task_updates'=>sf_production_task_notifications($editor),
   'schedule_board'=>sf_production_schedule_board($editor),
   'calendar'=>sf_production_calendar_chat($editor),
   'extension_requests'=>sf_production_deadline_requests($editor),
   'composer_queue'=>sf_db()->query("SELECT id,assembly_id,revision,status
     FROM story_composer_proposals WHERE status='pending_review' ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC)]);}
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $p=sf_request_json();$action=(string)($p['action']??'');
 if($action==='propose_gap')sf_json_response(['ok'=>true,'task'=>sf_production_task_propose_gap($editor,(string)($p['code']??''))]);
 if($action==='propose_editorial')sf_json_response(['ok'=>true,'task'=>sf_production_task_propose_editorial($editor,(int)($p['proposal_id']??0))]);
 if($action==='review_deadline'){
  sf_json_response(['ok'=>true,'review'=>sf_production_deadline_review($editor,
    (int)($p['request_id']??0),(string)($p['decision']??''),(string)($p['basis']??''))]);
 }
 if($action==='schedule'){
   sf_production_schedule_set($editor,(int)($p['task_id']??0),(string)($p['due_at']??''),
     (string)($p['priority']??''),(string)($p['reason']??''));
   sf_json_response(['ok'=>true,'auto_reassigned'=>false]);
 }
 if($action==='assign'){sf_production_task_assign($editor,(int)($p['task_id']??0),(int)($p['assignee_id']??0));sf_json_response(['ok'=>true]);}
 if($action==='reopen'){sf_production_task_reopen($editor,(int)($p['task_id']??0),
  (int)($p['assignee_id']??0),(string)($p['basis']??''));sf_json_response(['ok'=>true]);}
 if($action==='review'){sf_production_task_review($editor,(int)($p['task_id']??0),
  (string)($p['decision']??''),(string)($p['basis']??''));sf_json_response(['ok'=>true]);}
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_request','message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'task_conflict','message'=>$e->getMessage()],409);}
catch(Throwable $e){error_log('[Stonefellow G4J] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'production_tasks_unavailable'],503);}
