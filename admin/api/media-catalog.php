<?php
declare(strict_types=1);
/** Media Library and folder-scan Catalog opt-in; private unless confirmed. */
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-catalog-intake.php';
/** Publishing succeeded independently of optional history / Brain telemetry. */
function sf_media_catalog_record_success(int $owner,array $published,string $source):void {
    if(empty($published['changed']))return;
    try{sf_log_admin_action($owner,'media_catalog_published','track',(string)$published['track_id'],
        ['source'=>$source,'preview_only'=>!empty($published['preview_only'])]);}
    catch(Throwable $e){error_log('Catalog publish audit unavailable: '.$e->getMessage());}
    try{if(function_exists('sf_agent_brain_log'))sf_agent_brain_log($owner,'admin_catalog','song_publish',
        ['route'=>'catalog','action'=>'publish_song','context_profile'=>'music',
         'needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed',
         'request'=>'Publish reviewed song','response'=>'Published Catalog track '.$published['track_id']]);}
    catch(Throwable $e){error_log('Catalog publish Brain receipt unavailable: '.$e->getMessage());}
}
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
            'has_separate_preview'=>!empty($draft['candidate_preview_asset_id']),
            'digital_price'=>(float)($draft['template_metadata']['price']??0),
            'digital_purchase_enabled'=>!empty($draft['digital_purchase_enabled']),
            'vinyl_print_enabled'=>!empty($draft['pod_eligible']),
            'requires_listening_copy'=>sf_media_protected_master_asset((int)$draft['asset_id'])
                &&empty($draft['candidate_preview_asset_id']),
            'missing_fields'=>sf_media_catalog_missing_fields($draft),
            'review'=>array_intersect_key($draft,array_flip(['title','release','artist_display',
                'words_by','music_by','master_owner','composition_owner','genre','year','isrc',
                'release_date','explicit','pod_eligible']))];
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
        sf_media_catalog_record_success($owner,$result,'private_draft');
        sf_json_response(['ok'=>true]+$result);
    }
    if($action==='review_publish_draft'){
        if(empty($body['confirmed'])||empty($body['rights_confirmed']))
            throw new InvalidArgumentException('Confirm your publishing rights before publishing.');
        $key=(string)($body['request_key']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Invalid private song draft.');
        $revision=(string)($body['expected_hash']??'');
        $draft=null;
        foreach(sf_agent_song_intake_list($owner) as $item)
            if(($item['request_key']??'')===$key){$draft=$item;break;}
        if(!$draft||($draft['status']??'')!=='draft')
            throw new RuntimeException('This private draft is no longer editable. Refresh Catalog.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$revision)
            ||!hash_equals((string)$draft['payload_hash'],$revision))
            throw new RuntimeException('This draft changed since it was opened. Refresh before publishing.');
        $review=$body['review']??null;
        if(!is_array($review))throw new InvalidArgumentException('Song publication review is required.');
        $editable=['title','release','artist_display','words_by','music_by','master_owner',
            'composition_owner','genre','year','isrc','release_date','explicit','pod_eligible'];
        $fields=$draft;
        foreach($editable as $field)if(array_key_exists($field,$review))$fields[$field]=$review[$field];
        $fields['pod_eligible']=!empty($body['pod_eligible']);
        $fields['rights_confirmed']=true;
        $audio=sf_media_catalog_authorized_asset(sf_media_asset((int)$draft['asset_id']),$owner);
        $audio['created_by']=$owner; // Legacy site-owned audio is governed by this Admin.
        $artwork=null;
        if(!empty($draft['artwork_asset_id'])){
            $artwork=sf_media_catalog_authorized_asset(sf_media_asset((int)$draft['artwork_asset_id']),$owner,'image');
            $artwork['created_by']=$owner;
        }
        $normalized=sf_agent_song_intake_normalize($fields,$audio,$owner,$artwork);
        $normalized['digital_purchase_enabled']=!empty($body['digital_purchase_enabled']);
        $missing=sf_media_catalog_missing_fields($normalized);
        if($missing)throw new InvalidArgumentException('Complete the highlighted song details: '.implode(', ',$missing).'.');
        $saved=sf_agent_song_intake_update($owner,$normalized,$revision);
        $previewId=(int)($body['preview_asset_id']??0);
        $published=sf_media_catalog_publish_draft($owner,$key,(string)$saved['draft']['payload_hash'],true,
            $previewId>0?$previewId:null);
        sf_media_catalog_record_success($owner,$published,'catalog_review');
        sf_json_response(['ok'=>true]+$published);
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
    // A single-song upload should not require creating a metadata template.
    // The same reviewed writer and privacy guards still handle publication.
    if(!$template&&!empty($body['simple_upload'])&&empty($body['template_id']))
        $template=['id'=>'quick-song','name'=>'Single song upload','version'=>1,'defaults'=>[]];
    if(!$template)throw new InvalidArgumentException('Select an existing song metadata template.');
    $artworkId=(int)($body['artwork_asset_id']??0);
    $artwork=$artworkId>0?sf_media_catalog_authorized_asset(sf_media_asset($artworkId),$owner,'image'):null;
    $record=sf_media_catalog_intake_record($owner,$body,$asset,$template,$artwork);
    $result=sf_agent_song_intake_save($owner,$record);
    $draft=$result['draft'];
    if($result['created']){
        try{sf_log_admin_action($owner,'media_catalog_private_draft_created',
            'song_draft',(string)$draft['id'],['asset_id'=>$id,'template_id'=>$template['id']]);}
        catch(Throwable $e){error_log('Catalog intake audit unavailable: '.$e->getMessage());}
        try{if(function_exists('sf_agent_brain_log'))sf_agent_brain_log($owner,
            'admin_catalog','song_private_intake',['route'=>'catalog','action'=>'save_private_song',
            'context_profile'=>'music','needs_llm'=>false,'requires_confirmation'=>false,
            'status'=>'completed','request'=>'Add audio to private Catalog',
            'response'=>'Persisted private draft '.$draft['id']]);}
        catch(Throwable $e){error_log('Catalog intake Brain receipt unavailable: '.$e->getMessage());}
    }
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
            sf_media_catalog_record_success($owner,$published,'media_library');
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
