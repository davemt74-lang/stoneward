<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-orchestration.php';
require_once __DIR__.'/agent-mission-planner.php';
require_once __DIR__.'/agent-chat-errors.php';
$u=sf_require_user(false,true);
$b=sf_request_json();
$client=is_array($b['client_state']??null)?$b['client_state']:[];
$message=sf_agent_chat_message($b['message']??'',$client);
if($message==='')sf_json_response(['ok'=>false,'message'=>'Enter a message.'],422);
$cid=sf_clean_text($b['conversation_id']??'',80);
if($cid===''||!preg_match('/^[A-Za-z0-9_-]{12,80}$/',$cid))$cid='conv_'.bin2hex(random_bytes(12));
try{
    $r=sf_agent_reply($u,$cid,$message,$client);
    // A successful conversational reply must not turn into HTTP 503 when
    // optional mission/audit augmentation is unavailable after deployment.
    try{
        $missionPlan=sf_agent_mission_plan('customer',$message,(string)($r['decision']??''));
        if($missionPlan!==null)$r['mission_plan']=sf_agent_mission_plan_save('customer',(int)$u['id'],$cid,$message,$missionPlan);
    }catch(Throwable $ignored){error_log('Stonefellow agent-chat optional mission-plan unavailable');}
    try{$r['tool_contract']=sf_agent_orchestration_contract('customer',(string)($r['decision']??''));}
    catch(Throwable $ignored){error_log('Stonefellow agent-chat optional tool contract unavailable');}
    sf_json_response(['ok'=>true]+$r);
}catch(RuntimeException $e){
    if($e->getMessage()==='ACTIVE_TOKENS_REQUIRED')
        sf_json_response(['ok'=>false,'error'=>'active_tokens_required','message'=>'You are out of Active Tokens. Choose a plan or ask the administrator for a free token grant.'],402);
    sf_agent_chat_report_failure($e);
}catch(Throwable $e){
    sf_agent_chat_report_failure($e);
}
