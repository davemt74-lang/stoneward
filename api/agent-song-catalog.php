<?php
declare(strict_types=1);
/**
 * Section 90H: publish a previously prepared Admin song, never a raw intake.
 * No model-facing endpoint, no automatic release creation, no master exposure.
 */
require_once __DIR__.'/agent-song-intake.php';

function sf_agent_song_catalog_track_id(string $key):string {
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Invalid song draft identifier.');
    return 'sf-'.substr($key,0,16);
}
function sf_agent_song_catalog_candidate(array $draft,array $master,array $preview,array $cover,
    int $owner,array $catalog=[]):array {
    if($owner<1||(int)($draft['owner_id']??0)!==$owner||($draft['status']??'')!=='catalog_ready'
        ||empty($draft['prepared_at'])
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($draft['payload_hash']??'')))
        throw new RuntimeException('A prepared, revisioned, owner-approved song is required.');
    $id=sf_agent_song_catalog_track_id((string)($draft['request_key']??''));
    // The preflight may discover an already-published copy of this same stable
    // track ID after a crash. Validate all other ISRC collisions normally.
    $otherTracks=array_values(array_filter($catalog,static fn($t)=>($t['id']??'')!==$id));
    $ready=sf_agent_song_intake_readiness($draft,$master,$cover,$otherTracks);
    if(!$ready['ready'])throw new RuntimeException('Private song readiness changed: '.implode(' ',$ready['blockers']));
    if((int)($preview['id']??0)<1||($preview['category']??'')!=='audio'
        ||(string)($preview['extension']??'')!=='mp3'
        ||(int)($preview['created_by']??0)!==$owner
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($preview['sha256']??''))
        ||(float)($preview['duration_seconds']??0)<=0)
        throw new InvalidArgumentException('Choose a valid MP3 listening preview uploaded by this administrator.');
    if((int)$preview['id']===(int)($master['id']??0)
        ||hash_equals((string)($master['sha256']??''),(string)$preview['sha256']))
        throw new RuntimeException('Listening MP3 must be a separate audio export, never the private original.');
    if(!preg_match('/^M[A-F0-9]{20}$/D',(string)($preview['uuid']??''))
        ||!preg_match('/^M[A-F0-9]{20}$/D',(string)($cover['uuid']??'')))
        throw new RuntimeException('Managed preview or artwork identifier is invalid.');
    foreach([$master,$preview,$cover] as $asset){
        $root=defined('SF_ROOT')?realpath(SF_ROOT.'/storage/media'):false;
        $file=isset($asset['stored_path'])?realpath(SF_ROOT.'/'.ltrim((string)$asset['stored_path'],'/')):false;
        if(isset($asset['stored_path'])&&(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)
            ||!is_file($file)||!hash_equals((string)$asset['sha256'],(string)hash_file('sha256',$file))))
            throw new RuntimeException('Managed source, preview or cover file failed integrity verification.');
    }
    $sourceFingerprint=hash('sha256',$draft['request_key'].'|'.$draft['payload_hash'].'|'
        .$preview['sha256'].'|'.$cover['sha256']);
    $sourceTemplate=is_array($draft['template_metadata']??null)?$draft['template_metadata']:[];
    // Only whitelisted public metadata leaves the private import snapshot.
    $metadata=[];
    foreach(['pro_affiliation','ipi_cae','publisher','publisher_pro','publisher_ipi',
        'producer','co_producer','recording_engineer','mixing_engineer',
        'mastering_engineer','studio','recording_location','label',
        'catalog_number','language','subgenre'] as $field)
        if(isset($sourceTemplate[$field]))$metadata[$field]=$sourceTemplate[$field];
    if(isset($sourceTemplate['template'])&&is_array($sourceTemplate['template']))
        $metadata['template']=$sourceTemplate['template'];
    $metadata+= [
        'words_by'=>(string)$draft['words_by'],'music_by'=>(string)$draft['music_by'],
        'performing_artist'=>(string)$draft['artist_display'],
        'composition_copyright'=>(string)$draft['composition_owner'],
        'master_copyright'=>(string)$draft['master_owner'],
        'genre'=>(string)($draft['genre']??''),'isrc'=>(string)($draft['isrc']??''),
        'release_date'=>(string)($draft['release_date']??''),
        'release_year'=>(int)($draft['year']??0),
        'explicit'=>!empty($draft['explicit']),
        'source'=>['agent_publication_fingerprint'=>$sourceFingerprint,'kind'=>'reviewed_agent_song']
    ];
    return [
        'id'=>$id,'title'=>(string)$draft['title'],'release'=>(string)($draft['release']??'Unreleased'),
        'duration'=>max(1,(int)round((float)$preview['duration_seconds'])),
        'year'=>(int)($draft['year']??0),
        'audio'=>'api/media.php?id='.rawurlencode((string)$preview['uuid']),
        'private_master_audio'=>'api/media.php?id='.rawurlencode((string)$master['uuid']),
        'artwork'=>'api/media.php?id='.rawurlencode((string)$cover['uuid']).'&variant=large',
        'state'=>'published','public_visible'=>true,'agent_discoverable'=>true,
        'mood'=>[],'themes'=>[],'energy'=>3,
        'price'=>max(0,(float)($sourceTemplate['price']??2)),
        'podEligible'=>!empty($draft['pod_eligible']),
        'story'=>'','lyrics'=>'','credits'=>sf_admin_public_credits($metadata),
        'metadata'=>$metadata,'audio_publishing_mode'=>'preview_master_split',
        'media_primary_asset'=>(int)$preview['id'],
        'media_private_master_asset'=>(int)$master['id'],
        'media_primary_artwork_asset'=>(int)$cover['id'],
        'created_at'=>gmdate('c')
    ];
}
/** Throws on any ID/fingerprint collision; no mutation on an exact replay. */
function sf_agent_song_catalog_decide(array $catalog,array $candidate):array {
    $id=(string)$candidate['id'];
    $fingerprint=(string)($candidate['metadata']['source']['agent_publication_fingerprint']??'');
    foreach($catalog as $track){
        if(!is_array($track))continue;
        if((string)($track['id']??'')===$id){
            if($fingerprint!==''&&hash_equals($fingerprint,
                (string)($track['metadata']['source']['agent_publication_fingerprint']??''))
                &&($track['audio']??'')===($candidate['audio']??'')
                &&($track['artwork']??'')===($candidate['artwork']??''))
                return ['catalog'=>$catalog,'created'=>false,'track'=>$track];
            throw new RuntimeException('Catalog ID already exists with different source or publication details.');
        }
        $isrc=(string)($candidate['metadata']['isrc']??'');
        if($isrc!==''&&($track['metadata']['isrc']??'')===$isrc)
            throw new RuntimeException('ISRC already exists in the catalog.');
    }
    $catalog[]=$candidate;
    return ['catalog'=>$catalog,'created'=>true,'track'=>$candidate];
}
function sf_agent_song_catalog_publish(int $owner,string $key,string $expectedHash,
    array $master,array $preview,array $cover,?string $directory=null):array {
    if($owner<1||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))
        throw new InvalidArgumentException('Administrator and prepared revision are required.');
    sf_agent_song_catalog_track_id($key);
    $dir=$directory??sf_agent_song_intake_dir($owner);
    if(!is_dir($dir))throw new RuntimeException('Prepared song not found.');
    $lock=fopen($dir.'/.draft-writer.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Private song publication writer unavailable.');
    try{
        $path=$dir.'/sd_'.$key.'.json';
        if(!is_file($path))throw new RuntimeException('Prepared song not found.');
        $draft=json_decode((string)file_get_contents($path),true);
        if(!is_array($draft)||(int)($draft['owner_id']??0)!==$owner)
            throw new RuntimeException('Song draft is not owned by this administrator.');
        if(!hash_equals((string)($draft['payload_hash']??''),$expectedHash))
            throw new RuntimeException('Prepared song changed. Reload before publishing.');
        if(($draft['status']??'')==='published'){
            $id=(string)($draft['published_track_id']??'');
            if($id!==sf_agent_song_catalog_track_id($key))throw new RuntimeException('Publication receipt is inconsistent.');
            $rows=sf_catalog();$found=false;
            foreach($rows as $row)if(($row['id']??'')===$id){$found=true;break;}
            if(!$found)throw new RuntimeException('Publication receipt exists but catalog track is missing; review required.');
            return ['changed'=>false,'track_id'=>$id,'draft'=>$draft];
        }
        $catalogPath=SF_ROOT.'/data/catalog.json';
        $bytes=file_get_contents($catalogPath);
        $catalog=$bytes===false?null:json_decode($bytes,true);
        if(!is_array($catalog))throw new RuntimeException('Catalog source could not be read safely.');
        $candidate=sf_agent_song_catalog_candidate($draft,$master,$preview,$cover,$owner,$catalog);
        if(sf_media_protected_master_asset((int)$preview['id']))
            throw new RuntimeException('Selected preview is already protected as a private master.');
        $decision=sf_agent_song_catalog_decide($catalog,$candidate);
        $id=(string)$candidate['id'];
        // Every link begins PRIVATE, including the MP3 and artwork. No bytes
        // are exposed while a catalog revision is being written.
        sf_media_attach((int)$master['id'],'track',$id,'master_audio',
            ['public_visible'=>false,'download_allowed'=>false]);
        sf_media_attach((int)$preview['id'],'track',$id,'primary_audio',
            ['public_visible'=>false,'download_allowed'=>false]);
        sf_media_attach((int)$cover['id'],'track',$id,'primary_artwork',
            ['public_visible'=>false,'download_allowed'=>false]);
        // Re-emit the catalog JS even on exact replay: the earlier process may
        // have stopped between the JSON and JS atomic writes.
        sf_admin_write_catalog($decision['catalog'],hash('sha256',$bytes));
        // If the process stopped after writing catalog.json, this exact same
        // request safely resumes these public link toggles and the receipt.
        $links=sf_media_links('track',$id);
        $publishedPreview=false;$publishedArt=false;
        foreach($links as $link){
            if((int)$link['asset_id']===(int)$preview['id']&&$link['role']==='primary_audio'){
                sf_media_update_link((int)$link['id'],['public_visible'=>true,'download_allowed'=>false,'featured'=>true]);
                $publishedPreview=true;
            }elseif((int)$link['asset_id']===(int)$cover['id']&&$link['role']==='primary_artwork'){
                sf_media_update_link((int)$link['id'],['public_visible'=>true,'download_allowed'=>false,'featured'=>true]);
                $publishedArt=true;
            }
        }
        if(!$publishedPreview||!$publishedArt)throw new RuntimeException('Catalog published but media activation needs retry.');
        $draft['status']='published';$draft['published_at']=gmdate('c');$draft['published_track_id']=$id;
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
        $json=json_encode($draft,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not finalize private publication receipt.');
        chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not finalize song publication receipt.');}
        return ['changed'=>true,'track_id'=>$id,'draft'=>$draft,'created'=>$decision['created']];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
