<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-link-core.php';
require_once __DIR__.'/kunaki-publishing-jobs.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: private, no-store');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
try{
    $ownerId=sf_homeserver_bearer($auth,'admin.presence.read');
    $jobs=sf_kunaki_queue_operation(static function(array &$jobs):array {
        $out=[];foreach($jobs as $job){
            if(!is_array($job))continue;
            $state=(string)($job['state']??'unknown');
            $out[$state]=($out[$state]??0)+1;
        }
        return [$out,false];
    });
    sf_json_response(['ok'=>true,'contract'=>'stonefellow.admin-presence.v1',
        'admin_account_id'=>$ownerId,'publishing_job_counts'=>$jobs,
        'catalog_track_count'=>count(sf_track_map()),
        'operations'=>['catalog_read'=>true,'vinyl_queue_read'=>true,
            'browser_execution'=>false,'provider_publication'=>false,
            'financial_actions'=>false],'separate_fan_consent_required'=>true]);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'admin_scope_revoked_or_unauthorized'],401);
}
