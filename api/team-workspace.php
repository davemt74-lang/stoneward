<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_team_require('team.enter');$id=(int)$me['id'];
sf_json_response(['ok'=>true,'workspace'=>[
 'user'=>['id'=>$id,'display_name'=>$me['display_name']],
 'roles'=>sf_team_roles($id),'permissions'=>sf_team_permissions($id),
 'roster'=>sf_team_roster($id),
 'timeline'=>sf_team_timeline($id,(int)($_GET['limit']??80),(int)($_GET['offset']??0)),
 'role_catalog'=>sf_team_role_catalog()
]]);
