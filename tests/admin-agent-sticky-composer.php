<?php
declare(strict_types=1);
/** Admin Agent sticky-footer consolidation acceptance. */
$root=dirname(__DIR__);
$get=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$html=$get('admin/index.php');
$admin=$get('admin/assets/admin.js');
$chat=$get('admin/assets/agent-canvas.js');
$css=$get('admin/assets/agent-canvas.css');
$siteCss=$get('admin/assets/admin.css');
$endpoint=$get('admin/api/agent-chat.php');
$checks=[
 'Exactly one Admin Agent composer form in the page'=>substr_count($html,'id="adminChatForm"')===1,
 'Only sticky footer contains the Admin composer'=>str_contains($html,'<footer class="admin-chat-footer"') && str_contains($html,'id="adminChatForm"'),
 'Duplicate middle-of-page form eliminated'=>!str_contains($chat,'adminAgentCanvasForm') && !str_contains($chat,'Ask Admin Agent<input'),
 'Sticky footer supports multiline typing'=>str_contains($html,'<textarea id="adminChatInput" rows="1" maxlength="4000"'),
 'Textarea and submit button are accessible'=>str_contains($html,'aria-label="Message your Admin Agent"') && str_contains($html,'aria-label="Send message"'),
 'Only one canonical footer send function'=>substr_count($admin,'window.SFAdminAgentCanvas.send(message)')===1,
 'Send uses existing authenticated Admin endpoint'=>str_contains($chat,"c.api('agent-chat.php',{message,conversation_id:conversationId})") && str_contains($endpoint,'sf_admin_require_auth($write)'),
 'Existing Admin Agent session remains authoritative'=>str_contains($chat,"stonefellow.admin.agent.thread") && str_contains($chat,"sessionStorage.setItem('stonefellow.admin.agent.thread',conversationId)"),
 'Open chat footer uses same controller from other screens'=>str_contains($admin,"$('#openAdminAgentChat').onclick=()=>openView('agent-chat')"),
 'Single route updates unified chat layout state'=>str_contains($admin,"adminApp.classList.toggle('admin-agent-chat-active',view==='agent-chat')"),
 'No second Agent executor or direct publish'=>!str_contains($chat,'sf_campaign_send_email_node(') && !str_contains($chat,'sf_agent_action_execute('),
 'Footer prevents default form double-submit'=>str_contains($admin,"form.addEventListener('submit',e=>{e.preventDefault();void submit();})"),
 'Send gate blocks simultaneous requests'=>str_contains($admin,'if(!message||pending)return;') && str_contains($chat,"if(busy)throw new Error("),
 'Request failure keeps draft for retry'=>str_contains($admin,'Message could not be sent. Your draft is still here.') && str_contains($admin,'if(input.value.trim()===message)input.value='),
 'Empty or unavailable Agent does not erase draft'=>str_contains($admin,'Admin Agent unavailable; your draft was preserved.'),
 'Pending send disables write controls'=>str_contains($admin,'sendButton.disabled=true;voice.disabled=true;input.readOnly=true;'),
 'Enter sends, Shift+Enter inserts newline'=>str_contains($admin,"if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing)") && str_contains($admin,'form.requestSubmit()'),
 'Voice remains integrated through footer'=>str_contains($admin,'recognition.onresult=') && str_contains($html,'id="adminVoiceButton"'),
 'Final speech recognition event only submits once'=>str_contains($admin,'if(final&&!voiceSubmitted)'),
 'Speech unsupported gracefully disables voice'=>str_contains($admin,"voice.title='Voice input is not supported by this browser'"),
 'New chat clears only the current conversation reference'=>str_contains($chat,"conversationId='';sessionStorage.removeItem('stonefellow.admin.agent.thread')"),
 'New chat focuses unified footer'=>str_contains($chat,"document.querySelector('#adminChatInput')?.focus()"),
 'Conversation history still loads from original API'=>str_contains($chat,'const get=await load()') && str_contains($chat,'items.map(messageHtml)'),
 'Document scroll automatically shows newest response'=>str_contains($chat,"window.scrollTo({top:document.documentElement.scrollHeight,behavior:'auto'})") && !str_contains($chat,'transcript.scrollTop=transcript.scrollHeight;'),
 'Conversation has a single vertical document scroller'=>str_contains($css,'.admin-app.admin-agent-chat-active .workspace{') && str_contains($css,'height:auto;') && str_contains($css,'.admin-agent-chat .admin-agent-transcript{overflow:visible'),
 'Layout reserves document space for sticky composer'=>str_contains($css,'.admin-app.admin-agent-chat-active .admin-canvas{') && str_contains($css,'padding:16px 0 156px') && str_contains($siteCss,'.admin-chat-footer{position:fixed'),
 'Old IDLE strip hidden on chat, not other Admin pages'=>str_contains($css,'.admin-app.admin-agent-chat-active .agent-strip{display:none}'),
 'Sticky footer remains visible on narrow screens'=>str_contains($siteCss,'.admin-chat-footer{left:0}') && str_contains($css,'@media(max-width:760px)'),
 'Sidebar navigation scrolls instead of being clipped'=>str_contains($siteCss,'.sidebar nav{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden'),
 'Agent Brain stays a separate slide-out right panel'=>str_contains($chat,"className='admin-brain-drawer'") && str_contains($css,'right:0;top:0;bottom:0'),
 'Existing Admin exact approval action stays in owner view'=>str_contains($chat,"SFAgentCampaigns?.open?.('newsletter')") && str_contains($chat,"SFAutomationsAdmin?.openAutomation?.(0)"),
 'No customer Brain data displayed'=>!str_contains($chat,'agent_customer_memories') && !str_contains($chat,'customer_private_messages'),
 'Deployment cache version retains the sticky composer release baseline'=>preg_match("/SF_ADMIN_BUILD','([0-9.]+)'/",$html,$buildCheck) && version_compare($buildCheck[1]??'0','1.3.47','>=') && str_contains($get('service-worker.js'),'stonefellow-static-v'.($buildCheck[1]??'')),
 'Database target includes additive library repair'=>(preg_match("/'database_schema_target'=>'([0-9.]+)'/",$get('version.php'),$schemaMatch) && version_compare($schemaMatch[1],'1.3.48','>=')),
];
$fail=0;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$fail++;}
echo 'Admin sticky composer: '.(count($checks)-$fail).'/'.count($checks).' checks passed'.PHP_EOL;
exit($fail?1:0);
