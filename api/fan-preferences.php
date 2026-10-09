<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$u=sf_require_user(false,$method!=='GET');$uid=(int)$u['id'];
if($method==='GET')sf_json_response(['ok'=>true,'fan_crm'=>sf_crm_newsletter_state_for_user($uid),'csrf'=>sf_user_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$c=sf_crm_sync_user($uid);if(!$c)sf_json_response(['ok'=>false,'message'=>'Fan profile unavailable.'],503);
$marketing=!empty($b['marketing_opt_in']);$auto=!empty($b['agent_auto_engage']);$was=!empty($c['marketing_opt_in']);$now=gmdate('c');
if($marketing&&!$was){sf_crm_newsletter_signup((string)$u['email'],(string)$u['display_name']);$c=sf_crm_sync_user($uid);}
elseif(!$marketing&&$was){sf_db()->prepare('UPDATE fan_contacts SET marketing_opt_in=0,unsubscribed_at=?,unsubscribe_token_hash=NULL,updated_at=? WHERE id=?')->execute([$now,$now,(int)$c['id']]);sf_crm_log_event((int)$c['id'],$uid,'newsletter_unsubscribe_account','Disabled newsletter email from account preferences','newsletter','',[]);}
sf_db()->prepare('UPDATE fan_contacts SET agent_auto_engage=?,updated_at=? WHERE id=?')->execute([$auto?1:0,$now,(int)$c['id']]);
sf_crm_log_event((int)$c['id'],$uid,'fan_preferences_updated','Updated fan communication preferences','fan_contact',(string)$c['id'],['marketing_opt_in'=>$marketing,'agent_auto_engage'=>$auto]);
sf_json_response(['ok'=>true,'fan_crm'=>sf_crm_newsletter_state_for_user($uid),'csrf'=>sf_user_csrf()]);
