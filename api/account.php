<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-commerce-view.php';
$u=sf_require_user();$uid=(int)$u['id'];$orders=[];
foreach(glob(SF_ROOT.'/storage/orders/*.json')?:[] as $p){
    $o=json_decode((string)file_get_contents($p),true);if(!is_array($o))continue;
    $orderUserId=(int)($o['user_id']??0);$email=strtolower((string)($o['customer']['email']??''));
    if(sf_customer_commerce_owns($o,$u)){
        $orders[]=sf_customer_commerce_summary($o,$u);
        // Backfill the account library from existing owned orders; this is idempotent.
        try{sf_account_library_add_order($uid,$o);}catch(Throwable $e){}
    }
}
usort($orders,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
require_once __DIR__.'/vinyl-customer-status.php';
$vinylStatuses=sf_vinyl_customer_status($uid,(string)($u['email']??''),25);
$vinylByOrder=[];foreach($vinylStatuses as $production)$vinylByOrder[(string)$production['order_id']]=$production;
foreach($orders as &$order){$order['vinyl_production']=$vinylByOrder[(string)$order['id']]??null;}unset($order);
$entitlement=sf_entitlement_state($uid);if(($u['role']??'')!=='admin'&&!$entitlement['subscription'])$entitlement=sf_enroll_user($uid,null);$lifecycle=sf_customer_lifecycle_state($uid);$invoices=sf_billing_invoices($uid);$billing=sf_billing_settings();
sf_json_response(['ok'=>true,'user'=>sf_user_public($u),'orders'=>$orders,'saved_builds'=>sf_account_saved_builds($uid),'library'=>sf_account_library($uid),'my_library'=>sf_library_snapshot($uid),'personalization'=>sf_personalization_state($uid),'playlists'=>sf_playlist_list($uid),'entitlement'=>$entitlement,'lifecycle'=>$lifecycle,'billing'=>['mode'=>$billing['mode'],'provider'=>$billing['provider'],'stripe_enabled'=>$billing['stripe_enabled']],'invoices'=>$invoices,'sessions'=>sf_auth_sessions($uid),'notification_preferences'=>sf_notification_preferences($uid),'fan_crm'=>sf_crm_newsletter_state_for_user($uid),'privacy'=>sf_privacy_state($uid,$u),'csrf'=>sf_user_csrf()]);
