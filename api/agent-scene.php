<?php
declare(strict_types=1);
$method=$_SERVER['REQUEST_METHOD']??'GET';
$requestId=null;
$kind='listening_room';
$isRevision=false;
$isDeletion=false;
$streamStarted=false;
$sourceEvents=null;
$user=[];
$imageTotal=null;
$datasetTrackId='';
$datasetTrack=static function(mixed $value):string {
    $id=is_string($value)?trim($value):'';
    return strlen($id)<=180?$id:'';
};
$sendEvent=static function(array $event)use(&$streamStarted):void {
    if(!$streamStarted){
        if(function_exists('ini_set'))ini_set('zlib.output_compression','0');
        while(ob_get_level()>0&&@ob_end_clean()){}
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
        // A closed tab must not interrupt the scene/escrow transaction.
        if(function_exists('ignore_user_abort'))ignore_user_abort(true);
        $streamStarted=true;
    }
    if(connection_aborted())return;
    echo json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
    if(ob_get_level()>0)@ob_flush();
    flush();
};
$respond=static function(array $data,int $status)use(&$streamStarted,$sendEvent,&$sourceEvents,&$user,&$datasetTrackId,&$requestId,&$imageTotal):never {
    if($imageTotal!==null){
        $data['image_total']=$imageTotal;
        if(!isset($data['generated_images'])&&($status>=400||($data['state']??'')==='canceled')){
            try{
                $images=sf_agent_canvas_image_saved_batch((int)$user['id'],$requestId);
                if($images){
                    $data['generated_images']=$images;
                    if($status>=400&&isset($data['message']))$data['message'].=count($images)===1
                        ?' The image already saved is in your image library.':' The images already saved are in your image library.';
                }
            }catch(Throwable $error){error_log('[Stonefellow canvas images] '.get_class($error));}
        }
    }
    if($user&&($data['scene']['canvas_kind']??null)==='custom'){
        try{
            $images=sf_agent_canvas_image_assets((int)$user['id'],$data['scene']);
            if($images)$data['scene']['image_assets']=$images;
        }catch(Throwable $error){
            error_log('[Stonefellow canvas images] '.get_class($error));
        }
        try{
            $datasets=sf_agent_canvas_data_for_scene($user,$data['scene']['html'],$datasetTrackId,$data['scene']['music_inputs']??null,$data['scene']['artist_inputs']??null,isset($data['scene']['id'])?['scene_id'=>$data['scene']['id']]:null);
            if(isset($data['scene']['id']))$data['scene']['canvas_access']=sf_canvas_access_summary($user,['scene_id'=>$data['scene']['id']]);
            if($datasets){
                $data['scene']['datasets']=$datasets;
                foreach($datasets as $dataset){
                    if(array_key_exists('current_rows',$dataset)){
                        $data['scene']['dataset_track_id']=$datasetTrackId;
                        break;
                    }
                }
            }
        }catch(Throwable $error){
            error_log('[Stonefellow canvas data] '.get_class($error));
        }
    }
    if($user&&is_string($requestId)&&function_exists('sf_canvas_project_finish')){
        try{sf_canvas_project_finish((int)$user['id'],$requestId,$data);}catch(Throwable $error){error_log('[Stonefellow canvas project] result status unavailable');}
        if(($data['scene']['canvas_kind']??null)==='custom'&&sf_canvas_project_table_exists('canvas_projects')){
            $query=sf_db()->prepare('SELECT id FROM canvas_projects WHERE user_id=? AND request_id=?');
            $query->execute([(int)$user['id'],$requestId]);$proposalId=$query->fetchColumn();$query->closeCursor();
            $proposal=$proposalId===false?null:sf_canvas_project_get((int)$user['id'],(int)$proposalId);
            if($proposal){$data['project_managed']=true;$data['proposal_id']=$proposal['id'];$data['approved_project']=$proposal['project'];}
        }
    }
    if($user&&($data['scene']['canvas_kind']??null)==='custom'&&is_int($data['scene']['id']??null)){
        if(($data['scene']['workflow_required']??false)===true&&!isset($data['approved_project'])&&sf_canvas_project_table_exists('canvas_projects')){
            $query=sf_db()->prepare('SELECT id FROM canvas_projects WHERE user_id=? AND scene_id=? ORDER BY id DESC LIMIT 1');
            $query->execute([(int)$user['id'],$data['scene']['id']]);$proposalId=$query->fetchColumn();$query->closeCursor();
            $proposal=$proposalId===false?null:sf_canvas_project_get((int)$user['id'],(int)$proposalId);
            if($proposal){$data['project_managed']=true;$data['proposal_id']=$proposal['id'];$data['approved_project']=$proposal['project'];$data['build_request_id']=$proposal['request_id'];}
        }
        require_once __DIR__.'/canvas-build-workflow-core.php';
        $verification=sf_canvas_build_workflow_verified_derivative((int)$user['id'],$data['scene']);
        if($verification!==null)$data['verification']=$verification;
        $workflow=sf_canvas_build_workflow_for_scene((int)$user['id'],$data['scene']['id']);
        if($workflow!==null)$data['workflow']=$workflow;
    }
    $sourceEvents?->flush();
    if($streamStarted){
        $sendEvent(['type'=>$status>=400?'error':'result','status'=>$status,'data'=>$data]);
        exit;
    }
    sf_json_response($data,$status);
};
try{
    // PHP diagnostics belong in the server log, outside the JSON response.
    if(function_exists('ini_set'))ini_set('display_errors','0');
    require __DIR__.'/bootstrap.php';
    if(!in_array($method,['GET','POST'],true)){
        header('Allow: GET, POST');
        sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
    }
    $user=sf_require_user(false,$method==='POST');
    $userId=(int)$user['id'];
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    require_once __DIR__.'/agent-runtime.php';
    require_once __DIR__.'/song-moments-core.php';
    require_once __DIR__.'/ai-token-reservations.php';
    require_once __DIR__.'/agent-scene-core.php';
    require_once __DIR__.'/agent-scene-history.php';
    if($method==='GET'){
        $datasetTrackId=$datasetTrack($_GET['current_track_id']??null);
        if(isset($_GET['request_id'])){
            $requestId=sf_agent_scene_request_id($_GET['request_id']);
            $result=sf_agent_scene_request_result($userId,$requestId);
            if($result===null)sf_json_response(['ok'=>false,'error'=>'scene_request_not_found','message'=>'Listening room request not found.'],404);
            $respond(['ok'=>true]+$result,200);
        }
        if(($_GET['suggestions']??null)==='1')sf_json_response(['ok'=>true,'suggestions'=>sf_agent_scene_suggestions($userId)]);
        if(($_GET['models']??null)==='1')sf_json_response(['ok'=>true,'models'=>sf_ai_canvas_model_options()]);
        if(!isset($_GET['id']))sf_json_response(['ok'=>true,'scenes'=>sf_agent_scene_history_list($userId)]);
        $id=filter_var($_GET['id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new InvalidArgumentException('Choose a listening room.');
        $scene=sf_agent_scene_saved($userId,$id);
        if($scene===null)sf_json_response(['ok'=>false,'error'=>'scene_not_found','message'=>'Listening room not found.'],404);
        $respond(['ok'=>true,'scene'=>$scene,'design_source'=>sf_agent_scene_request_design_source($userId,$id)],200);
    }
    $requestLimit=2*1024*1024;
    $body=file_get_contents('php://input',false,null,0,$requestLimit+1);
    if($body===false||strlen($body)>$requestLimit)sf_json_response(['ok'=>false,'error'=>'scene_request_too_large','message'=>'The listening room request is too large.'],413);
    $request=json_decode($body,true,8);
    if(!is_array($request)||array_is_list($request))sf_json_response(['ok'=>false,'error'=>'invalid_json','message'=>'Invalid listening room request.'],400);
    if(($request['action']??null)==='image_preflight'){
        require_once __DIR__.'/agent-canvas-image-preflight.php';
        if(!is_string($request['brief']??null)||!is_bool($request['editing']??false))
            throw new InvalidArgumentException('Describe the requested images.');
        [$preflightBrief]=sf_canvas_resource_selection($request['brief']);
        sf_json_response(['ok'=>true,'preflight'=>sf_agent_canvas_image_preflight($user,$preflightBrief,$request['editing']??false)]);
    }
    if(($request['action']??null)==='delete'){
        $isDeletion=true;
        $id=$request['id']??null;
        if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose a saved canvas to delete.');
        if(!sf_agent_scene_delete($userId,$id))
            sf_json_response(['ok'=>false,'error'=>'scene_not_found','message'=>'Canvas not found.'],404);
        sf_json_response(['ok'=>true,'deleted_id'=>$id],200);
    }
    if(($request['action']??null)==='cancel'){
        $requestId=sf_agent_scene_request_id($request['request_id']??null);
        sf_agent_scene_request_cancel($userId,$requestId);
        $respond(['ok'=>true]+(sf_agent_scene_request_result($userId,$requestId)??['state'=>'failed']),200);
    }
    if(in_array($request['action']??null,['choose_image_variation','swap_image'],true)){
        require_once __DIR__.'/agent-canvas-image-review.php';
        $requestId=sf_agent_scene_request_id($request['request_id']??null);
        $datasetTrackId=$datasetTrack($request['context_track_id']??null);
        if($request['action']==='choose_image_variation'){
            $imageId=$request['image_id']??null;
            if(!is_string($imageId))throw new InvalidArgumentException('Choose an available image variation.');
            $result=sf_agent_canvas_image_review_choose($userId,$requestId,$imageId);
        }else{
            $result=sf_agent_canvas_image_swap($userId,$request);
        }
        $respond(['ok'=>true]+$result,200);
    }
    $isImageEdit=($request['action']??null)==='edit_image';
    $kind=$isImageEdit?'custom':($request['canvas_kind']??'listening_room');
    if($kind==='custom')$datasetTrackId=$datasetTrack($request['context_track_id']??null);
    $isRevision=isset($request['previous_id']);
    $requestId=array_key_exists('request_id',$request)?sf_agent_scene_request_id($request['request_id']):bin2hex(random_bytes(16));
    $request['request_id']=$requestId;
    header('X-Stonefellow-Scene-Request: '.$requestId);
    header('Vary: Accept');
    $wantsStream=strcasecmp(trim($_SERVER['HTTP_ACCEPT']??''),'application/x-ndjson')===0;
    $sourceEvents=$wantsStream?new SfAgentSceneSourceEvents($sendEvent):null;
    $onDelta=$sourceEvents===null?null:$sourceEvents->push(...);
    $onRetry=$sourceEvents===null?null:$sourceEvents->retry(...);
    $onImage=static function(string $state,?array $image,int $index,int $total)use($sourceEvents,$sendEvent,&$imageTotal):void {
        $imageTotal=$total;
        if($sourceEvents===null)return;
        $sourceEvents->flush();
        $sendEvent(['type'=>'image','state'=>$state,'index'=>$index,'total'=>$total]+($image===null?[]:['image'=>$image]));
    };
    if($isImageEdit)require_once __DIR__.'/agent-canvas-image-edit.php';
    $result=$isImageEdit?sf_agent_scene_edit_image($user,$request,$onImage):sf_agent_scene_create($user,$request,$onDelta,$onRetry,$onImage);
    $status=match($result['state']??'new'){'pending'=>202,'ready','failed','canceled'=>200,default=>201};
    $respond(['ok'=>true]+$result,$status);
}catch(SfCanvasProjectApprovalRequired $e){
    $respond(['ok'=>false,'error'=>'canvas_project_approval_required','message'=>$e->getMessage()],409);
}catch(SfCanvasProjectConflict $e){
    $respond(['ok'=>false,'error'=>'canvas_project_conflict','message'=>$e->getMessage()],409);
}catch(SfAiCanvasModelUnavailable $e){
    $respond(['ok'=>false,'error'=>'scene_coding_model_unavailable','message'=>$e->getMessage()],422);
}catch(InvalidArgumentException $e){
    $respond(['ok'=>false,'error'=>'invalid_scene_request','message'=>$e->getMessage()],422);
}catch(DomainException $e){
    $respond(['ok'=>false,'error'=>'scene_provider_required','message'=>$e->getMessage()],422);
}catch(SfAgentSceneModelUnavailable $e){
    $message=$e->getMessage().($isRevision?' Your saved canvas is still available.':'');
    $respond(['ok'=>false,'error'=>'scene_model_unavailable','message'=>$message,'reason'=>$e->reason,'request_id'=>$requestId],422);
}catch(SfAgentCanvasImageUnavailable $e){
    $message=$e->getMessage().($isRevision?' Your saved canvas is still available.':'');
    $respond(['ok'=>false,'error'=>'scene_image_unavailable','message'=>$message,'reason'=>$e->reason,'request_id'=>$requestId],422);
}catch(UnexpectedValueException $e){
    $message=$kind==='custom'?('The Coding Agent returned a design Stonefellow could not display.'.($isRevision?' Your saved canvas is still available.':'')):
        'Stonefellow could not build the revision. Your saved room is still available.';
    $reason=$e instanceof SfAgentSceneInvalidOutput?$e->reason:'invalid_code';
    $respond(['ok'=>false,'error'=>'invalid_scene_output','message'=>$message,'reason'=>$reason,'request_id'=>$requestId],422);
}catch(Throwable $e){
    if(function_exists('sf_json_response')&&$e->getMessage()==='ACTIVE_TOKENS_REQUIRED')
        $respond(['ok'=>false,'error'=>'active_tokens_required','message'=>$kind==='custom'?
            'More Active Tokens are required to generate this custom canvas.':'More Active Tokens are required to generate this listening room.'],402);
    error_log('[Stonefellow listening room] '.get_class($e).($requestId===null?'':' request='.$requestId));
    $message=$isDeletion?'The canvas could not be deleted. Try again in a moment.':($method==='GET'?'Canvas history could not be loaded. Try again in a moment.':($kind==='listening_room'?
        'The listening room could not be generated. Try again in a moment.':'The canvas could not be built. Try again in a moment.'));
    if(function_exists('sf_json_response'))$respond(['ok'=>false,'error'=>'scene_unavailable','message'=>$message],503);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"ok":false,"error":"scene_unavailable","message":"The listening room service could not start."}';
}
