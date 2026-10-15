<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-link-core.php';
require_once __DIR__.'/homeserver-music-core.php';
require_once __DIR__.'/vinyl-print-licenses.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: private, no-store');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
try{
    $uid=sf_homeserver_bearer($auth,'fan.library.read');
    $items=sf_homeserver_user_library($uid);
    $library=sf_account_library($uid);$orders=[];
    foreach($library as $entry){$id=(string)($entry['source_order_id']??'');if($id!==''&&!array_key_exists($id,$orders))$orders[$id]=sf_read_order($id);}
    $balances=sf_vinyl_license_balances($uid,$library,$orders,sf_kunaki_jobs_for_owner($uid));
    $items=sf_hs_music_rights($items,$balances,sf_track_map());
    sf_json_response(['ok'=>true,'contract'=>'stonefellow.fan-music.v1','tracks'=>$items,
        'total'=>count($items),'audio_path'=>'/api/homeserver-audio.php',
        'audio_auth'=>'homeserver_proxy_only','license_checkout'=>'stonefellow_site_only',
        'account_scoped'=>true,'can_manage_purchases'=>false,
        'can_publish_kunaki'=>false,'can_manage_admin'=>false]);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'link_revoked_or_unauthorized'],401);
}
