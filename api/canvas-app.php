<?php
declare(strict_types=1);

@ini_set('display_errors','0');
try{
    require __DIR__.'/bootstrap.php';
    require_once __DIR__.'/canvas-app-core.php';
    header('X-Content-Type-Options: nosniff');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='GET'){
        $action=$_GET['action']??'detail';
        if($action==='installed'){
            $user=sf_require_user();
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            sf_json_response(['ok'=>true,'items'=>sf_canvas_app_installed((int)$user['id'])]);
        }
        if($action!=='detail')throw new InvalidArgumentException('Choose a supported canvas app action.');
        $user=sf_current_user();
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $galleryId=isset($_GET['gallery_id'])?sf_canvas_gallery_id($_GET['gallery_id']):null;
        $appId=isset($_GET['app_id'])?sf_canvas_gallery_id($_GET['app_id']):null;
        sf_json_response(['ok'=>true,'app'=>sf_canvas_app_get((int)($user['id']??0),$galleryId,$appId)]);
    }
    if($method!=='POST'){
        header('Allow: GET, POST');
        sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    }
    $user=sf_require_user(false,true);
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $limit=4096;
    $raw=file_get_contents('php://input',false,null,0,$limit+1);
    if($raw===false||strlen($raw)>$limit)sf_json_response(['ok'=>false,'error'=>'canvas_app_request_too_large'],413);
    $request=json_decode($raw,true,8);
    if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Use a valid canvas app request.');
    $action=$request['action']??'';
    if(!in_array($action,['install','set_notifications','uninstall'],true))
        throw new InvalidArgumentException('Choose a supported canvas app action.');
    $galleryId=isset($request['gallery_id'])?sf_canvas_gallery_id($request['gallery_id']):null;
    $appId=isset($request['app_id'])?sf_canvas_gallery_id($request['app_id']):null;
    $enabled=$request['enabled']??null;
    $expectedGalleryId=isset($request['expected_gallery_id'])?sf_canvas_gallery_id($request['expected_gallery_id']):null;
    if($action==='set_notifications'&&$enabled===true&&$expectedGalleryId===null)
        throw new InvalidArgumentException('Review this published canvas before enabling notifications.');
    sf_json_response(['ok'=>true,'app'=>sf_canvas_app_change((int)$user['id'],$action,$galleryId,$appId,$enabled,$expectedGalleryId)]);
}catch(SfCanvasGalleryNotFound $error){
    sf_json_response(['ok'=>false,'error'=>'canvas_app_not_found','message'=>$error->getMessage()],404);
}catch(SfCanvasGalleryConflict $error){
    sf_json_response(['ok'=>false,'error'=>'canvas_app_changed','message'=>$error->getMessage()],409);
}catch(InvalidArgumentException|DomainException $error){
    sf_json_response(['ok'=>false,'error'=>'invalid_canvas_app','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow Canvas App] '.get_class($error));
    if(function_exists('sf_json_response'))
        sf_json_response(['ok'=>false,'error'=>'canvas_app_unavailable','message'=>'Canvas apps are temporarily unavailable.'],503);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":false,"error":"canvas_app_unavailable"}';
}
