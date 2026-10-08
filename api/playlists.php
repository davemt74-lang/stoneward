<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_playlists_ensure_schema();
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET'){
    $publicId=max(0,(int)($_GET['public_id']??0));
    if($publicId){$p=sf_playlist_get_public($publicId);if(!$p)sf_json_response(['ok'=>false,'message'=>'Public playlist not found.'],404);sf_json_response(['ok'=>true,'playlist'=>$p]);}
    $u=sf_require_user(false,false);$uid=(int)$u['id'];$id=max(0,(int)($_GET['id']??0));
    sf_json_response(['ok'=>true,'playlists'=>sf_playlist_list($uid),'playlist'=>$id?sf_playlist_get_owned($uid,$id):null,'csrf'=>sf_user_csrf()]);
}
$u=sf_require_user(false,true);$uid=(int)$u['id'];$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='create'){$p=sf_playlist_create($uid,(string)($b['name']??'My Playlist'),(string)($b['description']??''),(string)($b['visibility']??'private'),(string)($b['source_type']??'user'),(array)($b['track_ids']??[]));sf_log_user_activity($uid,'playlist_created','Created playlist “'.$p['name'].'”','playlist',(string)$p['id'],['source_type'=>$p['source_type'],'track_count'=>$p['track_count']]);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()],201);}
    if($action==='update'){$id=(int)($b['playlist_id']??0);$p=sf_playlist_update($uid,$id,(string)($b['name']??''),(string)($b['description']??''),(string)($b['visibility']??'private'));sf_log_user_activity($uid,'playlist_updated','Updated playlist “'.$p['name'].'”','playlist',(string)$id,['visibility'=>$p['visibility']]);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='delete'){$id=(int)($b['playlist_id']??0);$p=sf_playlist_get_owned($uid,$id);sf_playlist_delete($uid,$id);sf_log_user_activity($uid,'playlist_deleted','Deleted playlist “'.$p['name'].'”','playlist',(string)$id);sf_json_response(['ok'=>true,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='add_track'){$id=(int)($b['playlist_id']??0);$p=sf_playlist_add_track($uid,$id,(string)($b['track_id']??''));sf_log_user_activity($uid,'playlist_track_added','Added a track to “'.$p['name'].'”','playlist',(string)$id,['track_id'=>(string)($b['track_id']??'')]);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='remove_item'){$id=(int)($b['playlist_id']??0);$p=sf_playlist_remove_item($uid,$id,(int)($b['item_id']??0));sf_log_user_activity($uid,'playlist_track_removed','Removed a track from “'.$p['name'].'”','playlist',(string)$id);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='reorder'){$id=(int)($b['playlist_id']??0);$p=sf_playlist_reorder($uid,$id,(array)($b['item_ids']??[]));sf_log_user_activity($uid,'playlist_reordered','Reordered playlist “'.$p['name'].'”','playlist',(string)$id);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()]);}
    if($action==='save_agent_session'){$p=sf_playlist_create_from_agent_session($uid,(string)($b['name']??'Agent Listening Session'));sf_log_user_activity($uid,'playlist_created','Saved agent listening session as “'.$p['name'].'”','playlist',(string)$p['id'],['source_type'=>'agent_session','track_count'=>$p['track_count']]);sf_json_response(['ok'=>true,'playlist'=>$p,'playlists'=>sf_playlist_list($uid),'csrf'=>sf_user_csrf()],201);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported playlist action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
