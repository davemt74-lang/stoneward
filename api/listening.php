<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require_once __DIR__.'/listening-playback-core.php';require_once __DIR__.'/agent-scene-history.php';
header('Cache-Control: private, no-store');$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
if($method==='GET'){
    $u=sf_current_user();
    if(($_GET['protocol']??null)==='2')sf_json_response(['ok'=>true]+sf_listening_playback_session($u));
    if(!$u)sf_json_response(['ok'=>true,'analytics'=>null]);
    sf_json_response(['ok'=>true,'analytics'=>sf_listening_analytics(30,(int)$u['id'])]);
}
$b=sf_request_json();$u=sf_current_user();$uid=$u?(int)$u['id']:null;
try{
    if(($b['protocol']??null)===2){
        sf_require_csrf();$session=sf_listening_playback_session($u);
        if(!is_int($b['privacy_epoch']??null)||$b['privacy_epoch']!==$session['privacy_epoch'])throw new SfListeningPlaybackConflict('Playback privacy changed. Start a fresh observation.','privacy_changed');
        if(!is_string($b['session_token']??null)||!hash_equals($session['session_token'],$b['session_token']))throw new SfListeningPlaybackConflict('Playback session changed. Start a fresh observation.','session_changed');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        sf_json_response(['ok'=>true]+sf_listening_playback_record($u,$session,$b));
    }
    // Compatibility path: caller assertions retain legacy/unknown evidence and no verified attribution.
    $trackId=(string)($b['track_id']??'');$eventType=(string)($b['event_type']??'');$position=(int)($b['position_seconds']??0);$duration=(int)($b['duration_seconds']??0);
    sf_listen_record($uid,(string)($b['session_key']??''),$trackId,$eventType,$position,$duration,(string)($b['source']??'player'));
    sf_json_response(['ok'=>true]);
}catch(SfListeningPlaybackConflict $e){sf_json_response(['ok'=>false,'error'=>in_array($e->reason,['session_changed','privacy_changed'],true)?'playback_session_changed':'playback_conflict','reason'=>$e->reason,'message'=>$e->getMessage()],409);
}catch(OutOfBoundsException $e){sf_json_response(['ok'=>false,'error'=>'playback_context_unavailable','message'=>'Playback context is unavailable.'],404);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_playback','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('[Listening] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'listening_unavailable','message'=>'Listening activity could not be recorded.'],503);}
