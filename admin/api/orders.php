<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$me=sf_admin_require_auth($write);

function sf_admin_order_public(array $o): array {
    $f=(array)($o['fulfillment']??[]);
    $payment=(array)($o['payment']??[]);$status=(string)($payment['status']??'');
    $stripe=(string)($payment['provider']??'')==='stripe';
    $pendingRefund=0;
    foreach((array)($payment['refund_requests']??[]) as $request){
        if(is_array($request)&&in_array((string)($request['status']??''),['requested','pending','requires_action'],true))
            $pendingRefund+=(int)($request['amount_cents']??0);
    }
    $checkpoints=(array)($payment['postpayment_done']??[]);
    $needsPostpayment=$stripe&&$status==='paid';
    if($needsPostpayment){
        $needsPostpayment=false;
        foreach(['library','crm','campaign','pod','notification'] as $part)
            if(empty($checkpoints[$part])){$needsPostpayment=true;break;}
    }
    $created=strtotime((string)($o['created_at']??''));
    $stalePending=$stripe&&$status==='pending'&&$created!==false&&time()-$created>900;
    return [
        'id'=>$o['id']??'',
        'status'=>$o['status']??'',
        'created_at'=>$o['created_at']??'',
        'customer'=>['name'=>$o['customer']['name']??'','email'=>$o['customer']['email']??''],
        'total_cents'=>$o['quote']['total_cents']??0,
        'currency'=>$o['quote']['currency']??'USD',
        'items'=>count($o['quote']['items']??[]),
        'payment_method'=>$o['payment']['method']??'',
        'payment_provider'=>$o['payment']['provider']??'',
        'payment_status'=>$o['payment']['status']??'',
        'refund_pending_cents'=>$pendingRefund,
        'refunded_cents'=>(int)($payment['refunded_cents']??0),
        'payment_review'=>$stripe&&($stalePending||$needsPostpayment||$pendingRefund>0),
        'physical'=>!empty($o['quote']['physical']),
        'fulfillment'=>[
            'status'=>(string)($f['status']??(!empty($o['quote']['physical'])?'pending':'not_required')),
            'carrier'=>(string)($f['carrier']??''),
            'tracking_number'=>(string)($f['tracking_number']??''),
            'tracking_url'=>(string)($f['tracking_url']??''),
            'updated_at'=>$f['updated_at']??null,
        ],
    ];
}
/** Serialize Admin order mutations with verified Stripe webhook/refund updates.
 * Keep this resource alive through sf_json_response; PHP closes it on request exit.
 * Never acquire this lock around code that already calls Stripe's locking helpers. */
