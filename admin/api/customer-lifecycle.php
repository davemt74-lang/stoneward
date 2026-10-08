<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';$me=sf_admin_require_auth($write);sf_customer_lifecycle_ensure_schema();sf_billing_ensure_schema();
if(!$write){
    sf_json_response(['ok'=>true,'email'=>sf_customer_email_settings(),'billing'=>sf_billing_settings(),'outbox'=>sf_email_outbox(75),'webhooks'=>sf_billing_webhook_events(75),'billing_audit'=>sf_billing_audit(75),'csrf'=>sf_admin_csrf()]);
}
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='save_email') sf_json_response(['ok'=>true,'email'=>sf_customer_email_settings_save($b),'csrf'=>sf_admin_csrf()]);
    if($action==='save_billing') sf_json_response(['ok'=>true,'billing'=>sf_billing_settings_save($b),'csrf'=>sf_admin_csrf()]);
    if($action==='send_test_email'){
        $to=sf_email((string)($b['email']??$me['email']??''));if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid test email.');$r=sf_transactional_email((int)$me['id'],$to,'Stonefellow email delivery test',"Stonefellow transactional email is configured.\n\nSent: ".gmdate('c'),'delivery_test');sf_json_response(['ok'=>true,'delivery'=>$r,'csrf'=>sf_admin_csrf()]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported lifecycle setting action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
