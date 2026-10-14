<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/commerce-operations.php';
$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$me=sf_admin_require_auth($write);
if(!$write)sf_json_response(['ok'=>true,'operations'=>sf_commerce_ops_snapshot(sf_admin_orders()),'csrf'=>sf_admin_csrf()]);
$data=sf_request_json();$action=(string)($data['action']??'');
$id=sf_clean_text($data['order_id']??'',100);
try {
    if(in_array($action,['add_followup','resolve_followup'],true)){
        $items=sf_commerce_ops_update($id,(int)$me['id'],$action,$data);
        try{sf_log_admin_action((int)$me['id'],'commerce_'.$action,'order',$id,
            ['followup_id'=>(string)($data['followup_id']??''),'count'=>count($items)]);}
        catch(Throwable $logError){error_log('Commerce operations audit write pending for '.hash('sha256',$id));}
        sf_json_response(['ok'=>true,'followups'=>$items,'csrf'=>sf_admin_csrf()]);
    }
    if($action==='check_stripe'){
        $order=sf_read_order($id);
        if(!$order||!sf_store_customer_order_visible($order)
           ||($order['payment']['provider']??'')!=='stripe')
            throw new InvalidArgumentException('A valid Stripe customer order is required.');
        require_once SF_ROOT.'/api/store-payments.php';
        require_once SF_ROOT.'/api/store-reconciliation.php';
        $result=sf_store_reconcile_order($id);
        try{sf_log_admin_action((int)$me['id'],'commerce_payment_checked','order',$id,
           ['checkout'=>$result['checkout'],'needs_attention'=>count($result['warnings']??[])>0]);}
        catch(Throwable $logError){error_log('Commerce payment check audit pending for '.hash('sha256',$id));}
        sf_json_response(['ok'=>true,'reconciliation'=>$result,'csrf'=>sf_admin_csrf()]);
    }
    throw new InvalidArgumentException('Unsupported commerce operation.');
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    $ref='COMMERCE-'.strtoupper(bin2hex(random_bytes(5)));
    error_log('Stonefellow '.$ref.' commerce operation '.get_class($e).' '.$e->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Commerce operation requires review. Reference '.$ref,'error_ref'=>$ref],409);
}
