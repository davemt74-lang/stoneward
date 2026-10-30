<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/speech-engine-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
$u=sf_require_user(false,$method!=='GET');
$uid=(int)$u['id'];
if($method==='GET')sf_json_response(['ok'=>true,'speech_engine'=>sf_speech_engine_public_state($uid)]);
if($method!=='POST')sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
$body=sf_request_json();
try {
    if(($body['action']??'')!=='set_preference')throw new InvalidArgumentException('Unsupported voice engine action.');
    sf_speech_engine_save_choice($uid,(string)($body['engine']??''));
    sf_json_response(['ok'=>true,'speech_engine'=>sf_speech_engine_public_state($uid)]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Voice preference could not be saved.'],503);}
