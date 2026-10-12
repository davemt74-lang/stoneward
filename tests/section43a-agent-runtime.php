<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$chat=(string)file_get_contents($root.'/api/agent-chat.php');
$voice=(string)file_get_contents($root.'/api/agent-voice.php');
$runtime=(string)file_get_contents($root.'/api/agent-runtime.php');
$ai=(string)file_get_contents($root.'/api/ai.php');
$checks=[
 'chat authenticated'=>str_contains($chat,'sf_require_user(false,true)'),
 'chat quota failure retained'=>str_contains($chat,'active_tokens_required'),
 'chat raw exceptions not returned'=>!str_contains($chat,"'message'=>\$e->getMessage()"),
 'chat unknown failures generic'=>str_contains($chat,'catch(Throwable $e)'),
 'voice authenticated'=>str_contains($voice,'sf_require_user(false,true)'),
 'voice quota enforced'=>str_contains($voice,'sf_can_use_ai('),
 'voice raw exceptions redacted'=>!str_contains($voice,'$e->getMessage()'),
 'LLM candidates provider routing exists'=>str_contains($ai,'function sf_ai_llm_candidates'),
 'Agent local route exists'=>str_contains($runtime,'function sf_agent_local_route'),
 'Agent action policy exists'=>str_contains($runtime,'function sf_agent_policy'),
 'Agent context exists'=>str_contains($runtime,'function sf_agent_context'),
 'Agent Brain trail exists'=>str_contains($runtime,'sf_agent_brain_log(')
];
foreach($checks as $label=>$passed)echo ($passed?'PASS':'FAIL').': '.$label.PHP_EOL;
if(in_array(false,$checks,true))exit(1);
