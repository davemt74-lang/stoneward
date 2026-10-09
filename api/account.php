<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$u=sf_require_user();$uid=(int)$u['id'];$orders=[];
foreach(glob(SF_ROOT.'/storage/orders/*.json')?:[] as $p){
    $o=json_decode((string)file_get_contents($p),true);if(!is_array($o))continue;
    $orderUserId=(int)($o['user_id']??0);$email=strtolower((string)($o['customer']['email']??''));
    if($orderUserId===$uid||($orderUserId===0&&$email===strtolower((string)$u['email']))){
        $orders[]=['id'=>$o['id']??basename($p,'.json'),'status'=>$o['status']??'','created_at'=>$o['created_at']??'','total_cents'=>$o['quote']['total_cents']??0,'currency'=>$o['quote']['currency']??'USD','items'=>array_map(fn($x)=>['type'=>$x['type']??'','label'=>$x['label']??''],(array)($o['quote']['items']??[])),'fulfillment'=>['status'=>(string)($o['fulfillment']['status']??(!empty($o['quote']['physical'])?'pending':'not_required')),'carrier'=>(string)($o['fulfillment']['carrier']??''),'tracking_number'=>(string)($o['fulfillment']['tracking_number']??''),'tracking_url'=>(string)($o['fulfillment']['tracking_url']??'')]];
        // Backfill the account library from existing owned orders; this is idempotent.
        try{sf_account_library_add_order($uid,$o);}catch(Throwable $e){}
    }
}
usort($orders,fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at']));
$entitlement=sf_entitlement_state($uid);if(($u['role']??'')!=='admin'&&!$entitlement['subscription'])$entitlement=sf_enroll_user($uid,null);$lifecycle=sf_customer_lifecycle_state($uid);$invoices=sf_billing_invoices($uid);$billing=sf_billing_settings();
sf_json_response(['ok'=>true,'user'=>sf_user_public($u),'orders'=>$orders,'saved_builds'=>sf_account_saved_builds($uid),'library'=>sf_account_library($uid),'my_library'=>sf_library_snapshot($uid),'personalization'=>sf_personalization_state($uid),'playlists'=>sf_playlist_list($uid),'entitlement'=>$entitlement,'lifecycle'=>$lifecycle,'billing'=>['mode'=>$billing['mode'],'provider'=>$billing['provider'],'stripe_enabled'=>$billing['stripe_enabled']],'invoices'=>$invoices,'sessions'=>sf_auth_sessions($uid),'notification_preferences'=>sf_notification_preferences($uid),'csrf'=>sf_user_csrf()]);
