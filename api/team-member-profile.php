<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/band-member-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_team_require('team.enter',$method!=='GET');$id=(int)$me['id'];
if(!sf_band_is_member($id))sf_json_response(['ok'=>false,'error'=>'band_member_role_required'],403);
if($method==='GET')sf_json_response(['ok'=>true,'profile'=>sf_band_profile_private($id,$id)]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
try{
  if(($b['action']??'')==='draft')$out=['profile'=>sf_band_profile_update($id,$id,(array)($b['profile']??[]))];
  elseif(($b['action']??'')==='knowledge')$out=['entry_id'=>sf_band_knowledge_save($id,$id,(string)($b['title']??''),(string)($b['body']??''))];
  else sf_json_response(['ok'=>false,'error'=>'invalid_action'],422);
  sf_json_response(['ok'=>true]+$out);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'forbidden'],403);}
