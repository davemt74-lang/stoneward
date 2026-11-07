<?php
declare(strict_types=1);
@ini_set('display_errors','0');
try{
    require __DIR__.'/bootstrap.php';
    header('Cache-Control: private, no-store');
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
    $user=sf_require_user(false,$method==='POST');$uid=(int)$user['id'];
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    require_once __DIR__.'/canvas-projects-core.php';
    require_once __DIR__.'/canvas-feedback-core.php';
    if($method==='GET'){
        if(isset($_GET['id'])){
            $id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$proposal=$id===false?null:sf_canvas_project_get($uid,$id);
            if(!$proposal)sf_json_response(['ok'=>false,'error'=>'canvas_project_not_found','message'=>'Canvas project not found.'],404);
            sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid),'proposal'=>$proposal,'feedback'=>sf_canvas_feedback_export($uid)]);
        }
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid),'proposals'=>sf_canvas_project_list($uid),'feedback'=>sf_canvas_feedback_export($uid)]);
    }
    $limit=2*1024*1024;$body=file_get_contents('php://input',false,null,0,$limit+1);
    if($body===false||strlen($body)>$limit)sf_json_response(['ok'=>false,'error'=>'canvas_project_too_large'],413);
    $request=json_decode($body,true,12);
    if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Use a valid canvas project request.');
    $action=$request['action']??'';
    if($action==='preferences'){
        $changes=array_intersect_key($request,array_flip(['ideas_enabled','auto_approve']));
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences_save($uid,$changes)]);
    }
    if($action==='feedback')sf_json_response(['ok'=>true,'feedback'=>sf_canvas_feedback_set($uid,$request)]);
    if(in_array($action,['approve','cancel'],true)){
        $id=$request['id']??null;if(!is_int($id)||$id<1||sf_canvas_project_get($uid,$id)===null)sf_json_response(['ok'=>false,'error'=>'canvas_project_not_found','message'=>'Canvas project not found.'],404);
        $proposal=$action==='approve'?sf_canvas_project_approve($uid,$id):sf_canvas_project_cancel($uid,$id);
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid),'proposal'=>$proposal]);
    }
    if($action==='propose'){
        if(!is_array($request['project']??null))throw new InvalidArgumentException('Describe a canvas project.');
        $project=sf_canvas_project_bind_playlists($uid,sf_canvas_project_normalize($request['project']));
        if(isset($request['reuse_id'])){
            if(!is_int($request['reuse_id'])||$request['reuse_id']<1)throw new InvalidArgumentException('Choose a canvas project.');
            $reuse=sf_canvas_project_reuse($uid,$request['reuse_id'],$project);
            if($reuse!==null)sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid),'proposal'=>$reuse]);
        }
        if(isset($request['request_id'])){
            require_once __DIR__.'/canvas-project-ideas.php';
            $result=sf_canvas_project_ideas_plan($user,$request);
            if(isset($result['project']))$result['proposal']=$result['project'];
            sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid)]+$result,($result['state']??'')==='processing'?202:200);
        }
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid),'proposal'=>sf_canvas_project_propose($user,$request)],201);
    }
    if($action==='suggest'){
        require_once __DIR__.'/canvas-project-ideas.php';
        $result=sf_canvas_project_ideas_suggest($user,$request);
        if(isset($result['project']))$result['proposal']=$result['project'];
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid)]+$result,($result['state']??'')==='processing'?202:200);
    }
    if($action==='discuss'){
        require_once __DIR__.'/canvas-project-ideas.php';
        $result=sf_canvas_project_ideas_discuss($user,$request);
        sf_json_response(['ok'=>true,'preferences'=>sf_canvas_project_preferences($uid)]+$result,($result['state']??'')==='processing'?202:200);
    }
    throw new InvalidArgumentException('Choose a supported canvas project action.');
}catch(SfCanvasProjectApprovalRequired $error){sf_json_response(['ok'=>false,'error'=>'canvas_project_approval_required','message'=>$error->getMessage()],409);
}catch(SfCanvasProjectConflict $error){sf_json_response(['ok'=>false,'error'=>'canvas_project_conflict','message'=>$error->getMessage()],409);
}catch(InvalidArgumentException|DomainException $error){sf_json_response(['ok'=>false,'error'=>'invalid_canvas_project','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    if(function_exists('sf_json_response')&&$error->getMessage()==='ACTIVE_TOKENS_REQUIRED')sf_json_response(['ok'=>false,'error'=>'active_tokens_required','message'=>'More Active Tokens are required to plan this canvas.'],402);
    error_log('[Stonefellow canvas project] '.get_class($error));
    if(function_exists('sf_json_response'))sf_json_response(['ok'=>false,'error'=>'canvas_project_unavailable','message'=>'Canvas projects are temporarily unavailable.'],503);
    http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"canvas_project_unavailable"}';
}
