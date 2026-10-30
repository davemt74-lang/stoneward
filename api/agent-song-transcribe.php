<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-transcribe-core.php';
require_once __DIR__.'/story-inference-worker-core.php';

if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,true);
header('Cache-Control: no-store');
try{
    $meta=sf_song_transcribe_request($_POST,sf_agent_public_catalog());
    $file=$_FILES['clip']??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
        throw new InvalidArgumentException('Recorded song section was not uploaded. Check upload size and try again.');
    $bytes=(int)($file['size']??0);$path=(string)($file['tmp_name']??'');
    if($bytes<100||$bytes>20*1024*1024||!is_uploaded_file($path))
        throw new InvalidArgumentException('Captured audio is missing or exceeds the 20 MB limit.');
    $mime=sf_song_transcribe_mime((string)(new finfo(FILEINFO_MIME_TYPE))->file($path));
    // Use the already configured OpenAI provider independently of the user's
    // selected chat LLM. Claude remains the conversational provider if chosen.
    $settings=sf_ai_provider_row('openai');
    if(empty($settings['enabled'])||empty($settings['secret_payload'])||!empty($settings['endpoint_url']))
        sf_json_response(['ok'=>false,'error'=>'transcription_provider_unavailable',
            'message'=>'OpenAI audio transcription is not enabled in Admin → AI Providers. The captured clip remains in your browser.'],503);
    $estimated=max(12,(int)ceil($meta['duration_ms']/1000)*4);
    $allow=sf_can_use_ai($user,$estimated);
    if(!$allow['ok'])sf_json_response(['ok'=>false,'error'=>'active_tokens_required','message'=>'More Active Tokens are required to transcribe this clip.'],402);
    if(!class_exists('CURLFile'))throw new RuntimeException('Audio transcription requires PHP cURL support.');
    $provider=['provider'=>'openai','model'=>'gpt-4o-mini-transcribe','api_key'=>sf_ai_provider_secret('openai')];
    $extension=str_contains($mime,'webm')?'webm':(str_contains($mime,'mp4')?'m4a':(str_contains($mime,'ogg')?'ogg':(str_contains($mime,'wav')?'wav':'mp3')));
    $r=sf_inference_transport('https://api.openai.com/v1/audio/transcriptions',
        ['Authorization: Bearer '.$provider['api_key']],
        ['model'=>$provider['model'],'file'=>new CURLFile($path,$mime,'song-section.'.$extension)],true);
    $draft=sf_song_transcribe_draft($meta,$provider,$r);
    // Only successful, explicitly requested provider processing is metered.
    $balance=sf_consume_ai($user,$estimated,['provider'=>'openai','model'=>$provider['model'],
        'clip_seconds'=>(int)ceil($meta['duration_ms']/1000),'bytes'=>$bytes],'song-section-transcription');
    sf_json_response(['ok'=>true,'transcript'=>$draft,'active_tokens_remaining'=>($user['role']??'')==='admin'?null:$balance]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_song_section','message'=>$e->getMessage()],422);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'transcription_failed',
        'message'=>'Song transcription could not complete. The clip remains in your browser; please retry.'],503);
}
