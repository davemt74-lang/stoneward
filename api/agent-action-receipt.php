<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';

// The browser player, not the LLM or the action planner, reports the result.
// A receipt can replace only this user's matching, still-pending assistant row.
$u=sf_require_user(false,true);
$b=sf_request_json();
$conversationId=sf_clean_text($b['conversation_id']??'',80);
$messageId=(int)($b['message_id']??0);
$actionType=sf_clean_text($b['action_type']??'',60);
$result=sf_clean_text($b['text']??'',2000);
if(!preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$conversationId)||$messageId<=0||$result===''){
    sf_json_response(['ok'=>false,'message'=>'Invalid action receipt.'],422);
}
try{
    if(!sf_agent_apply_browser_action_receipt((int)$u['id'],$conversationId,$messageId,$actionType,$result)){
        sf_json_response(['ok'=>false,'message'=>'The action receipt is no longer pending.'],409);
    }
    sf_json_response(['ok'=>true]);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'message'=>'The action result could not be saved.'],503);
}
