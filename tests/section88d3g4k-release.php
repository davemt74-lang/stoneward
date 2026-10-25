<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.139'))throw new RuntimeException('G4K stale release '.$p);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-057'")
 ||!str_contains($read('api/migrations.php'),'sf_production_task_events_schema();')
 ||!str_contains($read('version.php'),"'artist_world_task_lifecycle'=>'source-linked-progress-recovery-history-private-activity'"))
 throw new RuntimeException('G4K migration/version capability contract absent.');
$core=$read('api/story-production-tasks-core.php');
$life=$read('api/story-production-task-lifecycle-core.php');
$admin=$read('admin/api/story-production-tasks.php');
$public=$read('api/story-production-tasks.php');
$studio=$read('admin/assets/director-capture.js');
$capture=$read('assets/js/app.js');
$brain=$read('api/story-agent-brain-bridge.php');
if(!str_contains($life,'function sf_production_task_progress(')
 ||!str_contains($life,'function sf_production_task_reopen(')
 ||!str_contains($life,'function sf_production_task_timeline(')
 ||!str_contains($life,'function sf_production_task_notifications(')
 ||!str_contains($life,'sf_production_task_event(')
 ||!str_contains($life,'sf_team_audit(')
 ||!str_contains($core,'sf_production_task_events_schema();')
 ||!str_contains($core,'sf_production_task_event(')
 ||!str_contains($admin,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($public,'sf_require_user(false,$method!==\'GET\')')
 ||!str_contains($admin,"'reopen'")
 ||!str_contains($public,"'progress'")
 ||!str_contains($studio,'data-task-reopen')
 ||!str_contains($capture,'data-assigned-progress')
 ||!str_contains($brain,'production_task_recovery_needed'))
 throw new RuntimeException('G4K activity, role checks, recovery/notifications or Agent Brain wiring incomplete.');
foreach(['sf_assembly_replace(','sf_render_enqueue(','sf_composer_apply(','sf_capture_publish_request('] as $forbidden)
 if(str_contains($life,$forbidden))throw new RuntimeException('Unsafe publication/media execution in event module.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4k-lifecycle.php')
 ||!str_contains($ci,'section88d3g4k-release.php')
 ||!str_contains($ci,'section88d3g4j-production-tasks.php'))
 throw new RuntimeException('G4K and prior G4J gates not certified.');
echo "88D3G4K: task lifecycle, privacy and version/CI contracts passed".PHP_EOL;
