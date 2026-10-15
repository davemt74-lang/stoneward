<?php
declare(strict_types=1);
/** Cloud-authoritative 84B7 private media stream for one approved fan.
 * The browser never receives the Cloud bearer or the underlying file path.
 */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/homeserver-link-core.php';
require_once __DIR__.'/homeserver-music-core.php';
require_once __DIR__.'/kunaki-media-transfer.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET')
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
try{
    $uid=sf_homeserver_bearer($auth,'fan.library.read');
    $trackId=(string)($_GET['id']??'');
    $download=($_GET['download']??'')==='1';
    $owned=sf_homeserver_user_library($uid);
    $ref=sf_hs_music_audio_reference($uid,$trackId,$owned,sf_track_map(),$download);
    // Use exactly the same vetted, private Media Library master resolver as 84B5.
    $media=sf_kunaki_media_resolve($ref,'audio');
    $size=(int)$media['size_bytes'];
    $range=sf_hs_music_range(
        !$download?(string)($_SERVER['HTTP_RANGE']??''):null,$size);
}catch(InvalidArgumentException $e){
    header('Content-Range: bytes */'.(int)($size??0));
    sf_json_response(['ok'=>false,'error'=>'invalid_range'],416);
}catch(Throwable $e){
    error_log('HomeServer fan audio denied: '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'unavailable_or_unauthorized'],404);
}
$ext=$media['extension'];$mime=$ext==='mp3'?'audio/mpeg':'audio/wav';
http_response_code($range['partial']?206:200);
header('Content-Type: '.$mime);
header('Accept-Ranges: bytes');
header('Content-Length: '.$range['length']);
if($range['partial'])
    header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
if($download)
    header('Content-Disposition: attachment; filename="stonefellow-'.$trackId.'.'.$ext.'"');
$stream=fopen($media['local_path'],'rb');
if($stream===false){http_response_code(503);exit;}
fseek($stream,$range['start']);
$left=$range['length'];
while($left>0&&!feof($stream)&&!connection_aborted()){
    $block=fread($stream,min(65536,$left));
    if($block===false||$block==='')break;
    echo $block;$left-=strlen($block);
}
fclose($stream);
