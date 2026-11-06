<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/track-media-publishing.php';
require_once SF_ROOT.'/api/fan-owned-music-core.php';
require_once SF_ROOT.'/api/kunaki-media-transfer.php';
require_once SF_ROOT.'/api/agent-canvas-image-references.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
sf_media_ensure_schema();

function sf_admin_media_link_row(int $linkId,string $entityType='',string $entityId=''): array {
    $sql='SELECT l.*,a.uuid,a.category,a.title,a.original_name,a.variants_json FROM media_links l JOIN media_assets a ON a.id=l.asset_id WHERE l.id=?';$args=[$linkId];
    if($entityType!==''){$sql.=' AND l.entity_type=?';$args[]=$entityType;}
    if($entityId!==''){$sql.=' AND l.entity_id=?';$args[]=$entityId;}
    $q=sf_db()->prepare($sql);$q->execute($args);$r=$q->fetch();if(!$r)throw new InvalidArgumentException('Media attachment not found.');$r['variants']=sf_media_decode($r['variants_json']??'',[]);return $r;
}
function sf_admin_media_publish_image(string $entityType,string $entityId,int $linkId,string $role): array {
    $allowed=[
      'release'=>['cover','back','label_a','label_b','social_square','social_story'],
      'show'=>['poster'],
      'campaign'=>['hero','background','offer_artwork'],
      'site'=>['logo','hero','social_share','app_icon','artist_photo'],
      'store'=>['product_primary'],
      'press_kit'=>['hero'],
      'story_entry'=>['hero'],
      'video_feature'=>['poster'],
      'photo_gallery'=>['cover']
    ];
    if(!isset($allowed[$entityType])||!in_array($role,$allowed[$entityType],true))throw new InvalidArgumentException('Unsupported primary media role.');
    $row=sf_admin_media_link_row($linkId,$entityType,$entityId);if(($row['category']??'')!=='image')throw new InvalidArgumentException('Choose an image asset for this role.');
    $asset=sf_media_asset((int)$row['asset_id']);if(!$asset)throw new InvalidArgumentException('Media asset not found.');
    $variant=in_array($role,['app_icon'],true)?'thumb':'large';$url=sf_media_managed_url($asset,$variant);$now=gmdate('c');
    sf_db()->prepare('UPDATE media_links SET featured=0,public_visible=0,updated_at=? WHERE entity_type=? AND entity_id=? AND role=? AND id<>?')->execute([$now,$entityType,$entityId,$role,$linkId]);
    sf_db()->prepare('UPDATE media_links SET role=?,featured=1,public_visible=1,updated_at=? WHERE id=?')->execute([$role,$now,$linkId]);

    if($entityType==='release'){
        $rows=sf_admin_releases();$found=false;foreach($rows as &$release)if((string)($release['id']??'')===$entityId){if(!is_array($release['artwork']??null))$release['artwork']=[];$release['artwork'][$role]=$url;$release['updated_at']=$now;$found=true;break;}unset($release);if(!$found)throw new InvalidArgumentException('Release not found.');sf_admin_write_releases($rows);
    }elseif($entityType==='show'){
        $rows=sf_admin_shows();$found=false;foreach($rows as &$show)if((string)($show['id']??'')===$entityId){$show['poster']=$url;$show['updated_at']=$now;$found=true;break;}unset($show);if(!$found)throw new InvalidArgumentException('Show not found.');sf_admin_write_shows($rows);
    }elseif($entityType==='campaign'){
        if($role==='hero'){$q=sf_db()->prepare('UPDATE campaigns SET artwork=?,updated_at=? WHERE id=?');$q->execute([$url,$now,(int)$entityId]);if(!$q->rowCount()&&!sf_campaign_get((int)$entityId))throw new InvalidArgumentException('Campaign not found.');}
    }elseif($entityType==='site'){
        sf_meta_set('site.media_'.$role,$url);
    }
    return ['asset'=>$asset,'url'=>$url,'role'=>$role,'links'=>sf_media_links($entityType,$entityId)];
}


