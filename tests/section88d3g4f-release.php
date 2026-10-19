<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $path)
 if(!str_contains($read($path),'1.3.129'))throw new RuntimeException('G4F stale release identity: '.$path);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.63'")
 ||!str_contains($read('api/migrations.php'),"SF_DB_SCHEMA_TARGET = '1.3.63'")
 ||!str_contains($read('version.php'),"'artist_world_editorial_refinement'=>'human-reviewed-ai-precision-trims-local-undo-redo-synchronized-preview'"))
 throw new RuntimeException('G4F feature identity or schema migration drift.');
$core=$read('api/story-composer-core.php');$ui=$read('admin/assets/story-assembly.js');
if(!str_contains($core,'trim_start_ms')||!str_contains($core,'trim_end_ms')
 ||!str_contains($core,'sf_composer_validate_suggestion(')
 ||!str_contains($core,'sf_composer_context(')
 ||!str_contains($core,'sf_capture_rights_may_use(')
 ||!str_contains($ui,"data-composer-refine")||!str_contains($ui,"editCheckpoint()")
 ||!str_contains($ui,"data-trim-side")
 ||!str_contains($ui,"action:'replace'")
 ||!str_contains($ui,"renderEdl?.clips?.[i]"))
 throw new RuntimeException('G4F governed AI, local undo/trim or EDL view missing.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4f-refinement.php')||!str_contains($ci,'section88d3g4f-release.php')
 ||!str_contains($ci,'section88d3e-story-composer.php'))
 throw new RuntimeException('G4F refinements and legacy AI composer tests must run in CI.');
echo "88D3G4F: governed editorial refinement and release contracts passed".PHP_EOL;
