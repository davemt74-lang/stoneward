<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $f):string=>(string)file_get_contents($root.'/'.$f);
$files=['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'];
foreach($files as $f)if(!str_contains($read($f),'1.3.125'))
 throw new RuntimeException('Missing v1.3.125 build identity in '.$f);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.61'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-043'")
 ||!str_contains($read('api/migrations.php'),"'story_inference_results'"))
 throw new RuntimeException('Missing additive private draft database migration.');
if(!str_contains($read('version.php'),"database_schema_target'=>'1.3.61'")
 ||!str_contains($read('version.php'),'admin-opt-in-supervised-openai-audio-openai-anthropic-still-vision-private-review-only'))
 throw new RuntimeException('Misrepresented provider capabilities.');
$worker=$read('api/story-inference-worker-core.php');
$endpoint=$read('admin/api/story-inference.php');
$cli=$read('scripts/artist-world-inference-worker.php');
if(!str_contains($worker,'sf_inference_lease_valid(')
 ||!str_contains($worker,'sf_inference_media_path(')
 ||!str_contains($worker,"sf_ai_meta_get('artist_world.inference_enabled','0')")
 ||!str_contains($worker,'sf_inference_finish(')
 ||!str_contains($worker,'CURLOPT_FOLLOWLOCATION=>false'))
 throw new RuntimeException('Missing processing security safeguards.');
if(!str_contains($endpoint,'sf_admin_require_auth($method!==\'GET\')')
 ||str_contains($endpoint,'sf_inference_run_once(')
 ||!str_contains($cli,"if(PHP_SAPI!=='cli')"))
 throw new RuntimeException('Inference execution exposed to HTTP or missing Admin CSRF.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'tests/section88d3b-provider-execution.php')
 ||!str_contains($gate,'tests/section88d3b-release.php'))
 throw new RuntimeException('Provider execution is not certified by release gate.');
echo "88D3B: build contract and security assertions passed".PHP_EOL;
