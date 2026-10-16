<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-alignment-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$admin=sf_admin_require_auth($method!=='GET');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try{
    $id=$method==='GET'?(string)($_GET['track_id']??''):'';
    if($method==='GET'){
        $project=sf_stem_project_read($id);
        if(!$project)throw new InvalidArgumentException('Private REAPER project not found.');
        sf_json_response(['ok'=>true,'analysis'=>sf_stem_alignment_summary($id,$project),'read_only'=>true]);
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Unsupported method.'],405);
    $request=sf_request_json();
    if(($request['action']??'')!=='save_alignment')throw new InvalidArgumentException('Unsupported timeline operation.');
    $id=(string)($request['track_id']??'');
    $saved=sf_stem_alignment_save($id,$request);
    sf_log_admin_action((int)$admin['id'],'stem_time_aligned_measurements','track',$id,[
        'revision'=>$saved['project_revision'],'mode'=>$saved['mode'],
        'source_tracks'=>count($saved['tracks']),'source'=>'browser_reported']);
    if(function_exists('sf_agent_brain_log'))
        sf_agent_brain_log((int)$admin['id'],'stem_studio','timed_source_analysis',[
            'song_id'=>$id,'revision'=>$saved['project_revision'],'mode'=>$saved['mode'],
            'status'=>'review_only','audio_shared_with_llm'=>false]);
    sf_json_response(['ok'=>true,'analysis'=>sf_stem_alignment_summary($id,sf_stem_project_read($id))]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>'Project changed or private analysis storage unavailable; reload and rescan.'],409);
}catch(Throwable $e){
    error_log('Stem time alignment: '.get_class($e).': '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Time-aligned analysis unavailable.'],500);
}
