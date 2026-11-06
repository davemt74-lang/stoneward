<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require_once __DIR__.'/token-meter-core.php';
header('Cache-Control: private, no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);}
$user=sf_require_user();
try{sf_json_response(['ok'=>true,'meter'=>sf_token_meter($user)]);
}catch(DomainException $e){sf_json_response(['ok'=>false,'error'=>'token_account_unavailable','message'=>'Your token account is unavailable.'],403);
}catch(Throwable $e){error_log('[Token meter] '.get_class($e));sf_json_response(['ok'=>false,'error'=>'token_meter_unavailable','message'=>'Token availability is temporarily unavailable.'],503);}
