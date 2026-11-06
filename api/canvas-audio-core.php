<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-data.php';
require_once __DIR__.'/media-stream-range.php';

function sf_canvas_audio_id(mixed $value): ?string {
    if(!is_string($value)||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$value)!==1)return null;
    return $value;
}

/** The path stays internal. Only the current catalog reference can reach this resolver. */
function sf_canvas_audio_local_file(string $relative,string $rootName,bool $allowRootLink=false): ?array {
    if(str_contains($relative,'\\')||str_contains($relative,"\0"))return null;
    $parts=explode('/',$relative);
    if(in_array('',$parts,true)||in_array('.',$parts,true)||in_array('..',$parts,true))return null;
    $prefix=$rootName.'/';
    if(!str_starts_with($relative,$prefix))return null;
    $base=SF_ROOT.'/'.$rootName;
    if(!$allowRootLink&&is_link($base))return null;
    $root=realpath($base);
    if($root===false||!is_dir($root))return null;
    $suffix=substr($relative,strlen($prefix));$current=$base;
    foreach(explode('/',$suffix) as $part){
        $current.='/'.$part;
        if(is_link($current))return null;
    }
    $path=realpath($current);
    if($path===false||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)||!is_readable($path))return null;
    $stat=@stat($path);
    if(!$stat||$stat['size']<1)return null;
    try{$type=sf_media_detect_type($path,'public-listening.mp3');}
    catch(InvalidArgumentException){return null;}
    if(($type['category']??'')!=='audio'||($type['extension']??'')!=='mp3')return null;
    return ['path'=>$path,'size'=>(int)$stat['size'],'mime'=>'audio/mpeg','device'=>$stat['dev'],'inode'=>$stat['ino']];
}

/** Resolve an existing public listening MP3 without granting access to an original. */
function sf_canvas_audio_track_source(array $track): array {
    $reference=$track['audio']??null;
    if(!is_string($reference)||$reference==='')return ['reason'=>'missing_source'];
    if(strlen($reference)>2000||str_contains($reference,'\\')||preg_match('/[\x00-\x1F\x7F]/',$reference))return ['reason'=>'unsupported_source'];
    $url=parse_url($reference);
    if($url===false)return ['reason'=>'unsupported_source'];
    if(isset($url['scheme'])||isset($url['host'])||str_starts_with($reference,'//'))return ['reason'=>'remote_source'];
    $path=ltrim((string)($url['path']??''),'/');
    foreach(sf_catalog() as $row){
        $master=$row['private_master_audio']??null;
        if(is_string($master)&&$master!==''&&rawurldecode(ltrim($master,'/'))===rawurldecode(ltrim($reference,'/')))
            return ['reason'=>'unavailable_source'];
    }
    if($path==='api/media.php'){
        parse_str((string)($url['query']??''),$query);
        $assetId=$query['id']??null;
        if(!is_string($assetId)||preg_match('/\A[A-Za-z0-9_-]{1,64}\z/',$assetId)!==1||
            array_diff(array_keys($query),['id','variant'])||isset($url['fragment'])||
            (isset($query['variant'])&&$query['variant']!=='original'))return ['reason'=>'unsupported_source'];
        $asset=sf_media_asset($assetId);
        if(!$asset||($asset['category']??'')!=='audio'||strtolower((string)($asset['extension']??''))!=='mp3'||
            !in_array(strtolower((string)($asset['mime_type']??'')),['audio/mpeg','audio/mp3'],true)||
            sf_media_protected_master_asset((int)$asset['id']))return ['reason'=>'unavailable_source'];
        $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE asset_id=? AND entity_type='track' AND entity_id=? AND role='primary_audio' AND public_visible=1 LIMIT 1");
        $q->execute([(int)$asset['id'],(string)$track['id']]);
        $published=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$published)return ['reason'=>'unavailable_source'];
        $file=sf_canvas_audio_local_file((string)$asset['stored_path'],'storage/media',true);
        return $file?['reason'=>'available','file'=>$file]:['reason'=>'unavailable_source'];
    }
    if(isset($url['query'])||isset($url['fragment']))return ['reason'=>'unsupported_source'];
    $path=rawurldecode($path);
    if(str_contains($path,'%')||preg_match('/\.mp3\z/i',$path)!==1)return ['reason'=>'unsupported_source'];
    $root=explode('/',$path,2)[0];
    if(!in_array($root,['assets','uploads'],true))return ['reason'=>'unsupported_source'];
    $file=sf_canvas_audio_local_file($path,$root);
    return $file?['reason'=>'available','file'=>$file]:['reason'=>'unavailable_source'];
}

