<?php
declare(strict_types=1);
@ini_set('display_errors','0');
try{
    require __DIR__.'/bootstrap.php';require_once SF_ROOT.'/api/canvas-gallery-core.php';
    header('Cache-Control: private, no-store');$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
    $admin=sf_admin_require_auth($method==='POST');$csrf=sf_admin_csrf();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if($method==='GET'){
        if(isset($_GET['id'])){$id=sf_canvas_gallery_id($_GET['id']);$item=sf_canvas_gallery_get($id,null,true);if(!$item)throw new SfCanvasGalleryNotFound('Canvas submission not found.');
            $data=['item'=>$item];if($item['status']!=='withdrawn')$data=sf_canvas_gallery_open($id,$admin,'',true);sf_json_response(['ok'=>true,'csrf'=>$csrf]+$data);}
        $options=[];foreach(['page'=>1000000,'limit'=>60] as $field=>$maximum)if(isset($_GET[$field])){$value=filter_var($_GET[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>$maximum]]);if($value===false)throw new InvalidArgumentException('Choose a valid gallery page.');$options[$field]=$value;}
        foreach(['q','status'] as $field)if(isset($_GET[$field]))$options[$field]=$_GET[$field];sf_json_response(['ok'=>true,'csrf'=>$csrf]+sf_canvas_gallery_list(null,true,$options));
    }
    $limit=128*1024;$body=file_get_contents('php://input',false,null,0,$limit+1);if($body===false||strlen($body)>$limit)sf_json_response(['ok'=>false,'error'=>'canvas_gallery_request_too_large'],413);
    $request=json_decode($body,true,8);if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Use a valid gallery review request.');
    if(!is_string($request['action']??null)||!is_string($request['snapshot_hash']??null))throw new InvalidArgumentException('Review this exact submitted snapshot.');
    $feedback=$request['feedback']??'';if(!is_string($feedback))throw new InvalidArgumentException('Use text for review feedback.');
    $item=sf_canvas_gallery_moderate($admin,sf_canvas_gallery_id($request['id']??null),$request['action'],$request['snapshot_hash'],$feedback);
    sf_json_response(['ok'=>true,'item'=>$item,'csrf'=>$csrf]);
}catch(SfCanvasGalleryNotFound $error){sf_json_response(['ok'=>false,'error'=>'canvas_gallery_not_found','message'=>$error->getMessage()],404);
}catch(SfCanvasGalleryConflict $error){sf_json_response(['ok'=>false,'error'=>'canvas_gallery_conflict','message'=>$error->getMessage()],409);
}catch(InvalidArgumentException|DomainException $error){sf_json_response(['ok'=>false,'error'=>'invalid_canvas_gallery','message'=>$error->getMessage()],422);
}catch(Throwable $error){error_log('[Stonefellow Admin Canvas Gallery] '.get_class($error));if(function_exists('sf_json_response'))sf_json_response(['ok'=>false,'error'=>'canvas_gallery_unavailable','message'=>'Canvas Gallery review is temporarily unavailable.'],503);http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"canvas_gallery_unavailable"}';}
