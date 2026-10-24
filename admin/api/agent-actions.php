<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-tool-registry.php';
function sf_admin_agent_action_audit(string $tool): array {
    return match($tool){
        'catalog_metadata_update'=>['agent_catalog','catalog_metadata'],
        'stem_automation_update'=>['agent_stem','stem_automation'],
        'commerce_followup_add'=>['agent_commerce','commerce_followup'],
        'automation_draft_save'=>['agent_campaign','journey_draft'],
        default=>['agent_campaign','campaign_draft']
    };
}
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
        if(!sf_agent_tool_executable($tool,'admin',true))sf_json_response(['ok'=>false,'error'=>'tool_not_allowed'],403);
        $draft=is_array($b['draft']??null)?$b['draft']:[];
        $proposal=sf_agent_action_prepare($uid,(string)($b['request_key']??''),$tool,$draft);
        [$scope,$route]=sf_admin_agent_action_audit($tool);
        sf_agent_brain_log($uid,$scope,'action_proposed',['route'=>$route,'action'=>$tool,'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'payload_hash'=>$proposal['payload_hash'],'requires_confirmation'=>true]);
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    if($action==='approve'){
        $proposal=sf_agent_action_approve($uid,(string)($b['proposal_id']??''),(string)($b['payload_hash']??''),(string)($b['typed_confirmation']??''));
        [$scope,$route]=sf_admin_agent_action_audit($proposal['tool_id']);
        sf_agent_brain_log($uid,$scope,'action_approved',['route'=>$route,'action'=>$proposal['tool_id'],'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'requires_confirmation'=>true]);
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
        $id=(string)($b['proposal_id']??'');
        $previous=sf_agent_action_get($uid,$id);
        if(!$previous)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
        $proposal=sf_agent_action_execute($uid,$id);
        // Replaying a completed request must not duplicate the Admin event trail.
        if($previous['status']!=='completed'){
            [$scope,$route]=sf_admin_agent_action_audit($proposal['tool_id']);
            $entityId=$proposal['result']['track_id']??($proposal['result']['order_id']??($proposal['result']['automation_id']??($proposal['result']['campaign_id']??null)));
            sf_agent_brain_log($uid,$scope,'action_result',['route'=>$route,'action'=>$proposal['tool_id'],'status'=>$proposal['status'],'proposal_id'=>$proposal['id'],'entity_id'=>$entityId,'requires_confirmation'=>true]);
            if($proposal['tool_id']==='stem_automation_update'){
                sf_log_admin_action($uid,'agent_stem_automation_saved','track',
                    (string)($proposal['result']['track_id']??''),
                    ['proposal_id'=>$proposal['id'],'revision'=>$proposal['result']['revision']??null]);
            }elseif($proposal['tool_id']==='catalog_metadata_update'){
                sf_log_admin_action($uid,'agent_catalog_metadata_updated','track',
                    (string)($proposal['result']['track_id']??''),
                    ['proposal_id'=>$proposal['id'],'changed_field'=>$proposal['result']['field']??'']);
            }else{
            $isJourney=$proposal['tool_id']==='automation_draft_save';
            $isCommerce=$proposal['tool_id']==='commerce_followup_add';
            sf_log_admin_action($uid,$isCommerce?'agent_commerce_followup_saved':($isJourney?'agent_journey_draft_saved':'agent_campaign_draft_saved'),$isCommerce?'order':($isJourney?'automation':'campaign'),
                (string)($isCommerce?($proposal['result']['order_id']??''):($isJourney?($proposal['result']['automation_id']??''):($proposal['result']['campaign_id']??''))),['proposal_id'=>$proposal['id']]);
            }
        }
        sf_json_response(['ok'=>true,'proposal'=>$proposal]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_request','message'=>'Review the proposed draft fields and confirmation.'],422);
}catch(Throwable $e){
    // Status can be inspected by proposal ID; failed/ambiguous work is never auto-retried.
    sf_json_response(['ok'=>false,'error'=>'review_required','message'=>'The proposed action could not be confirmed. Check the proposal status and the corresponding Admin editor before retrying.'],409);
}
