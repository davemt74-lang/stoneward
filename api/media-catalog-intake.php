<?php
declare(strict_types=1);
/** Section 90T: an explicit public-player opt-in for existing Media Library songs. */
require_once __DIR__.'/agent-song-catalog.php';

function sf_media_catalog_authorized_asset(?array $asset,int $owner,string $category='audio'):array {
    if(!$asset||(int)($asset['id']??0)<1||($asset['category']??'')!==$category
        ||!in_array((int)($asset['created_by']??0),[0,$owner],true))
        throw new InvalidArgumentException('Choose a Media Library file owned by this administrator or the site.');
    if($category==='audio'&&!in_array(strtolower((string)($asset['extension']??'')),['mp3','wav','wave'],true))
        throw new InvalidArgumentException('Only MP3 and WAV recordings can enter the song catalog.');
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($asset['sha256']??'')))
        throw new RuntimeException('Media checksum is unavailable.');
    $root=realpath(SF_ROOT.'/storage/media');
    $path=realpath(SF_ROOT.'/'.ltrim((string)($asset['stored_path']??''),'/'));
    if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)
        ||!is_file($path)||!hash_equals((string)$asset['sha256'],(string)hash_file('sha256',$path)))
        throw new RuntimeException('Media file failed stored-byte integrity verification.');
    return $asset;
}

function sf_media_catalog_intake_record(int $owner,array $input,array $asset,array $template,?array $artwork=null):array {
    $asset=sf_media_catalog_authorized_asset($asset,$owner);
    if($artwork)sf_media_catalog_authorized_asset($artwork,$owner,'image');
    $metadata=sf_admin_merge_metadata($template,[],sf_admin_normalize_isrc($input['isrc']??''),'media-library',
        ['private_import'=>true,'source_kind'=>'library_catalog_opt_in']);
    if(array_key_exists('digital_price',$input)){
        $price=filter_var($input['digital_price'],FILTER_VALIDATE_FLOAT);
        if($price===false||$price<0||$price>1000)throw new InvalidArgumentException('Digital price must be between $0 and $1,000.');
        $metadata['price']=round((float)$price,2);
    }
    if(!sf_admin_isrc_valid((string)$metadata['isrc']))throw new InvalidArgumentException('Invalid ISRC.');
    // The private-draft normalizer checks owner equality. Unowned legacy site
    // assets are governed by the site administrator, not by a second account.
    $owned=$asset;$owned['created_by']=$owner;
    $artworkOwned=$artwork;
    if($artworkOwned)$artworkOwned['created_by']=$owner;
    $record=sf_agent_song_intake_normalize([
        'request_key'=>(string)($input['request_key']??''),
        'title'=>sf_clean_text($input['title']??($asset['title']??$asset['original_name']??''),180),
        'release'=>sf_clean_text($input['release']??'Unreleased',180),
        'genre'=>$metadata['genre']??'','year'=>$metadata['release_year']??0,
        'words_by'=>sf_clean_text($input['words_by']??($metadata['words_by']??''),180),
        'music_by'=>sf_clean_text($input['music_by']??($metadata['music_by']??''),180),
        'artist_display'=>sf_clean_text($input['artist_display']??($metadata['performing_artist']??$metadata['artist_display']??'Stonefellow'),180),
        'master_owner'=>sf_clean_text(($input['master_owner']??'')?:((($metadata['master_copyright']??''))?:($metadata['artist_legal']??'')),180),
        'composition_owner'=>sf_clean_text(($input['composition_owner']??'')?:((($metadata['composition_copyright']??''))?:($metadata['artist_legal']??'')),180),
        'isrc'=>$metadata['isrc'],'release_date'=>$metadata['release_date']??'',
        'explicit'=>$metadata['explicit']??false,
        'pod_eligible'=>!empty($input['pod_eligible']),
        'rights_confirmed'=>false,
        'artwork_asset_id'=>(int)($artwork['id']??0)
    ],$owned,$owner,$artworkOwned);
    $record['template_metadata']=$metadata;
    $record['imported_asset_ids']=[(int)$asset['id']];
    $record['candidate_preview_asset_id']=0;
    return $record;
}

