<?php
declare(strict_types=1);

/** Section 91B17H: bounded, admin-only media diagnostics and deliberate song relinking. */
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/media-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
$admin=sf_admin_require_auth($method!=='GET');
sf_media_ensure_schema();

/** No URL, catalog field, or stored_path may escape the application's storage root. */
function sf_media_health_file(array $asset): array {
    $path=(string)($asset['stored_path']??'');
    if(!preg_match('~^storage/media/[a-zA-Z0-9_./-]+$~D',$path)
        ||str_contains('/'.$path.'/','/../')||str_contains('/'.$path.'/','/./'))
        return ['ok'=>false,'status'=>'unsafe_path'];
    $root=realpath(SF_ROOT.'/storage/media');
    if($root===false)return ['ok'=>false,'status'=>'storage_unavailable'];
    $file=realpath(SF_ROOT.'/'.$path);
    if($file===false)return ['ok'=>false,'status'=>'missing_file'];
    if(!str_starts_with($file,$root.DIRECTORY_SEPARATOR))return ['ok'=>false,'status'=>'unsafe_path'];
    if(!is_file($file)||!is_readable($file))return ['ok'=>false,'status'=>'unreadable_file'];
    $size=filesize($file);
    if($size===false||$size<=0)return ['ok'=>false,'status'=>'empty_file'];
    if((int)($asset['size_bytes']??0)>0 && $size!==(int)$asset['size_bytes'])
        return ['ok'=>false,'status'=>'size_mismatch'];
    return ['ok'=>true,'status'=>'ready','size_bytes'=>$size];
}

function sf_media_health_managed_asset(string $uuid,array $known): ?array {
    if(isset($known[$uuid]))return $known[$uuid];
    if(!preg_match('/^[a-zA-Z0-9-]{12,80}$/D',$uuid))return null;
    $q=sf_db()->prepare('SELECT id FROM media_assets WHERE uuid=? LIMIT 1');
    $q->execute([$uuid]);
    $id=(int)$q->fetchColumn();
    return $id>0?sf_media_asset($id):null;
}

function sf_media_health_track(array $track,array $audio): array {
    $id=(string)($track['id']??'');
    $rawUrl=(string)($track['audio']??'');
    $url=trim($rawUrl);
    $public=sf_track_is_public($track);
    $status='no_audio';$assetId=0;
    if($url!==''){
        $parts=parse_url($url);
        if($parts===false){$status='invalid_url';}
        elseif(isset($parts['scheme'])||isset($parts['host'])||str_starts_with($url,'//')){
            $status='external_unverified';
        }elseif(preg_match('~(?:^|/)api/media\.php$~D',(string)($parts['path']??''))){
            parse_str((string)($parts['query']??''),$query);
            $uuid=is_string($query['id']??null)?$query['id']:'';
            $asset=sf_media_health_managed_asset($uuid,$audio);
            if(!$asset||($asset['category']??'')!=='audio'){$status='reference_missing';}
            else{
                $assetId=(int)$asset['id'];
                $check=sf_media_health_file($asset);
                $status=$check['ok']?'ready':$check['status'];
                if($check['ok'] && $public){
                    // api/media.php authorizes public playback via media_links, not catalog JSON alone.
                    $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE entity_type='track' AND entity_id=? AND asset_id=? AND role='primary_audio' AND public_visible=1 LIMIT 1");
                    $q->execute([$id,$assetId]);
                    if(!$q->fetchColumn()||sf_media_protected_master_asset($assetId))$status='unpublished_link';
                    // The catalog duration follows the public preview, so it
                    // cannot reveal a short preview versus the private original.
                    // Surface that difference to Admin without exposing the master.
                    $masterId=(int)($track['media_private_master_asset']??0);
                    if($status==='ready'&&$masterId>0&&$masterId!==$assetId){
                        $master=sf_media_asset($masterId);
                        $previewSeconds=(float)($asset['duration_seconds']??0);
                        $masterSeconds=(float)($master['duration_seconds']??0);
                        if($master&&$previewSeconds>0&&$masterSeconds>=40&&
                            $masterSeconds-$previewSeconds>max(15,$masterSeconds*.08))
                            $status='preview_shorter_than_master';
                    }
                }
            }
        }else{
            $path=rawurldecode((string)($parts['path']??''));
            $relative=ltrim($path,'/');
            if($relative===''||str_contains('/'.$relative.'/','/../')||str_contains($relative,"\0")){
                $status='invalid_url';
            }else{
                $file=realpath(SF_ROOT.'/'.$relative);
                $root=realpath(SF_ROOT);
                $status=($file&&$root&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&is_readable($file)&&filesize($file)>0)
                    ?'legacy_ready':'legacy_missing';
            }
        }
    }
    $repairable=$public && !in_array($status,['ready','legacy_ready','external_unverified'],true);
    return ['id'=>$id,'title'=>(string)($track['title']??'Untitled'),'public'=>$public,
        'status'=>$status,'audio'=>$rawUrl,'managed_asset_id'=>$assetId,
        'media_primary_asset'=>(int)($track['media_primary_asset']??0),'can_relink'=>$repairable];
}

