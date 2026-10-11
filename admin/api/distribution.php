<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_distribution_ensure_schema();
if($method==='GET'){
    $releaseId=sf_clean_text($_GET['release_id']??'',160);
    if($releaseId!==''){$r=sf_distribution_record($releaseId);if(!$r)sf_json_response(['ok'=>false,'message'=>'Distribution release not found.'],404);sf_json_response(['ok'=>true,'release'=>sf_distribution_release($releaseId),'distribution'=>$r,'dashboard'=>sf_distribution_dashboard(),'csrf'=>sf_admin_csrf()]);}
    sf_json_response(['ok'=>true,'dashboard'=>sf_distribution_dashboard(),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');$releaseId=sf_clean_text($b['release_id']??'',160);
try{
    if($action==='save_metadata'){
        $r=sf_distribution_save_metadata($releaseId,(array)($b['metadata']??[]),(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'distribution_metadata_saved','release',$releaseId,['readiness'=>$r['readiness']]);
        sf_agent_brain_log((int)$me['id'],'admin_distribution','distribution_metadata',['route'=>'release_distribution','action'=>'save_metadata','context_profile'=>'release','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Update distribution metadata for '.$releaseId,'response'=>'Distribution metadata saved; readiness '.$r['readiness']['score'].'%.']);
        sf_json_response(['ok'=>true,'distribution'=>$r]);
    }
    if($action==='set_state'){
        $state=(string)($b['state']??'draft');$consequential=in_array($state,['submitted','scheduled','live'],true);if($consequential&&empty($b['confirmed']))throw new RuntimeException('Changing a release to '.$state.' requires explicit confirmation.');
        $r=sf_distribution_set_state($releaseId,$state,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'distribution_state_changed','release',$releaseId,['state'=>$state]);
        sf_agent_brain_log((int)$me['id'],'admin_distribution','distribution_state',['route'=>'release_distribution','action'=>'set_state','context_profile'=>'release','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Set release distribution state to '.$state,'response'=>'Distribution state is now '.$state.'.']);
        sf_json_response(['ok'=>true,'distribution'=>$r]);
    }
    if($action==='save_destination'){
        $status=(string)($b['destination']['status']??'not_started');$consequential=in_array($status,['submitted','scheduled','live'],true);if($consequential&&empty($b['confirmed']))throw new RuntimeException('Marking a DSP destination '.$status.' requires explicit confirmation.');
        $r=sf_distribution_save_destination($releaseId,(array)($b['destination']??[]),(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'distribution_destination_saved','release',$releaseId,['provider'=>$b['destination']['provider']??'','status'=>$status]);
        sf_agent_brain_log((int)$me['id'],'admin_distribution','distribution_destination',['route'=>'release_distribution','action'=>'save_destination','context_profile'=>'release','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Update DSP destination for '.$releaseId,'response'=>'Destination status saved as '.$status.'.']);
        sf_json_response(['ok'=>true,'distribution'=>$r]);
    }
    if($action==='sync_calendar'){
        $result=sf_distribution_sync_calendar($releaseId,(int)$me['id']);sf_log_admin_action((int)$me['id'],'distribution_calendar_synced','release',$releaseId,['plan_id'=>$result['plan']['id']??0,'added'=>$result['added'],'updated'=>$result['updated']]);sf_agent_brain_log((int)$me['id'],'admin_distribution','distribution_calendar',['route'=>'release_distribution','action'=>'sync_calendar','context_profile'=>'operations','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Sync distribution dates into Operating Calendar','response'=>'Distribution milestones synchronized without duplicate source dates.']);sf_json_response(['ok'=>true,'result'=>$result,'distribution'=>sf_distribution_record($releaseId)]);
    }
    if($action==='build_package'){
        $pkg=sf_distribution_build_package($releaseId,(int)$me['id']);sf_log_admin_action((int)$me['id'],'distribution_package_built','release',$releaseId,$pkg);sf_agent_brain_log((int)$me['id'],'admin_distribution','distribution_package',['route'=>'release_distribution','action'=>'build_package','context_profile'=>'release','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Build distributor-ready package for '.$releaseId,'response'=>'Distributor package built with SHA-256 '.$pkg['sha256'].'.']);sf_json_response(['ok'=>true,'package'=>$pkg,'distribution'=>sf_distribution_record($releaseId)]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported distribution action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
