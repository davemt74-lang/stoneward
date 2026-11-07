<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/canvas-audio-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
if(!in_array($method,['GET','HEAD'],true)){
    header('Allow: GET, HEAD');
    sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
}
$action=$_GET['action']??'catalog';
if(!is_string($action)||!in_array($action,['catalog','source'],true))sf_json_response(['ok'=>false,'error'=>'invalid_canvas_audio_action'],400);
foreach(['release_id','track_id'] as $key){
    if(isset($_GET[$key])&&sf_canvas_audio_id($_GET[$key])===null)sf_json_response(['ok'=>false,'error'=>'invalid_canvas_audio_filter'],400);
}
$sceneId=null;$galleryId=null;
if(isset($_GET['scene_id'])){
    $sceneId=filter_var($_GET['scene_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($sceneId===false)sf_json_response(['ok'=>false,'error'=>'invalid_canvas_audio_context'],400);
}
if(isset($_GET['gallery_id'])){
    $galleryId=$_GET['gallery_id'];
    if(!is_string($galleryId)||preg_match('/\A[a-f0-9]{32}\z/',$galleryId)!==1)sf_json_response(['ok'=>false,'error'=>'invalid_canvas_audio_context'],400);
}
if($sceneId!==null&&$galleryId!==null)sf_json_response(['ok'=>false,'error'=>'invalid_canvas_audio_context'],400);
try{
    $context=sf_canvas_audio_runtime_context($sceneId,$galleryId,($sceneId!==null||$galleryId!==null)?sf_current_user():null);
    if($action==='catalog')sf_json_response(['ok'=>true]+sf_canvas_audio_runtime_catalog($context,$_GET['release_id']??null,$_GET['track_id']??null));
    if(!isset($_GET['track_id']))sf_json_response(['ok'=>false,'error'=>'canvas_audio_track_required'],400);
    if(!sf_canvas_audio_context_allows($context,$_GET['track_id']))sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
    $source=sf_canvas_audio_source($_GET['track_id']);
    if($source===null)sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
    $file=@fopen($source['path'],'rb');
    if($file===false)sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
    $stat=fstat($file);
    if(!$stat||($stat['mode']&0170000)!==0100000||$stat['dev']!==$source['device']||$stat['ino']!==$source['inode']||$stat['size']<1){
        fclose($file);sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
    }
    $size=(int)$stat['size'];
    if(session_status()===PHP_SESSION_ACTIVE&&!session_write_close()){
        fclose($file);sf_json_response(['ok'=>false,'error'=>'canvas_audio_session_unavailable'],503);
    }
    @ini_set('zlib.output_compression','0');
    if(function_exists('apache_setenv'))@apache_setenv('no-gzip','1');
    if(function_exists('set_time_limit'))@set_time_limit(0);
    while(ob_get_level()>0){
        $state=ob_get_status();
        if(!((int)($state['flags']??0)&PHP_OUTPUT_HANDLER_REMOVABLE))break;
        @ob_end_clean();
    }
    try{$range=sf_media_stream_range((string)($_SERVER['HTTP_RANGE']??''),$size);}
    catch(InvalidArgumentException){
        fclose($file);header('Content-Range: bytes */'.$size);header('Cache-Control: private, no-store');http_response_code(416);exit;
    }
    http_response_code($range['partial']?206:200);
    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store, no-transform');
    header('Content-Length: '.$range['length']);
    if($range['partial'])header('Content-Range: bytes '.$range['start'].'-'.$range['end'].'/'.$size);
    if($method==='HEAD'){fclose($file);exit;}
    fseek($file,$range['start']);$left=$range['length'];
    while($left>0&&!feof($file)&&!connection_aborted()){
        $chunk=fread($file,min(65536,$left));
        if($chunk===false||$chunk==='')break;
        echo $chunk;$left-=strlen($chunk);
        if(function_exists('flush'))@flush();
    }
    fclose($file);
}catch(SfCanvasAudioContextUnavailable $error){
    sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
}catch(Throwable $error){
    error_log('[Stonefellow canvas audio] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],503);
}
