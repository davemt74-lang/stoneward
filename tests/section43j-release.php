<?php
declare(strict_types=1);
/** Section 43J certification wiring and synchronized v1.3.42 source. */
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$version=$get('version.php');
$public=$get('stonefellow-v120.php');
$admin=$get('admin/index.php');
$sw=$get('service-worker.js');
$apache=$get('.htaccess');
$workflow=$get('.github/workflows/release-gate.yml');
$docs=$get('docs/section43-agent-acceptance.md');
$sections=['a-agent-runtime','b-agent-privacy','c-public-discovery','d-favorite-actions','e-admin-campaign-brief','f-voice-validation','g-proactive-consent','h-brain-privacy','i-agent-governance'];
$allRegistered=true;
foreach($sections as $suffix){
    $test='tests/section43'.$suffix.'.php';
    if(!is_file($root.'/'.$test)||!str_contains($workflow,'php '.$test))$allRegistered=false;
}
$checks=[
 'Full Section 43A-I acceptance wired'=>$allRegistered,
 'Public app 1.3.42'=>str_contains($public,"define('SF_BUILD', '1.3.42')"),
 'Admin app 1.3.42'=>str_contains($admin,"define('SF_ADMIN_BUILD','1.3.42')"),
 'Version endpoint 1.3.42'=>str_contains($version,"'stonefellow'=>'1.3.42'"),
 'PWA cache 1.3.42'=>str_contains($sw,'stonefellow-static-v1.3.42'),
 'Apache build header 1.3.42'=>str_contains($apache,'X-Stonefellow-Build "1.3.42"'),
 'Schema target unchanged'=>str_contains($version,"'database_schema_target'=>'1.3.33'"),
 'Existing Section 42 regressions retained'=>str_contains($workflow,'php tests/section42-backup-validation.php')&&str_contains($workflow,'php tests/section42-ops-privacy.php'),
 'Only a green main gate packages ZIP'=>str_contains($get('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.conclusion == 'success'"),
 'Scope and limitations documented'=>str_contains($docs,'Production browser and provider-key smoke testing')
];
foreach($checks as $name=>$passed)echo ($passed?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
echo count($checks).' release wiring checks passed'.PHP_EOL;
