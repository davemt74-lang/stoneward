<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';
sf_admin_require_auth(false);

$catalog=sf_catalog();
$templates=sf_admin_templates();
$orders=sf_admin_orders();
$pod=sf_admin_pod_handoffs();
$media=sf_admin_media_index();
$releasesRows=sf_admin_releases();
$knowledge=sf_admin_knowledge_index();

$missingIsrc=0;
$missingWriter=0;
$releases=[];
foreach($catalog as $t){
    $m=$t['metadata']??[];
    if(empty($m['isrc']))$missingIsrc++;
    if(empty($m['words_by'])&&empty($m['music_by']))$missingWriter++;
    $r=(string)($t['release']??'Unreleased');
    $releases[$r]=($releases[$r]??0)+1;
}

$imports=[];
foreach(glob(SF_ROOT.'/storage/imports/*/finalized.json')?:[] as $path){
    $d=json_decode((string)file_get_contents($path),true);
    if(is_array($d))$imports[]=$d;
}
usort($imports,fn($a,$b)=>strcmp((string)($b['completed_at']??''),(string)($a['completed_at']??'')));

$pdo=sf_db();
sf_ops_ensure_schema();
sf_agent_brain_ensure_schema();

$recentUsers=$pdo->query(
    "SELECT id,email,display_name,role,status,created_at,last_login_at,email_verified_at
     FROM users
     ORDER BY created_at DESC
     LIMIT 8"
)->fetchAll();

$recentActivity=$pdo->query(
    "SELECT a.id,a.user_id,a.event_type,a.title,a.entity_type,a.entity_id,a.created_at,
            u.display_name,u.email
     FROM user_activity a
     LEFT JOIN users u ON u.id=a.user_id
     ORDER BY a.id DESC
     LIMIT 12"
)->fetchAll();

$recentBrain=$pdo->query(
    "SELECT b.id,b.user_id,b.phase,b.route,b.action,b.context_profile,b.request_excerpt,
            b.response_excerpt,b.provider_status,b.input_tokens,b.output_tokens,b.created_at,
            u.display_name,u.email
     FROM agent_brain_decisions b
     LEFT JOIN users u ON u.id=b.user_id
     ORDER BY b.id DESC
     LIMIT 10"
)->fetchAll();

$trackMap=sf_track_map();
$recentListeningRows=$pdo->query(
    "SELECT l.id,l.user_id,l.track_id,l.event_type,l.position_seconds,l.duration_seconds,l.created_at,
            u.display_name,u.email
     FROM listening_events l
     LEFT JOIN users u ON u.id=l.user_id
     WHERE l.event_type IN ('start','complete','skip')
     ORDER BY l.id DESC
     LIMIT 12"
)->fetchAll();
$recentListening=[];
foreach($recentListeningRows as $row){
    $track=$trackMap[(string)$row['track_id']]??null;
    $row['track_title']=$track['title']??$row['track_id'];
    $row['track_release']=$track['release']??'';
    $recentListening[]=$row;
}

$listening=sf_engagement_analytics(30,null);
$notificationAnalytics=sf_notification_analytics(30,null);
$searchAnalytics=sf_search_analytics(30,null);
$libraryAnalytics=sf_library_admin_analytics(null);
$recentNotificationEvents=$pdo->query(
    "SELECT e.id,e.user_id,e.notification_id,e.event_type,e.created_at,n.kind,n.title,u.display_name,u.email
     FROM notification_events e
     LEFT JOIN user_notifications n ON n.id=e.notification_id
     LEFT JOIN users u ON u.id=e.user_id
     ORDER BY e.id DESC
     LIMIT 12"
)->fetchAll();
$recentSearchEvents=$pdo->query(
    "SELECT e.id,e.user_id,e.event_type,e.query_text,e.normalized_query,e.result_count,e.result_type,e.result_id,e.created_at,
            u.display_name,u.email
     FROM catalog_search_events e
     LEFT JOIN users u ON u.id=e.user_id
     ORDER BY e.id DESC
     LIMIT 12"
)->fetchAll();


$recentOrders=[];
foreach(array_slice($orders,0,8) as $o){
    $recentOrders[]=[
        'id'=>$o['id']??'',
        'user_id'=>(int)($o['user_id']??0),
        'status'=>$o['status']??'',
        'created_at'=>$o['created_at']??'',
        'total_cents'=>(int)($o['quote']['total_cents']??0),
        'currency'=>$o['quote']['currency']??'USD',
        'customer_name'=>$o['customer']['name']??'',
        'customer_email'=>$o['customer']['email']??'',
        'payment_method'=>$o['payment']['method']??'',
        'payment_status'=>$o['payment']['status']??'',
        'payment_provider'=>$o['payment']['provider']??'',
        'physical'=>!empty($o['quote']['physical']),
        'fulfillment_status'=>$o['fulfillment']['status']??'',
    ];
}

$cfg=sf_store_config();
$aiState=sf_ai_state();
$aiConfigured=count(array_filter($aiState['providers'],fn($provider)=>!empty($provider['key_configured'])));

