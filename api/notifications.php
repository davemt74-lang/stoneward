<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];sf_ops_ensure_schema();sf_notification_ensure_schema();
if(!$write){
    $generation=sf_notification_generate_for_user($uid);
    sf_json_response(['ok'=>true,'unread'=>sf_notification_unread_count($uid),'notifications'=>sf_smart_user_notifications($uid),'preferences'=>sf_notification_preferences($uid),'generation'=>$generation,'history'=>sf_user_history($uid),'listening_preview'=>sf_personalization_history($uid,12),'brain'=>sf_user_brain_timeline($uid),'csrf'=>sf_user_csrf()]);
}
$b=sf_request_json();$action=(string)($b['action']??'');
if($action==='read'){
    $id=(int)($b['id']??0);sf_json_response(['ok'=>true,'unread'=>sf_notification_mark_read($uid,$id)]);
}
if($action==='read_all'){
    $q=sf_db()->prepare('SELECT id FROM user_notifications WHERE user_id=? AND is_read=0');$q->execute([$uid]);$ids=array_map('intval',array_column($q->fetchAll(),'id'));$now=gmdate('c');sf_db()->prepare('UPDATE user_notifications SET is_read=1,read_at=COALESCE(read_at,?) WHERE user_id=? AND is_read=0')->execute([$now,$uid]);foreach($ids as $id)sf_notification_event($uid,$id,'read');sf_json_response(['ok'=>true,'unread'=>0]);
}
if($action==='dismiss'){
    $id=(int)($b['id']??0);sf_json_response(['ok'=>true,'unread'=>sf_notification_dismiss($uid,$id)]);
}
if($action==='click'){
    $id=(int)($b['id']??0);try{$result=sf_notification_click($uid,$id);sf_json_response(['ok'=>true,...$result]);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],404);}
}
if($action==='preferences'){
    $prefs=is_array($b['preferences']??null)?$b['preferences']:[];sf_json_response(['ok'=>true,'preferences'=>sf_notification_preferences_save($uid,$prefs),'unread'=>sf_notification_unread_count($uid)]);
}
if($action==='refresh'){
    sf_json_response(['ok'=>true,'generation'=>sf_notification_generate_for_user($uid),'unread'=>sf_notification_unread_count($uid),'notifications'=>sf_smart_user_notifications($uid),'preferences'=>sf_notification_preferences($uid)]);
}
sf_json_response(['ok'=>false,'message'=>'Unsupported notification action.'],422);
