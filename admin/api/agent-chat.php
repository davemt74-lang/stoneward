<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-admin-brain.php';
require_once SF_ROOT.'/api/fan-journey-intelligence.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$admin=sf_admin_require_auth($write);
$adminId=(int)$admin['id'];
header('Cache-Control: no-store, private');
if(!$write){
    $threadId=(string)($_GET['conversation_id']??'');
    $out=['ok'=>true,'brain'=>sf_admin_agent_brain_snapshot($adminId),'conversations'=>sf_admin_agent_thread_list($adminId)];
    if($threadId!=='')$out['messages']=sf_admin_agent_thread_messages($adminId,$threadId);
    sf_json_response($out);
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
try{
    $answer=sf_admin_agent_reply($adminId,(string)($b['conversation_id']??''),(string)($b['message']??''),is_array($b['client_state']??null)?$b['client_state']:[]);
    sf_json_response(['ok'=>true]+$answer);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_request','message'=>'Check the message or conversation.'],422);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'admin_agent_unavailable','message'=>'The Admin Agent is temporarily unavailable. No action was performed.'],503);
}
