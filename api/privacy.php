<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-conversion-intent-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$u=sf_require_user(false,$method!=='GET');$uid=(int)$u['id'];$full=sf_user_by_email((string)$u['email'])?:$u;sf_privacy_ensure_schema();
if($method==='GET')sf_json_response(['ok'=>true,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save_profile'){$profile=sf_privacy_save_profile($uid,(array)($b['profile']??[]));if(empty($profile['personalization_enabled']))sf_87d_intent_clear($uid);sf_json_response(['ok'=>true,'profile'=>$profile,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);}
  if($action==='clear_history'){if(empty($b['confirmed']))throw new RuntimeException('Clearing listening history requires confirmation.');$r=sf_personalization_clear_history($uid);sf_privacy_log($uid,'listening_history_cleared',['anonymized_events'=>$r['anonymized_events']??0]);sf_json_response(['ok'=>true,'result'=>$r,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);}
  if($action==='reset_personalization'){if(empty($b['confirmed']))throw new RuntimeException('Resetting personalization requires confirmation.');sf_87d_intent_clear($uid);$r=sf_privacy_reset_personalization($uid);sf_json_response(['ok'=>true,'result'=>$r,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);}
  if($action==='clear_search_activity'){if(empty($b['confirmed']))throw new RuntimeException('Clearing search activity requires confirmation.');$r=sf_privacy_clear_search_activity($uid);sf_json_response(['ok'=>true,'result'=>$r,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);}
  if($action==='clear_agent_history'){if(empty($b['confirmed']))throw new RuntimeException('Clearing Agent history requires confirmation.');$r=sf_privacy_clear_agent_history($uid);sf_json_response(['ok'=>true,'result'=>$r,'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()]);}
  if($action==='export'){if(empty($b['confirmed']))throw new RuntimeException('Data export requires confirmation.');$export=sf_privacy_export($uid,$full);sf_json_response(['ok'=>true,'filename'=>'stonefellow-account-export-'.gmdate('Ymd-His').'.json','export'=>$export,'csrf'=>sf_user_csrf()]);}
  if($action==='close_account'){if(empty($b['confirmed']))throw new RuntimeException('Account closure requires confirmation.');$r=sf_privacy_close_account($uid,$full,(string)($b['current_password']??''),(string)($b['confirmation']??''));sf_logout_user();sf_json_response(['ok'=>true,'closed'=>true,'result'=>$r,'authenticated'=>false,'user'=>null,'csrf'=>null]);}
  sf_json_response(['ok'=>false,'message'=>'Unsupported privacy action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage(),'privacy'=>sf_privacy_state($uid,$full),'csrf'=>sf_user_csrf()],422);}
