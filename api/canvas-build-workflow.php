<?php
declare(strict_types=1);
@ini_set('display_errors','0');
$user=[];$requestId=null;$streamed=false;$events=null;$trackId='';
$emit=static function(array $event)use(&$streamed):void {
    if(!$streamed){@ini_set('zlib.output_compression','0');while(ob_get_level()>0&&@ob_end_clean()){}header('Content-Type: application/x-ndjson; charset=utf-8');header('Cache-Control: no-store, no-transform');header('X-Accel-Buffering: no');if(function_exists('ignore_user_abort'))ignore_user_abort(true);$streamed=true;}
    if(connection_aborted())return;echo json_encode($event,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";if(ob_get_level()>0)@ob_flush();flush();
};
$respond=static function(array $data,int $status=200)use(&$streamed,&$events,$emit):never {
    $events?->flush();if($streamed){$emit(['type'=>$status>=400?'error':'result','status'=>$status,'data'=>$data]);exit;}sf_json_response($data,$status);
};
try{
    require __DIR__.'/bootstrap.php';$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
    $user=sf_require_user(false,$method==='POST');$uid=(int)$user['id'];if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if(function_exists('set_time_limit'))@set_time_limit(0);if(function_exists('ignore_user_abort'))ignore_user_abort(true);
    require_once __DIR__.'/canvas-build-workflow-core.php';
    if($method==='GET'){$trackId=is_string($_GET['current_track_id']??null)&&strlen($_GET['current_track_id'])<=180?$_GET['current_track_id']:'';$requestId=sf_agent_scene_request_id($_GET['request_id']??null);$workflow=sf_canvas_build_workflow_get($uid,$requestId);if(!$workflow)$respond(['ok'=>false,'error'=>'canvas_workflow_not_found','message'=>'Canvas workflow not found.'],404);}
    else{
        $limit=128*1024;$body=file_get_contents('php://input',false,null,0,$limit+1);if($body===false||strlen($body)>$limit)$respond(['ok'=>false,'error'=>'canvas_workflow_request_too_large'],413);
        $request=json_decode($body,true,16);if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Use a valid canvas workflow request.');
        $requestId=sf_agent_scene_request_id($request['request_id']??null);$action=$request['action']??'';
        $trackId=is_string($request['current_track_id']??null)&&strlen($request['current_track_id'])<=180?$request['current_track_id']:'';
        if($action==='start'){
            $id=$request['scene_id']??null;if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose the approved build candidate.');$workflow=sf_canvas_build_workflow_start($user,$requestId,$id);
        }elseif($action==='status'){$workflow=sf_canvas_build_workflow_get($uid,$requestId);if(!$workflow)$respond(['ok'=>false,'error'=>'canvas_workflow_not_found'],404);
        }elseif($action==='review'){
            $stepId=sf_agent_scene_request_id($request['step_id']??null);$candidate=$request['candidate_scene_id']??null;$evidence=$request['evidence']??null;
            if(!is_int($candidate)||$candidate<1||!is_array($evidence))throw new InvalidArgumentException('Supply browser observations for the current candidate.');
            $workflow=sf_canvas_build_workflow_review($user,$requestId,$stepId,$candidate,$evidence);
        }elseif($action==='repair'){
            $stepId=sf_agent_scene_request_id($request['step_id']??null);$reviewId=sf_agent_scene_request_id($request['review_id']??null);
            if(strcasecmp(trim($_SERVER['HTTP_ACCEPT']??''),'application/x-ndjson')===0)$events=new SfAgentSceneSourceEvents($emit);
            $workflow=sf_canvas_build_workflow_repair($user,$requestId,$stepId,$reviewId,$events===null?null:$events->push(...),$events===null?null:$events->retry(...));
        }elseif($action==='cancel')$workflow=sf_canvas_build_workflow_cancel($user,$requestId);
        elseif($action==='apply'){
            $id=$request['scene_id']??null;if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose the current tested candidate.');$workflow=sf_canvas_build_workflow_apply($user,$requestId,$id);
        }else throw new InvalidArgumentException('Choose a canvas workflow action.');
    }
    $data=['ok'=>true,'workflow'=>$workflow];$scene=sf_canvas_build_workflow_scene($user,$workflow,$trackId);if($scene!==null)$data['scene']=$scene;
    $respond($data,in_array($workflow['state'],['reviewing','repairing'],true)?202:200);
}catch(SfAgentSceneBuildCanceled $error){
    $workflow=is_string($requestId)&&$user&&function_exists('sf_canvas_build_workflow_get')?sf_canvas_build_workflow_get((int)$user['id'],$requestId):null;
    $respond(['ok'=>true,'workflow'=>$workflow??['request_id'=>$requestId,'state'=>'canceled','steps'=>[],'total_active_tokens_used'=>0,'candidate_id'=>null]],200);
}catch(SfCanvasBuildWorkflowConflict $error){$respond(['ok'=>false,'error'=>'canvas_workflow_conflict','message'=>$error->getMessage()],409);
}catch(InvalidArgumentException|DomainException $error){$respond(['ok'=>false,'error'=>'invalid_canvas_workflow','message'=>$error->getMessage()],422);
}catch(Throwable $error){
    if(function_exists('sf_json_response')&&$error->getMessage()==='ACTIVE_TOKENS_REQUIRED')$respond(['ok'=>false,'error'=>'active_tokens_required','message'=>'More Active Tokens are required for this canvas agent step.'],402);
    error_log('[Stonefellow canvas workflow] '.get_class($error));
    if(function_exists('sf_json_response'))$respond(['ok'=>false,'error'=>'canvas_workflow_unavailable','message'=>'This canvas agent step did not finish. Your current canvas remains available.'],503);
    http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo '{"ok":false,"error":"canvas_workflow_unavailable"}';
}
