<?php
declare(strict_types=1);
@ini_set('display_errors','0');
try{
    require __DIR__.'/bootstrap.php';require_once __DIR__.'/canvas-gallery-core.php';
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    $method=$_SERVER['REQUEST_METHOD']??'GET';$action=$_GET['action']??(isset($_GET['id'])?'detail':'list');
    if(!is_string($action))throw new InvalidArgumentException('Choose a gallery action.');
    if(!in_array($method,['GET','POST'],true)&&!($method==='HEAD'&&$action==='asset')){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
    if($method==='GET'||$method==='HEAD'){
        if($action==='asset'){
            require_once __DIR__.'/canvas-gallery-assets.php';$id=sf_canvas_gallery_id($_GET['id']??null);$asset=sf_canvas_gallery_id($_GET['asset_id']??null);
            $viewer=sf_current_user();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            $file=sf_canvas_gallery_assets_file($id,$asset,$viewer);if($file===null)throw new SfCanvasGalleryNotFound('Canvas image not found.');
            header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['size']);header('Cache-Control: private, no-store, no-transform');header('Cross-Origin-Resource-Policy: same-origin');
            if($method!=='HEAD')fpassthru($file['handle']);fclose($file['handle']);exit;
        }
        $options=[];
        foreach(['page'=>1000000,'limit'=>60] as $field=>$maximum)if(isset($_GET[$field])){
            $value=filter_var($_GET[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$maximum]]);if($value===false)throw new InvalidArgumentException('Choose a valid gallery page.');$options[$field]=$value;
        }
        if(isset($_GET['q']))$options['q']=$_GET['q'];
        if($action==='list')sf_json_response(['ok'=>true]+sf_canvas_gallery_list(null,false,$options));
        if($action==='mine'){
            $user=sf_require_user();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$uid=(int)$user['id'];
            if(isset($_GET['id'])){$item=sf_canvas_gallery_get(sf_canvas_gallery_id($_GET['id']),$uid);if(!$item)throw new SfCanvasGalleryNotFound('Canvas submission not found.');sf_json_response(['ok'=>true,'item'=>$item]);}
            if(isset($_GET['status']))$options['status']=$_GET['status'];sf_json_response(['ok'=>true]+sf_canvas_gallery_list($uid,false,$options));
        }
        $id=sf_canvas_gallery_id($_GET['id']??null);
        if($action==='detail'){$item=sf_canvas_gallery_get($id);if(!$item)throw new SfCanvasGalleryNotFound('Canvas gallery entry not found.');sf_json_response(['ok'=>true,'item'=>$item]);}
        if($action==='open'){
            $track=$_GET['current_track_id']??'';if(!is_string($track)||strlen($track)>180||($track!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$track)!==1))throw new InvalidArgumentException('Choose a valid current song.');
            $viewer=sf_current_user();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();sf_json_response(['ok'=>true]+sf_canvas_gallery_open($id,$viewer,$track));
        }
        throw new InvalidArgumentException('Choose a supported gallery action.');
    }
    $user=sf_require_user(false,true);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $limit=128*1024;$body=file_get_contents('php://input',false,null,0,$limit+1);if($body===false||strlen($body)>$limit)sf_json_response(['ok'=>false,'error'=>'canvas_gallery_request_too_large'],413);
    $request=json_decode($body,true,12);if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Use a valid gallery request.');
    $action=$request['action']??'';
    if($action==='submit')sf_json_response(['ok'=>true,'item'=>sf_canvas_gallery_submit($user,$request)],201);
    if($action==='withdraw')sf_json_response(['ok'=>true,'item'=>sf_canvas_gallery_withdraw($user,sf_canvas_gallery_id($request['id']??null))]);
    if($action==='remix')sf_json_response(['ok'=>true]+sf_canvas_gallery_remix($user,sf_canvas_gallery_id($request['id']??null),sf_agent_scene_request_id($request['request_id']??null)));
    throw new InvalidArgumentException('Choose a supported gallery action.');
}catch(SfCanvasGalleryNotFound $error){sf_json_response(['ok'=>false,'error'=>'canvas_gallery_not_found','message'=>$error->getMessage()],404);
}catch(SfCanvasGalleryConflict $error){sf_json_response(['ok'=>false,'error'=>'canvas_gallery_conflict','message'=>$error->getMessage()],409);
}catch(SfAgentSceneBuildCanceled $error){sf_json_response(['ok'=>false,'error'=>'canvas_gallery_cleared','message'=>'The account or saved canvas is no longer available.'],409);
}catch(InvalidArgumentException|DomainException $error){sf_json_response(['ok'=>false,'error'=>'invalid_canvas_gallery','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    error_log('[Stonefellow Canvas Gallery] '.get_class($error));if(function_exists('sf_json_response'))sf_json_response(['ok'=>false,'error'=>'canvas_gallery_unavailable','message'=>'Canvas Gallery is temporarily unavailable.'],503);
    http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"canvas_gallery_unavailable"}';
}
