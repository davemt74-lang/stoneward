<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/song-lyrics-import.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth($method==='POST');header('Cache-Control: private, no-store');
try{
    if($method==='GET')sf_json_response(['ok'=>true,'jobs'=>sf_song_lyrics_import_jobs((int)$me['id'],sf_clean_text($_GET['track_id']??'',180))]);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)sf_json_response(['ok'=>false,'message'=>'Song import request is too large.'],413);
    $stream=fopen('php://input','rb');if($stream===false)throw new RuntimeException('Request body unavailable.');
    try{$raw=stream_get_contents($stream,8193);}finally{fclose($stream);}
    if(!is_string($raw)||strlen($raw)>8192)sf_json_response(['ok'=>false,'message'=>'Song import request is too large.'],413);
    $body=json_decode($raw,true);if(!is_array($body)||array_is_list($body))throw new InvalidArgumentException('Invalid song import request.');
    $action=(string)($body['action']??'');$adminId=(int)$me['id'];
    if($action==='enqueue'){
        $job=sf_song_lyrics_import_enqueue($adminId,sf_clean_text($body['track_id']??'',180));
        sf_log_admin_action($adminId,'song_lyrics_import_queued','track',$job['track_id'],['job_id'=>$job['id'],'model'=>$job['model']]);
        sf_json_response(['ok'=>true,'job'=>$job]);
    }
    $id=filter_var($body['job_id']??null,FILTER_VALIDATE_INT);if($id===false||$id<1)throw new InvalidArgumentException('Choose a song lyric import.');
    if(in_array($action,['pause','resume'],true))sf_json_response(['ok'=>true,'job'=>sf_song_lyrics_import_pause($adminId,$id,$action==='pause')]);
    if($action==='retry')sf_json_response(['ok'=>true,'job'=>sf_song_lyrics_import_retry($adminId,$id)]);
    if($action!=='advance')throw new InvalidArgumentException('Unknown song import action.');
    if(session_status()===PHP_SESSION_ACTIVE&&!session_write_close())throw new RuntimeException('Could not release the administrator session.');
    if(function_exists('ignore_user_abort'))ignore_user_abort(true);
    if(function_exists('set_time_limit'))@set_time_limit(100);
    sf_json_response(sf_song_lyrics_import_advance($adminId,$id));
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Song lyric import is unavailable. Check the published listening copy and OpenAI provider settings.'],409);}
