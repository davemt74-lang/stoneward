<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
require_once __DIR__.'/vinyl-print-licenses.php';
require_once __DIR__.'/kunaki-worker-auth.php';
require_once __DIR__.'/kunaki-media-transfer.php';
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$raw=file_get_contents('php://input');
if(!is_string($raw))sf_json_response(['ok'=>false,'error'=>'invalid_request'],400);
$secret=(string)getenv('STONEFELLOW_KUNAKI_WORKER_SECRET');
$expectedWorker=(string)getenv('STONEFELLOW_KUNAKI_WORKER_ID');
if(!preg_match('/^HS-[a-zA-Z0-9_-]{5,64}$/D',$expectedWorker))
    sf_json_response(['ok'=>false,'error'=>'worker_not_configured'],503);
try{
    $worker=sf_kunaki_worker_verify([
        'timestamp'=>$_SERVER['HTTP_X_SF_WORKER_TIMESTAMP']??'',
        'nonce'=>$_SERVER['HTTP_X_SF_WORKER_NONCE']??'',
        'signature'=>$_SERVER['HTTP_X_SF_WORKER_SIGNATURE']??'',
        'worker'=>$_SERVER['HTTP_X_SF_WORKER_ID']??''
    ],$raw,$secret,time());
    if(!hash_equals($expectedWorker,$worker))throw new RuntimeException('Wrong configured HomeServer identity.');
    sf_kunaki_worker_nonce_once((string)$_SERVER['HTTP_X_SF_WORKER_NONCE']);
}catch(Throwable $e){sf_json_response(['ok'=>false,'error'=>'worker_not_authorized'],401);}
$body=json_decode($raw,true);
if(!is_array($body))sf_json_response(['ok'=>false,'error'=>'invalid_json'],400);
try{
    switch((string)($body['operation']??'')){
        case 'claim':
            $job=sf_kunaki_job_claim($worker,'sf_vinyl_worker_job_allowed');
            sf_json_response(['ok'=>true,'job'=>$job,'next_poll_seconds'=>20]);
        case 'manifest':
            $job=sf_kunaki_media_lease_job((string)($body['job_id']??''),$worker,
                (string)($body['lease_token']??''));
            $order=sf_read_order((string)$job['order_id']);
            if(!is_array($order))throw new RuntimeException('Purchased record no longer exists.');
            $manifest=sf_kunaki_media_manifest($job,$order,sf_track_map(),$worker,
                (string)$body['lease_token'],$secret);
            sf_json_response(['ok'=>true,'manifest'=>$manifest]);
        case 'download':
            $job=sf_kunaki_media_lease_job((string)($body['job_id']??''),$worker,
                (string)($body['lease_token']??''));
            $order=sf_read_order((string)$job['order_id']);
            if(!is_array($order))throw new RuntimeException('Purchased record no longer exists.');
            $asset=sf_kunaki_media_granted_file($job,$order,sf_track_map(),$worker,
                (string)$body['lease_token'],(string)($body['grant']??''),$secret);
            $fp=@fopen($asset['local_path'],'rb');
            if(!$fp)throw new RuntimeException('Media disappeared before transfer.');
            header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: '.(int)$asset['size_bytes']);
            header('Content-Disposition: attachment; filename="stonefellow-'.$asset['asset_key'].'.'.$asset['extension'].'"');
            header('X-SF-Media-SHA256: '.$asset['sha256']);
            header('X-SF-Media-Bytes: '.(int)$asset['size_bytes']);
            while(!feof($fp)){
                $chunk=fread($fp,65536);
                if($chunk===false)break;
                echo $chunk;
            }
            fclose($fp);
            exit;
        case 'report':
            $job=sf_kunaki_job_report((string)($body['job_id']??''),$worker,
                (string)($body['lease_token']??''),(string)($body['state']??''));
            sf_json_response(['ok'=>true,'job_id'=>$job['job_id'],'state'=>$job['state']]);
        default:sf_json_response(['ok'=>false,'error'=>'unsupported_operation'],422);
    }
}catch(Throwable $e){
    error_log('Kunaki worker contract: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'worker_state_conflict'],409);
}
