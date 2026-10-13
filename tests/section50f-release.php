<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$admin=$read('admin/index.php');$public=$read('stonefellow-v120.php');$version=$read('version.php');
$cache=$read('service-worker.js');$apache=$read('.htaccess');$mig=$read('api/migrations.php');
$gate=$read('.github/workflows/release-gate.yml');
$checks=[
 'Section 50 release version is 1.3.63'=>str_contains($public,"define('SF_BUILD', '1.3.63')"),
 'Admin build is the same version'=>str_contains($admin,"SF_ADMIN_BUILD','1.3.63'"),
 'Public version endpoint matches release'=>str_contains($version,"'stonefellow'=>'1.3.63'"),
 'PWA shell cache invalidated for UI assets'=>str_contains($cache,'stonefellow-static-v1.3.63'),
 'Apache build header matches release'=>str_contains($apache,'X-Stonefellow-Build "1.3.63"'),
 'Database target remains 1.3.46 for UI-only change'=>str_contains($version,"'database_schema_target'=>'1.3.46'")&&str_contains($mig,"SF_DB_SCHEMA_TARGET = '1.3.46'"),
 'Migration 037 remains additive and preserved'=>str_contains($mig,"'id'=>'2026-10-12-037'"),
 'No user or administrator password rewrite in new UI'=>!str_contains($read('admin/assets/unified-ui.js'),'password_hash')&&!str_contains($read('admin/assets/homepage.js'),'password_hash'),
 'Full 50A-F phase coverage required in release gate'=>array_reduce(['a','b','c','d','e','f'],fn($ok,$p)=>$ok&&str_contains($gate,'php tests/section50'.$p.'-'),true),
 'Updated shared UI checked for JavaScript syntax'=>str_contains($gate,'node --check admin/assets/unified-ui.js'),
 'Updated Settings and Homepage checked for JS syntax'=>str_contains($gate,'node --check admin/assets/settings-tabs.js')&&str_contains($gate,'node --check admin/assets/homepage.js'),
 'Existing Section 47 functional security checks retained'=>str_contains($gate,'php tests/section47i-feedback-recovery.php')&&str_contains($gate,'php tests/section47j-release.php'),
 'Existing Admin v1.3.49 tests retained'=>str_contains($gate,'php tests/admin-unified-ui.php')&&str_contains($gate,'php tests/settings-tabs-story-modal.php'),
 'Certified ZIP tied to passing main SHA'=>str_contains($read('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.head_sha"),
 'Certified packaging verifies archive and checksum'=>str_contains($read('scripts/release-zip.sh'),'unzip -tqq')&&str_contains($read('scripts/release-zip.sh'),'sha256sum stonefellow-deploy.zip'),
 'Release audit documents all 29 pages and live browser caveat'=>str_contains($read('docs/section50-admin-ui-audit.md'),'29 Admin')&&str_contains($read('docs/section50-admin-ui-audit.md'),'Known limit'),
];
$errors=0;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$errors++;}
echo '50F: '.(count($checks)-$errors).'/'.count($checks).' checks passed'.PHP_EOL;
exit($errors?1:0);
