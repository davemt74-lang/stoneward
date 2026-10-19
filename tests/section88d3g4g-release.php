<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $path)
 if(!str_contains($read($path),'1.3.132'))throw new RuntimeException('G4G stale release identity: '.$path);
if(!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.65'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-054'")
 ||!str_contains($read('api/migrations.php'),'sf_assembly_history_schema();'))
 throw new RuntimeException('G4G history migration 054 missing.');
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.65'")
 ||!str_contains($read('version.php'),"'artist_world_review_history'=>'immutable-editorial-snapshots-private-diffs-ai-decision-history'"))
 throw new RuntimeException('G4G version or capability contract absent.');
$history=$read('api/story-assembly-history-core.php');
$assembly=$read('api/story-assembly-core.php');
$api=$read('admin/api/story-assembly.php');
$ui=$read('admin/assets/story-assembly.js');
if(!str_contains($history,'function sf_assembly_history_archive(')
 ||!str_contains($history,'function sf_assembly_history_compare(')
 ||!str_contains($history,"hash('sha256'")
 ||!str_contains($assembly,'sf_assembly_history_archive($editor,$assemblyId,$revision+1,$prepared)')
 ||!str_contains($api,"sf_admin_require_auth(")
 ||!str_contains($api,"'revision_history'=>")
 ||!str_contains($api,"'render_edl'=>")
 ||!str_contains($api,'sf_assembly_history_compare(')
 ||!str_contains($ui,'assembly-visual-tracks')
 ||!str_contains($ui,'assembly-history-picker')
 ||!str_contains($ui,'AI Director review history'))
 throw new RuntimeException('G4G immutable history, synchronized editor or private API contract missing.');
$ci=$read('.github/workflows/release-gate.yml');
if(!str_contains($ci,'section88d3g4g-history.php')
 ||!str_contains($ci,'section88d3g4g-release.php')
 ||!str_contains($ci,'section88d3d-story-assembly.php'))
 throw new RuntimeException('G4G tests must coexist with earlier assembly regression gates.');
echo "88D3G4G: history migration, private API, UI and exact release certification passed".PHP_EOL;
