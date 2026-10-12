<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-tool-registry.php';
$all=sf_agent_tool_registry();$guest=sf_agent_tool_inventory('guest');$fan=sf_agent_tool_inventory('fan');$admin=sf_agent_tool_inventory('admin');$guestIds=array_column($guest,'id');$fanIds=array_column($fan,'id');$adminIds=array_column($admin,'id');
$checks=[
 'Tool IDs unique'=>count($all)===count(array_unique(array_keys($all))),
 'Each tool identifies owner and handler'=>array_reduce($all,fn($ok,$t)=>$ok&&!empty($t['owner'])&&!empty($t['handler']),true),
 'Guest cannot see admin tools'=>!in_array('campaign_aggregate',$guestIds,true),
 'Fan cannot see admin tools'=>!in_array('campaign_draft_save',$fanIds,true),
 'Admin sees campaign tools'=>in_array('campaign_graph_validate',$adminIds,true),
 'No approval-free sends'=>!sf_agent_tool_allowed('campaign_send_email','admin',false),
 'Approved sends still require admin'=>!sf_agent_tool_allowed('campaign_send_email','fan',true),
 'Admin confirmed send eligibility'=>sf_agent_tool_allowed('campaign_send_email','admin',true),
 'No approval-free saves'=>!sf_agent_tool_allowed('campaign_draft_save','admin',false),
 'Missing tool denied'=>!sf_agent_tool_allowed('unknown','admin',true),
 'Internal audit not exposed'=>!in_array('agent_brain_log',$adminIds,true),
 'Admin API requires authorization'=>str_contains(file_get_contents(dirname(__DIR__).'/admin/api/agent-tools.php'),'sf_admin_require_auth(false)'),
];
foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed=true;}
if(!empty($failed))exit(1);
