<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/speech-engine-core.php';
require_once __DIR__.'/speech-engine-session-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
$u=sf_require_user(false,$method!=='GET');
$uid=(int)$u['id'];
if($method==='GET')sf_json_response(['ok'=>true,'speech_engine'=>sf_speech_engine_public_state($uid)]);
if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
$body=sf_request_json();
try {
    $action=(string)($body['action']??'');
    if($action==='set_preference'){
        sf_speech_engine_save_choice($uid,(string)($body['engine']??''));
        sf_json_response(['ok'=>true,'speech_engine'=>sf_speech_engine_public_state($uid)]);
    }
    if($action==='start'){
        if(!sf_speech_session_authorized($uid))throw new RuntimeException('Speech Engine is not available for this account.');
        $provider=sf_ai_resolve_voice();$pilot=sf_speech_engine_pilot_config();
        if(!$provider||$provider['provider']!=='elevenlabs')throw new RuntimeException('Speech Engine provider is unavailable.');
        $token=sf_speech_provider_token((string)$provider['api_key'],(string)$pilot['engine_id']);
        $claim=sf_speech_issue_claim($uid);
        sf_json_response(['ok'=>true,'conversation_token'=>$token,'bind_token'=>$claim]);
    }
    if($action==='bind'){
        sf_speech_bind_account($uid,(string)($body['conversation_id']??''),(string)($body['bind_token']??''));
        sf_json_response(['ok'=>true,'bound'=>true]);
    }
    throw new InvalidArgumentException('Unsupported voice engine action.');
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Speech Engine could not start or save that setting. Please try again.'],503);}
