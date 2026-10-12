<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$php=(string)file_get_contents($root.'/stonefellow-v120.php');
$app=(string)file_get_contents($root.'/assets/js/app.js');
$brain=(string)file_get_contents($root.'/assets/js/agent-brain.js');
$api=(string)file_get_contents($root.'/api/agent-brain.php');
$checks=[
 'Customer account menu links to private Brain'=>str_contains($php,'id="menuAgentBrain"')&&str_contains($php,'data-view="agent-brain"'),
 'Customer Brain script loaded before router'=>strpos($php,'assets/js/agent-brain.js')<strpos($php,'assets/js/app.js'),
 'Existing AJAX canvas routes Agent Brain'=>str_contains($app,"view==='agent-brain'")&&str_contains($app,'renderMyAgentBrain()'),
 'Brain page requires signed-in fan'=>str_contains($app,'if(!st.auth.authenticated){renderLogin();return;}'),
 'Client provides no user_id override'=>!str_contains($brain,'user_id:'),
 'Private Brain data read from authenticated endpoint'=>str_contains($brain,"api('agent-brain.php')"),
 'Memory writes require authenticated CSRF helper'=>str_contains($brain,"post('agent-brain.php',{action:'remember'"),
 'Forget operation exists'=>str_contains($brain,"action:'forget'"),
 'Clearing memories requires exact CLEAR phrase'=>str_contains($brain,"action:'clear_memories',confirmation:'CLEAR'"),
 'Personalization opt-in governs input visibility'=>str_contains($brain,"brain.personalization_enabled?"),
 'Conversation resume is explicit user interaction'=>str_contains($brain,'chooseConversation(button.dataset.resume)'),
 'Chosen conversation persisted'=>str_contains($app,"localStorage.setItem('stonefellow.agent.conversation',cid)"),
 'Private text is escaped before rendering'=>str_contains($brain,'esc(m.memory_text)'),
 'Canvas includes owned Agent activity list'=>str_contains($brain,'brain.decisions||[]'),
 'Admin operational data not mixed into Customer UI'=>!str_contains($brain,'admin/api/'),
 'Auth backend prevents cross-customer selection'=>!str_contains($api,"$"."body['user_id']")
];
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
