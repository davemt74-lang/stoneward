<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/song-moments-core.php';
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$admin=sf_admin_require_auth($method==='POST');
try{
    if($method==='GET')sf_json_response(['ok'=>true,'metrics'=>sf_song_moments_metrics(),
      'entries'=>sf_song_moments_admin_list(sf_clean_text($_GET['track_id']??'',180)),
      'references'=>sf_song_moments_admin_refs(),'csrf'=>sf_admin_csrf()]);
    $body=sf_request_json();$action=(string)($body['action']??'');
    if($action==='save')sf_json_response(['ok'=>true,'entry'=>sf_song_moments_admin_save((int)$admin['id'],(array)($body['entry']??[]))]);
    if(in_array($action,['approve','reject','remove'],true)){
        if(empty($body['confirmed']))throw new InvalidArgumentException('Confirm the public moderation decision.');
        $entry=sf_song_moments_admin_moderate((int)$admin['id'],(int)($body['id']??0),$action);
        sf_log_admin_action((int)$admin['id'],'song_moment_'.$action,'song_timeline_moment',(string)$entry['id'],['status'=>$entry['status']]);
        sf_json_response(['ok'=>true,'entry'=>$entry]);
    }
    sf_json_response(['ok'=>false,'error'=>'unsupported_action'],422);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_song_moment','message'=>$e->getMessage()],422);}
catch(Throwable $e){error_log('[Admin song moments] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'song_moment_failed','message'=>'Could not complete the song moment action.'],503);}
