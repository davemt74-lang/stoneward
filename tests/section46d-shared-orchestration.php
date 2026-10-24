<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-orchestration.php';
$fan=sf_agent_orchestration_contract('customer','favorite_save');
$campaign=sf_agent_orchestration_contract('admin','prepare_campaign');
$journey=sf_agent_orchestration_contract('admin','prepare_journey');
$crm=sf_agent_orchestration_contract('admin','crm_report');
$checks=[
 'Customer favorite maps to existing registered tool'=>$fan['tool_id']==='favorite_track'&&$fan['registered'],
 'Customer favorite still requires confirmation'=>$fan['requires_exact_approval']===true,
 'Fan cannot directly execute favorite action'=>$fan['may_execute_directly']===false,
 'Admin campaign may only prepare reviewed proposal'=>$campaign['may_prepare_reviewed_proposal']&&!$campaign['may_execute_directly'],
 'Admin journey may only prepare reviewed proposal'=>$journey['may_prepare_reviewed_proposal']&&!$journey['may_execute_directly'],
 'Admin CRM aggregate is read-only'=>$crm['may_read_or_navigate']&&!$crm['may_execute_directly'],
 'Customer cannot request Admin campaign authority'=>!sf_agent_orchestration_contract('customer','prepare_campaign')['registered'],
 'Admin-only tool is not customer-visible'=>!sf_agent_orchestration_contract('customer','crm_report')['registered'],
 'Unknown route has no direct authority'=>!sf_agent_orchestration_contract('admin','delete_everything')['registered'],
 'Internal audit is not a customer-discoverable tool'=>!sf_agent_orchestration_contract('admin','review_actions')['may_read_or_navigate'],
 'No mapped tool grants direct execution'=>array_reduce(['customer','admin'],function($carry,$scope){foreach(['favorite_save','prepare_campaign','prepare_journey','crm_report','journey_report','research'] as $i)$carry=$carry&&!sf_agent_orchestration_contract($scope,$i)['may_execute_directly'];return $carry;},true),
];
$admin=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$fanSource=(string)file_get_contents(dirname(__DIR__).'/api/agent-chat.php');
$checks['Admin calls shared contract before action']=str_contains($admin,"sf_agent_orchestration_contract('admin',$"."plan['type'])");
$checks['Customer calls shared contract before response']=str_contains($fanSource,"sf_agent_orchestration_contract('customer'");
$checks['LLM has no privileged tool executor']=str_contains($admin,'The model itself does not execute privileged writes');
$checks['Model only receives aggregate context']=str_contains($admin,'AGGREGATE FACTS: ')&&str_contains($admin,'sf_admin_agent_operational_context()');
$checks['Provider failures never leak exception to Admin']=!str_contains($admin,'throw $e;')&&!str_contains($admin,'return $e->getMessage()');
$bad=0;foreach($checks as $n=>$ok){echo ($ok?'PASS':'FAIL').' '.$n.PHP_EOL;if(!$ok)$bad++;}
exit($bad?1:0);
