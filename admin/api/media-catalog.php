<?php
declare(strict_types=1);
/** Media Library and folder-scan Catalog opt-in; private unless confirmed. */
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-catalog-intake.php';
if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
    $me=sf_admin_require_auth(false);
    header('Cache-Control: private, no-store');
    $rows=[];
    foreach(sf_agent_song_intake_list((int)$me['id']) as $draft){
        if(($draft['status']??'')==='published')continue;
        $rows[]=['id'=>$draft['id'],'request_key'=>$draft['request_key'],
            'payload_hash'=>$draft['payload_hash'],'title'=>$draft['title'],
            'filename'=>$draft['filename'],'status'=>$draft['status'],
            'template'=>$draft['template_metadata']['template']??null,
            'created_at'=>$draft['created_at']??'',
            'source_asset_id'=>(int)$draft['asset_id'],
            'has_separate_preview'=>!empty($draft['candidate_preview_asset_id'])];
    }
    sf_json_response(['ok'=>true,'drafts'=>$rows]);
}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth(true);
$owner=(int)$me['id'];
header('Cache-Control: private, no-store');
try{
    $body=sf_request_json();
    $action=(string)($body['action']??'');
    if($action==='publish_draft'){
        if(empty($body['confirmed']))throw new InvalidArgumentException('Explicit public publication confirmation is required.');
        $previewId=(int)($body['preview_asset_id']??0);
        $result=sf_media_catalog_publish_draft($owner,(string)($body['request_key']??''),
            (string)($body['expected_hash']??''),!empty($body['rights_confirmed']),
            $previewId>0?$previewId:null);
        if($result['changed'])sf_log_admin_action($owner,'media_catalog_published','track',
            $result['track_id'],['source'=>'folder_scan','preview_only'=>$result['preview_only']]);
        if($result['changed']&&function_exists('sf_agent_brain_log'))sf_agent_brain_log($owner,
            'admin_catalog','song_publish',['route'=>'catalog','action'=>'publish_song',
            'context_profile'=>'music','needs_llm'=>false,'requires_confirmation'=>true,
            'status'=>'completed','request'=>'Publish reviewed song',
            'response'=>'Published Catalog track '.$result['track_id']]);
        sf_json_response(['ok'=>true]+$result);
    }
    if($action!=='intake_asset')throw new InvalidArgumentException('Unknown media catalog action.');
    if(!empty($body['auto_publish'])&&(empty($body['confirmed'])||empty($body['rights_confirmed'])))
        throw new InvalidArgumentException('Auto-publish requires explicit public confirmation and rights approval.');
    $id=(int)($body['asset_id']??0);
    $asset=sf_media_catalog_authorized_asset(sf_media_asset($id),$owner);
    foreach(sf_agent_song_intake_list($owner) as $existing){
        if((int)($existing['asset_id']??0)!==$id)continue;
        if(($existing['status']??'')==='published')
            throw new RuntimeException('This song already has a public Catalog entry; open its Catalog record.');
        sf_json_response(['ok'=>true,'created'=>false,'publication'=>'needs_review',
            'publication_message'=>'This audio already has a private draft. Review that existing draft; no duplicate was created.',
            'draft'=>['request_key'=>$existing['request_key'],'payload_hash'=>$existing['payload_hash'],
                'status'=>$existing['status'],'title'=>$existing['title']]]);
    }
    $template=sf_admin_template((string)($body['template_id']??''));
    if(!$template)throw new InvalidArgumentException('Select an existing song metadata template.');
    $artworkId=(int)($body['artwork_asset_id']??0);
    $artwork=$artworkId>0?sf_media_catalog_authorized_asset(sf_media_asset($artworkId),$owner,'image'):null;
    $record=sf_media_catalog_intake_record($owner,$body,$asset,$template,$artwork);
    $result=sf_agent_song_intake_save($owner,$record);
    $draft=$result['draft'];
    if($result['created'])sf_log_admin_action($owner,'media_catalog_private_draft_created',
        'song_draft',(string)$draft['id'],['asset_id'=>$id,'template_id'=>$template['id']]);
    if($result['created']&&function_exists('sf_agent_brain_log'))sf_agent_brain_log($owner,
        'admin_catalog','song_private_intake',['route'=>'catalog','action'=>'save_private_song',
        'context_profile'=>'music','needs_llm'=>false,'requires_confirmation'=>false,
        'status'=>'completed','request'=>'Add audio to private Catalog',
        'response'=>'Persisted private draft '.$draft['id']]);
    $response=['ok'=>true,'created'=>$result['created'],
        'draft'=>['request_key'=>$draft['request_key'],'payload_hash'=>$draft['payload_hash'],
            'status'=>$draft['status'],'title'=>$draft['title']],
        'publication'=>'not_published'];
    if(!empty($body['auto_publish'])){
        try{
            $published=sf_media_catalog_publish_draft($owner,(string)$draft['request_key'],
                (string)$draft['payload_hash'],true);
            $response['publication']='published';$response['track_id']=$published['track_id'];
            $response['preview_only']=$published['preview_only']??false;
            if($published['changed'])sf_log_admin_action($owner,'media_catalog_published','track',
                $published['track_id'],['source'=>'media_library','preview_only'=>$published['preview_only']]);
            if($published['changed']&&function_exists('sf_agent_brain_log'))sf_agent_brain_log($owner,
                'admin_catalog','song_publish',['route'=>'catalog','action'=>'publish_song',
                'context_profile'=>'music','needs_llm'=>false,'requires_confirmation'=>true,
                'status'=>'completed','request'=>'Auto-publish private song',
                'response'=>'Published Catalog track '.$published['track_id']]);
        }catch(InvalidArgumentException|RuntimeException $e){
            // A failed preflight never hides or discards the successfully saved private draft.
            $response['publication']='needs_review';$response['publication_message']=$e->getMessage();
        }
    }
    sf_json_response($response);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}
