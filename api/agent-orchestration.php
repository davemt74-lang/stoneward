<?php
declare(strict_types=1);
require_once __DIR__.'/agent-skill-registry.php';
/**
 * Shared server-side capability contract used by both distinct Agent Brains.
 * The skill inventory provides names/intents, but never execution authority.
 * Only existing authenticated handlers can perform a reviewed action.
 */
function sf_agent_orchestration_contract(string $scope,string $intent): array {
    $skill=sf_agent_skill_for_intent($scope,$intent);
    $toolId=$skill['tool_id']??null;
    // Historical operational audit view is an internal navigation intent:
    // it must never become a discoverable or executable skill.
    if($scope==='admin' && $intent==='review_actions')$toolId='agent_brain_log';
    $registered=$toolId!==null?(sf_agent_tool_registry()[$toolId]??null):null;
    $role=sf_agent_skill_context_role($scope)??'guest';
    $discovery=$skill!==null && $registered && sf_agent_tool_allowed($toolId,$role,false);
    $proposal=$skill!==null && $registered
       && (bool)$skill['may_prepare_reviewed_proposal']
       && sf_agent_tool_executable($toolId,$role,true);
    return [
      'scope'=>$scope,'intent'=>$intent,'skill_id'=>$skill['id']??null,'tool_id'=>$toolId,
      'registered'=>(bool)$registered,
      'may_read_or_navigate'=>(bool)$discovery,
      'may_prepare_reviewed_proposal'=>(bool)$proposal,
      'requires_exact_approval'=>(bool)($registered['confirmation']??false),
      'may_execute_directly'=>false,
      'execution_location'=>$registered['execution']??'none'
    ];
}
