<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/speech-engine-session-core.php';
require_once __DIR__.'/agent-runtime.php';

if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$raw=(string)file_get_contents('php://input');
try{
    sf_speech_verify_bridge($raw,[
        'timestamp'=>(string)($_SERVER['HTTP_X_SF_SPEECH_TIMESTAMP']??''),
        'nonce'=>(string)($_SERVER['HTTP_X_SF_SPEECH_NONCE']??''),
        'signature'=>(string)($_SERVER['HTTP_X_SF_SPEECH_SIGNATURE']??'')
    ]);
}catch(Throwable $e){sf_json_response(['ok'=>false,'error'=>'unauthorized_gateway'],403);}

$body=json_decode($raw,true);
if(!is_array($body))sf_json_response(['ok'=>false,'error'=>'invalid_json'],400);
$action=(string)($body['action']??'');$cid=(string)($body['conversation_id']??'');
if(!preg_match('/^[A-Za-z0-9_-]{12,128}$/',$cid))sf_json_response(['ok'=>false,'error'=>'invalid_conversation'],422);
try {
    if($action==='register'){
        sf_speech_register_conversation($cid);
        sf_json_response(['ok'=>true,'registered'=>true]);
    }
    if($action==='close'){
        sf_speech_close_conversation($cid);
        sf_json_response(['ok'=>true,'closed'=>true]);
    }
    if($action==='turn'){
        $user=sf_speech_bound_user($cid);
        if(!$user)sf_json_response(['ok'=>false,'error'=>'not_bound'],409);
        $message=sf_clean_text($body['message']??'',4000);
        if($message==='')sf_json_response(['ok'=>false,'error'=>'empty_turn'],422);
        // Same policy and persisted Agent Brain path as authenticated text chat.
        // Client-side player/navigation actions remain disabled in the pilot.
        $reply=sf_agent_reply($user,'voice_'.substr(hash('sha256',$cid),0,38),$message,['voice_source'=>'speech_engine_pilot']);
        $actionType=(string)($reply['action']['type']??'none');
        if($actionType!=='none')sf_json_response(['ok'=>true,'text'=>'I understood the request. Please use the original chat for playback or page actions until the new voice controls are certified.','action_deferred'=>true]);
        sf_json_response(['ok'=>true,'text'=>(string)($reply['text']??'Please try again.')]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'error'=>'voice_turn_failed','message'=>'The voice conversation is temporarily unavailable.'],503);
}
