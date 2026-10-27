<?php
declare(strict_types=1);
/**
 * Section 91B1: honest, read-only coverage of the EXISTING Agent contracts.
 * A listed tool or a navigation intent is not evidence of a connected data read
 * or an executable workflow. This audit does not grant new capabilities.
 */
require_once __DIR__.'/agent-capability-audit.php';
require_once __DIR__.'/agent-skill-registry.php';
require_once __DIR__.'/agent-orchestration.php';

/** @param array<string,array<string,mixed>> $moduleRoutes */
function sf_agent_integration_coverage(array $moduleRoutes): array {
    $registry=sf_agent_tool_registry();
    $definitions=sf_agent_skill_definitions();
    $base=sf_agent_capability_audit();
    $toolToSkills=[];
    $invalidSkillTools=[];
    foreach($definitions as $skillId=>$skill){
        $tool=(string)($skill['tool']??'');
        if(!isset($registry[$tool])){
            $invalidSkillTools[]=(string)$skillId;
            continue;
        }
        $toolToSkills[$tool][]=(string)$skillId;
    }
    $tools=[];$missingSkills=[];$discoveryOnly=[];$reviewed=[];
    foreach($registry as $id=>$def){
        $skills=$toolToSkills[$id]??[];
        if($def['scope']!=='server' && !$skills)$missingSkills[]=$id;
        $canPropose=sf_agent_tool_executable($id,'admin',true);
        $canDiscover=sf_agent_tool_allowed($id,'admin',false);
        $classification=match(true){
            $def['scope']==='server'=>'internal_only',
            $canPropose=>'reviewed_action_eligible',
            $def['kind']==='local_action'=>'browser_action',
            $def['kind']==='read'=>'read_contract',
            $def['kind']==='metered'=>'provider_contract',
            default=>'owner_workflow_only'
        };
        if($canPropose)$reviewed[]=$id;
        elseif($def['scope']==='admin' && $def['kind']!=='internal')$discoveryOnly[]=$id;
        $tools[]=[
            'id'=>$id,'scope'=>$def['scope'],'kind'=>$def['kind'],
            'classification'=>$classification,'skill_ids'=>$skills,
            'can_discover_without_approval'=>$canDiscover,
            'may_prepare_reviewed_proposal'=>$canPropose,
            'direct_agent_execution'=>false,
            'requires_confirmation'=>(bool)$def['confirmation']
        ];
    }
    $modules=[];$invalidModules=[];
    foreach($moduleRoutes as $intent=>$route){
        $contract=sf_agent_orchestration_contract('admin',(string)$intent);
        $valid=is_array($route) && isset($route['view'],$route['label'],$route['pattern'],$route['reply'])
            && $contract['registered'] && $contract['tool_id']==='admin_module_navigate'
            && $contract['may_read_or_navigate'] && !$contract['may_prepare_reviewed_proposal']
            && !$contract['may_execute_directly'];
        if(!$valid)$invalidModules[]=(string)$intent;
        $modules[]=[
            'intent'=>(string)$intent,
            'view'=>is_array($route)?(string)($route['view']??''):'',
            'label'=>is_array($route)?(string)($route['label']??''):'',
            'conversation_integration'=>'navigation_only',
            'record_read_verified'=>false,
            'record_write_verified'=>false
        ];
    }
    sort($missingSkills);sort($invalidSkillTools);sort($invalidModules);
    return [
        'schema'=>'stonefellow.agent-integration-coverage.v1',
        'scope'=>'stonefellow_only',
        'registry_passed'=>(bool)$base['checks_passed'],
        'registered_tools'=>count($registry),
        'registered_skills'=>count($definitions),
        'reviewed_action_eligible'=>$reviewed,
        'admin_discovery_without_agent_proposal'=>$discoveryOnly,
        'admin_navigation_only_count'=>count($modules),
        'tools'=>$tools,'admin_module_routes'=>$modules,
        'missing_skill_bindings'=>$missingSkills,
        'invalid_skill_bindings'=>$invalidSkillTools,
        'invalid_module_contracts'=>$invalidModules,
        'checks_passed'=>(bool)$base['checks_passed']
            && !$missingSkills && !$invalidSkillTools && !$invalidModules,
        'interpretation'=>'Registry coverage verifies declarations, role policy and navigation wiring only. '
            .'It does not establish database access, successful live execution or permission to call an owner endpoint.'
    ];
}
