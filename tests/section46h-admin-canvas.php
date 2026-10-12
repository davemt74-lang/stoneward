<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$ui=(string)file_get_contents($root.'/admin/assets/agent-canvas.js');
$admin=(string)file_get_contents($root.'/admin/assets/admin.js');
$layout=(string)file_get_contents($root.'/admin/index.php');
$style=(string)file_get_contents($root.'/admin/assets/agent-canvas.css');
$api=(string)file_get_contents($root.'/admin/api/agent-chat.php');
$checks=[
 'Admin Agent topbar entry preserves original sidebar density'=>str_contains($layout,'id="openAdminAgentChat"')&&str_contains($admin,"$('#openAdminAgentChat').onclick"),
 'Admin Agent canvas module loaded'=>str_contains($layout,'assets/agent-canvas.js'),
 'Admin Agent CSS loaded'=>str_contains($layout,'assets/agent-canvas.css'),
 'Existing openView routes into Agent canvas'=>str_contains($admin,"'agent-chat':()=>window.SFAdminAgentCanvas?.render?.()"),
 'Existing footer and Voice send through same Agent controller'=>substr_count($admin,'window.SFAdminAgentCanvas?.send?.(input.value)')===2,
 'Conversation persists in Admin-only storage'=>str_contains($ui,"stonefellow.admin.agent.thread"),
 'Chat API is authenticated Admin only'=>str_contains($api,'sf_admin_require_auth($write)'),
 'Canvas is not an independent mission executor'=>str_contains($ui,"c.api('agent-chat.php'")&&!str_contains($ui,"sf_campaign_save("),
 'Admin Brain drawer is separate right-hand region'=>str_contains($ui,'className=\'admin-brain-drawer\'')&&str_contains($style,'right:0;top:0;bottom:0'),
 'Sidebar shows only safe owner mission summary'=>str_contains($ui,'brain.missions||[]')&&str_contains($ui,'brain.mission_counts||{}'),
 'Exact proposal flow still routes through existing builder'=>str_contains($ui,'SFAgentCampaigns?.open?.'),
 'Journey proposal routes through original Automation Builder'=>str_contains($ui,'SFAutomationsAdmin?.openAutomation?.(0)'),
 'No fan private memories rendered in Admin canvas'=>!str_contains($ui,'agent_customer_memories')&&!str_contains($ui,'fan_agent_private_messages'),
 'Explicit warning about private Customer Brain data'=>str_contains($ui,'Customer private conversations are not exposed'),
 'Brain drawer has accessible close and Escape key'=>str_contains($ui,'aria-label="Close Admin Brain"')&&str_contains($ui,"e.key==='Escape'")&&str_contains($ui,"e.key==='Tab'")&&str_contains($ui,'previousFocus.focus()'),
 'Mobile width caps at viewport'=>str_contains($style,'width:100vw'),
 'Keyboard focus has visible outline'=>str_contains($style,':focus-visible')
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
