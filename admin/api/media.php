<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
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
      'press_kit'=>['hero']
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

if($method==='GET'){
    $assetId=max(0,(int)($_GET['asset_id']??0));if($assetId){$asset=sf_media_asset($assetId);if(!$asset)sf_json_response(['ok'=>false,'message'=>'Media asset not found.'],404);sf_json_response(['ok'=>true,'asset'=>$asset,'usage'=>sf_media_usage($assetId),'capabilities'=>sf_media_capabilities()]);}
    $mode=sf_clean_text($_GET['mode']??'',40);if($mode==='dashboard'){$cfg=sf_store_config();$products=[];foreach((array)($cfg['products']??[]) as $id=>$p)$products[]=['id'=>(string)$id,'label'=>(string)($p['label']??$id),'format'=>(string)($p['format']??''),'kind'=>'builder'];if(function_exists('sf_commerce_products'))foreach(sf_commerce_products(false) as $p)$products[]=['id'=>(string)$p['id'],'label'=>(string)$p['title'],'format'=>'merch','kind'=>'merch'];sf_json_response(['ok'=>true,'completeness'=>sf_media_completeness(),'store_products'=>$products,'site'=>sf_site_settings(),'capabilities'=>sf_media_capabilities()]);}
    $entityType=sf_clean_text($_GET['entity_type']??'',40);$entityId=sf_clean_text($_GET['entity_id']??'',160);if($entityType!==''&&$entityId!=='')sf_json_response(['ok'=>true,'links'=>sf_media_links($entityType,$entityId),'capabilities'=>sf_media_capabilities()]);
    $category=sf_clean_text($_GET['category']??'',40);$q=sf_clean_text($_GET['q']??'',180);sf_json_response(['ok'=>true,'assets'=>sf_media_list($category,$q,500),'completeness'=>sf_media_completeness(),'capabilities'=>sf_media_capabilities()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='attach'){
        $entityType=sf_clean_text($b['entity_type']??'',40);$entityId=sf_clean_text($b['entity_id']??'',160);$role=sf_clean_text($b['role']??'media',60);$assetId=(int)($b['asset_id']??0);
        $links=sf_media_attach($assetId,$entityType,$entityId,$role,['featured'=>!empty($b['featured']),'public_visible'=>!empty($b['public_visible']),'download_allowed'=>!empty($b['download_allowed'])]);
        sf_log_admin_action((int)$me['id'],'media_attached','media_asset',(string)$assetId,['entity_type'=>$entityType,'entity_id'=>$entityId,'role'=>$role]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_attach',['route'=>'media_library','action'=>'attach_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Attach media to '.$entityType.' '.$entityId,'response'=>'Attached media asset '.$assetId.' as '.$role.'.']);
        sf_json_response(['ok'=>true,'links'=>$links]);
    }
    if($action==='detach'){
        $linkId=(int)($b['link_id']??0);$row=sf_admin_media_link_row($linkId);$ok=sf_media_detach($linkId);sf_log_admin_action((int)$me['id'],'media_detached','media_link',(string)$linkId,['entity_type'=>$row['entity_type'],'entity_id'=>$row['entity_id'],'role'=>$row['role']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_detach',['route'=>'media_library','action'=>'detach_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Detach media from '.$row['entity_type'].' '.$row['entity_id'],'response'=>'Detached '.$row['role'].' media relationship; library asset preserved.']);sf_json_response(['ok'=>$ok]);
    }
    if($action==='update_asset'){$a=sf_media_update_asset((int)($b['id']??0),(array)($b['asset']??[]));sf_log_admin_action((int)$me['id'],'media_metadata_updated','media_asset',(string)$a['id']);sf_json_response(['ok'=>true,'asset'=>$a]);}
    if($action==='update_link'){sf_media_update_link((int)($b['id']??0),(array)($b['link']??[]));sf_json_response(['ok'=>true]);}
    if($action==='delete_asset'){$id=(int)($b['id']??0);$ok=sf_media_delete_asset($id);if($ok)sf_log_admin_action((int)$me['id'],'media_deleted','media_asset',(string)$id);sf_json_response(['ok'=>$ok]);}
    if($action==='publish_track_audio'){$trackId=sf_clean_text($b['track_id']??'',160);$linkId=(int)($b['link_id']??0);$r=sf_media_publish_track_audio($trackId,$linkId);sf_log_admin_action((int)$me['id'],'track_primary_audio_published','track',$trackId,['asset_id'=>(int)$r['asset']['id'],'previous_audio'=>$r['previous_audio'],'duration'=>(float)$r['asset']['duration_seconds']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'track_media','action'=>'publish_primary_audio','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Replace primary audio for '.$trackId,'response'=>'Published asset '.$r['asset']['uuid'].'; previous source preserved in audit metadata.']);sf_json_response(['ok'=>true]+$r);}
    if($action==='publish_track_artwork'){$trackId=sf_clean_text($b['track_id']??'',160);$linkId=(int)($b['link_id']??0);$r=sf_media_publish_track_artwork($trackId,$linkId);sf_log_admin_action((int)$me['id'],'track_primary_artwork_published','track',$trackId,['asset_id'=>(int)$r['asset']['id'],'previous_artwork'=>$r['previous_artwork']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'track_media','action'=>'publish_primary_artwork','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Replace primary artwork for '.$trackId,'response'=>'Published artwork asset '.$r['asset']['uuid'].'.']);sf_json_response(['ok'=>true]+$r);}
    if($action==='publish_entity_image'){
        $entityType=sf_clean_text($b['entity_type']??'',40);$entityId=sf_clean_text($b['entity_id']??'',160);$linkId=(int)($b['link_id']??0);$role=sf_clean_text($b['role']??'',60);$r=sf_admin_media_publish_image($entityType,$entityId,$linkId,$role);
        sf_log_admin_action((int)$me['id'],'entity_primary_media_published',$entityType,$entityId,['asset_id'=>(int)$r['asset']['id'],'role'=>$role,'url'=>$r['url']]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_media','media_publish',['route'=>'universal_media','action'=>'publish_entity_media','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Publish '.$role.' media for '.$entityType.' '.$entityId,'response'=>'Published '.$r['asset']['uuid'].' as '.$role.'.']);
        sf_json_response(['ok'=>true]+$r);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
