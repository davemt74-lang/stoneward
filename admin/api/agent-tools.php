<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../api/agent-skill-registry.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$response=['ok'=>true,'tools'=>sf_agent_tool_inventory('admin'),'skills'=>sf_agent_skill_inventory('admin'),'schema'=>'stonefellow.agent-tools.v1'];
// Section 91B1: an authenticated, opt-in read-only diagnostic. Do not imply
// that a discovered tool or module is a live database read or approved write.
if(($_GET['audit']??'')==='1'){
    require_once SF_ROOT.'/api/agent-admin-brain.php';
    require_once SF_ROOT.'/api/agent-integration-coverage.php';
    $response['integration_coverage']=sf_agent_integration_coverage(sf_admin_agent_module_routes());
}
sf_json_response($response);
