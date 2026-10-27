<?php
declare(strict_types=1);
/** Per-recording folder intake. Never writes data/catalog.json or a public media link. */
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-song-intake.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth(true);
header('Cache-Control: private, no-store');
$owner=(int)$me['id'];
try {
    $body=sf_request_json();
    $batch=(string)($body['batch_id']??'');
    $key=(string)($body['request_key']??'');
    $templateId=(string)($body['template_id']??'');
    $track=is_array($body['track']??null)?$body['track']:[];
    if(!preg_match('/^[a-z0-9][a-z0-9-]{5,79}$/D',$batch)
        ||!preg_match('/^[a-f0-9]{32}$/D',$key))
        throw new InvalidArgumentException('Invalid private upload session. Rescan the folder.');
    $template=sf_admin_template($templateId);
    if(!$template)throw new InvalidArgumentException('Select an existing metadata template.');
    $title=sf_clean_text($track['title']??'',180);
    if($title==='')throw new InvalidArgumentException('This recording needs a title.');
    $isrc=sf_admin_normalize_isrc($track['isrc']??'');
    if(!sf_admin_isrc_valid($isrc))throw new InvalidArgumentException('ISRC must be 12 valid characters, or blank.');
    foreach(sf_catalog() as $published){
        if($isrc!==''&&($published['metadata']['isrc']??'')===$isrc)
            throw new InvalidArgumentException('The ISRC is already assigned to a published song.');
    }
    $ids=(array)($track['upload_ids']??[]);
    if(!$ids||count($ids)>24||count($ids)!==count(array_unique($ids)))
        throw new InvalidArgumentException('Select between 1 and 24 unique audio files per song.');
    $uploaded=[];$sourceTrackKey='';
    foreach($ids as $id){
        if(!is_string($id)||!preg_match('/^UP-[A-F0-9]{12}$/D',$id))
            throw new InvalidArgumentException('Invalid staged file reference.');
        $path=SF_ROOT.'/storage/imports/'.$batch.'/uploads/'.$id.'.json';
        $data=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        if(!is_array($data)||($data['batch_id']??'')!==$batch||(int)($data['owner_id']??0)!==$owner)
            throw new RuntimeException('An uploaded file is missing or belongs to another upload session.');
        if($sourceTrackKey!==''&&$sourceTrackKey!==(string)($data['track_key']??''))
            throw new RuntimeException('Files from different recording groups cannot be combined.');
        $sourceTrackKey=(string)($data['track_key']??'');
        $root=realpath(SF_ROOT.'/storage/media');
        $absolute=realpath(SF_ROOT.'/'.ltrim((string)($data['stored_path']??''),'/'));
        if(!$root||!$absolute||!str_starts_with($absolute,$root.DIRECTORY_SEPARATOR)
            ||!is_file($absolute)||!preg_match('/^[a-f0-9]{64}$/D',(string)($data['sha256']??''))
            ||!hash_equals((string)$data['sha256'],(string)hash_file('sha256',$absolute)))
            throw new RuntimeException('Uploaded audio failed private storage integrity verification.');
        $uploaded[$id]=$data;
    }
    $masterId=(string)($track['master_upload_id']??'');
    $previewId=(string)($track['preview_upload_id']??'');
    if(!isset($uploaded[$masterId])||!isset($uploaded[$previewId]))
        throw new InvalidArgumentException('Choose the original and candidate listening copy from this song.');
    $assets=[];
    foreach($uploaded as $id=>$file){
        $asset=sf_media_register_existing_file((string)$file['stored_path'],(string)$file['original_name'],$owner);
        if(($asset['category']??'')!=='audio'||(int)($asset['created_by']??0)!==$owner)
            throw new RuntimeException('Audio already belongs to another owner. Use an administrator-owned copy.');
        $assets[$id]=$asset;
    }
    $master=$assets[$masterId];$preview=$assets[$previewId];
    $sourceFiles=array_values(array_map(static fn($file)=>[
        'name'=>(string)$file['original_name'],
        'relative_path'=>(string)$file['relative_path'],
        'extension'=>(string)$file['extension'],
        'size'=>(int)$file['size'],
        'sha256'=>(string)$file['sha256']
    ],$uploaded));
    $metadata=sf_admin_merge_metadata($template,[], $isrc,$batch,[
        'private_import'=>true,
        'source_files'=>$sourceFiles,
        'private_master_sha256'=>(string)$master['sha256'],
        'private_candidate_preview_sha256'=>(string)$preview['sha256']
    ]);
    if(array_key_exists('digital_price',$track)){
        $price=filter_var($track['digital_price'],FILTER_VALIDATE_FLOAT);
        if($price===false||$price<0||$price>1000)throw new InvalidArgumentException('Digital price must be between $0 and $1,000.');
        $metadata['price']=round((float)$price,2);
    }
    $record=sf_agent_song_intake_normalize([
        'request_key'=>$key,'title'=>$title,
        'release'=>sf_clean_text($body['release']??'Unreleased',180),
        'genre'=>$metadata['genre']??'',
        'year'=>(int)($metadata['release_year']??0),
        'words_by'=>($track['words_by']??'')?:($metadata['words_by']??''),
        'music_by'=>($track['music_by']??'')?:($metadata['music_by']??''),
        'artist_display'=>($track['artist_display']??'')?:($metadata['performing_artist']?:($metadata['artist_display']?:'Stonefellow')),
        'master_owner'=>($track['master_owner']??'')?:($metadata['master_copyright']?:($metadata['artist_legal']??'')),
        'composition_owner'=>($track['composition_owner']??'')?:($metadata['composition_copyright']?:($metadata['artist_legal']??'')),
        'isrc'=>$isrc,'release_date'=>$metadata['release_date']??'',
        'explicit'=>$metadata['explicit']??false,
        'pod_eligible'=>!empty($track['pod_eligible']),
        // Folder import is NEVER a declaration of publication rights.
        'rights_confirmed'=>false,'artwork_asset_id'=>0
    ],$master,$owner);
    // Preserve the *entire* template snapshot privately for later review. The
    // publication code selects only public-safe fields after explicit approval.
    $record['template_metadata']=$metadata;
    $record['imported_asset_ids']=array_values(array_unique(array_map('intval',array_column($assets,'id'))));
    $record['candidate_preview_asset_id']=((int)$preview['id']!==(int)$master['id']
        &&(string)$preview['sha256']!==(string)$master['sha256']
        &&($preview['extension']??'')==='mp3')?(int)$preview['id']:0;
    $result=sf_agent_song_intake_save($owner,$record);
    // A network retry can arrive after the owner has already prepared or
    // published this draft. Never disguise that later state as a private draft.
    $persistedStatus=(string)($result['draft']['status']??'');
    if($persistedStatus!=='draft')
        throw new RuntimeException('Song has already advanced beyond private intake. Open its existing review or Catalog record.');
    if($result['created'])sf_log_admin_action($owner,'folder_scan_private_song_imported',
        'media_asset',(string)$master['id'],[
            'draft_id'=>$result['draft']['id'],
            'template_id'=>$template['id'],'asset_count'=>count($assets),
            'visibility'=>'private'
        ]);
    sf_json_response(['ok'=>true,'created'=>$result['created'],
        'draft'=>['id'=>$result['draft']['id'],'request_key'=>$result['draft']['request_key'],
            'payload_hash'=>$result['draft']['payload_hash'],
            'title'=>$result['draft']['title'],'status'=>$persistedStatus,
            'template'=>$metadata['template'],'file_count'=>count($assets)],
        'publication'=>'not_published']);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}
