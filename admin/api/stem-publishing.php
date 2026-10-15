<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stem-catalog-publishing.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
$me=sf_admin_require_auth($method!=='GET');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    if($method==='GET'){
        $id=(string)($_GET['track_id']??'');
        $p=sf_stem_project_read($id);
        if(!$p)throw new InvalidArgumentException('Song Stem Studio project not found.');
        sf_json_response(['ok'=>true,'status'=>sf_stem_86i_status($p),
            'media_publication'=>sf_track_media_publication_status($id)]);
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Method not permitted'],405);
    if(isset($_FILES['file'])){
        $id=(string)($_POST['track_id']??'');
        $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
        if($revision===false||$revision===null)throw new InvalidArgumentException('Exact project revision required.');
        $p=sf_stem_86i_stage($id,$revision,(array)$_FILES['file'],(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'stem_private_master_staged','track',$id,
            ['revision'=>$p['revision'],'sha256'=>$p['stem_publication']['sha256'],
             'public_preview_changed'=>false]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],
            'stem_studio','private_master_staged',['song_id'=>$id,'status'=>'staged',
             'asset_id'=>$p['stem_publication']['asset_id'],'publish'=>false]);
        sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($p),
            'status'=>sf_stem_86i_status($p)]);
    }
    $b=sf_request_json();$id=(string)($b['track_id']??'');
    $revision=$b['revision']??null;
    if(!is_int($revision))throw new InvalidArgumentException('Exact integer project revision required.');
    $action=(string)($b['action']??'');
    if($action==='publish_private_master'){
        $p=sf_stem_86i_publish($id,$revision,(string)($b['confirmation']??''),(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'stem_private_master_published','track',$id,
            ['revision'=>$p['revision'],'asset_id'=>$p['stem_publication']['asset_id'],
             'public_preview_changed'=>false,'licenses_changed'=>false]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],
            'stem_studio','private_master_published',['song_id'=>$id,
                'status'=>'published_private_master','requires_confirmation'=>true,
                'preview_unchanged'=>true,'license_entitlements_unchanged'=>true]);
    }elseif($action==='reconcile_private_master'){
        $p=sf_stem_86i_reconcile($id,$revision,(string)($b['confirmation']??''));
        sf_log_admin_action((int)$me['id'],'stem_private_master_reconciled','track',$id,
            ['revision'=>$p['revision'],'asset_id'=>$p['stem_publication']['asset_id']]);
    }else throw new InvalidArgumentException('Unsupported publishing operation.');
    sf_json_response(['ok'=>true,'project'=>sf_stem_project_with_assets($p),
        'status'=>sf_stem_86i_status($p),
        'media_publication'=>sf_track_media_publication_status($id)]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('Stem Catalog Publishing: '.get_class($e).': '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Publishing changed or was interrupted. Inspect the private Media Library and refresh; no automatic retry was made.'],409);
}
