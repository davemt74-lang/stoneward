<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js',
 '.htaccess','assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.124'))
  throw new RuntimeException('G4B release identity mismatch: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.61'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-18-052'")
 ||!str_contains($read('api/migrations.php'),"'story_render_transitions'"))
 throw new RuntimeException('Crossfade migration and private ledger are missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.61'")
 ||!str_contains($read('version.php'),'private-video-audio-overlapping-transitions'))
 throw new RuntimeException('Crossfade version flag incorrect.');
$core=$read('api/story-render-transition-core.php');
$worker=$read('api/story-render-worker-core.php');
$api=$read('admin/api/story-assembly.php');
$ui=$read('admin/assets/story-assembly.js');
$cli=$read('scripts/artist-world-private-render.php');
if(!str_contains($core,'xfade=transition=')
 ||!str_contains($core,'acrossfade=d=')
 ||!str_contains($core,'sf_render_plan(')
 ||!str_contains($core,'sf_team_audit(')
 ||!str_contains($core,'Queued render already locks transitions.')
 ||!str_contains($core,'sf_render_transition_effective_manifest(')
 ||!str_contains($core,'sf_render_exec('))
 throw new RuntimeException('Private video/audio filter, revision or ACL gate missing.');
if(!str_contains($worker,'sf_render_transition_apply(')
 ||!str_contains($worker,'sf_render_mix_apply($effective,')
 ||!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,'configure_transition')
 ||!str_contains($ui,'transitionSave')
 ||!str_contains($cli,'story-render-transition-core.php'))
 throw new RuntimeException('Crossfade private CLI/CSRF/Studio integration missing.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g4b-crossfade.php')
 ||!str_contains($gate,'section88d3g4b-release.php'))
 throw new RuntimeException('Real visual/audio crossfade regression absent from CI.');
echo "88D3G4B: crossfade, mixing, private editor, schema and release acceptance passed".PHP_EOL;
