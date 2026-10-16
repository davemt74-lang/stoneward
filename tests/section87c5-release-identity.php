<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$source=$read('stonefellow-v120.php');
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$source,$match);
$build=$match[1]??'';
$checks=[
 'Build advanced for all four 87C sections'=>version_compare($build,'1.3.101','>='),
 'Admin build equals public build'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$build."')"),
 'Version endpoint equals build'=>str_contains($read('version.php'),"'stonefellow'=>'".$build."'"),
 'PWA cache name invalidates old cached app'=>str_contains($read('service-worker.js'),"stonefellow-static-v".$build),
 'PWA fetch uses same build identity'=>str_contains($read('service-worker.js'),"const BUILD='".$build."'"),
 'Apache build header matches'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$build.'"'),
 'Public application JS asset marker refreshed'=>str_contains($read('assets/js/app.js'),$build),
 'Builder stylesheet marker refreshed'=>str_contains($read('assets/css/turntable-builder.css'),$build),
 'Admin scripts are cache-busted using Admin build'=>str_contains($read('admin/index.php'),'?v=<?=rawurlencode(SF_ADMIN_BUILD)?>'),
 'Admin fan intelligence is included'=>str_contains($read('admin/assets/analytics.js'),'journeyPanel(c,journey)'),
 'Admin consent guidance is included'=>str_contains($read('admin/assets/campaigns.js'),'fan_conversion_guidance'),
];
$failed=0;foreach($checks as $name=>$pass){echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;if(!$pass)$failed++;}
exit($failed?1:0);
