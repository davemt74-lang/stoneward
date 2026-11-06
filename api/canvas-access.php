<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require_once __DIR__.'/agent-scene-core.php';require_once __DIR__.'/canvas-access-core.php';
header('Cache-Control: private, no-store');$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
try{
    if($method==='GET'){
        if(($_GET['mine']??null)==='1'){$user=sf_require_user();sf_json_response(['ok'=>true,'grants'=>sf_canvas_access_saved((int)$user['id'])]);}
        $user=sf_current_user()??[];$context=[];
        if(isset($_GET['scene_id'])){$id=filter_var($_GET['scene_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new InvalidArgumentException('Choose a valid canvas.');$context['scene_id']=$id;}
        if(isset($_GET['gallery_id']))$context['gallery_id']=$_GET['gallery_id'];
        sf_json_response(['ok'=>true,'access'=>sf_canvas_access_summary($user,$context)]);
    }
    $user=sf_require_user(false,true);$body=sf_request_json();
    if(($body['action']??null)==='revoke_saved'){
        if(!is_string($body['context_key']??null)||!is_array($body['permission_ids']??null))throw new InvalidArgumentException('Choose saved access.');
        sf_json_response(['ok'=>true,'grants'=>sf_canvas_access_revoke_saved($user,$body['context_key'],$body['permission_ids'])]);
    }
    if(!is_string($body['action']??null)||!is_string($body['fingerprint']??null)||!is_array($body['permission_ids']??null))throw new InvalidArgumentException('Choose a current access action.');
    sf_json_response(['ok'=>true,'access'=>sf_canvas_access_change($user,sf_canvas_access_context($body['context']??null),$body['action'],$body['fingerprint'],$body['permission_ids'])]);
}catch(OutOfBoundsException $e){sf_json_response(['ok'=>false,'error'=>'canvas_not_found','message'=>'Canvas unavailable.'],404);
}catch(SfCanvasAccessConflict $e){sf_json_response(['ok'=>false,'error'=>'canvas_access_conflict','message'=>$e->getMessage()],409);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_canvas_access','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('[Canvas access] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'canvas_access_unavailable','message'=>'Canvas access is temporarily unavailable.'],503);}