function sf_admin_order_mutation_lock(string $id): mixed {
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id))throw new InvalidArgumentException('Invalid order identifier.');
    $path=SF_ROOT.'/storage/orders/'.$id.'.stripe.lock';
    $handle=@fopen($path,'c');
    if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Order is being updated. Retry after payment processing completes.');
    return $handle;
}
function sf_admin_order_write(array $order): void {
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]+$/',$id))throw new InvalidArgumentException('Invalid order.');
    sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);
}
function sf_admin_order_sync_pod(string $orderId,string $status): void {
    foreach(glob(SF_ROOT.'/storage/pod/'.$orderId.'-*.json')?:[] as $path){
        $h=json_decode((string)file_get_contents($path),true);if(!is_array($h))continue;
        $h['status']=$status;$h['updated_at']=gmdate('c');sf_write_json($path,$h);
    }
}
if(!$write){
    $id=sf_clean_text($_GET['id']??'',100);
    if($id!==''){
        $o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
        unset($o['confirmation_token_hash']);
        sf_json_response(['ok'=>true,'order'=>$o,'summary'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
    }
    $rows=[];foreach(sf_admin_orders() as $o)$rows[]=sf_admin_order_public($o);
    sf_json_response(['ok'=>true,'orders'=>$rows,'csrf'=>sf_admin_csrf()]);
}

$b=sf_request_json();$action=(string)($b['action']??'');
if($action==='reconcile_stripe_payment'){
    $id=sf_clean_text($b['id']??'',100);
    try {
        require_once SF_ROOT.'/api/store-payments.php';
        require_once SF_ROOT.'/api/store-reconciliation.php';
        $result=sf_store_reconcile_order($id);
        sf_log_admin_action((int)$me['id'],'stripe_order_reconciled','order',$id,
            ['checkout'=>$result['checkout'],'refunds_checked'=>$result['refunds_checked'],
             'needs_attention'=>count($result['warnings'])>0]);
        sf_json_response(['ok'=>true,'reconciliation'=>$result,'csrf'=>sf_admin_csrf()]);
    } catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    catch(Throwable $e){
        $ref='PAYREC-'.strtoupper(bin2hex(random_bytes(4)));
        error_log('Stonefellow '.$ref.' admin reconciliation '.get_class($e).' '.$e->getMessage());
        sf_json_response(['ok'=>false,'message'=>'Payment recovery needs review. Reference '.$ref,'error_ref'=>$ref],409);
    }
}
if($action==='request_stripe_refund'){
    $id=sf_clean_text($b['id']??'',100);
    $amount=(int)($b['amount_cents']??0);
    $reason=(string)($b['reason']??'requested_by_customer');
    $requestKey=(string)($b['request_key']??'');
    try{
        require_once SF_ROOT.'/api/store-payments.php';
        require_once SF_ROOT.'/api/store-refunds.php';
        $result=sf_store_refund_request($id,$amount,$reason,$requestKey,(int)$me['id']);
        try{sf_log_admin_action((int)$me['id'],'stripe_refund_requested','order',$id,
            ['amount_cents'=>$amount,'refund_id'=>$result['refund_id'],'status'=>$result['status']]);}
        catch(Throwable $auditFailure){error_log('Stripe refund audit sync pending for '.$id);}
        sf_json_response(['ok'=>true,'refund'=>$result,'csrf'=>sf_admin_csrf()]);
    }catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
    catch(Throwable $e){
        $ref='REFUND-'.strtoupper(bin2hex(random_bytes(4)));
        error_log('Stonefellow '.$ref.' Stripe refund '.get_class($e).' '.$e->getMessage());
        sf_json_response(['ok'=>false,'message'=>'Refund was not confirmed. Check Stripe before retrying. Reference '.$ref,
            'error_ref'=>$ref],409);
    }
}
if($action==='update_fulfillment'){
    $id=sf_clean_text($b['id']??'',100);$orderMutationLock=sf_admin_order_mutation_lock($id);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
    if(empty($o['quote']['physical']))sf_json_response(['ok'=>false,'message'=>'This order does not require physical fulfillment.'],422);
    if(($o['payment']['provider']??'')==='stripe'&&($o['payment']['status']??'')!=='paid')sf_json_response(['ok'=>false,'message'=>'Stripe payment must be webhook-confirmed before order fulfillment can change.'],409);
    $status=(string)($b['status']??'pending');
    $allowed=['pending','preparing','in_production','shipped','delivered','canceled'];
    if(!in_array($status,$allowed,true))sf_json_response(['ok'=>false,'message'=>'Invalid fulfillment status.'],422);
    $carrier=sf_clean_text($b['carrier']??'',100);
    $tracking=sf_clean_text($b['tracking_number']??'',120);
    $trackingUrl=trim((string)($b['tracking_url']??''));
    if($trackingUrl!==''&&!filter_var($trackingUrl,FILTER_VALIDATE_URL))sf_json_response(['ok'=>false,'message'=>'Tracking URL is invalid.'],422);
    $note=sf_clean_text($b['note']??'',1000);$now=gmdate('c');$previous=(string)($o['fulfillment']['status']??'pending');
    $o['fulfillment']=['status'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking,'tracking_url'=>$trackingUrl,'note'=>$note,'updated_at'=>$now,'updated_by'=>(int)$me['id']];
    $o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'fulfillment','status'=>$status,'label'=>'Fulfillment '.ucwords(str_replace('_',' ',$status)),'created_at'=>$now];
    if($status==='canceled'&&!in_array($previous,['shipped','delivered'],true))sf_commerce_release_order_inventory($id,'fulfillment_canceled',(int)$me['id']);
    if(in_array($status,['shipped','delivered'],true))sf_commerce_mark_order_sold($id);
    sf_admin_order_write($o);
    sf_admin_order_sync_pod($id,$status);
    $uid=(int)($o['user_id']??0);
    if($uid>0){
        $message='Order '.$id.' is now '.ucwords(str_replace('_',' ',$status)).'.';
        if($tracking!=='')$message.=' Tracking: '.$tracking.'.';
        sf_notify_user($uid,'fulfillment','Order '.$id.' updated',$message,'?view=order&id='.rawurlencode($id));
        sf_log_user_activity($uid,'order_fulfillment',$message,'order',$id,['status'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking]);
    }
    sf_log_admin_action((int)$me['id'],'fulfillment_updated','order',$id,['from'=>$previous,'to'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking]);
    if($status!==$previous&&in_array($status,['shipped','delivered','canceled'],true)){
        $email=(string)($o['customer']['email']??'');$uid=(int)($o['user_id']??0);
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            $subject=$status==='shipped'?'Stonefellow order shipped':($status==='delivered'?'Stonefellow order delivered':'Stonefellow order update');
            $body="Order {$id}\n\nStatus: ".ucwords(str_replace('_',' ',$status));
            if($status==='shipped'&&$tracking!=='')$body.="\nTracking: ".$tracking;
            if($status==='shipped'&&$trackingUrl!=='')$body.="\n".$trackingUrl;
            if($note!=='')$body.="\n\n".$note;
            try{sf_transactional_email($uid,$email,$subject,$body,'order_fulfillment');}catch(Throwable $e){}
        }
    }
    sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
}
if($action==='update_order_status'){
    $id=sf_clean_text($b['id']??'',100);$orderMutationLock=sf_admin_order_mutation_lock($id);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);$target=(string)($b['status']??'');$allowed=['canceled','refund_requested','refunded'];if(!in_array($target,$allowed,true))sf_json_response(['ok'=>false,'message'=>'Invalid order state.'],422);
    $provider=(string)($o['payment']['provider']??'');$method=(string)($o['payment']['method']??'');if($provider==='stripe')sf_json_response(['ok'=>false,'message'=>'Stripe order payment state must be updated by verified Stripe events or provider-backed refund operations.'],409);if($target==='refunded'&&!in_array($provider,['test','cash_simulation'],true)&&!in_array($method,['test','cash'],true))sf_json_response(['ok'=>false,'message'=>'This payment requires a provider-side refund. Mark it refund requested until the payment provider confirms the refund.'],422);
    if($provider==='stripe'&&$target==='canceled')sf_json_response(['ok'=>false,'message'=>'A Stripe order cannot be canceled locally while its Checkout Session may still accept payment. Expire the Stripe Checkout Session first.'],409);
    $previous=(string)($o['status']??'');$now=gmdate('c');$o['status']=$target;if($target==='refunded')$o['payment']['status']='refunded';if(in_array($target,['canceled','refunded'],true)&&!in_array((string)($o['fulfillment']['status']??''),['shipped','delivered'],true))sf_commerce_release_order_inventory($id,'order_'.$target,(int)$me['id']);if($target==='canceled'&&!empty($o['quote']['physical'])){$o['fulfillment']['status']='canceled';$o['fulfillment']['updated_at']=$now;sf_admin_order_sync_pod($id,'canceled');}$o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'order_status','status'=>$target,'label'=>ucwords(str_replace('_',' ',$target)),'created_at'=>$now];sf_admin_order_write($o);
    $uid=(int)($o['user_id']??0);if($uid>0){$label='Order '.$id.' is now '.ucwords(str_replace('_',' ',$target)).'.';sf_notify_user($uid,'order',$label,'','?view=order&id='.rawurlencode($id));sf_log_user_activity($uid,'order_status',$label,'order',$id,['from'=>$previous,'to'=>$target]);}sf_log_admin_action((int)$me['id'],'order_status_updated','order',$id,['from'=>$previous,'to'=>$target]);sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
}
if($action==='retry_pod'){
    $id=sf_clean_text($b['id']??'',100);$orderMutationLock=sf_admin_order_mutation_lock($id);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);if(empty($o['quote']['physical']))sf_json_response(['ok'=>false,'message'=>'This order has no physical handoff.'],422);if(($o['payment']['provider']??'')==='stripe'&&($o['payment']['status']??'')!=='paid')sf_json_response(['ok'=>false,'message'=>'Cannot create manufacturing files before Stripe payment is verified.'],409);$o['pod']=sf_build_pod_handoffs($id,(array)$o['quote'],(array)$o['customer']);$o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'pod_retry','status'=>'ready_for_provider','label'=>'POD handoff regenerated','created_at'=>gmdate('c')];sf_admin_order_write($o);sf_log_admin_action((int)$me['id'],'pod_handoff_retried','order',$id);sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'pod'=>$o['pod'],'csrf'=>sf_admin_csrf()]);
}
sf_json_response(['ok'=>false,'message'=>'Unsupported order action.'],422);
