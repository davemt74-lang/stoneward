<?php
declare(strict_types=1);
/** Section 44J: exact-release wiring, privacy and source consistency gate. */
$root=dirname(__DIR__);
$get=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$ci=$get('.github/workflows/release-gate.yml');
$registry=$get('api/agent-tool-registry.php');
$core=$get('api/agent-actions-core.php');
$api=$get('admin/api/agent-actions.php');
$version=$get('version.php');
$migrations=$get('api/migrations.php');
$docs=$get('docs/section44-agent-execution-acceptance.md');
$tests=[
 'a-tool-registry','b-reviewed-proposals','c-campaign-workbench',
 'd-audience-research','e-published-campaign-copy','f-execution-governance',
 'g-action-timeline','h-recovery','i-mobile-chat','j-live-campaign-save'
];
$complete=true;
foreach($tests as $suffix){
    $name='tests/section44'.$suffix.'.php';
    $complete=$complete&&is_file($root.'/'.$name)&&str_contains($ci,'php '.$name);
}
$checks=[
 'All 44A-J suites registered'=>$complete,
 'Separate post-build audit registered'=>is_file($root.'/tests/section44-audit-hardening.php')&&str_contains($ci,'php tests/section44-audit-hardening.php'),
 'Public frontend 1.3.44'=>str_contains($get('stonefellow-v120.php'),"define('SF_BUILD', '1.3.44')"),
 'Admin frontend 1.3.44'=>str_contains($get('admin/index.php'),"define('SF_ADMIN_BUILD','1.3.44')"),
 'Version endpoint 1.3.44'=>str_contains($version,"'stonefellow'=>'1.3.44'"),
 'PWA cache 1.3.44'=>str_contains($get('service-worker.js'),'stonefellow-static-v1.3.44'),
 'Apache build header 1.3.44'=>str_contains($get('.htaccess'),'X-Stonefellow-Build "1.3.44"'),
 'Schema target 1.3.34'=>str_contains($migrations,"const SF_DB_SCHEMA_TARGET = '1.3.34'")&&str_contains($version,"'database_schema_target'=>'1.3.34'"),
 'Migration 035 installed'=>str_contains($migrations,"'id'=>'2026-10-12-035'")&&str_contains($migrations,'sf_agent_actions_ensure_schema()'),
 'Authenticated CSRF governance'=>str_contains($api,"sf_admin_require_auth($"."method!=='GET')"),
 'Sending and publishing not executable'=>str_contains($registry,"if(!in_array($"."toolId,['campaign_draft_save','automation_draft_save'],true))return false"),
 'Approval requires exact digest'=>str_contains($core,'hash_equals($'."p['payload_hash']"),
 'No automatic replay of uncertain status'=>str_contains($core,"'review_required'")&&str_contains($core,"'reviewed_no_retry'"),
 'Mandatory real campaign persistence test'=>str_contains($ci,'php tests/section44j-live-campaign-save.php'),
 'Certified ZIP tied to successful main'=>str_contains($get('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.conclusion == 'success'"),
 'Deployment documentation warns about live dependencies'=>str_contains($docs,'production MySQL migration rehearsal'),
];
$fail=0;
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
echo (count($checks)-$fail).'/'.count($checks).' release checks passed'.PHP_EOL;
exit($fail?1:0);
