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
    $record['digital_purchase_enabled']=!empty($input['digital_purchase_enabled']);
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
        'digital_purchase_enabled'=>$split&&!empty($draft['digital_purchase_enabled']),
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

/**
 * Hosting-safe MP3 listening copy: strip private ID3 metadata without exposing
 * the original file, even when the host has no FFmpeg executable. MP3 frames
 * retain their original bitrate; this is a metadata-free remux, not a transcode.
 */
function sf_media_catalog_remux_mp3_preview(array $master,int $owner,string $key):array {
    $master=sf_media_catalog_authorized_asset($master,$owner);
    if($owner<1||!preg_match('/^[a-f0-9]{32}$/D',$key))
        throw new InvalidArgumentException('A verified, administrator-owned song is required.');
    if(strtolower((string)($master['extension']??''))!=='mp3')
        throw new RuntimeException('A WAV master needs a separate public listening MP3 when server conversion is unavailable.');
    $source=SF_ROOT.'/'.ltrim((string)$master['stored_path'],'/');
    $size=filesize($source);
    if($size===false||$size<1024)throw new RuntimeException('The MP3 source is too small for a listening copy.');
    $in=fopen($source,'rb');
    if(!$in)throw new RuntimeException('Could not read the private MP3.');
    try{
        $header=fread($in,10);$start=0;
        if(strlen($header)===10&&substr($header,0,3)==='ID3'){
            for($i=6;$i<10;$i++)if((ord($header[$i])&128)!==0)
                throw new RuntimeException('The MP3 has invalid ID3 metadata. Upload a separate listening MP3.');
            $start=10+((ord($header[6])&127)<<21)+((ord($header[7])&127)<<14)
                +((ord($header[8])&127)<<7)+(ord($header[9])&127);
            if((ord($header[5])&16)!==0)$start+=10; // ID3v2.4 footer
        }
        if($start>$size-1024)throw new RuntimeException('The MP3 has no verified audio frames.');
        // A small bounded scan tolerates padding between the ID3 tag and audio.
        fseek($in,$start);$sample=fread($in,min(65536,$size-$start));
        $offset=null;
        for($i=0,$n=strlen($sample)-4;$i<$n;$i++){
            if(ord($sample[$i])!==255||(ord($sample[$i+1])&224)!==224)continue;
            $version=(ord($sample[$i+1])>>3)&3;
            $layer=(ord($sample[$i+1])>>1)&3;
            $bitrate=(ord($sample[$i+2])>>4)&15;
            $rate=(ord($sample[$i+2])>>2)&3;
            if($version!==1&&$layer!==0&&$bitrate>0&&$bitrate<15&&$rate!==3){$offset=$start+$i;break;}
        }
        if($offset===null)throw new RuntimeException('Could not verify MP3 audio frames. Upload a separate listening MP3.');
        $end=$size;
        if($end>=128){fseek($in,$end-128);if(fread($in,3)==='TAG')$end-=128;}
        // Reject trailing APEv2 metadata rather than risk publishing private tags.
        if($end>=32){fseek($in,$end-32);$footer=fread($in,32);
            if(substr($footer,0,8)==='APETAGEX'){
                $tagSize=unpack('V',substr($footer,12,4))[1]??0;
                if($tagSize<32||$tagSize>$end-$offset-1024)
                    throw new RuntimeException('Unsupported MP3 footer. Upload a separate listening MP3.');
                $end-=$tagSize;
            }
        }
        if($end-$offset<1024)throw new RuntimeException('No usable MP3 frames remain after removing private tags.');
        $token=hash('sha256',$master['sha256'].'|'.$owner.'|'.$key.'|tagless-public-mp3-v1');
        $relative='storage/media/listening/'.substr($token,0,2).'/'.$token.'.mp3';
        $target=SF_ROOT.'/'.$relative;
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0770,true)&&!is_dir(dirname($target)))
            throw new RuntimeException('Could not create private listening-copy storage.');
        if(!is_file($target)){
            $tmp=$target.'.'.bin2hex(random_bytes(8)).'.tmp';
            $out=fopen($tmp,'wb');
            if(!$out)throw new RuntimeException('Could not write listening MP3.');
            try{
                // A new, innocuous ID3 tag ensures a distinct asset checksum
                // while the original artist/owner/notes tags are never copied.
                // ID3v2.3 TXXX text encoding 0 is portable ISO-8859-1;
                // the field contains ASCII only, so every MP3 tag reader can parse it.
                $value="\x00Stonefellow public listening copy\x00".$token;
                $frame='TXXX'.pack('N',strlen($value))."\x00\x00".$value;
                $len=strlen($frame);
                $sync=chr(($len>>21)&127).chr(($len>>14)&127).chr(($len>>7)&127).chr($len&127);
                if(fwrite($out,"ID3\x03\x00\x00".$sync.$frame)!==10+$len)
                    throw new RuntimeException('Could not write listening MP3 metadata.');
                fseek($in,$offset);
                if(stream_copy_to_stream($in,$out,$end-$offset)!==$end-$offset)
                    throw new RuntimeException('Could not copy verified listening MP3 frames.');
            }catch(Throwable $error){fclose($out);@unlink($tmp);throw $error;}
            fclose($out);chmod($tmp,0600);
            if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Could not save listening MP3.');}
        }
    }finally{fclose($in);}
    $preview=sf_media_register_existing_file($relative,'Stonefellow listening copy.mp3',$owner);
    if((int)$preview['id']===(int)$master['id']
        ||hash_equals((string)$preview['sha256'],(string)$master['sha256'])
        ||sf_media_protected_master_asset((int)$preview['id']))
        throw new RuntimeException('The listening copy must remain separate from the private master.');
    return sf_media_catalog_authorized_asset($preview,$owner);
}

