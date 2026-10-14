<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
require_once __DIR__.'/kunaki-worker-auth.php';
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
            $job=sf_kunaki_job_claim($worker);
            sf_json_response(['ok'=>true,'job'=>$job,'next_poll_seconds'=>20]);
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
