<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=(string)file_get_contents($root.'/stonefellow-v120.php');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$ui=(string)file_get_contents($root.'/assets/js/agent-chat-canvas.js');
$brain=(string)file_get_contents($root.'/assets/js/agent-brain.js');
$api=(string)file_get_contents($root.'/api/agent-brain.php');
$checks=[
 'Private Agent Chat menu entry'=>str_contains($php,'id="menuAgentChat"'),
 'Chat canvas module loads before primary app'=>strpos($php,'assets/js/agent-chat-canvas.js')<strpos($php,'assets/js/app.js'),
 'Existing app router includes chat canvas'=>str_contains($app,"view==='agent-chat'")&&str_contains($app,'renderMyAgentConversation()'),
 'Only authenticated customers enter chat canvas'=>str_contains($app,'await loadAuth(true)')&&str_contains($app,'if(!st.auth.authenticated){renderLogin();return;}'),
 'Conversation viewer uses same authenticated Brain API'=>str_contains($ui,"api('agent-brain.php?conversation_id='"),
 'Conversations preserve chosen ID'=>str_contains($app,"localStorage.setItem('stonefellow.agent.conversation',cid)"),
 'New chat clears old context'=>str_contains($ui,"conversationId('')"),
 'Private transcript safely escaped'=>str_contains($ui,'esc(m.content'),
 'Conversation uses existing Agent action execution'=>str_contains($app,'send:liveAgent'),
 'Conversation full message appears in canvas log'=>str_contains($ui,'role="log"'),
 'Canvas form awaits response'=>str_contains($ui,'await send(value)'),
 'My Brain resume targets chat canvas'=>str_contains($brain,"navigate('agent-chat')"),
 'Customer API scoped to authenticated user'=>str_contains($api,'sf_agent_history($userId,$conversation,30)'),
 'No Admin Agent history in customer canvas'=>!str_contains($ui,'admin/api/'),
 'Player not replaced or independently recreated'=>!str_contains($ui,'new Audio(')
];
$errors=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$errors++;}
exit($errors?1:0);