/** 91B20G: verified image placement, published-asset history and guarded restore. */
function sf_admin_image_studio_target(string $type,string $id,string $role): void {
    $allowed=['track'=>['artwork'],'release'=>['cover'],'story_entry'=>['hero'],'campaign'=>['hero'],'site'=>['hero']];
    if(!isset($allowed[$type])||!in_array($role,$allowed[$type],true)||$id==='')throw new InvalidArgumentException('Select a supported primary artwork placement.');
    if($type==='site'){if($id!=='public')throw new InvalidArgumentException('Select the public site workspace.');return;}
    if($type==='track'){foreach(sf_catalog() as $row)if((string)($row['id']??'')===$id)return;}
    if($type==='release'){foreach(sf_admin_releases() as $row)if((string)($row['id']??'')===$id)return;}
    if($type==='story_entry'){require_once SF_ROOT.'/api/story-core.php';if(ctype_digit($id)&&sf_story_get((int)$id,false))return;}
    if($type==='campaign'){require_once SF_ROOT.'/api/campaign-core.php';if(ctype_digit($id)&&sf_campaign_get((int)$id))return;}
    throw new InvalidArgumentException('The selected artwork target no longer exists.');
}
function sf_admin_image_studio_current_link(string $type,string $id,string $role): int {
    $actualRole=$type==='track'?'primary_artwork':$role;
    $q=sf_db()->prepare('SELECT id FROM media_links WHERE entity_type=? AND entity_id=? AND role=? AND public_visible=1 ORDER BY featured DESC,id DESC LIMIT 1');
    $q->execute([$type,$id,$actualRole]);return (int)($q->fetchColumn()?:0);
}
function sf_admin_image_studio_validate_link(int $linkId,string $type,string $id,string $role): array {
    if($linkId<1)throw new InvalidArgumentException('Choose an attached image.');
    $row=sf_admin_media_link_row($linkId,$type,$id);
    if(($row['category']??'')!=='image'||!in_array((string)$row['role'],[$role,$type==='track'?'primary_artwork':$role],true))
        throw new InvalidArgumentException('The image is not attached in the requested placement.');
    sf_media_guard_generated_image_approval((int)$row['asset_id']);
    return $row;
}
function sf_admin_image_studio_publish_link(string $type,string $id,int $linkId,string $role): array {
    if($type==='track')return sf_media_publish_track_artwork($id,$linkId);
    return sf_admin_media_publish_image($type,$id,$linkId,$role);
}
function sf_admin_image_studio_history(string $type,string $id,string $role): array {
    sf_admin_image_studio_target($type,$id,$role);
    sf_ops_ensure_schema();
    $q=sf_db()->prepare("SELECT id,admin_user_id,action,details_json,created_at FROM admin_audit_log WHERE entity_type=? AND entity_id=? AND action IN ('image_studio_placement_applied','image_studio_placement_restored') ORDER BY id DESC LIMIT 80");
    $q->execute([$type,$id]);$items=[];
    foreach($q->fetchAll() as $r){
        $d=json_decode((string)($r['details_json']??'{}'),true);
        if(!is_array($d)||($d['role']??'')!==$role)continue;
        $items[]=['id'=>(int)$r['id'],'action'=>(string)$r['action'],'actor_id'=>(int)$r['admin_user_id'],
            'created_at'=>(string)$r['created_at'],'previous_link_id'=>(int)($d['previous_link_id']??0),
            'new_link_id'=>(int)($d['new_link_id']??0),'previous_uuid'=>(string)($d['previous_uuid']??''),
            'new_uuid'=>(string)($d['new_uuid']??''),'previous_title'=>(string)($d['previous_title']??''),
            'new_title'=>(string)($d['new_title']??'')];
        if(count($items)>=12)break;
    }
    return ['current_link_id'=>sf_admin_image_studio_current_link($type,$id,$role),'entries'=>$items];
}

