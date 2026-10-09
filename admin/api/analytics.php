<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$me=sf_admin_require_auth(false);sf_ops_ensure_schema();
$days=max(1,min(3650,(int)($_GET['days']??30)));$userId=max(0,(int)($_GET['user_id']??0));
$overall=sf_engagement_analytics($days,null);$notificationAnalytics=sf_notification_analytics($days,null);$searchAnalytics=sf_search_analytics($days,null);$libraryAnalytics=sf_library_admin_analytics(null);$users=(array)($overall['users']??[]);$selected=null;
if($userId>0){
    $q=sf_db()->prepare('SELECT id,email,display_name,role,status,created_at,last_login_at FROM users WHERE id=?');$q->execute([$userId]);$u=$q->fetch();
    if($u){
        $selected=[
            'user'=>$u,
            'analytics'=>sf_engagement_analytics($days,$userId),
            'notification_analytics'=>sf_notification_analytics($days,$userId),
            'search_analytics'=>sf_search_analytics($days,$userId),
            'library_analytics'=>sf_library_admin_analytics($userId),
            'library'=>sf_library_snapshot($userId),
            'history'=>sf_personalization_history($userId,120),
            'activity'=>sf_user_history($userId,120),
            'brain'=>sf_user_brain_timeline($userId,80),
            'favorites'=>sf_personalization_favorites($userId),
            'playlists'=>sf_playlist_list($userId),
            'builds'=>sf_account_saved_builds($userId),
            'orders'=>array_values(array_reverse(sf_analytics_order_rows(gmdate('c',time()-$days*86400),$userId))),
        ];
    }
}
sf_json_response(['ok'=>true,'days'=>$days,'overall'=>$overall,'notification_analytics'=>$notificationAnalytics,'search_analytics'=>$searchAnalytics,'library_analytics'=>$libraryAnalytics,'users'=>$users,'selected'=>$selected,'csrf'=>sf_admin_csrf()]);