sf_json_response([
    'ok'=>true,
    'stats'=>[
        'tracks'=>count($catalog),
        'templates'=>count($templates),
        'media_files'=>count($media['files']??[]),
        'orders'=>count($orders),
        'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'pod_handoffs'=>count($pod),
        'release_records'=>count($releasesRows),
        'knowledge_files'=>count($knowledge['files']??[]),
        'knowledge_folders'=>count($knowledge['folders']??[]),
        'missing_isrc'=>$missingIsrc,
        'missing_writer'=>$missingWriter,
        'ai_providers_configured'=>$aiConfigured,
        'listens_30d'=>(int)($listening['starts']??0),
        'completes_30d'=>(int)($listening['completes']??0),
        'listeners_30d'=>(int)($listening['listeners']??0),
        'listen_completion_30d'=>(float)($listening['completion_rate']??0),
        'listen_skip_30d'=>(float)($listening['skip_rate']??0),
        'repeat_starts_30d'=>(int)($listening['repeat_starts']??0),
        'favorites_30d'=>(int)($listening['favorites_added']??0),
        'playlists_30d'=>(int)($listening['playlists_created']??0),
        'builds_30d'=>(int)($listening['builds_created']??0),
        'paid_orders_30d'=>(int)($listening['paid_orders']??0),
        'purchase_conversion_30d'=>(float)($listening['conversion']['purchase_rate']??0),
        'custom_media_conversion_30d'=>(float)($listening['conversion']['custom_media_rate']??0),
        'revenue_30d_cents'=>(int)($listening['revenue_cents']??0),
        'notifications_30d'=>(int)($notificationAnalytics['delivered']??0),
        'notification_click_rate_30d'=>(float)($notificationAnalytics['click_rate']??0),
        'notification_listen_conversion_30d'=>(float)($notificationAnalytics['listen_conversion_rate']??0),
        'notification_purchase_conversion_30d'=>(float)($notificationAnalytics['purchase_conversion_rate']??0),
        'searches_30d'=>(int)($searchAnalytics['searches']??0),
        'search_click_rate_30d'=>(float)($searchAnalytics['click_through_rate']??0),
        'search_zero_result_rate_30d'=>(float)($searchAnalytics['zero_result_rate']??0),
        'library_users'=>(int)($libraryAnalytics['users_with_library']??0),
        'library_collections'=>(int)($libraryAnalytics['collections']??0),
        'library_saved_items'=>(int)(($libraryAnalytics['favorites']??0)+($libraryAnalytics['playlists']??0)+($libraryAnalytics['builds']??0)+($libraryAnalytics['purchases']??0)),
    ],
    'releases'=>$releases,
    'recent_imports'=>array_slice($imports,0,5),
    'recent_users'=>$recentUsers,
    'recent_orders'=>$recentOrders,
    'recent_activity'=>$recentActivity,
    'recent_listening'=>$recentListening,
    'recent_brain'=>$recentBrain,
    'notification_analytics'=>$notificationAnalytics,
    'recent_notification_events'=>$recentNotificationEvents,
    'search_analytics'=>$searchAnalytics,
    'recent_search_events'=>$recentSearchEvents,
    'library_analytics'=>$libraryAnalytics,
    'listening'=>[
        'days'=>30,
        'starts'=>(int)($listening['starts']??0),
        'completes'=>(int)($listening['completes']??0),
        'skips'=>(int)($listening['skips']??0),
        'sessions'=>(int)($listening['sessions']??0),
        'listeners'=>(int)($listening['listeners']??0),
        'repeat_starts'=>(int)($listening['repeat_starts']??0),
        'completion_rate'=>(float)($listening['completion_rate']??0),
        'skip_rate'=>(float)($listening['skip_rate']??0),
        'favorites_added'=>(int)($listening['favorites_added']??0),
        'playlists_created'=>(int)($listening['playlists_created']??0),
        'builds_created'=>(int)($listening['builds_created']??0),
        'paid_orders'=>(int)($listening['paid_orders']??0),
        'revenue_cents'=>(int)($listening['revenue_cents']??0),
        'conversion'=>(array)($listening['conversion']??[]),
        'sources'=>(array)($listening['sources']??[]),
        'tracks'=>array_slice((array)($listening['tracks']??[]),0,8),
        'daily'=>(array)($listening['daily']??[]),
    ],
    'store'=>[
        'mode'=>$cfg['mode']??'test',
        'payment_provider'=>$cfg['payment_provider']??'test',
        'pod_provider'=>$cfg['pod_provider']??'file_handoff',
    ],
    'site'=>sf_site_settings(),
    'csrf'=>sf_admin_csrf(),
    'php'=>[
        'upload_max_filesize'=>ini_get('upload_max_filesize'),
        'post_max_size'=>ini_get('post_max_size'),
        'max_file_uploads'=>ini_get('max_file_uploads'),
    ],
]);
