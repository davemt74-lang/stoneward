<?php
declare(strict_types=1);
/**
 * Section 47A: read-only audit of the existing tool inventory.
 * This report is intentionally not an executor and cannot grant authority.
 */
require_once __DIR__.'/agent-tool-registry.php';

function sf_agent_capability_audit(): array {
    $tools=sf_agent_tool_registry();
    $summary=['registered'=>count($tools),'by_scope'=>[],'by_kind'=>[],'missing_owner_files'=>[],'invalid_contracts'=>[],'direct_executors'=>[]];
    $ids=[];
    foreach($tools as $id=>$tool) {
        $ids[]=$id;
        $scope=(string)($tool['scope']??'');
        $kind=(string)($tool['kind']??'');
        $summary['by_scope'][$scope]=($summary['by_scope'][$scope]??0)+1;
        $summary['by_kind'][$kind]=($summary['by_kind'][$kind]??0)+1;
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',(string)$id)
            || !in_array($scope,['fan','signed_in_fan','admin','server'],true)
            || !in_array($kind,['read','write','consequential','metered','internal','local_action'],true)
            || empty($tool['handler']) || empty($tool['owner'])
            || !isset($tool['confirmation'],$tool['execution'])) {
            $summary['invalid_contracts'][]=$id;
        }
        $owner=dirname(__DIR__).'/'.ltrim((string)($tool['owner']??''),'/');
        if(!is_file($owner))$summary['missing_owner_files'][]=$id;
        foreach(['guest','fan','admin'] as $role) {
            if(sf_agent_tool_executable((string)$id,$role,false))$summary['direct_executors'][]=$role.':'.$id;
        }
    }
    $summary['unique_ids']=count($ids)===count(array_unique($ids));
    $summary['checks_passed']=$summary['unique_ids'] && !$summary['invalid_contracts'] && !$summary['missing_owner_files'] && !$summary['direct_executors'];
    return $summary;
}
