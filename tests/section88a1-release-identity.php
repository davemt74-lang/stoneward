<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$m);$build=$m[1]??'';
$checks=[
 'Artist World release version advanced'=>version_compare($build,'1.3.105','>='),
 'Admin matches public build'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$build."')"),
 'Version endpoint matches build'=>str_contains($read('version.php'),"'stonefellow'=>'".$build."'"),
 'PWA cache invalidates previous version'=>str_contains($read('service-worker.js'),"stonefellow-static-v".$build)
  &&str_contains($read('service-worker.js'),"const BUILD='".$build."'"),
 'Apache build header matches'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$build.'"'),
 'Builder and app asset version markers match'=>str_contains($read('assets/js/app.js'),$build)
   &&str_contains($read('assets/css/turntable-builder.css'),$build),
 'Admin gallery Media reuse control available'=>str_contains($read('admin/assets/gallery.js'),'data-gallery-vinyl'),
 'Fan Vinyl Builder picker available'=>str_contains($read('assets/js/app.js'),'vinylArtPicker()'),
 'Both order and saved draft validate artwork'=>str_contains($read('api/bootstrap.php'),'sf_world_vinyl_approved_reference($front)')
   &&str_contains($read('api/account-data.php'),'sf_world_vinyl_approved_reference($reference)'),
 'Curated photo permission regression remains gated'=>str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88a-artist-world-vinyl.php')
];
$failed=0;foreach($checks as $label=>$ok){echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
