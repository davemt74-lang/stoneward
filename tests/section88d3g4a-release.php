<?php
declare(strict_types=1);
require_once __DIR__.'/release-identity.php';
$root=dirname(__DIR__);
$get=static fn(string $name):string=>(string)file_get_contents($root.'/'.$name);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $name)
 if(!str_contains($get($name),SF_TEST_RELEASE_VERSION))throw new RuntimeException('Mismatched G4A release: '.$name);
if(!str_contains($get('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($get('api/migrations.php'),"'id'=>'2026-10-18-051'")
 ||!str_contains($get('api/migrations.php'),"'story_render_mixes'"))
 throw new RuntimeException('G4A private mixer migration is not registered.');
if(!str_contains($get('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($get('version.php'),'licensed-revision-locked-music-bed-dialogue-duck-fades-supervised-private-output'))
 throw new RuntimeException('G4A feature version identity missing.');
$core=$get('api/story-render-mix-core.php');
$worker=$get('api/story-render-worker-core.php');
$api=$get('admin/api/story-assembly.php');
$cli=$get('scripts/artist-world-private-render.php');
$ui=$get('admin/assets/story-assembly.js');
if(!str_contains($core,'sf_render_plan(')||!str_contains($core,'sf_render_mix_filter(')
 ||!str_contains($core,'sf_team_audit(')||!str_contains($core,'sf_render_ffmpeg()')
 ||!str_contains($core,'sf_render_exec(')||!str_contains($core,'sf_render_mix_source(')
 ||!str_contains($core,'Render already queued; mix preset is locked.'))
 throw new RuntimeException('G4A independent rights, audit, scope or FFmpeg graph contract missing.');
if(!str_contains($worker,'sf_render_mix_apply(')||!str_contains($cli,'story-render-mix-core.php')
 ||!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'configure_mix')
 ||!str_contains($ui,'mixSave'))
 throw new RuntimeException('G4A worker/CSRF/private Editor controls absent.');
$ci=$get('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4a-music-mix.php')
 ||!str_contains($ci,'section88d3g4a-release.php'))
 throw new RuntimeException('G4A real FFmpeg mixer CI gate absent.');
echo "88D3G4A: release identity, private licensed mix, worker and CI contracts passed".PHP_EOL;
