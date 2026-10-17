<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/band-member-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');$adminId=(int)$me['id'];
if($method==='GET'){
  $userId=(int)($_GET['user_id']??0);$details=null;
  if($userId>0)$details=sf_band_profile_private($userId,$adminId);
  $users=sf_db()->query("SELECT id,display_name FROM users WHERE status='active' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
  $people=[];foreach($users as $u)if(sf_band_is_member((int)$u['id']))$people[]=$u;
  sf_json_response(['ok'=>true,'band_members'=>$people,'profile'=>$details]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$id=(int)($b['user_id']??0);
try{
  $action=(string)($b['action']??'');
  if($action==='update')$result=['profile'=>sf_band_profile_update($id,$adminId,(array)($b['profile']??[]))];
  elseif($action==='publish'){
    if(empty($b['confirmed']))sf_json_response(['ok'=>false,'error'=>'confirmation_required'],422);
    $result=['profile'=>sf_band_profile_publish($id,$adminId,!empty($b['likeness_consent_confirmed']))];
  }elseif($action==='knowledge')$result=['entry_id'=>sf_band_knowledge_save($id,$adminId,(string)($b['title']??''),(string)($b['body']??''))];
  elseif($action==='approve_knowledge'){
    if(empty($b['confirmed']))sf_json_response(['ok'=>false,'error'=>'confirmation_required'],422);
    sf_band_knowledge_approve((int)($b['knowledge_id']??0),$adminId);$result=[];
  }else sf_json_response(['ok'=>false,'error'=>'invalid_action'],422);
  sf_json_response(['ok'=>true]+$result);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'permission_denied'],403);}
