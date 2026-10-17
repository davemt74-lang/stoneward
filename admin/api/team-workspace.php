<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
if($method==='GET'){
  $userId=(int)($_GET['user_id']??0);
  sf_json_response(['ok'=>true,'roles'=>sf_team_role_catalog(),
    'assignments'=>$userId>0?sf_team_roles($userId):null,
    'roster'=>sf_team_roster((int)$me['id']),
    'timeline'=>sf_team_timeline((int)$me['id'],(int)($_GET['limit']??80),(int)($_GET['offset']??0)),
    'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
if(($b['action']??'')!=='assign_roles')sf_json_response(['ok'=>false,'error'=>'invalid_action'],422);
if(empty($b['confirmed'])||!is_array($b['roles']??null))
  sf_json_response(['ok'=>false,'error'=>'explicit_confirmation_required'],422);
try{$assigned=sf_team_assign_roles((int)($b['user_id']??0),$b['roles'],(int)$me['id']);
    sf_json_response(['ok'=>true,'roles'=>$assigned]);}
catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
