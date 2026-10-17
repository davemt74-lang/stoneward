<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$found);$build=$found[1]??'';
$sw=$read('service-worker.js');$seo=$read('api/seo-core.php');$html=$read('stonefellow-v120.php');$js=$read('assets/js/app.js');
$checks=[
'Release advanced to 1.3.108'=>version_compare($build,'1.3.108','>='),
'Admin public and version endpoint agree'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$build."')")
 &&str_contains($read('version.php'),"'stonefellow'=>'".$build."'"),
'Apache build header matches'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$build.'"'),
'New Artist World public Apache route'=>str_contains($read('.htaccess'),'world|music|releases'),
'PWA cache invalidated'=>str_contains($sw,"stonefellow-static-v".$build)&&str_contains($sw,"const BUILD='".$build."'"),
'Artist World CSS precached and treated as static'=>str_contains($sw,"'assets/css/artist-world.css'")
 &&str_contains($sw,"'/assets/css/artist-world.css'"),
'World CSS loaded with exact build version'=>str_contains($html,'artist-world.css?v=<?=rawurlencode(SF_BUILD)?>'),
'Public app and Builder styles share version'=>str_contains($js,$build)&&str_contains($read('assets/css/turntable-builder.css'),$build),
'World indexing and sitemap enabled'=>str_contains($seo,"if(\$view==='world')")&&str_contains($seo,"'world'=>'0.9'"),
'World menu and public source endpoint present'=>str_contains($html,'data-view="world"')
 &&str_contains($read('api/artist-world.php'),'sf_world_public_graph()'),
'World interaction, public-graph and release tests enforced'=>str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88b1-world-graph.php')
 &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88b2-world-canvas.php')
 &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88b3-release-identity.php')
];
$bad=0;foreach($checks as $name=>$pass){echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;if(!$pass)$bad++;}
exit($bad?1:0);
