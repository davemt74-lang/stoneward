<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-campaign-sources.php';
$admin=sf_admin_require_auth(false);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_json_response(['ok'=>true,'sources'=>sf_agent_campaign_sources(),'source'=>'published_stonefellow_only']);
