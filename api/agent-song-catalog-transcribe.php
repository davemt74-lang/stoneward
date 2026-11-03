<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-catalog-core.php';

$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,$method==='POST');
header('Cache-Control: private, no-store');
try{
    if($method==='GET'){
        if(!is_string($_GET['track_id']??null)||!is_string($_GET['source_ref']??null)||strlen($_GET['source_ref'])>512)
            sf_song_catalog_error('invalid_song_section',422,'Choose a published playable song.');
        $result=sf_song_catalog_readiness($_GET['track_id'],sf_agent_public_catalog(),$user,$_GET['source_ref']);
    }else{
        if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)sf_song_catalog_error('invalid_song_section',413,'The lyric request is too large.');
        $stream=fopen('php://input','rb');
        if($stream===false)throw new RuntimeException('Request body unavailable.');
        try{$body=stream_get_contents($stream,8193);}finally{fclose($stream);}
        if(!is_string($body)||strlen($body)>8192)sf_song_catalog_error('invalid_song_section',413,'The lyric request is too large.');
        $raw=json_decode($body,true);
        if(!is_array($raw)||array_is_list($raw))sf_song_catalog_error('invalid_song_section',422,'The lyric request is invalid.');
        $catalog=sf_agent_public_catalog();
        // Long provider requests must not block playback, navigation or Stop in this browser.
        if(session_status()===PHP_SESSION_ACTIVE&&!session_write_close())throw new RuntimeException('Session could not be released.');
        if(function_exists('ignore_user_abort'))ignore_user_abort(true);
        if(function_exists('set_time_limit'))@set_time_limit(100);
        $result=sf_song_catalog_transcribe($user,$raw,$catalog);
    }
    // Every temporary-file finally block has already completed here.
    sf_json_response($result);
}catch(SFSongCatalogError $e){
    if($e->httpStatus===429)header('Retry-After: 120');
    sf_json_response(['ok'=>false,'error'=>$e->error,'message'=>$e->getMessage()],$e->httpStatus);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'transcription_unavailable','message'=>'Song transcription is temporarily unavailable. Your player and saved clips are unchanged.'],503);
}
