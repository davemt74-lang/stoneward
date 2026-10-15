<?php
declare(strict_types=1);
/** Session-authorized Stonefellow purchased music and per-song vinyl rights. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-owned-music-core.php';
require_once __DIR__.'/vinyl-print-licenses.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
    sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$user=sf_require_user();$uid=(int)$user['id'];
try {
    $library=sf_account_library($uid);$orders=[];
    foreach($library as $entry){
        $id=(string)($entry['source_order_id']??'');
        if($id!==''&&!array_key_exists($id,$orders))$orders[$id]=sf_read_order($id);
    }
    $catalog=sf_track_map();
    $balances=sf_vinyl_license_balances($uid,$library,$orders,sf_kunaki_jobs_for_owner($uid));
    sf_json_response(['ok'=>true]+sf_fan_owned_project($uid,$library,$orders,$catalog,$balances));
}catch(Throwable $e){
    error_log('Stonefellow owned-music read failed: '.get_class($e));
    sf_json_response(['ok'=>false,'message'=>'Your purchased music is temporarily unavailable.'],503);
}