function sf_media_health_audit(): array {
    $all=sf_media_list('','',1000);$audio=[];$candidates=[];$broken=[];
    foreach($all as $asset){
        $status=sf_media_health_file($asset);
        if(!$status['ok'])$broken[]=['id'=>(int)$asset['id'],'category'=>(string)$asset['category'],
            'name'=>(string)($asset['original_name']??''),'stored_path'=>(string)$asset['stored_path'],'status'=>$status['status']];
        if(($asset['category']??'')!=='audio')continue;
        $audio[(string)$asset['uuid']]=$asset;
        if($status['ok']&&!sf_media_protected_master_asset((int)$asset['id'])){
            $candidates[]=['id'=>(int)$asset['id'],'title'=>(string)($asset['title']?:$asset['original_name']),
                'original_name'=>(string)$asset['original_name'],'size_bytes'=>(int)$status['size_bytes'],
                'uuid'=>(string)$asset['uuid']];
        }
    }
    $catalog=sf_catalog();$tracks=[];$publicCount=0;$issues=0;$ready=0;
    foreach(array_slice($catalog,0,600) as $track){
        if(!is_array($track)||empty($track['id']))continue;
        $row=sf_media_health_track($track,$audio);
        if($row['public']){
            $publicCount++;
            if(in_array($row['status'],['ready','legacy_ready'],true))$ready++;
            elseif($row['status']!=='external_unverified')$issues++;
        }
        $tracks[]=$row;
    }
    $q=sf_db()->query('SELECT COUNT(*) FROM media_assets');
    $totalAssets=(int)$q->fetchColumn();
    return ['ok'=>true,'audited_at'=>gmdate('c'),
        'summary'=>['total_tracks'=>count($catalog),'checked_tracks'=>count($tracks),
            'public_tracks'=>$publicCount,'ready_public_tracks'=>$ready,'public_issues'=>$issues,
            'total_assets'=>$totalAssets,'checked_assets'=>count($all),'broken_assets'=>count($broken),
            'candidate_audio'=>count($candidates),'truncated'=>count($catalog)>600||$totalAssets>1000],
        'tracks'=>$tracks,'broken_assets'=>$broken,'candidates'=>$candidates];
}

if($method==='GET')sf_json_response(sf_media_health_audit());
if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);

$body=sf_request_json();
try{
    if(($body['action']??'')!=='relink_track_audio')throw new InvalidArgumentException('Unknown recovery action.');
    if(($body['confirmed']??false)!==true)throw new RuntimeException('Explicit Admin confirmation is required.');
    $trackId=sf_clean_text($body['track_id']??'',160);
    $assetId=(int)($body['asset_id']??0);
    if($trackId===''||$assetId<1||!isset($body['expected_audio'])||!is_string($body['expected_audio']))
        throw new InvalidArgumentException('Song, managed asset and previous audio reference are required.');
    // The approved reference and writer revision must come from the same disk snapshot.
    $catalogBytes=file_get_contents(SF_ROOT.'/data/catalog.json');
    if($catalogBytes===false)throw new RuntimeException('Could not verify the live catalog revision.');
    $catalogSnapshot=json_decode($catalogBytes,true);
    if(!is_array($catalogSnapshot))throw new RuntimeException('Live catalog data is invalid.');
    $expectedCatalogHash=hash('sha256',$catalogBytes);
    $track=null;
    foreach($catalogSnapshot as $t)if(is_array($t)&&(string)($t['id']??'')===$trackId){$track=$t;break;}
    if(!$track)throw new InvalidArgumentException('Song not found.');
    if(!sf_track_is_public($track))throw new RuntimeException('Hidden and unpublished songs cannot be relinked into public playback here.');
    $previous=(string)($track['audio']??'');
    if(!hash_equals($previous,(string)$body['expected_audio']))
        throw new RuntimeException('The song audio reference has changed. Re-run Media Health before relinking.');
    $current=sf_media_health_track($track,[]);
    if(!$current['can_relink'])throw new RuntimeException('This audio is already available or cannot be verified as broken. No change was made.');
    $asset=sf_media_asset($assetId);
    if(!$asset||($asset['category']??'')!=='audio')throw new InvalidArgumentException('Choose an existing managed audio asset.');
    $check=sf_media_health_file($asset);
    if(!$check['ok'])throw new RuntimeException('Selected audio cannot be verified on the server: '.$check['status']);
    if(sf_media_protected_master_asset($assetId))throw new RuntimeException('A private original cannot be made public.');
    $links=sf_media_links('track',$trackId);$linkId=0;
    foreach($links as $link)if((int)$link['asset_id']===$assetId && !in_array((string)$link['role'],['master_audio','stem_audio'],true)){$linkId=(int)$link['id'];break;}
    if(!$linkId){
        $links=sf_media_attach($assetId,'track',$trackId,'audio_candidate',['public_visible'=>false,'download_allowed'=>false]);
        foreach($links as $link)if((int)$link['asset_id']===$assetId && $link['role']==='audio_candidate'){$linkId=(int)$link['id'];break;}
    }
    if(!$linkId)throw new RuntimeException('Could not establish the private candidate attachment.');
    $result=sf_media_publish_track_audio($trackId,$linkId,$expectedCatalogHash);
    sf_log_admin_action((int)$admin['id'],'media_health_audio_relinked','track',$trackId,
        ['asset_id'=>$assetId,'previous_audio'=>$previous,'new_audio'=>(string)$result['track']['audio'],
            'file_bytes'=>(int)$check['size_bytes']]);
    sf_json_response(['ok'=>true,'track_id'=>$trackId,'previous_audio'=>$previous,
        'new_audio'=>(string)$result['track']['audio'],'asset_id'=>$assetId,'verified'=>true]);
}catch(Throwable $error){sf_json_response(['ok'=>false,'message'=>$error->getMessage()],422);}
