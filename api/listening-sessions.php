<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$u=sf_require_user(false,$write);$uid=(int)$u['id'];sf_listening_sessions_ensure_schema();
if(!$write){
    $id=trim((string)($_GET['id']??''));$session=$id!==''?sf_agent_listening_session_get($uid,$id):sf_agent_listening_session_active($uid);
    sf_json_response(['ok'=>true,'session'=>$session,'csrf'=>sf_user_csrf()]);
}
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='start'){
        $session=sf_agent_listening_session_create($uid,(string)($b['prompt']??'play me something'),(string)($b['mode']??'mix'),isset($b['active_track_id'])?(string)$b['active_track_id']:null);
        sf_json_response(['ok'=>true,'session'=>$session,'csrf'=>sf_user_csrf()]);
    }
    if($action==='advance'){
        $session=sf_agent_listening_session_advance($uid,(string)($b['session_id']??''),(string)($b['track_id']??''),(string)($b['result']??'complete'));
        sf_json_response(['ok'=>true,'session'=>$session,'csrf'=>sf_user_csrf()]);
    }
    if($action==='feedback'){
        $result=sf_agent_listening_session_feedback($uid,(string)($b['session_id']??''),(string)($b['track_id']??''),(string)($b['sentiment']??'neutral'));
        sf_json_response(['ok'=>true,...$result,'csrf'=>sf_user_csrf()]);
    }
    if($action==='end'){
        $session=sf_agent_listening_session_end($uid,(string)($b['session_id']??''));
        sf_json_response(['ok'=>true,'session'=>$session,'csrf'=>sf_user_csrf()]);
    }
    if($action==='save_playlist'){
        $playlist=sf_agent_listening_session_save_playlist($uid,(string)($b['session_id']??''),(string)($b['name']??''));
        sf_json_response(['ok'=>true,'playlist'=>$playlist,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported listening-session action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
