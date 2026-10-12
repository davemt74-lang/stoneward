<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$ui=(string)file_get_contents($root.'/admin/assets/automations.js');
$brain=(string)file_get_contents($root.'/admin/assets/agent-actions.js');
$advice=(string)file_get_contents($root.'/admin/api/fan-journey-advice.php');
$checks=[
 'Existing Journey Intelligence tab retained'=>str_contains($ui,"['journey','Journey Intelligence']"),
 'Evidence-backed advice rendered in Journey Dashboard'=>str_contains($ui,'id="journeyRecommendations"'),
 'Advice uses authorized read-only admin endpoint'=>str_contains($ui,"api('fan-journey-advice.php?days='")&&str_contains($advice,'sf_admin_require_auth(false)'),
 'Advice does not auto execute changes'=>!str_contains($ui,'execute_automation_recommendation')&&str_contains($ui,'separate administrator action'),
 'Suggestions and evidence are escaped'=>str_contains($ui,'esc(item.title')&&str_contains($ui,'esc(item.evidence'),
 'Agent Brain timeline now includes journey saves'=>str_contains($brain,'data-agent-journey-id'),
 'Completed journey opens existing Automation Builder'=>str_contains($brain,'SFAutomationsAdmin?.openAutomation?.(id)'),
 'Campaign history retained'=>str_contains($brain,'data-agent-campaign-id'),
 'Interrupted journey requires manual review'=>str_contains($brain,'correct Campaign or Automation Builder'),
 'Measured time windows retained'=>str_contains($ui,'[7,30,90]')
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
