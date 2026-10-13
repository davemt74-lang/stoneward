<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-admin-intelligence.php';
$facts=[
 'crm_contacts'=>100,'newsletter_opted_in'=>60,'campaigns_active'=>0,
 'journey_counts'=>['journeys_failed'=>2,'journeys_started'=>17,'journeys_completed'=>13],
 'private_customer_memory'=>'DO_NOT_EXPOSE','customer_agent_conversations'=>[['message'=>'PRIVATE_SECRET']]
];
$brief=sf_admin_agent_decision_brief($facts);
$ids=array_column($brief,'id');
$check=[
 'Three supported evidence-backed Admin opportunities'=>count($brief)===3,
 'Failed journey aggregate highest priority'=>$ids[0]==='journey_failures',
 'Consent gap distinct from engagement analytics'=>in_array('newsletter_consent_review',$ids,true),
 'Campaign is a reviewed draft suggestion only'=>in_array('campaign_draft_opportunity',$ids,true)
    && $brief[2]['requires_exact_approval']===true,
 'Explicit approval still required by shared contract'=>sf_agent_orchestration_contract('admin','prepare_campaign')['may_prepare_reviewed_proposal']
    && !sf_agent_orchestration_contract('admin','prepare_campaign')['may_execute_directly'],
 'No direct execution flags in output'=>!str_contains(json_encode($brief),'may_execute_directly'),
 'No private memory or conversation leaks'=>!str_contains(json_encode($brief),'DO_NOT_EXPOSE')
    && !str_contains(json_encode($brief),'PRIVATE_SECRET'),
 'Only known aggregate metrics included'=>($brief[0]['evidence']['failed_runs']??null)===2
    && ($brief[1]['evidence']['contacts']??null)===100,
 'No misleading opt-in claims or send suggestions'=>!str_contains(strtolower(json_encode($brief)),'send now'),
 'No available facts means no invented insight'=>sf_admin_agent_decision_brief([])===[],
 'Zero counts yield no invented campaign'=>sf_admin_agent_decision_brief(['newsletter_opted_in'=>0,'campaigns_active'=>0])===[],
 'Unknown campaign count yields no draft claim'=>sf_admin_agent_decision_brief(['newsletter_opted_in'=>14])===[],
 'Invalid negative counts rejected'=>sf_admin_agent_decision_brief(['crm_contacts'=>-1,'newsletter_opted_in'=>3])===[],
 'Fractional counts rejected'=>sf_admin_agent_decision_brief(['crm_contacts'=>9.7,'newsletter_opted_in'=>3])===[],
 'Non-finite counts rejected'=>sf_admin_agent_decision_brief(['crm_contacts'=>INF,'newsletter_opted_in'=>4])===[],
 'Out-of-range counts rejected'=>sf_admin_agent_decision_brief(['crm_contacts'=>1000000001,'newsletter_opted_in'=>4])===[],
 'Untrusted text does not become metric'=>sf_admin_agent_decision_brief(['crm_contacts'=>'DROP TABLE users','newsletter_opted_in'=>4])===[],
 'No consent gap when opted-in >= contacts'=>sf_admin_agent_decision_brief(['crm_contacts'=>30,'newsletter_opted_in'=>30])===[],
 'Failing journey read never changes records'=>sf_admin_agent_decision_brief(['journey_counts'=>['journeys_failed'=>1]])[0]['next_intent']==='review_actions',
 'Output bounded to three insights'=>count(sf_admin_agent_decision_brief($facts))<=3,
];
$source=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$check['Admin conversation uses already-scoped context']=str_contains($source,'sf_admin_agent_operational_context()')
    && str_contains($source,'sf_admin_agent_decision_brief($context)');
$check['Admin Canvas visibly renders explainable insights']=str_contains($ui,'Evidence-backed opportunities');
$check['Customer Chat has no Admin insight route']=!str_contains(file_get_contents(dirname(__DIR__).'/api/agent-chat.php'),'sf_admin_agent_decision_brief(');
$failed=0;foreach($check as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
echo '47E: '.(count($check)-$failed).'/'.count($check).' checks passed'.PHP_EOL;
exit($failed?1:0);
