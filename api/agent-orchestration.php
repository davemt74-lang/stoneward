<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-registry.php';
/**
 * Shared server-side capability contract used by both distinct Agent Brains.
 * The client may display an action, but may never acquire authority by naming it.
 */
function sf_agent_orchestration_contract(string $scope,string $intent): array {
    $mapping=[
      'customer'=>[
        'public_catalog_search'=>'public_catalog_search',
        'favorite_save'=>'favorite_track',
        'favorite_remove'=>'favorite_track',
        'create_playlist'=>'create_playlist',
        'player_recommend'=>'recommend_public_track'
      ],
      'admin'=>[
        'crm_report'=>'crm_aggregate',
        'journey_report'=>'campaign_aggregate',
        'prepare_campaign'=>'campaign_draft_save',
        'prepare_journey'=>'automation_draft_save',
        'review_actions'=>'agent_brain_log'
      ]
    ];
    $toolId=$mapping[$scope][$intent]??null;
    $registered=$toolId!==null?(sf_agent_tool_registry()[$toolId]??null):null;
    $role=$scope==='admin'?'admin':($scope==='customer'?'fan':'guest');
    $discovery=$registered && sf_agent_tool_allowed($toolId,$role,false);
    $proposal=$registered && in_array($toolId,['campaign_draft_save','automation_draft_save'],true)
       && sf_agent_tool_executable($toolId,$role,true);
    // Internal audit entries are never customer-discoverable tools.
    if($toolId==='agent_brain_log'){$discovery=false;$proposal=false;}
    return [
      'scope'=>$scope,'intent'=>$intent,'tool_id'=>$toolId,
      'registered'=>(bool)$registered,
      'may_read_or_navigate'=>(bool)$discovery,
      'may_prepare_reviewed_proposal'=>(bool)$proposal,
      'requires_exact_approval'=>(bool)($registered['confirmation']??false),
      'may_execute_directly'=>false,
      'execution_location'=>$registered['execution']??'none'
    ];
}
