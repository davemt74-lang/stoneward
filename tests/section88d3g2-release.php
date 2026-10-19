<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.130'))throw new RuntimeException('Build identity stale: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.64'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-049'")
 ||!str_contains($read('api/migrations.php'),"'story_render_jobs'"))
 throw new RuntimeException('Private FFmpeg render database migration missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.64'")
 ||!str_contains($read('version.php'),'supervised-cli-ffmpeg-720p-cut-only-private-output-no-publishing'))
 throw new RuntimeException('Private render version contract missing.');
$worker=$read('api/story-render-worker-core.php');
$api=$read('admin/api/story-assembly.php');$ui=$read('admin/assets/story-assembly.js');
$cli=$read('scripts/artist-world-private-render.php');
if(!str_contains($worker,"sf_ai_meta_get('artist_world.render_enabled','0')")
 ||!str_contains($worker,'sf_render_plan(')
 ||!str_contains($worker,'sf_render_source_manifest(')
 ||!str_contains($worker,'sf_render_private_root(')
 ||!str_contains($worker,'hash_file(')
 ||!str_contains($worker,"'private_review'")
 ||!str_contains($worker,'proc_open(')
 ||!str_contains($worker,"'bypass_shell'=>true"))
 throw new RuntimeException('Bounded supervised private worker safety missing.');
if(!str_contains($cli,"if(PHP_SAPI!=='cli')")
 ||!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||str_contains($api,'sf_render_run_once(')
 ||!str_contains($api,'enqueue_render')
 ||!str_contains($api,'configure_render')
 ||!str_contains($ui,'renderBindMaster')
 ||!str_contains($ui,'renderQueue'))
 throw new RuntimeException('Browser could trigger render or UI/CSRF is absent.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g2-ffmpeg-render.php')
 ||!str_contains($gate,'section88d3g2-release.php'))
 throw new RuntimeException('Live FFmpeg render certification not included in CI.');
echo "88D3G2: real FFmpeg CLI, private output, version and no-publication contracts passed".PHP_EOL;
