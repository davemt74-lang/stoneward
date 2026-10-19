<?php
declare(strict_types=1);
$base=dirname(__DIR__);$read=static fn(string $path):string=>(string)file_get_contents($base.'/'.$path);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $path)
 if(!str_contains($read($path),'1.3.135'))throw new RuntimeException('G4M stale release version: '.$path);
if(!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-059'")
 ||!str_contains($read('api/migrations.php'),'sf_production_deadline_schema();')
 ||!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('version.php'),"'artist_world_chat_calendar'=>'private-agent-chat-task-cards-reviewed-deadline-extensions'"))
 throw new RuntimeException('G4M schema 1.3.68 or protected capability missing.');
$core=$read('api/story-production-deadline-core.php');
$agent=$read('api/agent-admin-brain.php');
$chat=$read('admin/assets/agent-canvas.js');
$crew=$read('api/story-production-tasks.php');
$admin=$read('admin/api/story-production-tasks.php');
$brain=$read('api/story-agent-brain-bridge.php');
if(!str_contains($core,'function sf_production_deadline_request(')
 ||!str_contains($core,'function sf_production_deadline_review(')
 ||!str_contains($core,'function sf_production_calendar_chat(')
 ||!str_contains($core,"sf_production_task_editor(")
 ||!str_contains($core,'sf_team_audit(')
 ||!str_contains($core,'sf_production_task_event(')
 ||!str_contains($core,'expected_due_at')
 ||!str_contains($core,"'pending_review'")
 ||!str_contains($agent,"'production_schedule_calendar'")
 ||!str_contains($agent,'sf_production_calendar_chat($adminId)')
 ||!str_contains($chat,'data-agent-deadline-review')
 ||!str_contains($chat,'data-agent-task-schedule')
 ||!str_contains($chat,"send('production calendar')")
 ||!str_contains($crew,'sf_require_user(false,$method!==\'GET\')')
 ||!str_contains($crew,"'request_deadline'")
 ||!str_contains($admin,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($admin,"'review_deadline'")
 ||!str_contains($brain,'production_deadline_extensions_pending'))
 throw new RuntimeException('Chat-first human approval, authority and Agent Brain integration incomplete.');
foreach(['sf_capture_publish_request(','sf_render_enqueue(','sf_assembly_replace(',
 'sf_production_task_assign(','sf_production_task_review('] as $unsafe)
 if(str_contains($core,$unsafe))throw new RuntimeException('Deadline approval may not execute other production tools.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4m-chat-calendar.php')
 ||!str_contains($ci,'section88d3g4m-release.php')
 ||!str_contains($ci,'section88d3g4l-scheduling.php'))
 throw new RuntimeException('G4M or prior scheduling gate not included.');
echo "88D3G4M: exact release, CSRF, Admin Agent Chat, and human review checks passed\n";
