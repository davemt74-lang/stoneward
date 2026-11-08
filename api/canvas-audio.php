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
    $status=sf_media_stream_mp3($source,$method);
    sf_json_response(['ok'=>false,'error'=>$status===503?'canvas_audio_session_unavailable':'canvas_audio_unavailable'],$status);
}catch(SfCanvasAudioContextUnavailable $error){
    sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],404);
}catch(Throwable $error){
    error_log('[Stonefellow canvas audio] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'canvas_audio_unavailable'],503);
}
