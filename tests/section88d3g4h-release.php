<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
foreach(['stonefellow-v120.php','admin/index.php','version.php','service-worker.js','.htaccess',
 'assets/js/app.js','assets/css/turntable-builder.css'] as $file)
 if(!str_contains($read($file),'1.3.136'))throw new RuntimeException('G4H release identity drift: '.$file);
if(!str_contains($read('version.php'),"'database_schema_target'=>'1.3.68'")
 ||!str_contains($read('api/migrations.php'),"const SF_DB_SCHEMA_TARGET = '1.3.68'")
 ||!str_contains($read('api/migrations.php'),"'id'=>'2026-10-19-055'")
 ||!str_contains($read('api/migrations.php'),'sf_assembly_restore_schema();'))
 throw new RuntimeException('G4H migration 055, schema and release identity incomplete.');
$restore=$read('api/story-assembly-restore-core.php');$core=$read('api/story-assembly-core.php');
$api=$read('admin/api/story-assembly.php');$ui=$read('admin/assets/story-assembly.js');
if(!str_contains($restore,'function sf_assembly_restore_request(')
 ||!str_contains($restore,'function sf_assembly_restore_review(')
 ||!str_contains($restore,'function sf_assembly_restore_apply(')
 ||!str_contains($restore,'sf_assembly_history_read(')
 ||!str_contains($restore,'sf_assembly_validate_item(')
 ||!str_contains($restore,'sf_team_audit(')
 ||!str_contains($restore,'hash_equals(')
 ||!str_contains($core,'?callable $onApplied=null')
 ||!str_contains($core,'if($onApplied!==null)$onApplied($revision+1)')
 ||!str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 ||!str_contains($api,"'restoration_proposals'=>")
 ||!str_contains($api,"'request_restore'")
 ||!str_contains($api,"'review_restore'")
 ||!str_contains($api,"'apply_restore'")
 ||!str_contains($ui,'data-restore-apply')
 ||!str_contains($ui,'data-restore-review'))
 throw new RuntimeException('G4H supervised revision-restoration integration incomplete.');
$gate=$read('.github/workflows/release-gate.yml');
if(!str_contains($gate,'section88d3g4h-governed-restore.php')
 ||!str_contains($gate,'section88d3g4h-release.php')
 ||!str_contains($gate,'section88d3g4g-history.php'))
 throw new RuntimeException('G4H certification not included in release gate.');
echo "88D3G4H: governed restoration release and schema contracts passed".PHP_EOL;
