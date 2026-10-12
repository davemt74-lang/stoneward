<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-tool-registry.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$admin=sf_admin_require_auth($method!=='GET');
$uid=(int)$admin['id'];
if($method==='GET'){
    $id=(string)($_GET['id']??'');
    if($id==='')sf_json_response(['ok'=>true,'proposals'=>sf_agent_action_recent($uid,30)]);
    $p=sf_agent_action_get($uid,$id);
    if(!$p)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
    sf_json_response(['ok'=>true,'proposal'=>$p]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='prepare'){
        $tool=(string)($b['tool_id']??'');
        if(!sf_agent_tool_allowed($tool,'admin',true))sf_json_response(['ok'=>false,'error'=>'tool_not_allowed'],403);
        $draft=is_array($b['draft']??null)?$b['draft']:[];
        $proposal=sf_agent_action_prepare($uid,(string)($b['request_key']??''),$tool,$draft);
        sf_agent_brain_log($uid,'agent_campaign','action_proposed',['route'=>'campaign_draft','action'=>$tool,'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'payload_hash'=>$proposal['payload_hash'],'requires_confirmation'=>true]);
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    if($action==='approve'){
        $proposal=sf_agent_action_approve($uid,(string)($b['proposal_id']??''),(string)($b['payload_hash']??''),(string)($b['typed_confirmation']??''));
        sf_agent_brain_log($uid,'agent_campaign','action_approved',['route'=>'campaign_draft','action'=>$proposal['tool_id'],'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'requires_confirmation'=>true]);
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    if($action==='cancel'||$action==='reconcile'){
        $id=(string)($b['proposal_id']??'');
        $typed=(string)($b['typed_confirmation']??'');
        $proposal=$action==='cancel'?sf_agent_action_cancel($uid,$id,$typed):sf_agent_action_reconcile($uid,$id,$typed);
        sf_agent_brain_log($uid,'agent_campaign','action_recovery',['route'=>'campaign_draft','action'=>$action,'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'requires_confirmation'=>true]);
        sf_log_admin_action($uid,'agent_action_'.$action,'agent_action',$proposal['id'],['status'=>$proposal['status']]);
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    if($action==='execute'){
        $proposal=sf_agent_action_execute($uid,(string)($b['proposal_id']??''));
        sf_agent_brain_log($uid,'agent_campaign','action_result',['route'=>'campaign_draft','action'=>$proposal['tool_id'],'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'campaign_id'=>$proposal['result']['campaign_id']??null,'requires_confirmation'=>true]);
        sf_log_admin_action($uid,'agent_campaign_draft_saved','campaign',(string)($proposal['result']['campaign_id']??''),['proposal_id'=>$proposal['id']]);
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_request','message'=>'Review the proposed campaign fields and confirmation.'],422);
}catch(Throwable $e){
    // Status can be inspected by proposal ID; failed/ambiguous work is never auto-retried.
    sf_json_response(['ok'=>false,'error'=>'review_required','message'=>'The proposed action could not be confirmed. Check the proposal status and Campaign Builder before retrying.'],409);
}