/** Transcode a protected source into a lower-bitrate MP3, or remux MP3 safely. */
function sf_media_catalog_transcoded_preview(array $master,int $owner,string $key):array {
    $master=sf_media_catalog_authorized_asset($master,$owner);
    if($owner<1||!preg_match('/^[a-f0-9]{32}$/D',$key))
        throw new InvalidArgumentException('A verified, owned song is required.');
    $ffmpeg='';
    foreach(['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg'] as $binary)
        if(is_file($binary)&&is_executable($binary)){$ffmpeg=$binary;break;}
    if($ffmpeg===''||!function_exists('proc_open')||in_array('proc_open',array_map('trim',explode(',',(string)ini_get('disable_functions'))),true))
        return sf_media_catalog_remux_mp3_preview($master,$owner,$key);
    $token=hash('sha256',$master['sha256'].'|'.$owner.'|'.$key.'|public-mp3-128k-v1');
    $relative='storage/media/listening/'.substr($token,0,2).'/'.$token.'.mp3';
    $target=SF_ROOT.'/'.$relative;$source=SF_ROOT.'/'.ltrim((string)$master['stored_path'],'/');
    $dir=dirname($target);
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))
        throw new RuntimeException('Could not create listening-copy storage.');
    if(!is_file($target)){
        $tmp=$target.'.'.bin2hex(random_bytes(8)).'.mp3';
        $command=[$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-i',$source,
            '-map','0:a:0','-map_metadata','-1','-vn','-codec:a','libmp3lame',
            '-ar','44100','-ac','2','-b:a','128k','-f','mp3',$tmp];
        $pipes=[];$process=@proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],
            2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($process))return sf_media_catalog_remux_mp3_preview($master,$owner,$key);
        fclose($pipes[0]);$exit=proc_close($process);
        if($exit!==0||!is_file($tmp)||(filesize($tmp)?:0)<1000){@unlink($tmp);
            return sf_media_catalog_remux_mp3_preview($master,$owner,$key);}
        chmod($tmp,0600);
        if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Could not store converted listening audio.');}
    }
    $preview=sf_media_register_existing_file($relative,'Stonefellow listening copy.mp3',$owner);
    if((int)$preview['id']===(int)$master['id']
        ||hash_equals((string)$preview['sha256'],(string)$master['sha256'])
        ||sf_media_protected_master_asset((int)$preview['id']))
        throw new RuntimeException('Converted listening copy is not safely separate from the private source.');
    return sf_media_catalog_authorized_asset($preview,$owner);
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
        // An administrator may explicitly supply a newly uploaded listening
        // MP3 in the same review. Site-wide/legacy media cannot be substituted.
        if(!in_array($candidateId,$allowedIds,true)){
            $chosen=sf_media_asset($candidateId);
            if($previewAssetId!==$candidateId||!$chosen||(int)($chosen['created_by']??0)!==$owner)
                throw new InvalidArgumentException('Upload a listening MP3 owned by this administrator.');
        }
        $preview=sf_media_catalog_authorized_asset(sf_media_asset($candidateId),$owner);
        if((int)$preview['id']===(int)$master['id']
            &&(sf_media_protected_master_asset((int)$master['id'])
                ||!empty($draft['digital_purchase_enabled'])||!empty($draft['pod_eligible'])))
            $preview=sf_media_catalog_transcoded_preview($master,$owner,$key);
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
