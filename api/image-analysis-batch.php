<?php
declare(strict_types=1);
@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/image-analysis-batch-core.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
$user=sf_require_user(false,$method==='POST');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{
    $scope='private';
    if($method==='GET'){
        if(!isset($_GET['id']))sf_json_response(['ok'=>true]+sf_image_analysis_batch_list($user,$scope));
        if(!is_string($_GET['id']))throw new InvalidArgumentException('Choose a saved image analysis batch.');
        sf_json_response(['ok'=>true,'job'=>sf_image_analysis_batch_get($user,$scope,$_GET['id'])]);
    }
    $body=sf_request_json();$action=$body['action']??null;
    if($action==='create'){
        if(!is_string($body['request_id']??null)||!is_string($body['mode']??null)||!is_array($body['ids']??[]))
            throw new InvalidArgumentException('Choose images for a new analysis batch.');
        $job=sf_image_analysis_batch_create($user,$scope,$body['request_id'],$body['mode'],$body['ids']??[]);
        sf_json_response(['ok'=>true,'job'=>$job]);
    }
    if(!is_string($body['id']??null))throw new InvalidArgumentException('Choose a saved image analysis batch.');
    if($action==='review'){
        if(!is_array($body['items']??null))throw new InvalidArgumentException('Choose image analyses to review.');
        sf_json_response(['ok'=>true]+sf_image_analysis_batch_review($user,$scope,$body['id'],$body['items']));
    }
    if($action==='step'){
        if(isset($body['request_id'])&&!is_string($body['request_id']))throw new InvalidArgumentException('Choose a batch item.');
        $job=sf_image_analysis_batch_step($user,$scope,$body['id'],null,null,$body['request_id']??null);
    }elseif(in_array($action,['pause','resume','cancel'],true))$job=sf_image_analysis_batch_action($user,$scope,$body['id'],$action);
    else throw new InvalidArgumentException('Choose a batch action.');
    sf_json_response(['ok'=>true,'job'=>$job]);
}catch(OutOfBoundsException $error){sf_json_response(['ok'=>false,'error'=>'image_analysis_batch_not_found','message'=>$error->getMessage()],404);}
catch(InvalidArgumentException $error){sf_json_response(['ok'=>false,'error'=>'invalid_image_analysis_batch','message'=>$error->getMessage()],422);}
catch(Throwable $error){error_log('[Stonefellow image analysis batch] '.get_class($error));sf_json_response(['ok'=>false,'error'=>'image_analysis_batch_unavailable','message'=>'The saved image analysis batch is temporarily unavailable.'],503);}
