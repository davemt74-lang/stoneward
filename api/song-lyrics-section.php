<?php
declare(strict_types=1);
/** Saved catalog lyrics only. This endpoint never extracts audio, calls a model, or spends Active Tokens. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-catalog-core.php';
require_once __DIR__.'/song-moments-core.php';
require_once __DIR__.'/song-section-context.php';

$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,$method==='POST');
$admin=($user['role']??'')==='admin';
header('Cache-Control: private, no-store');
try{
    $catalog=sf_agent_public_catalog();
    if($method==='GET'){
        if(!is_string($_GET['track_id']??null)||!is_string($_GET['source_ref']??null)||strlen($_GET['source_ref'])>512)
            sf_song_catalog_error('invalid_song_section',422,'Choose a published playable song.');
        $source=sf_song_catalog_source($_GET['track_id'],$catalog,$user,false,$_GET['source_ref']);
        $count=sf_song_moments_lyric_available($_GET['track_id'],$admin);
        sf_json_response(['ok'=>true,'available'=>$count>0,'source'=>'saved-catalog-lyrics',
            'source_fingerprint'=>$source['fingerprint'],'max_seconds'=>90,'segments_available'=>$count,
            'message'=>$count>0?'Select a song section, then Stop to retrieve its saved lyrics.':
                ($admin?'Open this song in Admin → Catalog to import its lyrics or check its review status.':'Reviewed lyrics are not available for this song yet.')]);
    }
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)sf_song_catalog_error('invalid_song_section',413,'The lyric request is too large.');
    $stream=fopen('php://input','rb');
    if($stream===false)throw new RuntimeException('Request body unavailable.');
    try{$body=stream_get_contents($stream,8193);}finally{fclose($stream);}
    if(!is_string($body)||strlen($body)>8192)sf_song_catalog_error('invalid_song_section',413,'The lyric request is too large.');
    $raw=json_decode($body,true);
    if(!is_array($raw)||array_is_list($raw))sf_song_catalog_error('invalid_song_section',422,'The lyric request is invalid.');
    $meta=sf_song_catalog_request($raw,$catalog);
    $source=sf_song_catalog_source($meta['track_id'],$catalog,$user,false,$meta['source_ref']);
    if(!hash_equals($source['fingerprint'],$meta['source_fingerprint']))
        sf_song_catalog_error('song_source_changed',409,'The song changed after lyric selection began. Reload the player and select a new section.');
    $section=sf_song_moments_lyric_section($meta['track_id'],$meta['start_ms'],$meta['end_ms'],$admin);
    if(!$section['segments']||trim((string)$section['text'])==='')
        sf_song_catalog_error('saved_lyrics_unavailable',404,'No saved lyrics overlap this section. No transcription was requested or charged.');
    if(!hash_equals($source['fingerprint'],(string)$section['source_fingerprint']))
        sf_song_catalog_error('song_source_changed',409,'The saved lyrics belong to another song version. Reload the player.');
    $context=sf_song_section_public_context($meta['track_id'],$meta['start_ms'],$meta['end_ms']);
    sf_json_response(['ok'=>true,'transcript'=>[
        'track_id'=>$meta['track_id'],'title'=>$meta['title'],'release'=>$meta['release'],
        'start_ms'=>$meta['start_ms'],'end_ms'=>$meta['end_ms'],'selected_start_ms'=>$meta['start_ms'],
        'selected_end_ms'=>$meta['end_ms'],'captured_at'=>$meta['captured_at'],'text'=>$section['text'],
        'origin'=>'saved_catalog_lyrics','review_status'=>$section['review_status'],
        'timing'=>'saved_segments','segments'=>$section['segments'],'section_context'=>$context,'source'=>'saved-catalog-lyrics',
        'source_fingerprint'=>$source['fingerprint'],'provider'=>'stored','model'=>'',
        'timing_notice'=>'Saved blocks can extend beyond your selection. Approximate block boundaries are editable by the artist.'
    ]]);
}catch(SFSongCatalogError $e){
    sf_json_response(['ok'=>false,'error'=>$e->error,'message'=>$e->getMessage()],$e->httpStatus);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_song_section','message'=>$e->getMessage()],422);
}catch(Throwable $e){
    error_log('[Saved song lyrics] '.get_class($e));
    sf_json_response(['ok'=>false,'error'=>'saved_lyrics_unavailable','message'=>'Saved song lyrics are temporarily unavailable. No transcription was requested or charged.'],503);
}
