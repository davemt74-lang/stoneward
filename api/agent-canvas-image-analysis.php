<?php
declare(strict_types=1);
@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-canvas-image-analysis-core.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
$user=sf_require_user(false,$method==='POST');$owner=(int)$user['id'];
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    $body=$method==='POST'?sf_request_json():$_GET;$id=$body['id']??null;
    if(!sf_agent_canvas_image_id($id))throw new InvalidArgumentException('Choose an image from your library.');
    if($method==='GET')$result=sf_agent_canvas_image_analysis_get($owner,$id);
    elseif(($body['action']??'')==='analyze'){
        if(!sf_agent_canvas_image_id($body['request_id']??null))throw new InvalidArgumentException('Use a valid image analysis request ID.');
        if(isset($body['suggest_profile'])&&!is_bool($body['suggest_profile']))throw new InvalidArgumentException('Choose whether to review profile suggestions.');
        $result=sf_agent_canvas_image_analyze($user,$id,$body['request_id'],($body['suggest_profile']??false)===true);
    }elseif(($body['action']??'')==='reset'){
        if(!is_string($body['analysis_id']??null))throw new InvalidArgumentException('Choose the analysis to reset.');
        $result=sf_agent_canvas_image_analysis_reset($user,$id,$body['analysis_id']);
    }elseif(($body['action']??'')==='save'){
        if(!is_string($body['analysis_id']??null)||!is_int($body['revision']??null))throw new InvalidArgumentException('Choose the current analysis revision.');
        $result=sf_agent_canvas_image_analysis_save($owner,$id,$body['analysis_id'],$body['revision'],$body['analysis']??null);
    }else throw new InvalidArgumentException('Choose an image analysis action.');
    sf_json_response(['ok'=>true]+$result);
}catch(SfAgentCanvasImageAnalysisConflict $error){sf_json_response(['ok'=>false,'error'=>'image_analysis_conflict','message'=>$error->getMessage()],409);}
catch(InvalidArgumentException $error){sf_json_response(['ok'=>false,'error'=>'invalid_image_analysis','message'=>$error->getMessage()],422);}
catch(Throwable $error){
    if($error->getMessage()==='ACTIVE_TOKENS_REQUIRED')sf_json_response(['ok'=>false,'error'=>'active_tokens_required','message'=>'Not enough unreserved Active Tokens remain for image analysis.'],402);
    error_log('[Stonefellow private image analysis API] '.get_class($error));
    sf_json_response(['ok'=>false,'error'=>'image_analysis_unavailable','message'=>'Image analysis is temporarily unavailable.'],503);
}
