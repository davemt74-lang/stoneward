<?php
declare(strict_types=1);
$src=(string)file_get_contents(dirname(__DIR__).'/api/crm-core.php');
$fn=substr($src,strpos($src,'function sf_crm_next_agent_engagement('));
$fn=substr($fn,0,strpos($fn,'function sf_crm_agent_context('));
$checks=[
 'Agent auto-engagement requires explicit opt-in'=>str_contains($fn,"empty($"."c['agent_auto_engage'])"),
 'Requires personalization permission'=>str_contains($fn,'sf_privacy_personalization_enabled($userId)'),
 'Honors listening-history switch'=>str_contains($fn,'$listenHistory&&$type'),
 'At most one delivered proactive event per day'=>str_contains($fn,'time()-24*3600'),
 'Discards stale behavioral triggers'=>str_contains($fn,'time()-7*86400'),
 'Deduplication remains before dispatch'=>str_contains($fn,'SELECT id FROM fan_agent_engagements WHERE dedupe_key=?'),
 'Reuses logged CRM engagement'=>str_contains($fn,'sf_crm_agent_log('),
];
foreach($checks as $name=>$ok)echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