if($method==='GET'){
    $assetId=max(0,(int)($_GET['asset_id']??0));if($assetId){$asset=sf_media_asset($assetId);if(!$asset)sf_json_response(['ok'=>false,'message'=>'Media asset not found.'],404);sf_json_response(['ok'=>true,'asset'=>sf_agent_canvas_reference_admin_assets([$asset])[0],'usage'=>sf_media_usage($assetId),'capabilities'=>sf_media_capabilities()]);}
    $mode=sf_clean_text($_GET['mode']??'',40);
    if($mode==='image_placement_history'){
        $type=sf_clean_text($_GET['entity_type']??'',40);$id=sf_clean_text($_GET['entity_id']??'',160);$role=sf_clean_text($_GET['role']??'',60);
        sf_json_response(['ok'=>true]+sf_admin_image_studio_history($type,$id,$role));
    }if($mode==='dashboard'){$cfg=sf_store_config();$products=[];foreach((array)($cfg['products']??[]) as $id=>$p)$products[]=['id'=>(string)$id,'label'=>(string)($p['label']??$id),'format'=>(string)($p['format']??''),'kind'=>'builder'];if(function_exists('sf_commerce_products'))foreach(sf_commerce_products(false) as $p)$products[]=['id'=>(string)$p['id'],'label'=>(string)$p['title'],'format'=>'merch','kind'=>'merch'];sf_json_response(['ok'=>true,'completeness'=>sf_media_completeness(),'store_products'=>$products,'site'=>sf_site_settings(),'capabilities'=>sf_media_capabilities()]);}
    $entityType=sf_clean_text($_GET['entity_type']??'',40);$entityId=sf_clean_text($_GET['entity_id']??'',160);if($entityType!==''&&$entityId!=='')sf_json_response(['ok'=>true,'links'=>sf_agent_canvas_reference_admin_assets(sf_media_links($entityType,$entityId),'asset_id'),'publication'=>$entityType==='track'?sf_track_media_publication_status($entityId):null,'capabilities'=>sf_media_capabilities()]);
    $category=sf_clean_text($_GET['category']??'',40);$q=sf_clean_text($_GET['q']??'',180);sf_json_response(['ok'=>true,'assets'=>sf_agent_canvas_reference_admin_assets(sf_media_list($category,$q,500)),'completeness'=>sf_media_completeness(),'capabilities'=>sf_media_capabilities()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='analyze_image'){
        require_once SF_ROOT.'/api/media-image-analysis.php';
        $id=(int)($b['id']??0);$requestId=$b['request_id']??null;
        if(!is_string($requestId))throw new InvalidArgumentException('Choose a valid image analysis request.');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        try{$analysis=sf_media_image_analysis_analyze((int)$me['id'],$id,$requestId);}
        catch(InvalidArgumentException $error){throw $error;}
        catch(Throwable $error){
            error_log('[Stonefellow media image analysis] '.get_class($error));
            sf_json_response(['ok'=>false,'error'=>'image_analysis_unavailable',
                'message'=>'Image analysis could not be completed. Check the enabled AI provider and try again.'],422);
        }
        sf_log_admin_action((int)$me['id'],'media_image_analyzed','media_asset',(string)$id,
            ['analysis_id'=>$analysis['id'],'status'=>$analysis['status'],'source_sha256'=>$analysis['source_sha256']]);
        sf_json_response(['ok'=>true,'asset_id'=>$id,'analysis'=>$analysis]);
    }
    if($action==='save_image_analysis'){
        require_once SF_ROOT.'/api/media-image-analysis.php';
        $id=(int)($b['id']??0);$analysisId=$b['analysis_id']??null;$version=$b['expected_version']??null;
        if(!is_string($analysisId)||!is_int($version))throw new InvalidArgumentException('Refresh the image analysis before saving.');
        $analysis=sf_media_image_analysis_save((int)$me['id'],$id,$analysisId,$version,$b['analysis']??null);
        sf_log_admin_action((int)$me['id'],'media_image_analysis_reviewed','media_asset',(string)$id,
            ['analysis_id'=>$analysis['id'],'version'=>$analysis['version'],'source_sha256'=>$analysis['source_sha256']]);
        sf_json_response(['ok'=>true,'asset_id'=>$id,'analysis'=>$analysis]);
    }
    if($action==='set_reference_gallery'){
        if(!is_bool($b['enabled']??null))throw new InvalidArgumentException('Choose whether to include this image in the reference gallery.');
        $id=(int)($b['id']??0);$enabled=$b['enabled'];
        $asset=sf_agent_canvas_reference_set($id,$enabled);
        sf_log_admin_action((int)$me['id'],$enabled?'canvas_reference_published':'canvas_reference_revoked',
            'media_asset',(string)$id,['reference_id'=>$asset['reference_image_id'],'available_to_all_visitors'=>$enabled]);
        sf_json_response(['ok'=>true,'asset'=>$asset]);
    }
    if($action==='attach'){
        $entityType=sf_clean_text($b['entity_type']??'',40);$entityId=sf_clean_text($b['entity_id']??'',160);$role=sf_clean_text($b['role']??'media',60);$assetId=(int)($b['asset_id']??0);
        if(in_array($entityType,['video_feature','photo_gallery'],true)&&(!empty($b['public_visible'])||!empty($b['download_allowed']))&&empty($b['confirmed'])){if($entityType==='video_feature')throw new RuntimeException('Changing Video Hub media visibility requires explicit Admin confirmation.');throw new RuntimeException('Publishing governed public media requires explicit Admin confirmation.');}
        $revisionNote=$entityType==='track'&&$role==='audio_candidate'?sf_clean_text($b['revision_note']??'',500):'';$links=sf_media_attach($assetId,$entityType,$entityId,$role,['featured'=>!empty($b['featured']),'public_visible'=>!empty($b['public_visible']),'download_allowed'=>!empty($b['download_allowed']),'link'=>$revisionNote!==''?['revision_note'=>$revisionNote]:[]]);
        sf_log_admin_action((int)$me['id'],'media_attached','media_asset',(string)$assetId,['entity_type'=>$entityType,'entity_id'=>$entityId,'role'=>$role]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_attach',['route'=>'media_library','action'=>'attach_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Attach media to '.$entityType.' '.$entityId,'response'=>'Attached media asset '.$assetId.' as '.$role.'.']);
        sf_json_response(['ok'=>true,'links'=>$links]);
    }
    if($action==='detach'){
        $linkId=(int)($b['link_id']??0);$row=sf_admin_media_link_row($linkId);if(($row['entity_type']??'')==='track'&&($row['role']??'')==='master_audio')throw new RuntimeException('Source master revisions are retained; select a different private original instead.');$ok=sf_media_detach($linkId);sf_log_admin_action((int)$me['id'],'media_detached','media_link',(string)$linkId,['entity_type'=>$row['entity_type'],'entity_id'=>$row['entity_id'],'role'=>$row['role']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_detach',['route'=>'media_library','action'=>'detach_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Detach media from '.$row['entity_type'].' '.$row['entity_id'],'response'=>'Detached '.$row['role'].' media relationship; library asset preserved.']);sf_json_response(['ok'=>$ok]);
    }
    if($action==='update_asset'){$a=sf_media_update_asset((int)($b['id']??0),(array)($b['asset']??[]));sf_log_admin_action((int)$me['id'],'media_metadata_updated','media_asset',(string)$a['id']);sf_json_response(['ok'=>true,'asset'=>sf_agent_canvas_reference_admin_assets([$a])[0]]);}
    if($action==='update_link'){$id=(int)($b['id']??0);$patch=(array)($b['link']??[]);$row=sf_admin_media_link_row($id);if(($row['entity_type']??'')==='photo_gallery'&&array_key_exists('role',$patch)&&!in_array((string)$patch['role'],['cover','photo'],true))throw new InvalidArgumentException('Photo gallery media role must be cover or photo.');$consequential=false;if(in_array((string)($row['entity_type']??''),['video_feature','photo_gallery'],true)){foreach(['public_visible','download_allowed'] as $k)if(array_key_exists($k,$patch)&&((int)!empty($patch[$k]))!==(int)!empty($row[$k]))$consequential=true;if($consequential&&empty($b['confirmed'])){if(($row['entity_type']??'')==='video_feature')throw new RuntimeException('Changing Video Hub media visibility requires explicit Admin confirmation.');throw new RuntimeException('Changing governed public media visibility requires explicit Admin confirmation.');}}sf_media_update_link($id,$patch);if($consequential){$isVideo=($row['entity_type']??'')==='video_feature';$auditAction=$isVideo?'video_media_visibility_changed':'governed_media_visibility_changed';$brainKind=$isVideo?'video_media_visibility':'governed_media_visibility';$request=$isVideo?'Change Video Hub media visibility':'Change governed public media visibility';sf_log_admin_action((int)$me['id'],$auditAction,(string)$row['entity_type'],(string)$row['entity_id'],['link_id'=>$id,'role'=>$row['role'],'public_visible'=>array_key_exists('public_visible',$patch)?!empty($patch['public_visible']):!empty($row['public_visible']),'download_allowed'=>array_key_exists('download_allowed',$patch)?!empty($patch['download_allowed']):!empty($row['download_allowed'])]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media',$brainKind,['route'=>'universal_media','action'=>'update_media_visibility','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>$request,'response'=>'Public media visibility updated for '.$row['role'].'.']);}sf_json_response(['ok'=>true]);}
    if($action==='delete_asset'){$id=(int)($b['id']??0);$ok=sf_media_delete_asset($id);if($ok)sf_log_admin_action((int)$me['id'],'media_deleted','media_asset',(string)$id);sf_json_response(['ok'=>$ok]);}
    if($action==='publish_track_master'){
        if(empty($b['confirmed']))throw new RuntimeException('Publishing a private original requires explicit confirmation.');
        $trackId=sf_clean_text($b['track_id']??'',160);$linkId=(int)($b['link_id']??0);
        $r=sf_track_media_publish_master($trackId,$linkId);
        sf_log_admin_action((int)$me['id'],'track_private_master_published','track',$trackId,
            ['asset_id'=>(int)$r['asset']['id'],'previous_master'=>$r['previous_master']]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',
            ['route'=>'track_media','action'=>'publish_private_master','context_profile'=>'catalog',
             'needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed',
             'request'=>'Set private master for '.$trackId,'response'=>'Selected privately stored original.']);
        sf_json_response(['ok'=>true]+$r);
    }
    if($action==='publish_track_audio'){if(empty($b['confirmed']))throw new RuntimeException('Publishing a public preview requires explicit confirmation.');$trackId=sf_clean_text($b['track_id']??'',160);$linkId=(int)($b['link_id']??0);$r=sf_media_publish_track_audio($trackId,$linkId);sf_log_admin_action((int)$me['id'],'track_primary_audio_published','track',$trackId,['asset_id'=>(int)$r['asset']['id'],'previous_audio'=>$r['previous_audio'],'duration'=>(float)$r['asset']['duration_seconds']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'track_media','action'=>'publish_primary_audio','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Replace primary audio for '.$trackId,'response'=>'Published asset '.$r['asset']['uuid'].'; previous source preserved in audit metadata.']);sf_json_response(['ok'=>true]+$r);}
    if($action==='publish_track_artwork'){$trackId=sf_clean_text($b['track_id']??'',160);$linkId=(int)($b['link_id']??0);$r=sf_media_publish_track_artwork($trackId,$linkId);sf_log_admin_action((int)$me['id'],'track_primary_artwork_published','track',$trackId,['asset_id'=>(int)$r['asset']['id'],'previous_artwork'=>$r['previous_artwork']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'track_media','action'=>'publish_primary_artwork','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Replace primary artwork for '.$trackId,'response'=>'Published artwork asset '.$r['asset']['uuid'].'.']);sf_json_response(['ok'=>true]+$r);}
    if($action==='publish_entity_image'){
        $entityType=sf_clean_text($b['entity_type']??'',40);if(in_array($entityType,['video_feature','photo_gallery'],true)&&empty($b['confirmed'])){if($entityType==='video_feature')throw new RuntimeException('Publishing a Video Hub poster requires explicit Admin confirmation.');throw new RuntimeException('Publishing governed primary media requires explicit Admin confirmation.');}$entityId=sf_clean_text($b['entity_id']??'',160);$linkId=(int)($b['link_id']??0);$role=sf_clean_text($b['role']??'',60);$r=sf_admin_media_publish_image($entityType,$entityId,$linkId,$role);
        sf_log_admin_action((int)$me['id'],'entity_primary_media_published',$entityType,$entityId,['asset_id'=>(int)$r['asset']['id'],'role'=>$role,'url'=>$r['url']]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'universal_media','action'=>'publish_entity_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Publish '.$role.' media for '.$entityType.' '.$entityId,'response'=>'Published '.$r['asset']['uuid'].' as '.$role.'.']);
        sf_json_response(['ok'=>true]+$r);
    }

    if($action==='image_studio_apply'||$action==='image_studio_restore'){
        if(empty($b['confirmed']))throw new InvalidArgumentException('Confirm the artwork change before publication.');
        if(!array_key_exists('expected_current_link_id',$b))throw new InvalidArgumentException('Refresh the artwork preview before applying changes.');
        $type=sf_clean_text($b['entity_type']??'',40);$entityId=sf_clean_text($b['entity_id']??'',160);
        $role=sf_clean_text($b['role']??'',60);
        sf_admin_image_studio_target($type,$entityId,$role);
        $current=sf_admin_image_studio_current_link($type,$entityId,$role);
        if($current!==max(0,(int)$b['expected_current_link_id']))
            throw new RuntimeException('The published artwork changed. Refresh the comparison before applying.');
        $receiptId=0;$sourceJobId=0;$linkId=0;
        if($action==='image_studio_apply'){
            require_once SF_ROOT.'/api/image-generation-core.php';
            $sourceJobId=max(0,(int)($b['job_id']??0));$job=sf_image_gen_job($sourceJobId);
            if(!$job||($job['status']??'')!=='ready'||(int)($job['asset_approved_by']??0)<1)
                throw new RuntimeException('Only an approved image-generation result may be applied.');
            $linkId=max(0,(int)($b['link_id']??0));$link=sf_admin_image_studio_validate_link($linkId,$type,$entityId,$role);
            if((int)$link['asset_id']!==(int)$job['media_asset_id'])
                throw new InvalidArgumentException('The staged image no longer matches this approved result.');
            if(!empty($link['public_visible'])||$linkId===$current)
                throw new RuntimeException('This image is already publicly applied. Refresh the placement.');
        }else{
            $receiptId=max(0,(int)($b['receipt_id']??0));sf_ops_ensure_schema();
            $q=sf_db()->prepare("SELECT details_json FROM admin_audit_log WHERE id=? AND entity_type=? AND entity_id=? AND action IN ('image_studio_placement_applied','image_studio_placement_restored')");
            $q->execute([$receiptId,$type,$entityId]);$row=$q->fetch();
            $details=$row?json_decode((string)$row['details_json'],true):null;
            if(!is_array($details)||($details['role']??'')!==$role)
                throw new InvalidArgumentException('The selected restore receipt is unavailable.');
            if($current!==(int)($details['new_link_id']??0))
                throw new RuntimeException('Another image revision is active. Refresh before restoring.');
            $linkId=(int)($details['previous_link_id']??0);
            if($linkId<1)throw new RuntimeException('This revision has no managed previous image to restore.');
            sf_admin_image_studio_validate_link($linkId,$type,$entityId,$role);
        }
        $previous=$current?sf_admin_media_link_row($current,$type,$entityId):null;
        $next=sf_admin_media_link_row($linkId,$type,$entityId);
        // Use the SAME authoritative publishers as the Media Library and catalog editors.
        $published=sf_admin_image_studio_publish_link($type,$entityId,$linkId,$role);
        $after=sf_admin_image_studio_current_link($type,$entityId,$role);
        if($after!==$linkId)throw new RuntimeException('Artwork publication was not confirmed by the Media Library.');
        $auditAction=$action==='image_studio_apply'?'image_studio_placement_applied':'image_studio_placement_restored';
        sf_log_admin_action((int)$me['id'],$auditAction,$type,$entityId,[
            'role'=>$role,'source_job_id'=>$sourceJobId,'restore_receipt_id'=>$receiptId,
            'previous_link_id'=>$current,'new_link_id'=>$after,
            'previous_uuid'=>(string)($previous['uuid']??''),'new_uuid'=>(string)$next['uuid'],
            'previous_title'=>(string)($previous['title']??''),'new_title'=>(string)($next['title']??'')
        ]);
        if(function_exists('sf_agent_brain_log')){
            try{sf_agent_brain_log((int)$me['id'],'admin_media',$auditAction,
                ['route'=>'image_studio','action'=>$auditAction,'context_profile'=>'catalog','needs_llm'=>false,
                 'requires_confirmation'=>true,'status'=>'completed','request'=>'Review '.$type.' artwork placement',
                 'response'=>'Applied reviewed Media Library artwork '.$next['uuid'].' as '.$role.'.']);}
            catch(Throwable $brainError){error_log('[Image Studio publish brain receipt] '.get_class($brainError));}
        }
        sf_json_response(['ok'=>true,'published'=>true,'current_link_id'=>$after,
            'history'=>sf_admin_image_studio_history($type,$entityId,$role)]);
    }

    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
