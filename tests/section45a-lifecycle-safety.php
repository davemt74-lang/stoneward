<?php
declare(strict_types=1);
/** Section 45A — existing lifecycle engine safety and inventory. */
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require dirname(__DIR__).'/api/automation-core.php';
$source=(string)file_get_contents(dirname(__DIR__).'/api/automation-core.php');
$crm=(string)file_get_contents(dirname(__DIR__).'/api/crm-core.php');
$campaign=(string)file_get_contents(dirname(__DIR__).'/api/campaign-core.php');
$steps=sf_automation_steps_validate([
 ['type'=>'agent_message','config'=>['message'=>'New Stonefellow release']],
 ['type'=>'wait','config'=>['hours'=>24]],
 ['type'=>'exit','config'=>[]]
]);
$checks=[
 'Canonical journey step validation works'=>$steps['ok']===true&&count($steps['steps'])===3,
 'Unapproved/unknown automation steps rejected'=>sf_automation_steps_validate([['type'=>'purchase','config'=>[]]])['ok']===false,
 'Forced manual run cannot bypass published status'=>str_contains($source,"if(($"."automation['status']??'')!=='published')return false;")&&!str_contains($source,'if($force)return true'),
 'Paused journey will not execute via direct continuation'=>str_contains($source,"if(($"."a['status']??'')!=='published')return $"."run;"),
 'Waiting resumes restricted to published automations'=>str_contains($source,"JOIN lifecycle_automations a ON a.id=r.automation_id WHERE r.status='waiting' AND a.status='published'"),
 'Waiting run requires an atomic status claim'=>str_contains($source,'$claim->rowCount()!==1'),
 'Automation exceptions not returned as raw diagnostic strings'=>!str_contains($source,"['error'=>$"."e->getMessage()]")&&!str_contains($source,"sf_auto_json(['error'=>$"."e->getMessage()])"),
 'CRM event integration preserved'=>str_contains($crm,'sf_automation_process_crm_event('),
 'Campaign attribution integration preserved'=>str_contains($campaign,'sf_campaign_attribute_order_from_session('),
 'Existing lifecycle deduplication preserved'=>str_contains($source,'dedupe_key TEXT NOT NULL UNIQUE')
];
$failed=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;}
exit($failed?1:0);
