<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $file)
 if(!str_contains($read($file),'1.3.138'))throw new RuntimeException('G4J stale release identity: '.$file);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-056'")
 ||!str_contains($read('api/migrations.php'),'sf_production_tasks_schema();')
 ||!str_contains($read('version.php'),"'artist_world_crew_tasks'=>'director-gap-task-assignment-evidence-human-completion-approval'"))
 throw new RuntimeException('G4J task migration/capability 056 incomplete.');
$core=$read('api/story-production-tasks-core.php');
$adminApi=$read('admin/api/story-production-tasks.php');
$userApi=$read('api/story-production-tasks.php');
$brain=$read('api/story-agent-brain-bridge.php');
$adminUi=$read('admin/assets/director-capture.js');
$userUi=$read('assets/js/app.js');
if(!str_contains($core,'function sf_production_task_propose_gap(')
 ||!str_contains($core,'function sf_production_task_propose_editorial(')
 ||!str_contains($core,'function sf_production_task_submit(')
 ||!str_contains($core,'function sf_production_task_review(')
 ||!str_contains($core,'sf_team_audit(')
 ||!str_contains($core,"$" . "db->beginTransaction();")
 ||!str_contains($adminApi,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($userApi,'sf_require_user(false,$method!==\'GET\')')
 ||!str_contains($brain,'sf_production_task_list($editor)')
 ||!str_contains($adminUi,'data-task-review')
 ||!str_contains($userUi,'data-assigned-submit'))
 throw new RuntimeException('G4J task permissions, human review, activity trace and UI missing.');
$workflow=$read('.github/workflows/release-gate.yml');
if(!str_contains($workflow,'section88d3g4j-production-tasks.php')
 ||!str_contains($workflow,'section88d3g4j-release.php')
 ||!str_contains($workflow,'section88d3g4i-agent-editorial.php'))
 throw new RuntimeException('G4J must preserve previous Agent Brain and release workflows.');
echo "88D3G4J: schema, task interface, Agent Brain and release-gate contracts passed".PHP_EOL;
