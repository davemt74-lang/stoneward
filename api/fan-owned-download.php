<?php
declare(strict_types=1);
/** Direct Stonefellow session download. Never exposes private masters or links. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-owned-music-core.php';
require_once __DIR__.'/kunaki-media-transfer.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
    sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
$user=sf_require_user();$uid=(int)$user['id'];
$trackId=(string)($_GET['id']??'');
try {
    // Re-evaluate signed-in user's PAID digital entitlement on every request.
    $owned=sf_homeserver_user_library($uid);
    $reference=sf_fan_owned_download_reference($uid,$trackId,$owned,sf_track_map());
    if(!sf_fan_owned_media_ready($reference))
        throw new RuntimeException('Private original master is not safely available.');
    $media=sf_kunaki_media_resolve($reference,'audio');
    $length=(int)$media['size_bytes'];
    $stream=sf_hs_music_open_range((string)$media['local_path'],
        ['start'=>0,'length'=>$length]);
}catch(Throwable $e){
    error_log('Stonefellow owned-master download denied: '.get_class($e));
    sf_json_response(['ok'=>false,'message'=>'Download unavailable or not purchased.'],404);
}
// Drop the PHP session lock during an extended file transfer.
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$ext=(string)$media['extension'];
header('Content-Type: '.($ext==='mp3'?'audio/mpeg':'audio/wav'));
header('Content-Length: '.$length);
header('Content-Disposition: attachment; filename="stonefellow-'.$trackId.'.'.$ext.'"');
header('X-Download-Options: noopen');
$remaining=$length;
while($remaining>0&&!connection_aborted()){
    $block=fread($stream,min(65536,$remaining));
    if($block===false||$block==='')break;
    echo $block;$remaining-=strlen($block);
}
fclose($stream);