/** A single source is a *public preview only*; no paid master is inferred. */
function sf_media_catalog_candidate(array $draft,array $preview,?array $master,?array $artwork):array {
    $key=(string)($draft['request_key']??'');
    $id=sf_agent_song_catalog_track_id($key);
    $split=$master!==null&&(int)$preview['id']!==(int)$master['id'];
    $source=is_array($draft['template_metadata']??null)?$draft['template_metadata']:[];
    $metadata=[];
    foreach(['pro_affiliation','ipi_cae','publisher','publisher_pro','publisher_ipi','producer','co_producer',
        'recording_engineer','mixing_engineer','mastering_engineer','studio','recording_location',
        'label','catalog_number','language','subgenre'] as $field)
        if(isset($source[$field]))$metadata[$field]=$source[$field];
    if(is_array($source['template']??null))$metadata['template']=$source['template'];
    $metadata+=['words_by'=>(string)$draft['words_by'],'music_by'=>(string)$draft['music_by'],
        'performing_artist'=>(string)$draft['artist_display'],
        'composition_copyright'=>(string)$draft['composition_owner'],
        'master_copyright'=>(string)$draft['master_owner'],
        'genre'=>(string)$draft['genre'],'isrc'=>(string)$draft['isrc'],
        'release_date'=>(string)$draft['release_date'],'release_year'=>(int)$draft['year'],
        'explicit'=>!empty($draft['explicit']),
        'source'=>['kind'=>'reviewed_media_library_song','publication_fingerprint'=>hash('sha256',
            $key.'|'.$draft['payload_hash'].'|'.$preview['sha256'].'|'.($artwork['sha256']??''))]];
    $row=['id'=>$id,'title'=>(string)$draft['title'],'release'=>(string)$draft['release'],
        'duration'=>max(1,(int)round((float)$preview['duration_seconds'])),
        'year'=>(int)$draft['year'],'audio'=>'api/media.php?id='.rawurlencode((string)$preview['uuid']),
        'artwork'=>$artwork?'api/media.php?id='.rawurlencode((string)$artwork['uuid']).'&variant=large':'',
        'state'=>'published','public_visible'=>true,'agent_discoverable'=>true,
        'mood'=>[],'themes'=>[],'energy'=>3,'price'=>max(0,(float)($source['price']??2)),
        'digital_purchase_enabled'=>$split,
        'podEligible'=>$split&&!empty($draft['pod_eligible']),
        'story'=>'','lyrics'=>'','credits'=>sf_admin_public_credits($metadata),'metadata'=>$metadata,
        'audio_publishing_mode'=>$split?'preview_master_split':'preview_only',
        'media_primary_asset'=>(int)$preview['id'],
        'media_primary_artwork_asset'=>(int)($artwork['id']??0),
        'created_at'=>gmdate('c')];
    if($split){$row['private_master_audio']='api/media.php?id='.rawurlencode((string)$master['uuid']);
        $row['media_private_master_asset']=(int)$master['id'];}
    return $row;
}

/** Public-player preflight: show the same exact missing fields in Admin as on the writer. */
function sf_media_catalog_missing_fields(array $draft):array {
    $required=['title'=>'Song title','artist_display'=>'Artist name',
        'words_by'=>'Lyrics / words credit','music_by'=>'Music / composition credit',
        'master_owner'=>'Master recording owner','composition_owner'=>'Composition rights owner'];
    $missing=[];
    foreach($required as $key=>$label)
        if(trim((string)($draft[$key]??''))==='')$missing[$key]=$label;
    return $missing;
}

