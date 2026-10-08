<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
    $u=sf_current_user();if(!$u)sf_json_response(['ok'=>true,'analytics'=>null]);
    sf_json_response(['ok'=>true,'analytics'=>sf_listening_analytics(30,(int)$u['id'])]);
}
$b=sf_request_json();$u=sf_current_user();$uid=$u?(int)$u['id']:null;
try{
    $trackId=(string)($b['track_id']??'');$eventType=(string)($b['event_type']??'');$position=(int)($b['position_seconds']??0);$duration=(int)($b['duration_seconds']??0);
    sf_listen_record($uid,(string)($b['session_key']??''),$trackId,$eventType,$position,$duration,(string)($b['source']??'player'));
    if($uid)sf_personalization_record_progress($uid,$trackId,$eventType,$position,$duration);
    sf_json_response(['ok'=>true]);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
