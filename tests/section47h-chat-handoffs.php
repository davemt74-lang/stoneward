<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$customer=(string)file_get_contents($root.'/assets/js/agent-chat-canvas.js');
$admin=(string)file_get_contents($root.'/admin/assets/agent-canvas.js');
$customerBrain=(string)file_get_contents($root.'/api/agent-brain.php');
$adminChat=(string)file_get_contents($root.'/admin/api/agent-chat.php');
$customerChat=(string)file_get_contents($root.'/api/agent-chat.php');
$adminActions=(string)file_get_contents($root.'/admin/api/agent-actions.php');
$checks=[
 'Customer chat uses authenticated owner Brain GET'=>str_contains($customer, "api('agent-brain.php") && str_contains($customerBrain,'sf_require_user(false,$write)'),
 'Customer Chat displays durable owner-scoped steps'=>str_contains($customer,'brain.planned_missions||[]') && str_contains($customer,'Recent governed mission steps'),
 'Customer steps advertise actual review requirement'=>str_contains($customer,"s.requires_exact_approval?' (approval required)'"),
 'Customer handoffs restricted to catalog and Library'=>str_contains($customer,"const safeStepRoute={public_catalog_search:'music',player_recommend:'music',create_playlist:'library',favorite_save:'library',favorite_remove:'library'}"),
 'Customer step controls are explicitly user initiated'=>str_contains($customer,'data-agent-navigate=') && str_contains($customer,"querySelectorAll('[data-agent-navigate]')"),
 'Customer library navigation resolves to supported playlist tab'=>str_contains($customer,"library:['library',{tab:'playlists'}]"),
 'Customer destination list does not contain Admin'=>!str_contains($customer,'data-admin-agent-action') && !str_contains($customer,"navigate('admin"),
 'Customer message text and mission IDs are escaped'=>str_contains($customer,'esc(m.content') && str_contains($customer,"esc(String(p.id||'').slice(0,8))"),
 'Customer navigation never calls write endpoints'=>!str_contains($customer,"secureJsonPost(") && !str_contains($customer,"fetch('admin/"),
 'Customer retains existing Agent send function'=>str_contains($customer,'await send(value)') && str_contains($customer,'conversationId('),
 'Admin transcript displays durable plan statuses'=>str_contains($admin,'action.mission_plan.steps') && str_contains($admin,'Governed mission steps'),
 'Admin step buttons have strict route allowlist'=>str_contains($admin,"const safeStepAction={crm_report:'crm',journey_report:'automations',prepare_campaign:'campaigns',prepare_journey:'journey',commerce_report:'commerce-ops',prepare_commerce_followup:'commerce-ops'}"),
 'Admin stage handoff uses existing button wiring'=>str_contains($admin,'data-admin-agent-action=') && str_contains($admin,"bindActions(transcript)"),
 'Admin draft route still uses exact-review workbench'=>str_contains($admin,"SFAgentCampaigns?.open?.('newsletter')"),
 'Admin journey route still uses original builder'=>str_contains($admin,'SFAutomationsAdmin?.openAutomation?.(0)'),
 'Admin published/sent status cannot trigger an action'=>!str_contains($admin,"'campaign_send_email':'") && !str_contains($admin,"'campaign_publish':'"),
 'Admin read actions route to existing Admin views'=>str_contains($admin,"['crm','automations','analytics','commerce-ops'].includes(type)"),
 'Admin Chat still uses authenticated Admin endpoint'=>str_contains($adminChat,'sf_admin_require_auth($write)'),
 'Agent UI does not bypass governance by writing proposal data'=>!str_contains($admin,'sf_agent_action_execute(') && !str_contains($admin,'sf_campaign_send_email_node('),
 'Approved actions remain in dedicated Admin API'=>str_contains($adminActions,'sf_agent_action_approve(') && str_contains($adminActions,'sf_agent_action_execute('),
 'Customer runtime keeps owner-scoped chat endpoint'=>str_contains($customerChat,'sf_require_user(false,true)'),
 'No additional independent audio player or engine'=>!str_contains($customer,'new Audio(') && !str_contains($admin,'new Audio('),
];
$fail=0;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$fail++;}
echo '47H: '.(count($checks)-$fail).'/'.count($checks).' checks passed'.PHP_EOL;
exit($fail?1:0);
