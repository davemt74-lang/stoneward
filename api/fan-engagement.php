<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';require_once __DIR__.'/agent-runtime.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$u=sf_require_user(false,false);$engagement=sf_crm_next_agent_engagement((int)$u['id']);sf_json_response(['ok'=>true,'engagement'=>$engagement,'crm'=>sf_crm_newsletter_state_for_user((int)$u['id'])]);