function sf_media_catalog_publish_draft(int $owner,string $key,string $expectedHash,bool $rightsConfirmed,
    ?int $previewAssetId=null,?string $directory=null):array {
    if(!$rightsConfirmed)throw new InvalidArgumentException('Confirm your authority to publish this song publicly.');
    sf_agent_song_catalog_track_id($key);
    if(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))throw new InvalidArgumentException('Reload the exact song draft before publishing.');
    $dir=$directory??sf_agent_song_intake_dir($owner);
    if(!is_dir($dir))throw new RuntimeException('Private song draft not found.');
    $lock=fopen($dir.'/.draft-writer.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Private song writer unavailable.');
    try{
        $path=$dir.'/sd_'.$key.'.json';
        $draft=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(!is_array($draft)||(int)($draft['owner_id']??0)!==$owner)
            throw new RuntimeException('Song draft is not owned by this administrator.');
        if(!hash_equals((string)($draft['payload_hash']??''),$expectedHash))
            throw new RuntimeException('Song metadata changed. Reload before publishing.');
        $id=sf_agent_song_catalog_track_id($key);
        if(($draft['status']??'')==='published'){
            if(($draft['published_track_id']??'')!==$id)throw new RuntimeException('Inconsistent publication receipt.');
            foreach(sf_catalog() as $row)if(($row['id']??'')===$id)
                return ['changed'=>false,'track_id'=>$id,'publication'=>'published'];
            throw new RuntimeException('Published receipt has no Catalog record.');
        }
        if(($draft['status']??'')!=='draft')throw new RuntimeException('Use the prepared Catalog review for this song.');
        $missing=sf_media_catalog_missing_fields($draft);
        if($missing)throw new InvalidArgumentException('Complete publication review: '.implode(', ',$missing).'.');
        $master=sf_media_catalog_authorized_asset(sf_media_asset((int)$draft['asset_id']),$owner);
        if(!hash_equals((string)$draft['asset_sha256'],(string)$master['sha256']))
            throw new RuntimeException('Private source changed after intake.');
        $candidateId=$previewAssetId??(int)($draft['candidate_preview_asset_id']??0);
        if($candidateId<1)$candidateId=(int)$master['id'];
        $allowedIds=array_map('intval',(array)($draft['imported_asset_ids']??[]));
        $allowedIds[]=(int)$master['id'];
        if(!in_array($candidateId,$allowedIds,true))throw new InvalidArgumentException('Listening audio must belong to this song intake.');
        $preview=sf_media_catalog_authorized_asset(sf_media_asset($candidateId),$owner);
        $split=(int)$preview['id']!==(int)$master['id'];
        if($split&&hash_equals((string)$preview['sha256'],(string)$master['sha256']))
            throw new RuntimeException('Separate listening preview must differ from the private original.');
        if(sf_media_protected_master_asset((int)$preview['id']))
            throw new RuntimeException('This source is protected as a private master; select another public listening copy.');
        $artwork=null;
        if(!empty($draft['artwork_asset_id']))$artwork=sf_media_catalog_authorized_asset(
            sf_media_asset((int)$draft['artwork_asset_id']),$owner,'image');
        if($artwork&&!hash_equals((string)$draft['artwork_sha256'],(string)$artwork['sha256']))
            throw new RuntimeException('Song artwork changed after review.');
        $bytes=file_get_contents(SF_ROOT.'/data/catalog.json');
        $catalog=$bytes===false?null:json_decode($bytes,true);
        if(!is_array($catalog))throw new RuntimeException('Catalog source unavailable.');
        $row=sf_media_catalog_candidate($draft,$preview,$split?$master:null,$artwork);
        $same=false;
        foreach($catalog as $existing){
            if((string)($existing['id']??'')===$id){
                if(($existing['metadata']['source']['publication_fingerprint']??'')!==
                    $row['metadata']['source']['publication_fingerprint']
                    ||($existing['audio']??'')!==$row['audio'])
                    throw new RuntimeException('Catalog ID already belongs to different song details.');
                $same=true;continue;
            }
            if(($row['metadata']['isrc']??'')!==''&&($existing['metadata']['isrc']??'')===$row['metadata']['isrc'])
                throw new RuntimeException('ISRC is already in the public Catalog.');
            if((int)($existing['media_primary_asset']??0)===(int)$preview['id']
                ||(string)($existing['audio']??'')===(string)$row['audio']
                ||(int)($existing['media_private_master_asset']??0)===(int)$master['id'])
                throw new RuntimeException('This audio is already assigned to another Catalog song.');
        }
        if(!$same)$catalog[]=$row;
        if($split)sf_media_attach((int)$master['id'],'track',$id,'master_audio',
            ['public_visible'=>false,'download_allowed'=>false]);
        sf_media_attach((int)$preview['id'],'track',$id,'primary_audio',
            ['public_visible'=>false,'download_allowed'=>false]);
        if($artwork)sf_media_attach((int)$artwork['id'],'track',$id,'primary_artwork',
            ['public_visible'=>false,'download_allowed'=>false]);
        sf_admin_write_catalog($catalog,hash('sha256',$bytes));
        $activated=false;
        foreach(sf_media_links('track',$id) as $link){
            if((int)$link['asset_id']===(int)$preview['id']&&$link['role']==='primary_audio'){
                sf_media_update_link((int)$link['id'],['public_visible'=>true,'download_allowed'=>false,'featured'=>true]);
                $activated=true;
            }elseif($artwork&&(int)$link['asset_id']===(int)$artwork['id']&&$link['role']==='primary_artwork')
                sf_media_update_link((int)$link['id'],['public_visible'=>true,'download_allowed'=>false,'featured'=>true]);
        }
        if(!$activated)throw new RuntimeException('Catalog saved, but public audio needs reconciliation.');
        $draft['status']='published';$draft['rights_confirmed']=true;
        $draft['published_at']=gmdate('c');$draft['published_track_id']=$id;
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
        $json=json_encode($draft,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not save publication receipt.');
        chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not finalize publication receipt.');}
        return ['changed'=>true,'track_id'=>$id,'publication'=>'published','preview_only'=>!$split];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
