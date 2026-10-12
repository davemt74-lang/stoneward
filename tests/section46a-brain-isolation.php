<?php
declare(strict_types=1);
/** Section 46A: customer Brain privacy / Admin Brain operational visibility. */
$root=dirname(__DIR__);$api=(string)file_get_contents($root.'/admin/api/agent-brain.php');
$admin=(string)file_get_contents($root.'/admin/assets/admin.js');
$customer=(string)file_get_contents($root.'/api/agent-runtime.php');
$privacy=(string)file_get_contents($root.'/api/privacy-core.php');
$checks=[
 'Admin Brain requires authenticated administrator'=>str_contains($api,'sf_admin_require_auth($write)'),
 'Operational query selects explicit safe fields'=>str_contains($api,'SELECT b.id,b.user_id,b.phase')&&!str_contains($api,'b.conversation_id,b.phase'),
 'No select-all on personal Agent decisions'=>!str_contains($api,'SELECT b.*,u.email'),
 'Admin search cannot query private conversation content'=>!str_contains($api,'b.request_excerpt LIKE')&&!str_contains($api,'b.response_excerpt LIKE'),
 'Admin Brain cannot return private conversation snippets'=>!str_contains($api,'b.request_excerpt,b.response_excerpt')&&!str_contains($api,'b.details_json'),
 'Admin Brain never queries fan email addresses'=>!str_contains($api,'u.email')&&!str_contains($api,'c.email'),
 'Admin engagement list omits individual message text'=>!str_contains($api,'e.message_text')&&!str_contains($api,'e.reason'),
 'Admin cannot clear every customers Brain'=>!str_contains($api,"DELETE FROM agent_brain_decisions")&&str_contains($api,"'error'=>'not_allowed'"),
 'Admin canvas displays pseudonymous customer IDs'=>str_contains($admin,"'Customer #'+r.user_id"),
 'Admin canvas excludes customer conversation text'=>!str_contains($admin,'r.request_excerpt||')&&!str_contains($admin,'r.response_excerpt'),
 'Admin canvas has no global customer Brain wipe button'=>!str_contains($admin,'id="clearBrain"'),
 'Customer conversation reads require user_id'=>str_contains($customer,'WHERE conversation_id=? AND user_id=?'),
 'Customer Brain decisions tied to authenticated user'=>str_contains($customer,'sf_agent_brain_log($userId'),
 'Customer privacy clears only own Brain records'=>str_contains($privacy,'DELETE FROM agent_brain_decisions WHERE user_id=?'),
];
$bad=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$bad++;}
exit($bad?1:0);
