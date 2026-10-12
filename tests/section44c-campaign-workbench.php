<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$module=(string)file_get_contents($root.'/admin/assets/agent-actions.js');
$campaigns=(string)file_get_contents($root.'/admin/assets/campaigns.js');
$admin=(string)file_get_contents($root.'/admin/assets/admin.js');
$layout=(string)file_get_contents($root.'/admin/index.php');
$endpoint=(string)file_get_contents($root.'/admin/api/agent-actions.php');
$checks=[
 'Admin-only API authenticates writes'=>str_contains($endpoint,'sf_admin_require_auth($method!=='),
 'Template comes from canonical campaign builder'=>str_contains($module,'SFCampaignAdmin?.templateDraft'),
 'Agent opens campaigns workbench'=>str_contains($admin,'SFAgentCampaigns?.open?.'),
 'Campaign Builder has Agent draft entry'=>str_contains($campaigns,'agentCampaignDraft'),
 'Campaign Builder exposes native template recipes'=>str_contains($campaigns,'templateDraft:templateDraft'),
 'Module loaded after Campaign Builder'=>strpos($layout,'assets/agent-actions.js')>strpos($layout,'assets/campaigns.js'),
 'Prepare requires durable idempotency key'=>str_contains($module,'request_key:requestKey'),
 'Only exact approved proposal can execute'=>str_contains($module,"proposal.status!=='approved'"),
 'Explicit typed approval included'=>str_contains($module,'typed_confirmation:typed'),
 'Review binds exact content hash'=>str_contains($module,'payload_hash:proposal.payload_hash'),
 'After save directs to canonical Campaign Builder'=>str_contains($module,"openView('campaigns')"),
 'Copy states nothing sent'=>str_contains($module,'No campaign will be published'),
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
