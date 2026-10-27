<?php
declare(strict_types=1);
/**
 * Section 85B: finish the EXISTING Media Library track roles. Do not build a
 * parallel uploader or catalog. Audio original and public preview must differ.
 */
function sf_track_media_master_reference(array $track):string {
    return (string)($track['private_master_audio']??'');
}
function sf_track_media_publish_master(string $trackId,int $linkId):array {
    sf_media_ensure_schema();
    // Use a fresh, exact catalog revision for this write. The static catalog
    // cache may be older than a concurrent Admin edit in another request.
    $sourceBytes=file_get_contents(SF_ROOT.'/data/catalog.json');
    $catalog=$sourceBytes===false?null:json_decode($sourceBytes,true);
    if(!is_array($catalog))throw new RuntimeException('Catalog source unavailable.');
    $expectedHash=hash('sha256',$sourceBytes);
    $idx=null;
    foreach($catalog as $i=>$track)if((string)($track['id']??'')===$trackId){$idx=$i;break;}
    if($idx===null)throw new RuntimeException('Song does not exist.');
    $q=sf_db()->prepare("SELECT l.*,a.uuid,a.category,a.extension,a.size_bytes FROM media_links l JOIN media_assets a ON a.id=l.asset_id WHERE l.id=? AND l.entity_type='track' AND l.entity_id=?");
    $q->execute([$linkId,$trackId]);$link=$q->fetch();
    if(!$link||$link['category']!=='audio'
        ||!in_array((string)$link['role'],['master_audio','download_audio','audio_candidate'],true))
        throw new RuntimeException('Choose a private attached MP3/WAV original.');
    $id=(int)$link['asset_id'];
    if(sf_media_protected_master_asset($id)&&$link['role']!=='master_audio')
        throw new RuntimeException('Original master is already assigned elsewhere.');
    if(!empty($link['public_visible'])||!empty($link['download_allowed']))
        throw new RuntimeException('Make this asset private before selecting it as an original.');
    $q=sf_db()->prepare('SELECT 1 FROM media_links WHERE asset_id=? AND id<>? AND (public_visible=1 OR download_allowed=1) LIMIT 1');
    $q->execute([$id,$linkId]);
    if($q->fetchColumn())throw new RuntimeException('Original is publicly linked elsewhere; use a separate preview.');
    $ref='api/media.php?id='.(string)$link['uuid'];
    if((string)($catalog[$idx]['audio']??'')===$ref)
        throw new RuntimeException('The public preview and private original must be different media assets.');
    // Existing 84B5 resolver verifies private storage, SHA-256, media type and size.
    sf_kunaki_media_resolve($ref,'audio');
    $previous=(string)($catalog[$idx]['private_master_audio']??'');
    if($previous===$ref)return ['track'=>$catalog[$idx],'previous_master'=>$previous,'asset'=>sf_media_asset($id),'unchanged'=>true];
    $db=sf_db();$db->prepare("UPDATE media_links SET role='master_audio',public_visible=0,download_allowed=0,updated_at=? WHERE id=?")
       ->execute([gmdate('c'),$linkId]);
    $catalog[$idx]['private_master_audio']=$ref;
    $catalog[$idx]['media_private_master_asset']=$id;
    $catalog[$idx]['audio_publishing_mode']='preview_master_split';
    if(!function_exists('sf_admin_write_catalog'))throw new RuntimeException('Catalog writer unavailable.');
    sf_admin_write_catalog($catalog,$expectedHash);
    return ['track'=>$catalog[$idx],'previous_master'=>$previous,'asset'=>sf_media_asset($id),'unchanged'=>false];
}
function sf_track_media_publication_status(string $trackId):array {
    $track=null;foreach(sf_catalog() as $row)if((string)($row['id']??'')===$trackId){$track=$row;break;}
    if(!is_array($track))throw new InvalidArgumentException('Unknown song.');
    $master=sf_track_media_master_reference($track);
    $preview=(string)($track['audio']??'');
    $ready=false;
    if($master!==''&&function_exists('sf_fan_owned_media_ready'))$ready=sf_fan_owned_media_ready($master);
    return ['mode'=>(string)($track['audio_publishing_mode']??'legacy'),
        'public_preview'=>$preview!=='' && str_starts_with($preview,'api/media.php?id='),
        'master_asset_id'=>(int)($track['media_private_master_asset']??0),
        'master_reference'=>$master,
        'private_master'=>$master!=='','download_ready'=>$ready,
        'hint'=>!$master?'Choose a separate private MP3/WAV original.':(!$ready?'Private master missing or unavailable.':'Original validated for owned downloads.')];
}
