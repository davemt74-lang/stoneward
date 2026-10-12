<?php
declare(strict_types=1);
$runtime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$privacy=(string)file_get_contents(dirname(__DIR__).'/api/privacy-core.php');
$checks=[
 'personalization persisted as explicit user control'=>str_contains($privacy,'function sf_privacy_personalization_enabled'),
 'private recommendation context requires enabled preference'=>substr_count($runtime,"$"."profile!=='recommendation'")>=2,
 'CRM enrichment still exists for account enquiries'=>str_contains($runtime,'sf_crm_agent_context($userId)'),
 'membership enrichment still exists for signed-in account'=>str_contains($runtime,'sf_membership_agent_context($userId)'),
 'recommendation preference checked at runtime'=>substr_count($runtime,'sf_privacy_personalization_enabled($userId)')>=2,
 'private context never takes arbitrary client user ID'=>str_contains($runtime,'function sf_agent_context(string $query,string $profile='),
];
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
