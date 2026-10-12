<?php
declare(strict_types=1);
/** Integrated Section 45J source, permissions, version and acceptance gate. */
$root=dirname(__DIR__);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$ci=$read('.github/workflows/release-gate.yml');
$version=$read('version.php');$migrations=$read('api/migrations.php');
$docs=$read('docs/section45-journey-intelligence-acceptance.md');
$tests=[
 'a-lifecycle-safety','b-journey-metrics','c-journey-builder','d-outreach-consent',
 'e-attribution-growth','f-advisory-agent','g-reviewed-journeys','h-journey-dashboard',
 'i-journey-recovery'
];
$all=true;
foreach($tests as $suffix){
    $path='tests/section45'.$suffix.'.php';
    $all=$all&&is_file($root.'/'.$path)&&str_contains($ci,'php '.$path);
}
$checks=[
 'Section 45A-I executable acceptance wired'=>$all,
 'Section 45J integration test wired'=>str_contains($ci,'php tests/section45j-release.php'),
 'Public build 1.3.44'=>str_contains($read('stonefellow-v120.php'),"define('SF_BUILD', '1.3.44')"),
 'Admin build 1.3.44'=>str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','1.3.44')"),
 'Version endpoint 1.3.44'=>str_contains($version,"'stonefellow'=>'1.3.44'"),
 'PWA cache 1.3.44'=>str_contains($read('service-worker.js'),'stonefellow-static-v1.3.44'),
 'Apache build 1.3.44'=>str_contains($read('.htaccess'),'X-Stonefellow-Build "1.3.44"'),
 'Database target unchanged 1.3.34'=>str_contains($migrations,"SF_DB_SCHEMA_TARGET = '1.3.34'")&&str_contains($version,"'database_schema_target'=>'1.3.34'"),
 'No unimplemented migration claimed'=>!str_contains($migrations,"'id'=>'2026-10-12-036'"),
 'Existing Agent action owner authentication preserved'=>str_contains($read('admin/api/agent-actions.php'),'sf_admin_require_auth($method!=='),
 'Full reviewed journey proposal retained'=>str_contains($read('admin/assets/automations.js'),'agentJourneyPayload'),
 'Agent can save draft but not publish'=>str_contains($read('api/agent-actions-core.php'),"'status'=>'draft'")&&!str_contains($read('api/agent-tool-registry.php'),"'automation_publish'=>"),
 'Read-only metrics endpoint is Admin guarded'=>str_contains($read('admin/api/fan-journeys.php'),'sf_admin_require_auth(false)'),
 'Read-only advice endpoint is Admin guarded'=>str_contains($read('admin/api/fan-journey-advice.php'),'sf_admin_require_auth(false)'),
 'Recovery is terminal and explicitly reviewed'=>str_contains($read('api/automation-core.php'),"'reviewed_no_retry'"),
 'Post-merge certified packaging still gated by success'=>str_contains($read('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.conclusion == 'success'"),
 'Historical Section 44 save integration retained'=>str_contains($ci,'php tests/section44j-live-campaign-save.php'),
 'Attribution and privacy caveats documented'=>str_contains($docs,'not causal uplift')&&str_contains($docs,'most recent consent'),
];
$bad=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$bad++;}
echo (count($checks)-$bad).'/'.count($checks).' integrated release checks passed'.PHP_EOL;
exit($bad?1:0);
