<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$release=['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'];
foreach($release as $p)if(!str_contains($read($p),'1.3.137'))
 throw new RuntimeException('Build identity not synchronized for '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-047'")
 ||!str_contains($read('api/migrations.php'),"'story_track_license_reviews'"))
 throw new RuntimeException('Scope-specific license ledger missing migration/integrity.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('version.php'),
 'private-card-time-preview-music-scope-license-and-render-readiness-no-render'))
 throw new RuntimeException('Incorrect Storyboard feature contract.');
$core=$read('api/storyboard-preview-core.php');
$api=$read('admin/api/story-assembly.php');
$ui=$read('admin/assets/story-assembly.js');
$css=$read('admin/assets/story-assembly.css');
if(!str_contains($core,"'render_allowed'=>false")
 ||!str_contains($core,"'publication_allowed'=>false")
 ||!str_contains($core,"'hash_verification_at_render'=>false")
 ||!str_contains($core,'sf_storyboard_asset_present(')
 ||!str_contains($core,"['sync','master']")
 ||!str_contains($core,'sf_capture_rights_status('))
 throw new RuntimeException('Renderer or rights-preflight security boundary missing.');
if(!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'sf_storyboard_preview(')
 ||!str_contains($api,'sf_storyboard_license_review(')
 ||str_contains($api,'sf_inference_run_once(')
 ||str_contains($api,'sf_composer_run_once('))
 throw new RuntimeException('Unsafe story assembly API.');
if(!str_contains($ui,'assemblyPreviewRange')
 ||!str_contains($ui,'assemblyLicenseSave')
 ||!str_contains($ui,'assembly-board-card')
 ||!str_contains($css,'.assembly-board-card'))
 throw new RuntimeException('Storyboard canvas or timed preview not installed.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3f-storyboard.php')
 ||!str_contains($gate,'section88d3f-release.php')
 ||!str_contains($gate,'node --check admin/assets/story-assembly.js'))
 throw new RuntimeException('Storyboard tests are not part of release gate.');
echo "88D3F: release, rights, private preview and no-render security contracts passed".PHP_EOL;
