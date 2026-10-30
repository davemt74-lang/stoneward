<?php
declare(strict_types=1);
require_once __DIR__.'/speech-engine-session-core.php';

/** Interpret only the public, bounded health contract; never trust its text. */
function sf_speech_gateway_health_decode(int $status,string $raw):bool {
    if($status!==200||strlen($raw)>1024)return false;
    $data=json_decode($raw,true);
    return is_array($data)&&($data['ok']??false)===true
        &&($data['service']??'')==='stonefellow-speech-gateway'
        &&($data['account_bridge']??'')==='signed';
}

/** Gateway URL is server-side only; it is never supplied by the browser. */
function sf_speech_gateway_probe():array {
    $url=trim((string)getenv('SF_SPEECH_GATEWAY_HEALTH_URL'));
    if($url==='')return ['configured'=>false,'reachable'=>false,'signed'=>false];
    $valid=preg_match('~^https://[a-z0-9.-]+(?::[0-9]{2,5})?/health$~i',$url)
        ||preg_match('~^http://127\.0\.0\.1(?::[0-9]{2,5})?/health$~',$url);
    if(!$valid||strlen($url)>300||!function_exists('curl_init'))return ['configured'=>true,'reachable'=>false,'signed'=>false];
    $curl=curl_init($url);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json']];
    if(defined('CURLOPT_PROTOCOLS'))$opts[CURLOPT_PROTOCOLS]=str_starts_with($url,'https://')?CURLPROTO_HTTPS:CURLPROTO_HTTP;
    curl_setopt_array($curl,$opts);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $reachable=$status===200&&is_string($raw);
    return ['configured'=>true,'reachable'=>$reachable,'signed'=>$reachable&&sf_speech_gateway_health_decode($status,$raw)];
}

/** Called only by an explicit admin POST, never during page load. */
function sf_speech_engine_diagnostics():array {
    $pilot=sf_speech_engine_pilot_config();
    $providerOk=false;
    if($pilot['ready']){
        try{
            $provider=sf_ai_resolve_voice();
            if($provider&&$provider['provider']==='elevenlabs'){
                $token=sf_speech_provider_token((string)$provider['api_key'],(string)$pilot['engine_id']);
                $providerOk=$token!=='';
            }
        }catch(Throwable $error){$providerOk=false;}
    }
    $gateway=sf_speech_gateway_probe();
    return ['settings_ready'=>$pilot['ready'],'provider_token_verified'=>$providerOk,
        'gateway'=>$gateway,'ready_for_live_test'=>$pilot['ready']&&$providerOk&&$gateway['signed']];
}
