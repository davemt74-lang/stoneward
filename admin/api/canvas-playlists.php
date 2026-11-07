<?php
declare(strict_types=1);
@ini_set('display_errors','0');

try{
    require __DIR__.'/bootstrap.php';
    require_once SF_ROOT.'/api/canvas-playlists-core.php';
    header('Cache-Control: private, no-store');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){
        header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    }
    $actor=sf_admin_require_auth($method==='POST');$csrf=sf_admin_csrf();
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if($method==='GET')sf_json_response(['ok'=>true,'csrf'=>$csrf,'items'=>sf_canvas_playlists_admin_inventory($actor)]);
    $body=file_get_contents('php://input',false,null,0,16385);
    if($body===false||strlen($body)>16384)sf_json_response(['ok'=>false,'error'=>'canvas_playlist_request_too_large'],413);
    $request=json_decode($body,true,8);
    if(!is_array($request)||array_is_list($request)||array_diff(array_keys($request),['action','playlist_id','revision_hash'])||
        !is_int($request['playlist_id']??null)||!is_string($request['action']??null)||!is_string($request['revision_hash']??null))
        throw new InvalidArgumentException('Review an exact playlist revision before publication.');
    $publication=sf_canvas_playlists_moderate($actor,$request['playlist_id'],$request['action'],$request['revision_hash']);
    sf_json_response(['ok'=>true,'csrf'=>$csrf,'publication'=>$publication,'items'=>sf_canvas_playlists_admin_inventory($actor)]);
}catch(InvalidArgumentException|DomainException $error){
    sf_json_response(['ok'=>false,'error'=>'invalid_canvas_playlist','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow Artist Playlists] '.get_class($error));
    if(function_exists('sf_json_response'))sf_json_response(['ok'=>false,'error'=>'canvas_playlists_unavailable','message'=>'Artist playlist publication is temporarily unavailable.'],503);
    http_response_code(503);header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"canvas_playlists_unavailable"}';
}
