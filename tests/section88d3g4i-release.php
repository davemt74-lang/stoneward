<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $file)
 if(!str_contains($read($file),'1.3.136'))
  throw new RuntimeException('G4I stale release version: '.$file);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('version.php'),"'artist_world_agent_editorial'=>'admin-only-live-evidence-approval-production-ledger-read-only'"))
 throw new RuntimeException('G4I schema drift or release capability missing.');
$bridge=$read('api/story-agent-brain-bridge.php');$agent=$read('api/agent-admin-brain.php');
$ui=$read('admin/assets/agent-canvas.js');
if(!str_contains($bridge,'function sf_story_agent_brain_summary(')
 ||!str_contains($bridge,'function sf_story_agent_brain_inspect(')
 ||!str_contains($bridge,'sf_composer_list(')
 ||!str_contains($bridge,'sf_assembly_restore_list(')
 ||!str_contains($bridge,'sf_render_jobs(')
 ||!str_contains($bridge,'sf_render_source_admin_status(')
 ||!str_contains($agent,"'production_editorial_status'")
 ||!str_contains($agent,'sf_story_agent_brain_summary(')
 ||!str_contains($agent,'sf_story_agent_brain_brief(')
 ||!str_contains($ui,'brainProductionOpen')
 ||!str_contains($ui,'data-agent-show-calendar'))
 throw new RuntimeException('G4I editorial brain, policy boundary or reviewer navigation missing.');
if(str_contains($bridge,'sf_render_enqueue(')||str_contains($bridge,'sf_assembly_replace(')
 ||str_contains($bridge,'sf_composer_apply(')||str_contains($bridge,'sf_assembly_restore_apply('))
 throw new RuntimeException('G4I read projection cannot perform edits, model work or rendering.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g4i-agent-editorial.php')
 ||!str_contains($gate,'section88d3g4i-release.php')
 ||!str_contains($gate,'section88d3g4h-governed-restore.php'))
 throw new RuntimeException('G4I reviewer integration and older restore gates absent from CI.');
echo "88D3G4I: exact release, ACL, read-only editorial bridge and CI contracts passed".PHP_EOL;
