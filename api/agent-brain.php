<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-skill-registry.php';
require_once __DIR__.'/agent-mission-planner.php';
require_once __DIR__.'/agent-proactive-research.php';
require_once __DIR__.'/agent-specialist-delegation.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$user=sf_require_user(false,$write);
$userId=(int)$user['id'];
header('Cache-Control: no-store, private');
if(!$write){
    $snapshot=sf_agent_customer_brain_snapshot($userId);
    $snapshot['skill_inventory']=sf_agent_skill_inventory('customer');
    $snapshot['planned_missions']=sf_agent_mission_plan_recent('customer',$userId);
    $snapshot['specialist_assignments']=sf_agent_specialist_recent('customer',$userId);
    $snapshot['proactive_suggestion']=sf_agent_proactive_customer_suggestion($userId);
    $conversation=sf_clean_text($_GET['conversation_id']??'',80);
    if($conversation!==''&&preg_match('/^[A-Za-z0-9_-]{12,80}$/D',$conversation)){
        $snapshot['messages']=sf_agent_history($userId,$conversation,30);
    }
    sf_json_response(['ok'=>true,'brain'=>$snapshot]);
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$body=sf_request_json();$action=(string)($body['action']??'');
try{
    if($action==='remember'){
        $memory=sf_agent_customer_memory_save($userId,(string)($body['label']??''),(string)($body['text']??''));
        sf_json_response(['ok'=>true,'memory'=>$memory]);
    }
    if($action==='forget'){
        sf_json_response(['ok'=>true,'forgot'=>sf_agent_customer_memory_forget($userId,(string)($body['label']??''))]);
    }
    if($action==='clear_memories'){
        if(($body['confirmation']??'')!=='CLEAR')sf_json_response(['ok'=>false,'error'=>'confirmation_required'],422);
        sf_json_response(['ok'=>true,'cleared'=>sf_agent_customer_memory_clear($userId)]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_memory','message'=>'Check the memory label and text length.'],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'error'=>'memory_unavailable','message'=>'Your privacy settings or memory limits prevent this change.'],409);
}
