<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../api/agent-skill-registry.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_json_response(['ok'=>true,'tools'=>sf_agent_tool_inventory('admin'),'skills'=>sf_agent_skill_inventory('admin'),'schema'=>'stonefellow.agent-tools.v1']);
