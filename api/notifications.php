<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
$u=sf_require_user(false,($_SERVER['REQUEST_METHOD']??'GET')!=='GET');sf_ops_ensure_schema();$uid=(int)$u['id'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    sf_json_response(['ok'=>true,'unread'=>sf_notification_unread_count($uid),'notifications'=>sf_user_notifications($uid),'history'=>sf_user_history($uid),'listening_preview'=>sf_personalization_history($uid,12),'brain'=>sf_user_brain_timeline($uid),'csrf'=>sf_user_csrf()]);
}
$b=sf_request_json();$action=(string)($b['action']??'');
if($action==='read'){
    $id=(int)($b['id']??0);$q=sf_db()->prepare('UPDATE user_notifications SET is_read=1,read_at=? WHERE id=? AND user_id=?');$q->execute([gmdate('c'),$id,$uid]);sf_json_response(['ok'=>true,'unread'=>sf_notification_unread_count($uid)]);
}
if($action==='read_all'){
    $q=sf_db()->prepare('UPDATE user_notifications SET is_read=1,read_at=? WHERE user_id=? AND is_read=0');$q->execute([gmdate('c'),$uid]);sf_json_response(['ok'=>true,'unread'=>0]);
}
sf_json_response(['ok'=>false,'message'=>'Unsupported notification action.'],422);
