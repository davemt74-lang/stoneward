<?php
declare(strict_types=1);
/**
 * Section 90F: private, owner-scoped song intake. No public catalog write here.
 * The authenticated media uploader stores and analyzes bytes separately;
 * this store holds only references and editable draft metadata.
 */
function sf_agent_song_intake_normalize(array $raw,array $asset,int $adminId,?array $artworkAsset=null):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator required.');
    $key=(string)($raw['request_key']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Invalid draft request key.');
    if(($asset['category']??'')!=='audio')throw new InvalidArgumentException('Choose an MP3 or WAV recording.');
    if((int)($asset['id']??0)<1 || (int)($asset['created_by']??0)!==$adminId)
        throw new InvalidArgumentException('The recording is not owned by this administrator.');
    $title=sf_clean_text($raw['title']??'',180);
    if($title==='')throw new InvalidArgumentException('A song title is required.');
    $year=trim((string)($raw['year']??''))===''?0:filter_var($raw['year'],FILTER_VALIDATE_INT);
    if($year===false || ($year!==0 && ($year<1900 || $year>2100)))
        throw new InvalidArgumentException('Year must be blank or between 1900 and 2100.');
    $artworkId=(int)($raw['artwork_asset_id']??0);
    if($artworkId<0||($artworkId>0&&($artworkAsset===null
        ||(int)($artworkAsset['id']??0)!==$artworkId
        ||($artworkAsset['category']??'')!=='image'
        ||(int)($artworkAsset['created_by']??0)!==$adminId)))
        throw new InvalidArgumentException('Choose artwork uploaded by this administrator.');
    $isrc=strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)($raw['isrc']??''))??'');
    if($isrc!==''&&!preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/D',$isrc))
        throw new InvalidArgumentException('ISRC must be 12 characters (or left blank).');
    $date=trim((string)($raw['release_date']??''));
    if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))
        throw new InvalidArgumentException('Release date must be YYYY-MM-DD.');
    $parsedDate=$date===''?null:DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if($date!==''&&(!$parsedDate||$parsedDate->format('Y-m-d')!==$date))
        throw new InvalidArgumentException('Release date must be a real YYYY-MM-DD date.');
    $record=[
      'id'=>'sd_'.$key,'request_key'=>$key,'owner_id'=>$adminId,'status'=>'draft',
      'asset_id'=>(int)$asset['id'],'asset_sha256'=>(string)($asset['sha256']??''),
      'filename'=>sf_clean_text($asset['original_name']??'',255),
      'duration_seconds'=>round(max(0,(float)($asset['duration_seconds']??0)),3),
      'codec'=>sf_clean_text($asset['codec']??'',120),
      'title'=>$title,'release'=>sf_clean_text($raw['release']??'Unreleased',180),
      'genre'=>sf_clean_text($raw['genre']??'',120),'year'=>$year,
      'words_by'=>sf_clean_text($raw['words_by']??'',180),
      'music_by'=>sf_clean_text($raw['music_by']??'',180),
      'notes'=>sf_clean_text($raw['notes']??'',1000),
      'artist_display'=>sf_clean_text($raw['artist_display']??'Stonefellow',180),
      'master_owner'=>sf_clean_text($raw['master_owner']??'',180),
      'composition_owner'=>sf_clean_text($raw['composition_owner']??'',180),
      'rights_confirmed'=>filter_var($raw['rights_confirmed']??false,FILTER_VALIDATE_BOOLEAN),
      'artwork_asset_id'=>$artworkId,
      'artwork_sha256'=>$artworkId>0?(string)($artworkAsset['sha256']??''):'',
      'isrc'=>$isrc,'release_date'=>$date,
      'explicit'=>filter_var($raw['explicit']??false,FILTER_VALIDATE_BOOLEAN),
    ];
    if($record['release']==='')$record['release']='Unreleased';
    return $record;
}
/** Readiness is an owner-facing preflight, never a publication decision. */
function sf_agent_song_intake_readiness(array $draft,?array $audioAsset=null,?array $artworkAsset=null,array $catalog=[]):array {
    $blockers=[];
    foreach(['title'=>'Song title','artist_display'=>'Artist name','words_by'=>'Lyric credits',
        'music_by'=>'Composition credits','master_owner'=>'Master recording owner',
        'composition_owner'=>'Composition rights owner'] as $key=>$label)
        if(trim((string)($draft[$key]??''))==='')$blockers[]=$label.' is missing.';
    if(empty($draft['rights_confirmed']))$blockers[]='Administrator must confirm authority to prepare this recording.';
    if((int)($draft['asset_id']??0)<1||!$audioAsset
        ||(int)($audioAsset['id']??0)!==(int)$draft['asset_id']
        ||($audioAsset['category']??'')!=='audio'
        ||(int)($audioAsset['created_by']??0)!==(int)($draft['owner_id']??0)
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($draft['asset_sha256']??''))
        ||!hash_equals((string)($draft['asset_sha256']??''),(string)($audioAsset['sha256']??'')))
        $blockers[]='Private audio ownership or integrity could not be verified.';
    if((int)($draft['artwork_asset_id']??0)<1||!$artworkAsset
        ||(int)($artworkAsset['id']??0)!==(int)$draft['artwork_asset_id']
        ||($artworkAsset['category']??'')!=='image'
        ||(int)($artworkAsset['created_by']??0)!==(int)($draft['owner_id']??0)
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($draft['artwork_sha256']??''))
        ||!hash_equals((string)($draft['artwork_sha256']??''),(string)($artworkAsset['sha256']??'')))
        $blockers[]='Private artwork ownership or integrity could not be verified.';
    $isrc=(string)($draft['isrc']??'');
    if($isrc!=='')foreach($catalog as $track){
        if((string)($track['metadata']['isrc']??'')===$isrc){
            $blockers[]='ISRC is already assigned to catalog track '.(string)($track['id']??'unknown').'.';
            break;
        }
    }
    return ['ready'=>$blockers===[],'score'=>max(0,(int)round(100*(9-count($blockers))/9)),
        'blockers'=>$blockers,'stage'=>'private_catalog_preflight'];
}
function sf_agent_song_intake_dir(int $adminId):string {
    if($adminId<1)throw new InvalidArgumentException('Administrator required.');
    return SF_ROOT.'/storage/agent-song-drafts/'.$adminId;
}
function sf_agent_song_intake_list(int $adminId,?string $directory=null):array {
    $dir=$directory??sf_agent_song_intake_dir($adminId);
    if(!is_dir($dir))return [];
    $records=[];
    foreach(glob($dir.'/sd_'.str_repeat('?',32).'.json')?:[] as $path){
        $r=json_decode((string)file_get_contents($path),true);
        if(!is_array($r)||(int)($r['owner_id']??0)!==$adminId||!in_array(($r['status']??''),['draft','catalog_ready'],true))continue;
        $records[]=$r;
    }
    usort($records,static fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
    return array_slice($records,0,100);
}
/** An exact-revision, single-transition preflight; no catalog writer or public URL. */
function sf_agent_song_intake_prepare(int $adminId,string $key,string $expectedHash,
    array $audioAsset,?array $artworkAsset=null,?string $directory=null,array $catalog=[]):array {
    if($adminId<1||!preg_match('/^[a-f0-9]{32}$/D',$key)
        ||!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))
        throw new InvalidArgumentException('Invalid song draft or revision.');
    $dir=$directory??sf_agent_song_intake_dir($adminId);
    if(!is_dir($dir))throw new RuntimeException('Private song draft not found.');
    $lock=fopen($dir.'/.draft-writer.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Draft writer unavailable.');
    try{
        $path=$dir.'/sd_'.$key.'.json';
        if(!is_file($path))throw new RuntimeException('Private song draft not found.');
        $draft=json_decode((string)file_get_contents($path),true);
        if(!is_array($draft)||(int)($draft['owner_id']??0)!==$adminId)
            throw new RuntimeException('Song draft ownership could not be verified.');
        if(!hash_equals((string)($draft['payload_hash']??''),$expectedHash))
            throw new RuntimeException('Draft changed since review. Reload the latest revision.');
        if(($draft['status']??'')==='catalog_ready')return ['draft'=>$draft,'changed'=>false];
        if(($draft['status']??'')!=='draft')throw new RuntimeException('Draft is not eligible for preparation.');
        $readiness=sf_agent_song_intake_readiness($draft,$audioAsset,$artworkAsset,$catalog);
        if(!$readiness['ready'])throw new RuntimeException('Preflight incomplete: '.implode(' ',$readiness['blockers']));
        $draft['status']='catalog_ready';
        $draft['prepared_at']=gmdate('c');
        $draft['preflight']=$readiness;
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
        $json=json_encode($draft,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not prepare private song draft.');
        chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not finalize preparation.');}
        return ['draft'=>$draft,'changed'=>true];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_agent_song_intake_save(int $adminId,array $record,?string $directory=null):array {
    if($adminId<1 || (int)($record['owner_id']??0)!==$adminId)
        throw new InvalidArgumentException('Owner mismatch.');
    $key=(string)($record['request_key']??'');
    if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Invalid draft request key.');
    $dir=$directory??sf_agent_song_intake_dir($adminId);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Private draft directory unavailable.');
    $lock=fopen($dir.'/.draft-writer.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Draft writer unavailable.');
    try{
        $path=$dir.'/sd_'.$key.'.json';
        $hash=hash('sha256',json_encode($record,JSON_THROW_ON_ERROR));
        if(is_file($path)){
            $existing=json_decode((string)file_get_contents($path),true);
            if(!is_array($existing)||(int)($existing['owner_id']??0)!==$adminId)
                throw new RuntimeException('Saved draft could not be verified.');
            if(!hash_equals((string)($existing['payload_hash']??''),$hash))
                throw new RuntimeException('This request key already saved different song details. Inspect the existing draft.');
            return ['draft'=>$existing,'created'=>false];
        }
        // The same uploaded recording must not silently produce duplicate drafts.
        foreach(sf_agent_song_intake_list($adminId,$dir) as $existing){
            if((int)($existing['asset_id']??0)===(int)$record['asset_id'])
                throw new RuntimeException('This recording already has a private song draft. Open the existing draft instead of creating a duplicate.');
        }
        if(count(sf_agent_song_intake_list($adminId,$dir))>=100)
            throw new RuntimeException('Private draft limit reached. Finish or archive an existing draft first.');
        $created=$record+['created_at'=>gmdate('c'),'payload_hash'=>$hash];
        $json=json_encode($created,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not save song draft.');
        chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not finalize song draft.');}
        return ['draft'=>$created,'created'=>true];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Exact-revision, owner-authorized revision of an existing private draft. */
function sf_agent_song_intake_update(int $adminId,array $record,string $expectedHash,?string $directory=null):array {
    $key=(string)($record['request_key']??'');
    if($adminId<1||(int)($record['owner_id']??0)!==$adminId||!preg_match('/^[a-f0-9]{32}$/D',$key))
        throw new InvalidArgumentException('Invalid draft owner or identifier.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash))throw new InvalidArgumentException('A valid saved-draft revision is required.');
    $dir=$directory??sf_agent_song_intake_dir($adminId);
    if(!is_dir($dir))throw new RuntimeException('Saved draft not found.');
    $lock=fopen($dir.'/.draft-writer.lock','c');
    if($lock===false||!flock($lock,LOCK_EX))throw new RuntimeException('Draft writer unavailable.');
    try{
        $path=$dir.'/sd_'.$key.'.json';
        if(!is_file($path))throw new RuntimeException('Saved draft not found.');
        $existing=json_decode((string)file_get_contents($path),true);
        if(!is_array($existing)||(int)($existing['owner_id']??0)!==$adminId||($existing['status']??'')!=='draft')
            throw new RuntimeException('Draft ownership or status could not be verified.');
        if((int)($record['asset_id']??0)!==(int)($existing['asset_id']??0)
            ||($record['asset_sha256']??'')!==($existing['asset_sha256']??''))
            throw new RuntimeException('A draft recording cannot be replaced. Start a new song intake.');
        $hash=hash('sha256',json_encode($record,JSON_THROW_ON_ERROR));
        if(hash_equals((string)($existing['payload_hash']??''),$hash))return ['draft'=>$existing,'updated'=>false];
        if(!hash_equals((string)($existing['payload_hash']??''),$expectedHash))
            throw new RuntimeException('This draft changed since it was opened. Reload before editing.');
        $updated=$record+['created_at'=>$existing['created_at'],'updated_at'=>gmdate('c'),'payload_hash'=>$hash];
        $tmp=$path.'.tmp.'.bin2hex(random_bytes(6));
        $json=json_encode($updated,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Could not update private draft.');
        chmod($tmp,0600);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not finalize song draft update.');}
        return ['draft'=>$updated,'updated'=>true];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
