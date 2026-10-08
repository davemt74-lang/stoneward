<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_billing_ensure_schema();
$method=$_SERVER['REQUEST_METHOD']??'GET';$u=sf_require_user(false,$method!=='GET');$uid=(int)$u['id'];
if($method==='GET') sf_json_response(['ok'=>true,'entitlement'=>sf_entitlement_state($uid),'invoices'=>sf_billing_invoices($uid),'billing'=>sf_billing_settings()]);
$b=sf_request_json();$action=(string)($b['action']??'choose_package');
try{
    if($action==='choose_package'){
        $packageId=(int)($b['package_id']??0);$result=sf_billing_checkout($u,$packageId);sf_json_response(['ok'=>true]+$result);
    }
    if($action==='portal') sf_json_response(['ok'=>true]+sf_billing_portal($u));
    if($action==='cancel') sf_json_response(['ok'=>true,'entitlement'=>sf_billing_cancel($u)]);
    if($action==='resume') sf_json_response(['ok'=>true,'entitlement'=>sf_billing_resume($u)]);
    sf_json_response(['ok'=>false,'message'=>'Unsupported subscription action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
