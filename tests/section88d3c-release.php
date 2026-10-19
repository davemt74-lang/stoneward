<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$files=['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'];
foreach($files as $f)
 if(!str_contains($read($f),'1.3.130'))throw new RuntimeException('Release identity not updated: '.$f);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.64'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-044'")
 ||!str_contains($read('api/migrations.php'),"'story_inference_imports'"))
 throw new RuntimeException('Migration 044 or integrity report missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.64'")
 ||!str_contains($read('version.php'),'editorial-ai-drafts-canonical-private-import-timecoded-ffmpeg-optional'))
 throw new RuntimeException('Public version contract missing private review feature.');
$admin=$read('admin/index.php');$nav=$read('admin/assets/admin.js');
$ui=$read('admin/assets/production-review.js');$api=$read('admin/api/story-inference.php');
if(!str_contains($admin,'data-view="production-review"')
 ||!str_contains($admin,'assets/production-review.js')
 ||!str_contains($admin,'assets/production-review.css')
 ||!str_contains($nav,"'production-review':()=>window.SFProductionReviewAdmin")
 ||!str_contains($ui,"window.SFProductionReviewAdmin={render")
 ||!str_contains($ui,'import_reviewed')
 ||!str_contains($ui,'review_rights'))
 throw new RuntimeException('Native Admin review controls not correctly wired.');
if(!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'sf_production_import(')
 ||!str_contains($api,'sf_production_review_items('))
 throw new RuntimeException('Private Admin import not guarded.');
$core=$read('api/story-production-review-core.php');
$video=$read('api/story-inference-video-core.php');
$worker=$read('api/story-inference-worker-core.php');
if(!str_contains($core,"'machine_draft'")
 ||!str_contains($core,"'draft'")
 ||!str_contains($core,'sf_capture_rights_may_use(')
 ||!str_contains($core,'sf_inference_source(')
 ||!str_contains($worker,'sf_inference_extract_video_frame(')
 ||!str_contains($worker,'@unlink($temporaryFrame)')
 ||!str_contains($video,'proc_terminate($proc,9)'))
 throw new RuntimeException('AI import or optional FFmpeg safety contract missing.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'tests/section88d3c-production-review.php')
 ||!str_contains($gate,'tests/section88d3c-release.php')
 ||!str_contains($gate,'node --check admin/assets/production-review.js'))
 throw new RuntimeException('Production Review was not added to release gate.');
echo "88D3C: private review, migration, UI and video sampling checks passed".PHP_EOL;