/** Keep legacy title-only links behind the same release visibility boundary. */
function sf_canvas_audio_public_track(string $trackId): ?array {
    $track=sf_seo_public_track($trackId);
    if($track===null)return null;
    $releaseTitle=(string)($track['release']??'');
    if(empty($track['_release'])&&$releaseTitle!==''){
        foreach(sf_seo_release_rows() as $release){
            if((string)($release['title']??'')===$releaseTitle)return null;
        }
    }
    return $track;
}

/** A fresh public track and release check applies equally to visitors and administrators. */
function sf_canvas_audio_source(string $trackId): ?array {
    if(sf_canvas_audio_id($trackId)===null)return null;
    $track=sf_canvas_audio_public_track($trackId);
    if($track===null)return null;
    $source=sf_canvas_audio_track_source($track);
    return isset($source['file'])?$source['file']+['track_id'=>$trackId]:null;
}

/** Public metadata contains stable IDs and authorized source URLs, never storage references. */
function sf_canvas_audio_catalog(?string $releaseId=null,?string $trackId=null): array {
    $members=[];$releaseTracks=[];
    foreach(sf_agent_canvas_release_track_rows() as $row){
        if(sf_canvas_audio_public_track($row['track_id'])===null)continue;
        $releaseTracks[$row['release_id']][]=$row['track_id'];
        $members[$row['track_id']][]=$row['release_id'];
    }
    $releases=[];
    foreach(sf_agent_canvas_catalog_release_rows() as $release){
        $id=(string)$release['id'];$ids=$releaseTracks[$id]??[];
        if($releaseId!==null&&$id!==$releaseId)continue;
        if($trackId!==null&&!in_array($trackId,$ids,true))continue;
        $releases[]=['release_id'=>$id,'title'=>sf_agent_public_artist_text($release['title']??'',180),'track_ids'=>$ids];
    }
    $tracks=[];$public=[];
    foreach(sf_public_catalog() as $row){
        $id=(string)($row['id']??'');
        if(sf_canvas_audio_id($id)===null||($trackId!==null&&$id!==$trackId))continue;
        if($releaseId!==null&&!in_array($releaseId,$members[$id]??[],true))continue;
        $track=sf_canvas_audio_public_track($id);
        if($track===null)continue;
        $source=sf_canvas_audio_track_source($track);$available=isset($source['file']);
        $url=$available?'api/canvas-audio.php?action=source&track_id='.rawurlencode($id):null;
        $public[$id]=['track_id'=>$id,'title'=>sf_agent_public_artist_text($track['title']??'',180),
            'duration_seconds'=>is_numeric($track['duration']??null)?max(0,(int)$track['duration']):0,
            'release_ids'=>$members[$id]??[],'audio_url'=>$url,'source_url'=>$url,'mime'=>$available?'audio/mpeg':null,
            'source_available'=>$available,'source_reason'=>$source['reason']];
    }
    if($releaseId!==null){
        foreach($releaseTracks[$releaseId]??[] as $id)if(isset($public[$id]))$tracks[]=$public[$id];
    }else $tracks=array_values($public);
    return ['tracks'=>$tracks,'releases'=>$releases];
}
