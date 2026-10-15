<?php
declare(strict_types=1);
require_once __DIR__.'/stem-release-readiness.php';
require_once __DIR__.'/track-media-publishing.php';
require_once __DIR__.'/kunaki-media-transfer.php';

/**
 * 86I joins REAPER Stem Studio to the EXISTING media_assets, media_links,
 * private original and fan-ownership system. Neither stage nor publishing
 * replaces the public preview, modifies purchases, or grants vinyl licenses.
 */
function sf_stem_86i_wav_header(string $path):array {
    $handle=@fopen($path,'rb');
    if(!$handle)throw new InvalidArgumentException('Cannot open uploaded master.');
    try{$bytes=(string)fread($handle,44);}finally{fclose($handle);}
    if(strlen($bytes)<44||substr($bytes,0,4)!=='RIFF'||substr($bytes,8,4)!=='WAVE'||
        substr($bytes,12,4)!=='fmt '||unpack('V',substr($bytes,16,4))[1]!==16||
        substr($bytes,36,4)!=='data')
        throw new InvalidArgumentException('Expected standard 48 kHz stereo PCM WAV (44-byte header).');
    $format=unpack('v',substr($bytes,20,2))[1];
    $channels=unpack('v',substr($bytes,22,2))[1];
    $rate=unpack('V',substr($bytes,24,4))[1];
    $bits=unpack('v',substr($bytes,34,2))[1];
    $data=unpack('V',substr($bytes,40,4))[1];
    $size=filesize($path);
    if($format!==1||$channels!==2||$rate!==48000||$bits!==16||
       $data<19200||$data+44!==$size||$size>104857600)
        throw new InvalidArgumentException('WAV must be complete 48 kHz stereo PCM 16-bit, at most 100 MiB.');
    return ['bytes'=>$size,'duration_seconds'=>round($data/192000,3),
        'sample_rate'=>48000,'channels'=>2,'bits'=>16];
}
function sf_stem_86i_require_review(array $project):array {
    $state=sf_stem_86h_review_status($project);
    if(($state['status']??'')!=='reviewed_pending_media_upload'||
       ($project['mastering_readiness']['state']??'')!=='reviewed_pending_media_upload')
        throw new InvalidArgumentException('The current mix needs an explicit, non-stale 86H review.');
    $profile=(string)($project['mastering_readiness']['profile']??'');
    if(!isset(sf_stem_86h_presets()[$profile]))
        throw new InvalidArgumentException('Unknown readiness destination.');
    if($profile==='vinyl')
        throw new InvalidArgumentException('Vinyl pre-master requires separate cutting-engineer verification before catalog promotion.');
    $row=array_slice((array)($project['mastering_analyses']??[]),-1)[0]??null;
    $sha=(string)($row['details']['render_sha256']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||
       (int)($row['revision']??-1)!==(int)($project['mastering_readiness']['analysis_revision']??-2))
        throw new InvalidArgumentException('Re-render and save a cryptographically identified WAV analysis, then repeat 86H review.');
    if(($row['measurement_provenance']??'')!=='browser_measured_not_server_verified')
        throw new InvalidArgumentException('Mastering measurement provenance is not supported.');
    return ['profile'=>$profile,'sha256'=>$sha,'analysis'=>$row];
}
function sf_stem_86i_status(array $project):array {
    $p=(array)($project['stem_publication']??[]);
    $readiness=sf_stem_86h_review_status($project);
    $sig=sf_stem_86h_signature($project);
    return ['state'=>$p['state']??'not_staged',
        'audio_signature_matches'=>($p['audio_signature']??'')===$sig,
        'asset_id'=>(int)($p['asset_id']??0),'link_id'=>(int)($p['link_id']??0),
        'sha256'=>$p['sha256']??null,'profile'=>$p['profile']??null,
        'review'=>$readiness,'public_preview_unchanged'=>true,
        'publish_scope'=>'existing_media_library_private_master_only',
        'requires_exact_owner_publication_confirmation'=>true,
        'mastering_not_independently_certified'=>true];
}
function sf_stem_86i_stage(string $songId,int $revision,array $file,int $adminId):array {
    $p=sf_stem_project_read($songId);
    if(!$p)throw new InvalidArgumentException('Private REAPER project not found.');
    if((int)$p['revision']!==$revision)throw new RuntimeException('Project changed. Refresh before staging.');
    $review=sf_stem_86i_require_review($p);
    $source=(string)($file['tmp_name']??'');
    if(($file['error']??99)!==UPLOAD_ERR_OK||!is_uploaded_file($source))
        throw new InvalidArgumentException('A newly rendered private WAV upload is required.');
    $info=sf_stem_86i_wav_header($source);
    if((float)$info['duration_seconds']<.1)
        throw new InvalidArgumentException('Master recording is too short.');
    $sha=hash_file('sha256',$source);
    if(!is_string($sha)||!hash_equals($review['sha256'],$sha))
        throw new InvalidArgumentException('Uploaded WAV SHA-256 does not match the analyzed and reviewed render.');
    $old=(array)($p['stem_publication']??[]);
    if(in_array(($old['state']??''),['staged','publishing_in_progress','published_private_master'],true))
        throw new InvalidArgumentException('A master is already staged or published. Review its status; no duplicate staging.');
    $asset=sf_media_ingest_upload($file,$adminId);
    if(($asset['category']??'')!=='audio'||($asset['extension']??'')!=='wav'||
       !hash_equals((string)($asset['sha256']??''),$sha))
        throw new RuntimeException('Managed Media Library did not preserve the expected private WAV.');
    $links=sf_media_attach((int)$asset['id'],'track',$songId,'audio_candidate',
        ['public_visible'=>false,'download_allowed'=>false]);
    $found=null;
    foreach($links as $link)if((int)$link['asset_id']===(int)$asset['id']&&
        (string)$link['role']==='audio_candidate'&&empty($link['public_visible'])&&empty($link['download_allowed']))
        {$found=$link;break;}
    if(!$found)throw new RuntimeException('Private media candidate could not be linked.');
    $p['stem_publication']=[
        'state'=>'staged','asset_id'=>(int)$asset['id'],'link_id'=>(int)$found['id'],
        'sha256'=>$sha,'profile'=>$review['profile'],
        'audio_signature'=>sf_stem_86h_signature($p),'analysis_revision'=>$review['analysis']['revision'],
        'staged_at'=>gmdate('c'),'staged_by'=>$adminId,
        'catalog_published'=>false,'original_private'=>true
    ];
    return sf_stem_project_write($songId,$p,$revision);
}
function sf_stem_86i_publish(string $id,int $revision,string $confirmation,int $adminId):array {
    if($confirmation!=='PUBLISH PRIVATE MASTER')
        throw new InvalidArgumentException('Type PUBLISH PRIVATE MASTER to approve changing the catalog’s private original.');
    $p=sf_stem_project_read($id);
    if(!$p||(int)$p['revision']!==$revision)
        throw new RuntimeException('Project revision changed. Reload before publishing.');
    $stage=(array)($p['stem_publication']??[]);
    if(($stage['state']??'')!=='staged'||!hash_equals(
        (string)($stage['audio_signature']??''),sf_stem_86h_signature($p)))
        throw new InvalidArgumentException('A current, verified, staged master is required.');
    $review=sf_stem_86i_require_review($p);
    if(!hash_equals((string)($stage['sha256']??''),$review['sha256']))
        throw new InvalidArgumentException('Staged render no longer matches reviewed analysis.');
    $linkId=(int)($stage['link_id']??0);$assetId=(int)($stage['asset_id']??0);
    $matches=array_values(array_filter(sf_media_links('track',$id),
        static fn(array $l):bool=>(int)$l['id']===$linkId&&(int)$l['asset_id']===$assetId &&
            $l['role']==='audio_candidate'&&!$l['public_visible']&&!$l['download_allowed']));
    if(count($matches)!==1)throw new InvalidArgumentException('Staged Media Library attachment has changed.');
    $asset=sf_media_asset($assetId);
    if(!$asset||!hash_equals((string)$asset['sha256'],(string)$stage['sha256']))
        throw new InvalidArgumentException('Staged WAV no longer matches its SHA-256.');
    // Reserve a durable, no-auto-retry intent before external catalog mutation.
    $p['stem_publication']['state']='publishing_in_progress';
    $p['stem_publication']['confirmation_at']=gmdate('c');
    $p['stem_publication']['confirmed_by']=$adminId;
    $intent=sf_stem_project_write($id,$p,$revision);
    // Existing guarded publishing verifies media type, private visibility,
    // public preview separation, checksum and downloadable-original contract.
    $result=sf_track_media_publish_master($id,$linkId);
    $intent['stem_publication']['state']='published_private_master';
    $intent['stem_publication']['catalog_published']=true;
    $intent['stem_publication']['published_at']=gmdate('c');
    $intent['stem_publication']['prior_private_master']=(string)$result['previous_master'];
    return sf_stem_project_write($id,$intent,(int)$intent['revision']);
}
/** Manual-only crash recovery: no publishing retry and no new side effects. */
function sf_stem_86i_reconcile(string $id,int $revision,string $confirmation):array {
    if($confirmation!=='RECONCILE MASTER')throw new InvalidArgumentException('Explicit reconciliation confirmation required.');
    $p=sf_stem_project_read($id);
    if(!$p||(int)$p['revision']!==$revision)throw new RuntimeException('Stale project revision.');
    if(($p['stem_publication']['state']??'')!=='publishing_in_progress')
        throw new InvalidArgumentException('No interrupted catalog publication to reconcile.');
    $asset=sf_media_asset((int)($p['stem_publication']['asset_id']??0));
    if(!$asset)throw new InvalidArgumentException('Cannot find the staged private asset.');
    $expected='api/media.php?id='.(string)$asset['uuid'];
    $track=sf_stem_project_track($id);
    if(($track['private_master_audio']??'')!==$expected)
        throw new RuntimeException('Catalog does not contain the candidate original. Inspect Media Library manually; no automatic replay.');
    $p['stem_publication']['state']='published_private_master';
    $p['stem_publication']['catalog_published']=true;
    $p['stem_publication']['reconciled_at']=gmdate('c');
    return sf_stem_project_write($id,$p,$revision);
}
