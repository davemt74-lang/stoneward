<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.122'))throw new RuntimeException('Release identity stale: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.59'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-050'")
 ||!str_contains($read('api/migrations.php'),"'story_render_reviews'"))
 throw new RuntimeException('Private video review migration missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.59'")
 ||!str_contains($read('version.php'),'admin-authenticated-ranged-private-mp4-human-review-no-publication'))
 throw new RuntimeException('Video review release feature missing.');
$core=$read('api/story-render-review-core.php');$api=$read('admin/api/story-render-review.php');
$ui=$read('admin/assets/story-assembly.js');
if(!str_contains($core,'hash_file(')
 ||!str_contains($core,'sf_render_plan(')
 ||!str_contains($core,'sf_assembly_editor(')
 ||!str_contains($core,'sf_render_review_range(')
 ||!str_contains($core,"['private_review','approved_private']")
 ||!str_contains($core,'sf_team_audit('))
 throw new RuntimeException('Review permissions, hash checks or rollback safeguards missing.');
if(!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'sf_render_review_file(')
 ||!str_contains($api,'Cache-Control: private, no-store')
 ||!str_contains($api,'Content-Range')
 ||!str_contains($api,'publication_allowed')
 ||!str_contains($ui,'story-render-review.php?action=stream')
 ||!str_contains($ui,'approved_private'))
 throw new RuntimeException('Private Admin range playback or editorial controls missing.');
if(str_contains($api,'sf_render_run_once(')||str_contains($api,'sf_render_concat('))
 throw new RuntimeException('Stream endpoint must never render or publish.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g3-private-review.php')
 ||!str_contains($gate,'section88d3g3-release.php'))
 throw new RuntimeException('Private render review tests not included in release gate.');
echo "88D3G3: auth, private playback, human review and no-publishing contracts passed".PHP_EOL;
