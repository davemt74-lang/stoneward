<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-audience-core.php';
$rows=[
 ['email'=>'private@example.com','display_name'=>'Secret Fan','marketing_opt_in'=>1,'id'=>42],
 ['email'=>'optout@example.com','display_name'=>'Unsubscribed','marketing_opt_in'=>0,'id'=>43],
 ['email'=>'yes@example.com','marketing_opt_in'=>1,'id'=>44]
];
$agg=sf_agent_audience_aggregate($rows);
$encoded=json_encode($agg);
$endpoint=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-audience.php');
$module=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-actions.js');
$checks=[
 'Counts matched contacts'=>($agg['matched']??0)===3,
 'Counts opt-in only'=>($agg['email_eligible']??0)===2,
 'Counts suppressed contacts'=>($agg['suppressed']??0)===1,
 'Aggregated JSON excludes emails'=>!str_contains($encoded,'@')&&!str_contains($encoded,'Secret Fan'),
 'Cap declared only when reached'=>sf_agent_audience_aggregate($rows,3)['capped']===true,
 'Requires admin authentication'=>str_contains($endpoint,'sf_admin_require_auth(false)'),
 'Endpoint never emits raw contact array'=>!str_contains($endpoint,"'contacts'=>$contacts"),
 'CRM campaign preview reuses segment match logic'=>str_contains($endpoint,'sf_campaign_audience_contacts('),
 'Workbench never claims email was sent'=>str_contains($module,'Draft does not send.'),
 'Workbench applies segment ID not contact identities'=>str_contains($module,'d.audience={segment_id:'),
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
