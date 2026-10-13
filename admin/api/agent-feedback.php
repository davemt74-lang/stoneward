<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-feedback-core.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$admin=sf_admin_require_auth($write);$uid=(int)$admin['id'];
header('Cache-Control: private, no-store');
if(!$write)sf_json_response(['ok'=>true,'feedback'=>sf_agent_feedback_recent('admin',$uid),'summary'=>sf_agent_feedback_summary('admin',$uid)]);
if(($_SERVER['REQUEST_METHOD']??'POST')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$body=sf_request_json();$raw=$body['message_id']??null;
if(!(is_int($raw)||(is_string($raw)&&preg_match('/^[0-9]{1,16}$/D',$raw))))
    sf_json_response(['ok'=>false,'error'=>'invalid_request'],422);
try {
    $result=sf_agent_feedback_save('admin',$uid,(int)$raw,(string)($body['rating']??''));
    sf_json_response(['ok'=>true,'feedback'=>$result]);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'error'=>'invalid_request'],422);}
catch(RuntimeException $e){sf_json_response(['ok'=>false,'error'=>'response_not_found'],404);}
catch(Throwable $e){sf_json_response(['ok'=>false,'error'=>'feedback_unavailable'],503);}
