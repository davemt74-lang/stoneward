<?php
declare(strict_types=1);
require_once __DIR__.'/release-identity.php';
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$files=['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
  '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'];
foreach($files as $file){
  if(!str_contains($read($file),SF_TEST_RELEASE_VERSION))throw new RuntimeException('Incorrect release asset identity: '.$file);
}
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.68'")
  ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-042'")
  ||!str_contains($read('api/migrations.php'),"'story_inference_jobs'"))
  throw new RuntimeException('Missing additive durable queue migration.');
$version=$read('version.php');
if(!str_contains($version,"'database_schema_target'=>'1.3.68'")
  ||!str_contains($version,"'artist_world_inference_queue'=>'private-rights-gated-durable-lease-human-retry'"))
  throw new RuntimeException('Version contract overstates or omits queue capability.');
$queue=$read('api/story-inference-queue-core.php');
$endpoint=$read('admin/api/story-inference.php');
if(str_contains($queue,'sf_openai_call(')||str_contains($queue,'sf_anthropic_call(')
   ||str_contains($queue,'curl_exec(')||str_contains($endpoint,'sf_inference_claim('))
  throw new RuntimeException('Queue stage must not expose model execution.');
if(!str_contains($endpoint,'sf_admin_require_auth($method!==\'GET\')')
   ||!str_contains($queue,'sf_capture_rights_may_use(')
  ||!str_contains($queue,'sf_inference_lease_valid'))
  throw new RuntimeException('Required production evidence/rights safety gate missing.');
echo "88D3A: 12 release/safety assertions green".PHP_EOL;
