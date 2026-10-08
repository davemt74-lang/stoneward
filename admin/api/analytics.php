<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$me=sf_admin_require_auth(false);sf_ops_ensure_schema();
$days=max(1,min(3650,(int)($_GET['days']??30)));$userId=max(0,(int)($_GET['user_id']??0));
$overall=sf_listening_analytics($days,null);$users=sf_listening_users($days);$selected=null;
if($userId>0){
    $q=sf_db()->prepare('SELECT id,email,display_name,role,status,created_at,last_login_at FROM users WHERE id=?');$q->execute([$userId]);$u=$q->fetch();
    if($u)$selected=['user'=>$u,'analytics'=>sf_listening_analytics($days,$userId),'history'=>sf_user_history($userId,100),'brain'=>sf_user_brain_timeline($userId,100)];
}
sf_json_response(['ok'=>true,'days'=>$days,'overall'=>$overall,'users'=>$users,'selected'=>$selected,'csrf'=>sf_admin_csrf()]);
