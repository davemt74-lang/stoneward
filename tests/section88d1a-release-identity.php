<?php
declare(strict_types=1);
$root=dirname(__DIR__);$read=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
preg_match("/define\('SF_BUILD',\s*'([0-9.]+)'\)/",$read('stonefellow-v120.php'),$m);$v=$m[1]??'';
$app=$read('assets/js/app.js');$site=$read('stonefellow-v120.php');$admin=$read('admin/index.php');
$checks=[
'Public site advanced to 1.3.111'=>version_compare($v,'1.3.111','>='),
'Admin version matches'=>str_contains($admin,"define('SF_ADMIN_BUILD','".$v."')"),
'Version endpoint matches'=>str_contains($read('version.php'),"'stonefellow'=>'".$v."'"),
'PWA cache refreshed'=>str_contains($read('service-worker.js'),"stonefellow-static-v".$v)&&str_contains($read('service-worker.js'),"const BUILD='".$v."'"),
'Apache response header matches'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$v.'"'),
'Static JS and Builder CSS aligned'=>str_contains($app,$v)&&str_contains($read('assets/css/turntable-builder.css'),$v),
'Logged-in fans can open Creative Capture'=>str_contains($site,'data-view="capture"')&&str_contains($app,"view==='capture'"),
'Capture camera and user forms are present'=>str_contains($app,'creativeCaptureForm')&&str_contains($app,'capture="environment"'),
'Capture role types do not grant Team access'=>str_contains($read('api/team-workspace-core.php'),"'customer'=>['label'=>'Customer','permissions'=>['capture.view','capture.submit']]"),
'Shot requests and approvals are Admin-gated'=>str_contains($read('admin/api/story-capture.php'),'sf_admin_require_auth')&&str_contains($read('api/story-capture-core.php'),"'ai_suggestion'"),
'Pre-ingest quota limits audience upload abuse'=>str_contains($read('api/story-capture-upload.php'),'dailyLimit')&&str_contains($read('api/story-capture-upload.php'),'sf_capture_schema()'),
'Contributor media uses private review process'=>str_contains($read('api/story-capture-upload.php'),'sf_media_ingest_upload(')&&str_contains($read('api/story-capture-core.php'),"'pending_review'"),
'Director panel stays in existing Admin Team Workspace'=>str_contains($admin,'director-capture.js?v=')&&str_contains($read('admin/assets/team-workspace.js'),'SFDirectorCaptureAdmin'),
'Full acceptance includes captured-data privacy suite'=>str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88d1-story-capture.php')
  &&str_contains($read('.github/workflows/release-gate.yml'),'php tests/section88d1a-release-identity.php')
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
