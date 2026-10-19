<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.128'))throw new RuntimeException('Incorrect release identity: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.62'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-048'")
 ||!str_contains($read('api/migrations.php'),"'story_render_track_masters'"))
 throw new RuntimeException('Render master binding migration missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.62'")
 ||!str_contains($read('version.php'),'private-sha256-source-certification-revision-scoped-track-masters-no-render'))
 throw new RuntimeException('Render source feature version claim mismatch.');
$core=$read('api/story-render-source-core.php');
$api=$read('admin/api/story-assembly.php');
if(!str_contains($core,"hash_file('sha256'")
 ||!str_contains($core,'sf_capture_rights_may_use(')
 ||!str_contains($core,'sf_storyboard_track_rights(')
 ||!str_contains($core,'story_media_evidence')
 ||!str_contains($core,'sf_assembly_validate_item(')
 ||!str_contains($core,"'render_started'=>false")
 ||!str_contains($core,"'public_output_allowed'=>false"))
 throw new RuntimeException('Render source integrity, evidence and no-render boundary missing.');
if(!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'sf_render_master_bind(')
 ||!str_contains($api,'sf_render_source_admin_status(')
 ||str_contains($api,'sf_render_source_manifest($id,'))
 throw new RuntimeException('Unsafe Admin path disclosure or write permissions.');
if(!str_contains($read('.github/workflows/release-gate.yml'),'section88d3g1-render-certification.php'))
 throw new RuntimeException('Render certification functional test not part of PR release gate.');
echo "88D3G1: source manifest, strict SHA, private Admin status, schema and no-render contract passed".PHP_EOL;
