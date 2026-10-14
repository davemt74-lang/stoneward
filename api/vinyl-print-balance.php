<?php
declare(strict_types=1);
/** Authenticated customer's available/reserved vinyl rights; no other users,
 * payment details, asset paths or raw vendor job records are returned.
 */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
require_once __DIR__.'/vinyl-print-licenses.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$u=sf_require_user();$uid=(int)$u['id'];
try{
    $library=sf_account_library($uid);$sourceOrders=[];
    foreach($library as $entry){
        $id=(string)($entry['source_order_id']??'');
        if($id!==''&&!array_key_exists($id,$sourceOrders))$sourceOrders[$id]=sf_read_order($id);
    }
    $balances=sf_vinyl_license_balances($uid,$library,$sourceOrders,
        sf_kunaki_jobs_for_owner($uid));
    sf_json_response(['ok'=>true,'per_song'=>$balances,
        'rules'=>['digital_track_includes'=>1,'digital_album_includes_per_song'=>1,
            'printing_cost_per_song_per_record'=>1,
            'reserved_counts_against_available'=>true,
            'digital_playback_and_download_unchanged'=>true]]);
}catch(Throwable $e){
    error_log('Print license balance read failed: '.get_class($e));
    sf_json_response(['ok'=>false,'message'=>'Print license balance unavailable.'],503);
}
