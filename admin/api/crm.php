<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_crm_ensure_schema();
if($method==='GET'){
    $contactId=max(0,(int)($_GET['contact_id']??0));if($contactId)sf_json_response(['ok'=>true,'detail'=>sf_crm_admin_contact_detail($contactId),'summary'=>sf_crm_admin_summary()]);
    $q=sf_clean_text($_GET['q']??'',120);$posts=sf_db()->query("SELECT p.id,p.user_id,p.body_text,p.status,p.created_at,u.display_name,u.email FROM community_posts p JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 100")->fetchAll();
    $eng=sf_db()->query("SELECT e.id,e.user_id,e.channel,e.trigger_type,e.status,e.message_text,e.reason,e.created_at,c.display_name,c.email FROM fan_agent_engagements e JOIN fan_contacts c ON c.id=e.contact_id ORDER BY e.id DESC LIMIT 80")->fetchAll();
    sf_json_response(['ok'=>true,'summary'=>sf_crm_admin_summary(),'contacts'=>sf_crm_admin_contacts(300,$q),'community_posts'=>$posts,'agent_engagements'=>$eng]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='update_contact'){
        $id=(int)($b['id']??0);$c=sf_crm_contact_by_id($id);if(!$c)sf_json_response(['ok'=>false,'message'=>'Fan contact not found.'],404);
        $status=(string)($b['status']??$c['status']);if(!in_array($status,['lead','fan','customer','member','inactive'],true))throw new InvalidArgumentException('Invalid CRM stage.');
        $marketing=!empty($c['marketing_opt_in']);$auto=!empty($b['agent_auto_engage']);$tags=array_values(array_unique(array_filter(array_map(fn($x)=>sf_clean_text($x,60),(array)($b['tags']??[])))));
        $notes=sf_clean_text($b['notes']??'',4000);$now=gmdate('c');
        sf_db()->prepare('UPDATE fan_contacts SET status=?,marketing_opt_in=?,marketing_opt_in_at=CASE WHEN ?=1 THEN COALESCE(marketing_opt_in_at,?) ELSE marketing_opt_in_at END,unsubscribed_at=CASE WHEN ?=0 THEN ? ELSE NULL END,agent_auto_engage=?,tags_json=?,notes=?,updated_at=? WHERE id=?')->execute([$status,$marketing?1:0,$marketing?1:0,$now,$marketing?1:0,$now,$auto?1:0,sf_crm_json($tags),$notes,$now,$id]);
        sf_crm_log_event($id,!empty($c['user_id'])?(int)$c['user_id']:null,'admin_crm_update','Admin updated fan CRM profile','fan_contact',(string)$id,['status'=>$status,'marketing_opt_in_read_only'=>$marketing,'agent_auto_engage'=>$auto]);
        sf_log_admin_action((int)$me['id'],'fan_crm_updated','fan_contact',(string)$id,['status'=>$status]);sf_json_response(['ok'=>true,'detail'=>sf_crm_admin_contact_detail($id)]);
    }
    if($action==='moderate_post'){
        $id=(int)($b['id']??0);$status=(string)($b['status']??'hidden');if(!in_array($status,['published','hidden','deleted'],true))throw new InvalidArgumentException('Invalid community status.');
        sf_db()->prepare('UPDATE community_posts SET status=?,updated_at=? WHERE id=?')->execute([$status,gmdate('c'),$id]);sf_log_admin_action((int)$me['id'],'community_post_moderated','community_post',(string)$id,['status'=>$status]);sf_json_response(['ok'=>true]);
    }
    if($action==='send_agent_message'){
        $id=(int)($b['id']??0);$message=sf_clean_text($b['message']??'',800);$c=sf_crm_contact_by_id($id);if(!$c||empty($c['user_id']))throw new InvalidArgumentException('This fan does not have a linked Stonefellow account.');if($message==='')throw new InvalidArgumentException('Enter a message.');
        $uid=(int)$c['user_id'];sf_notify_user($uid,'agent','Stonefellow Agent',$message,'?view=home');$key='admin-agent:'.$id.':'.bin2hex(random_bytes(6));sf_crm_agent_log($id,$uid,'in_app','admin_outreach',$key,$message,'Administrator-approved Agent outreach',['admin_user_id'=>(int)$me['id']]);sf_log_admin_action((int)$me['id'],'fan_agent_message','fan_contact',(string)$id);sf_json_response(['ok'=>true]);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
