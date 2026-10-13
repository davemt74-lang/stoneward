<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$m);
$version=$m[1]??'';
$checks=[
 'Build version is valid and at least the completed dual-Brain baseline'=>$version!==''&&version_compare($version,'1.3.45','>='),
 'Public build matches Admin'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$version."')"),
 'Version endpoint matches public build'=>str_contains($read('version.php'),"'stonefellow'=>'".$version."'"),
 'Service worker cache matches current build'=>str_contains($read('service-worker.js'),'stonefellow-static-v'.$version),
 'Apache diagnostic header matches build'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$version.'"'),
];
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
