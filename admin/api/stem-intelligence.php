<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-intelligence-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$admin=sf_admin_require_auth($method!=='GET');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try{
    if($method==='GET'){
        $id=(string)($_GET['track_id']??'');
        $project=sf_stem_project_read($id);
        if(!$project)throw new InvalidArgumentException('Import a private song project first.');
        sf_json_response(['ok'=>true,'summary'=>sf_stem_intelligence_summary($id,$project),
            'read_only'=>true]);
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Unsupported method.'],405);
    $payload=sf_request_json();
    if(($payload['action']??'')!=='save_measurements')
        throw new InvalidArgumentException('Unsupported per-stem analysis operation.');
    $id=(string)($payload['track_id']??'');
    // No LLM invocation occurs here. Data is a bounded browser-origin claim.
    $report=sf_stem_intelligence_save($id,$payload);
    sf_log_admin_action((int)$admin['id'],'stem_private_spectral_evidence','track',$id,
        ['project_revision'=>$report['project_revision'],'stem_count'=>count($report['tracks']),
        'provenance'=>'browser_measured_not_verified']);
    if(function_exists('sf_agent_brain_log'))
        sf_agent_brain_log((int)$admin['id'],'stem_studio','private_spectral_evidence',[
            'song_id'=>$id,'stem_count'=>count($report['tracks']),
            'project_revision'=>$report['project_revision'],
            'status'=>'review_only','permissions_changed'=>false]);
    sf_json_response(['ok'=>true,'summary'=>sf_stem_intelligence_summary($id,sf_stem_project_read($id))]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>'Project was updated or analysis could not be saved. Reload and rescan.'],409);
}catch(Throwable $e){
    error_log('Stem Intelligence: '.get_class($e).': '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Private stem analysis unavailable.'],500);
}
