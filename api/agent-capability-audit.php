<?php
declare(strict_types=1);
/**
 * Section 47A: read-only inventory and authority audit of registered tools.
 * No execution endpoint is exposed; this file does not authorize actions.
 */
require_once __DIR__.'/agent-tool-registry.php';

/**
 * Accept an optional registry snapshot for adversarial contract tests.
 * Runtime visibility/authorization checks always use the live registry.
 */
function sf_agent_capability_audit(?array $snapshot=null): array {
    $tools=$snapshot??sf_agent_tool_registry();
    $report=[
        'registered'=>count($tools),
        'by_scope'=>[],
        'by_kind'=>[],
        'invalid_contracts'=>[],
        'unsafe_owner_paths'=>[],
        'missing_owner_files'=>[],
        'confirmation_violations'=>[],
        'role_visibility_violations'=>[],
        'unapproved_executors'=>[],
        'approved_proposal_executors'=>[],
    ];
    $required=['label','kind','scope','owner','handler','confirmation','execution'];
    $scopeTypes=['fan','signed_in_fan','admin','server'];
    $kindTypes=['read','write','consequential','metered','internal','local_action'];
    $executionTypes=['server','authenticated_api','browser','governed_admin_api','provider'];
    foreach($tools as $id=>$tool) {
        if(!is_string($id) || !preg_match('/\A[a-z][a-z0-9_]*\z/D',$id) || !is_array($tool)) {
            $report['invalid_contracts'][]=(string)$id;
            continue;
        }
        $missing=array_diff($required,array_keys($tool));
        if($missing) {
            $report['invalid_contracts'][]=$id;
            continue;
        }
        $scope=$tool['scope'];
        $kind=$tool['kind'];
        $owner=$tool['owner'];
        $handler=$tool['handler'];
        $execution=$tool['execution'];
        if(!is_string($scope) || !in_array($scope,$scopeTypes,true)
            || !is_string($kind) || !in_array($kind,$kindTypes,true)
            || !is_string($tool['label']) || trim($tool['label'])===''
            || !is_string($handler) || !preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D',$handler)
            || !is_string($execution) || !in_array($execution,$executionTypes,true)
            || !is_bool($tool['confirmation'])) {
            $report['invalid_contracts'][]=$id;
            continue;
        }
        $report['by_scope'][$scope]=($report['by_scope'][$scope]??0)+1;
        $report['by_kind'][$kind]=($report['by_kind'][$kind]??0)+1;

        // Resolve only repository-local source paths. Never probe arbitrary paths.
        if(!is_string($owner) || !preg_match('~\A(?:api/|admin/api/|assets/js/)[a-zA-Z0-9_./-]+\z~D',$owner)
            || str_contains($owner,'..') || str_contains($owner,'//')) {
            $report['unsafe_owner_paths'][]=$id;
        } elseif(!is_file(dirname(__DIR__).'/'.$owner)) {
            $report['missing_owner_files'][]=$id;
        }
        if(in_array($kind,['write','consequential'],true) && $tool['confirmation']!==true) {
            $report['confirmation_violations'][]=$id;
        }
        if($kind==='consequential' && $scope!=='admin') {
            $report['confirmation_violations'][]=$id.':scope';
        }
        if($scope==='server' && $kind!=='internal') {
            $report['invalid_contracts'][]=$id.':internal_scope';
        }
    }

    // Exercise the actual production visibility and authorization helpers,
    // not a duplicate role policy that might drift from them.
    if($snapshot===null) {
        $roleIds=[];
        foreach(['guest','fan','admin'] as $role) {
            $roleIds[$role]=array_column(sf_agent_tool_inventory($role),'id');
        }
        foreach($tools as $id=>$tool) {
            if(!is_array($tool) || !isset($tool['scope']))continue;
            foreach(['guest','fan','admin'] as $role) {
                $expected=($tool['scope']==='fan')
                    || ($tool['scope']==='signed_in_fan' && $role!=='guest')
                    || ($tool['scope']==='admin' && $role==='admin');
                $listed=in_array($id,$roleIds[$role],true);
                $allowed=sf_agent_tool_allowed((string)$id,$role,true);
                if($listed!==$expected || $allowed!==$expected) {
                    $report['role_visibility_violations'][]=$role.':'.$id;
                }
                if(sf_agent_tool_executable((string)$id,$role,false)) {
                    $report['unapproved_executors'][]=$role.':'.$id;
                }
                $executable=sf_agent_tool_executable((string)$id,$role,true);
                if($executable) {
                    if($role!=='admin' || !in_array($id,['campaign_draft_save','automation_draft_save','commerce_followup_add'],true)) {
                        $report['unapproved_executors'][]=$role.':'.$id.':confirmed';
                    } else {
                        $report['approved_proposal_executors'][]=$id;
                    }
                }
            }
        }
        sort($report['approved_proposal_executors']);
        if($report['approved_proposal_executors']!==['automation_draft_save','campaign_draft_save','commerce_followup_add']) {
            $report['unapproved_executors'][]='approved_proposal_allowlist_mismatch';
        }
        if(sf_agent_tool_allowed('unknown','admin',true)
            || sf_agent_tool_executable('unknown','admin',true)) {
            $report['unapproved_executors'][]='unknown_tool';
        }
    }
    $report['checks_passed']=$report['registered']>0;
    foreach(['invalid_contracts','unsafe_owner_paths','missing_owner_files','confirmation_violations','role_visibility_violations','unapproved_executors'] as $field) {
        if($report[$field])$report['checks_passed']=false;
    }
    return $report;
}
