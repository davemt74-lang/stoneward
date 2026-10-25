<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.141'))throw new RuntimeException('G4L stale release '.$p);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-058'")
 ||!str_contains($read('api/migrations.php'),'sf_production_schedule_schema();')
 ||!str_contains($read('version.php'),"'artist_world_production_scheduling'=>'human-deadlines-private-crew-availability-readonly-agent-conflict-alerts'"))
 throw new RuntimeException('G4L migration or capability release contract absent.');
$core=$read('api/story-production-scheduling-core.php');$admin=$read('admin/api/story-production-tasks.php');
$crew=$read('api/story-production-tasks.php');$agent=$read('api/story-agent-brain-bridge.php');
$studio=$read('admin/assets/director-capture.js');$capture=$read('assets/js/app.js');
foreach(['sf_production_schedule_set(','sf_production_availability_set(',
 'sf_production_schedule_board(','sf_production_schedule_recommendations('] as $fn)
 if(!str_contains($core,$fn))throw new RuntimeException('G4L schedule core incomplete: '.$fn);
if(!str_contains($core,'sf_production_task_editor($editor)')
 ||!str_contains($core,'sf_production_task_list($viewer)')
 ||!str_contains($core,'sf_team_audit(')
 ||!str_contains($core,'sf_production_task_event(')
 ||!str_contains($core,'automatic_notifications_sent')
 ||!str_contains($admin,"'schedule'")
 ||!str_contains($crew,"'availability'")
 ||!str_contains($studio,'data-task-schedule')
 ||!str_contains($capture,'crewAvailabilityForm')
 ||!str_contains($agent,'sf_production_schedule_recommendations('))
 throw new RuntimeException('G4L human scheduling controls, rights or Agent Brain guidance missing.');
foreach(['sf_assembly_replace(','sf_render_enqueue(','sf_capture_publish_request(',
 'sf_production_task_assign(','sf_production_task_review('] as $forbidden)
 if(str_contains($core,$forbidden))throw new RuntimeException('G4L may not execute editorial/reassignment/publishing actions.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4l-scheduling.php')
 ||!str_contains($ci,'section88d3g4l-release.php')
 ||!str_contains($ci,'section88d3g4k-lifecycle.php'))
 throw new RuntimeException('G4L and prior lifecycle gate absent.');
echo "88D3G4L: version, schema, ACL, human schedule and CI release contracts passed".PHP_EOL;
