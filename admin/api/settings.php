<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
sf_admin_require_auth($write);
if(!$write) sf_json_response(['ok'=>true,'site'=>sf_site_settings(),'csrf'=>sf_admin_csrf()]);
$body=sf_request_json();
if(($body['action']??'')!=='save_site') sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
$changes=[
    'splash_enabled'=>!empty($body['splash_enabled']),
    'artist_homepage_enabled'=>!empty($body['artist_homepage_enabled']),
    'homepage_template'=>$body['homepage_template']??'classic',
    'fan_community_enabled'=>!empty($body['fan_community_enabled']),
    'announcement_enabled'=>!empty($body['announcement_enabled']),
    'announcement_text'=>$body['announcement_text']??'',
    'maintenance_enabled'=>!empty($body['maintenance_enabled']),
    'maintenance_message'=>$body['maintenance_message']??'',
    'seo_title'=>$body['seo_title']??'',
    'seo_description'=>$body['seo_description']??'',
    'social_instagram'=>$body['social_instagram']??'',
    'social_youtube'=>$body['social_youtube']??'',
    'social_bandcamp'=>$body['social_bandcamp']??'',
    'social_spotify'=>$body['social_spotify']??'',
    'privacy_text'=>$body['privacy_text']??'',
    'terms_text'=>$body['terms_text']??'',
];
if(array_key_exists('orb_song_moments_enabled',$body)){
    if(!is_bool($body['orb_song_moments_enabled']))sf_json_response(['ok'=>false,'error'=>'invalid_orb_song_moments_setting','message'=>'Orb Song Moments must be on or off.'],422);
    $changes['orb_song_moments_enabled']=$body['orb_song_moments_enabled'];
}
$site=sf_site_settings_update($changes);
sf_log_admin_action((int)sf_current_user()['id'],'site_settings_updated','site','public',['splash'=>$site['splash_enabled'],'artist_homepage'=>$site['artist_homepage_enabled'],'homepage_template'=>$site['homepage_template'],'fan_community'=>$site['fan_community_enabled'],'orb_song_moments'=>$site['orb_song_moments_enabled'],'announcement'=>$site['announcement_enabled'],'maintenance'=>$site['maintenance_enabled']]);
sf_json_response(['ok'=>true,'site'=>$site,'csrf'=>sf_admin_csrf()]);
