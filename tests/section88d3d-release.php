<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$files=['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'];
foreach($files as $p){
 if(!str_contains($read($p),'1.3.138'))throw new RuntimeException('Release identity mismatched: '.$p);
}
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-045'")
 ||!str_contains($read('api/migrations.php'),"'story_assemblies','story_assembly_items'"))
 throw new RuntimeException('Missing private timeline migration/integrity gate.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('version.php'),'private-verified-evidence-story-timeline-catalog-music-deterministic-director-gaps'))
 throw new RuntimeException('Public version metadata incorrect.');
$index=$read('admin/index.php');$ui=$read('admin/assets/story-assembly.js');
$review=$read('admin/assets/production-review.js');$api=$read('admin/api/story-assembly.php');
$core=$read('api/story-assembly-core.php');
if(!str_contains($index,'assets/story-assembly.js')||!str_contains($index,'assets/story-assembly.css')
 ||!str_contains($review,'SFStoryAssembly?.render?.()')
 ||!str_contains($ui,'window.SFStoryAssembly={render')
 ||!str_contains($ui,'story-capture.php')
 ||!str_contains($ui,'action:\'replace\''))
 throw new RuntimeException('Private editorial timeline UI not connected.');
if(!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||str_contains($api,'sf_openai_call(')||str_contains($api,'sf_inference_run_once(')
 ||!str_contains($core,"review_status='verified'")
 ||!str_contains($core,'revision=revision+1')
 ||!str_contains($core,'sf_catalog')
 ||!str_contains($core,"'ready_to_render'=>false"))
 throw new RuntimeException('Unsafe assembly/rights/editor revision architecture.');
if(!str_contains($read('.github/workflows/release-gate.yml'),'section88d3d-story-assembly.php')
 ||!str_contains($read('.github/workflows/release-gate.yml'),'section88d3d-release.php'))
 throw new RuntimeException('Missing full-release certification registration.');
echo "88D3D: version, privacy, music reference, edit and CI gates passed".PHP_EOL;
