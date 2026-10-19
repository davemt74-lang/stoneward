<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $p)
 if(!str_contains($read($p),'1.3.133'))throw new RuntimeException('G4E stale release identity: '.$p);
if(!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.66'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-053'")
 ||!str_contains($read('api/migrations.php'),"sf_render_cut_schema();"))
 throw new RuntimeException('G4E schema migration missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.66'")
 ||!str_contains($read('version.php'),"'artist_world_per_cut'=>'audited-per-boundary-fade-wipe-and-variable-audio-overlaps'"))
 throw new RuntimeException('G4E runtime feature missing.');
if(!str_contains($read('api/story-render-cut-core.php'),'function sf_render_cut_set(')
 ||!str_contains($read('api/story-render-cut-core.php'),'sf_team_audit(')
 ||!str_contains($read('api/story-render-transition-core.php'),'sf_render_transition_cuts(')
 ||!str_contains($read('api/story-render-transition-core.php'),'xfade=transition=')
 ||!str_contains($read('api/story-render-edl-core.php'),"['cuts']")
 ||!str_contains($read('admin/api/story-assembly.php'),"'configure_cuts'")
 ||!str_contains($read('admin/assets/story-assembly.js'),'#cutSave')
 ||!str_contains($read('scripts/artist-world-private-render.php'),'story-render-cut-core.php')
 ||!str_contains($read('admin/api/story-render-review.php'),'story-render-cut-core.php'))
 throw new RuntimeException('Per-cut revision review/render/editor integration absent.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4e-per-cut.php')||!str_contains($ci,'section88d3g4e-release.php'))
 throw new RuntimeException('G4E actual FFmpeg regression missing from release CI.');
echo "88D3G4E: schema, revision-safe cut editor, private FFmpeg and release identity passed\n";
