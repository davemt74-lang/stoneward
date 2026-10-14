<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/stripe-connect.php';
require_once SF_ROOT.'/api/stripe-merchant.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$admin=sf_admin_require_auth($write);
if(!$write)sf_json_response(['ok'=>true,'stripe'=>sf_merchant_status(),'csrf'=>sf_admin_csrf()]);
try{
 $body=sf_request_json();$action=(string)($body['action']??'');
 if($action==='save_test_key'){
     $status=sf_merchant_save_test_key((string)($body['secret_test_key']??''));
     sf_json_response(['ok'=>true,'stripe'=>$status]);
 }
 if($action==='refresh_direct'){
     sf_json_response(['ok'=>true,'stripe'=>sf_merchant_refresh_test_account()]);
 }
 if($action==='create_test_webhook'){
     sf_json_response(['ok'=>true,'stripe'=>sf_merchant_create_test_webhook()]);
 }
 if($action==='start'){
     sf_user_session();
     $url=sf_connect_authorize_url((int)$admin['id']);
     sf_json_response(['ok'=>true,'authorize_url'=>$url]);
 }
 if($action==='refresh'){
     $status=sf_connect_refresh_status();
     sf_json_response(['ok'=>true,'stripe'=>$status]);
 }
 if($action==='enable_test'){
     $status=sf_merchant_status();
     if($status['provider']==='direct')$status=sf_merchant_refresh_test_account();
     else if($status['provider']==='connect')sf_connect_refresh_status();
     $status=sf_merchant_status();
     if(!$status['can_enable_test']||!$status['sandbox_certified'])
         throw new RuntimeException('Stripe account, signed webhook and independent sandbox payment/refund certification must pass before activation.');
     sf_ai_meta_set('billing.mode','test');
     sf_ai_meta_set('billing.provider','stripe');
     sf_ai_meta_set('billing.stripe_enabled','1');
     sf_json_response(['ok'=>true,'stripe'=>sf_merchant_status()]);
 }
 if($action==='disable'){
     sf_ai_meta_set('billing.stripe_enabled','0');
     sf_json_response(['ok'=>true,'stripe'=>sf_merchant_status()]);
 }
 sf_json_response(['ok'=>false,'message'=>'Unsupported Stripe connection action.'],422);
}catch(InvalidArgumentException|RuntimeException $e){
 sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
 error_log('Stonefellow Stripe connection endpoint: '.get_class($e));
 sf_json_response(['ok'=>false,'message'=>'Stripe connection could not be completed. Review protected server logs.'],500);
}
