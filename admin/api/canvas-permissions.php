<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/canvas-capabilities.php';
header('Cache-Control: private, no-store');
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
$admin=sf_admin_require_auth($method==='POST');
try{
    if($method==='GET')sf_json_response(['ok'=>true,'csrf'=>sf_admin_csrf()]+sf_canvas_permission_admin_view());
    $body=sf_request_json();
    if(!is_int($body['revision']??null)||!is_array($body['changes']??null))throw new InvalidArgumentException('Use the current revision and permission changes.');
    sf_json_response(['ok'=>true,'csrf'=>sf_admin_csrf()]+sf_canvas_permission_save($admin,$body['revision'],$body['changes']));
}catch(UnexpectedValueException $e){sf_json_response(['ok'=>false,'error'=>'policy_conflict','message'=>$e->getMessage()],409);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_permissions','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('[Canvas permissions] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'permissions_unavailable','message'=>'Canvas permissions are temporarily unavailable.'],503);}
