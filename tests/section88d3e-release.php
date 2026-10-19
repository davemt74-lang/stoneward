<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.135'))throw new RuntimeException('Release identity mismatch: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-046'")
 ||!str_contains($read('api/migrations.php'),"'story_composer_proposals'"))
 throw new RuntimeException('Composer migration/integrity table missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('version.php'),
 'supervised-metadata-only-llm-story-proposals-explicit-human-approval-revisioned-apply'))
 throw new RuntimeException('Composer public capability contract incorrect.');
$worker=$read('api/story-composer-core.php');$cli=$read('scripts/artist-world-story-composer.php');
$api=$read('admin/api/story-assembly.php');$ui=$read('admin/assets/story-assembly.js');
if(!str_contains($cli,"if(PHP_SAPI!=='cli')")
 ||!str_contains($worker,"sf_ai_meta_get('artist_world.composer_enabled','0')")
 ||!str_contains($worker,'sf_composer_context(')
 ||!str_contains($worker,'sf_capture_rights_may_use(')
 ||!str_contains($worker,'sf_composer_validate_suggestion(')
 ||!str_contains($worker,'sf_assembly_replace(')
 ||!str_contains($read('api/story-inference-worker-core.php'),'CURLOPT_FOLLOWLOCATION=>false'))
 throw new RuntimeException('Composer rights/controlled provider contract missing.');
if(str_contains($api,'sf_composer_run_once(')
 ||!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($ui,'composerToggle')
 ||!str_contains($ui,'apply_composer')
 ||!str_contains($ui,'review_composer'))
 throw new RuntimeException('Unsafe Composer browser execution or absent review UI.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3e-story-composer.php')
 ||!str_contains($gate,'section88d3e-release.php'))
 throw new RuntimeException('Composer regression absent from release gate.');
echo "88D3E: security, API, version and migration contracts green".PHP_EOL;
