<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-production-advisor.php';
$me=sf_admin_require_auth(false);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
        sf_json_response(['ok'=>false,'message'=>'Read-only mix inspection. Use governed Agent actions for changes.'],405);
    $id=(string)($_GET['track_id']??'');
    $snapshot=sf_stem_production_inspect($id);
    if($snapshot['status']==='not_imported')sf_json_response([
        'ok'=>true,'inspection'=>$snapshot,'read_only'=>true]);
    sf_json_response(['ok'=>true,'inspection'=>$snapshot,'read_only'=>true,
        'approval_tool'=>'stem_automation_update',
        'requires_manual_exact_hash_approval'=>true]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Stem Production Advisor: '.get_class($e).': '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Private production review is unavailable.'],500);
}
