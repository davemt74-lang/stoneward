<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/vp3-music-core.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try{
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        sf_admin_require_auth(false);
        if(array_diff(array_keys($_GET),['action'])||($_GET['action']??'status')!=='status')sf_vp3_music_fail('unsupported_action','Use the connection status request.');
        sf_json_response(sf_vp3_music_status());
    }
    if($method!=='POST')sf_json_response(['ok'=>false,'code'=>'method_not_allowed','message'=>'Use GET or POST.'],405);
    $me=sf_admin_require_auth(true);
    if($_GET)sf_vp3_music_fail('invalid_request','Send action fields in the JSON request.');
    $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
    if($contentType!=='application/json')sf_vp3_music_fail('invalid_request','Use a JSON request.',415);
    $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,65537);fclose($stream);
    if(!is_string($raw)||strlen($raw)>65536)sf_vp3_music_fail('request_too_large','This request is too large.',413);
    try{$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){$body=null;}
    if(!is_array($body)||array_is_list($body))sf_vp3_music_fail('invalid_request','Use a JSON object.');
    // Explicit trusted PHP dependency for the isolated integration fixture; never supplied by HTTP or environment.
    $context=function_exists('sf_vp3_music_runtime_context')?sf_vp3_music_runtime_context():[];
    $context=is_array($context)?$context:[];
    if(($body['action']??null)==='connect')$context['site_base_url']=sf_public_base_url();
    sf_json_response(sf_vp3_music_action($body,(int)$me['id'],$context));
}catch(SfVp3MusicException $error){
    $payload=['ok'=>false,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'code'=>$error->publicCode,'message'=>$error->getMessage(),'retryable'=>$error->retryable];
    try{$payload['pending']=sf_vp3_music_pending_projection(sf_vp3_music_read_state());}catch(Throwable){}
    sf_json_response($payload,$error->httpStatus);
}catch(Throwable){sf_json_response(['ok'=>false,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'code'=>'music_connection_unavailable',
    'message'=>'The music connection could not be updated. Check its status before retrying.','retryable'=>true],503);}
