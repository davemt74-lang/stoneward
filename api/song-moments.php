<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/song-moments-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
try{
    if($method==='GET'){
        $track=sf_song_moments_track((string)($_GET['track_id']??''));
        $user=sf_current_user();$uid=(int)($user['id']??0);
        sf_json_response(['ok'=>true,'track_id'=>(string)$track['id'],'duration'=>(int)($track['duration']??0),
          'moments'=>sf_song_moments_public((string)$track['id']),
          'mine'=>sf_song_moments_mine((string)$track['id'],$uid),
          'can_comment'=>$uid>0,'csrf'=>$uid>0?sf_user_csrf():null]);
    }
    $user=sf_require_user(false,true);$body=sf_request_json();
    if(($body['action']??'')!=='comment')sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
    sf_json_response(['ok'=>true,'comment'=>sf_song_moments_comment((int)$user['id'],$body)],201);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_song_moment','message'=>$e->getMessage()],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'song_moment_limit','message'=>$e->getMessage()],409);}
catch(Throwable $e){error_log('[Song moments] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'song_moments_unavailable','message'=>'Song moments are temporarily unavailable.'],503);}
