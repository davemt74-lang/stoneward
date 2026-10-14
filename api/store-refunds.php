<?php
declare(strict_types=1);
/** Section 75B — one-time Stripe refund requests and signed webhook reconciliation.
 * Provider API replies NEVER confirm refunded funds; Stripe signed webhooks do.
 * No automatic restocking or digital entitlement revocation; both require policy review.
 */
function sf_store_refund_with_lock(string $orderId, callable $work): mixed {
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$orderId))throw new InvalidArgumentException('Invalid order identifier.');
    $lockPath=SF_ROOT.'/storage/orders/'.$orderId.'.stripe.lock';
    $lock=@fopen($lockPath,'c');
    if(!$lock)throw new RuntimeException('Refund lock could not be opened.');
    try {
        if(!flock($lock,LOCK_EX))throw new RuntimeException('Refund lock could not be acquired.');
        try {return $work();} finally {flock($lock,LOCK_UN);}
    } finally {fclose($lock);}
}
function sf_store_refund_total(array $requests, array $includedStatuses): int {
    $sum=0;
    foreach($requests as $record){
        if(!is_array($record)||!in_array((string)($record['status']??''),$includedStatuses,true))continue;
        $amount=(int)($record['amount_cents']??0);
        if($amount<0)throw new RuntimeException('Stored refund amount is invalid.');
        $sum+=$amount;
    }
    return $sum;
}
/** An Admin authenticated by admin/api/orders.php initiates a specific refund. */
function sf_store_refund_request(string $orderId,int $amountCents,string $reason,string $requestKey,int $adminId): array {
    if($adminId<1)throw new InvalidArgumentException('Authenticated Admin approval required.');
    if(!preg_match('/^[A-Za-z0-9._:-]{16,96}$/D',$requestKey))throw new InvalidArgumentException('Invalid refund request identifier.');
    if(!in_array($reason,['duplicate','fraudulent','requested_by_customer'],true))throw new InvalidArgumentException('Unsupported Stripe refund reason.');
    if($amountCents<1)throw new InvalidArgumentException('Refund amount must be positive.');
    sf_store_stripe_ready();
    return sf_store_refund_with_lock($orderId,static function()use($orderId,$amountCents,$reason,$requestKey,$adminId){
        $order=sf_read_order($orderId);
        if(!$order||($order['payment']['provider']??'')!=='stripe')throw new RuntimeException('Stripe order not found.');
        if(!in_array((string)($order['payment']['status']??''),['paid','partially_refunded'],true))
            throw new RuntimeException('Only confirmed paid Stripe orders can be refunded.');
        $paymentIntent=(string)($order['payment']['payment_intent']??'');
        if(!preg_match('/^pi_[A-Za-z0-9]+$/D',$paymentIntent))throw new RuntimeException('Stripe payment identifier is missing.');
        $full=(int)($order['quote']['total_cents']??0);
        if($full<=0)throw new RuntimeException('Original payment amount is invalid.');
        $requests=(array)($order['payment']['refund_requests']??[]);
        $existing=$requests[$requestKey]??null;
        if(is_array($existing)){
            if((int)($existing['amount_cents']??0)!==$amountCents || ($existing['reason']??'')!==$reason)
                throw new RuntimeException('Refund request reused with different terms.');
            if(($existing['status']??'')==='failed'||($existing['status']??'')==='canceled')
                throw new RuntimeException('This refund failed; start a new reviewed refund request.');
            if(!empty($existing['refund_id']))return ['order_id'=>$orderId,'refund_id'=>$existing['refund_id'],
                'status'=>(string)$existing['status'],'amount_cents'=>$amountCents];
            // Stripe idempotency keys may be forgotten after ~24h. Do not replay
            // an ambiguous money movement after that window; require reconciliation.
            $created=strtotime((string)($existing['created_at']??''));
            if($created===false || time()-$created>23*3600)
                throw new RuntimeException('Refund request is too old for a safe automatic retry. Review it in Stripe first.');
        }else{
            $committed=sf_store_refund_total($requests,['requested','pending','succeeded','requires_action']);
            if($amountCents>$full-$committed)throw new RuntimeException('Refund exceeds remaining refundable balance.');
            $requests[$requestKey]=['amount_cents'=>$amountCents,'reason'=>$reason,'status'=>'requested',
                'refund_id'=>'','created_at'=>gmdate('c'),'requested_by'=>$adminId];
            $order['payment']['refund_requests']=$requests;
            sf_write_json(SF_ROOT.'/storage/orders/'.$orderId.'.json',$order);
        }
        // Network uncertainty: identical Stripe idempotency key on every retry.
        $fields=['payment_intent'=>$paymentIntent,'amount'=>(string)$amountCents,'reason'=>$reason,
            'metadata[stonefellow_kind]'=>'store_refund','metadata[stonefellow_order_id]'=>$orderId,
            'metadata[stonefellow_refund_request]'=>$requestKey];
        $refund=sf_stripe_request('POST','refunds',$fields,'sf-store-refund-'.$orderId.'-'.$requestKey);
        $id=(string)($refund['id']??'');
        if(!preg_match('/^re_[A-Za-z0-9]+$/D',$id)
            ||(string)($refund['payment_intent']??'')!==$paymentIntent
            ||(int)($refund['amount']??-1)!==$amountCents
            ||strtoupper((string)($refund['currency']??''))!==strtoupper((string)($order['quote']['currency']??'')))
            throw new RuntimeException('Stripe refund response requires reconciliation before retrying.');
        // A concurrent signed webhook can have updated this record; retain its authoritative status.
        $fresh=sf_read_order($orderId)??$order;
        $record=(array)($fresh['payment']['refund_requests'][$requestKey]??$requests[$requestKey]);
        $record['refund_id']=$id;
        if(($record['status']??'')==='requested')$record['status']='pending';
        $fresh['payment']['refund_requests'][$requestKey]=$record;
        sf_write_json(SF_ROOT.'/storage/orders/'.$orderId.'.json',$fresh);
        return ['order_id'=>$orderId,'refund_id'=>$id,'status'=>$record['status'],'amount_cents'=>$amountCents];
    });
}
/** Only invoked from sf_billing_process_stripe_event, after HMAC verification. */
function sf_store_refund_webhook_event(array $refund,string $eventType,bool $verifiedLiveMode): void {
    $meta=(array)($refund['metadata']??[]);
    $orderId=(string)($meta['stonefellow_order_id']??'');
    $requestKey=(string)($meta['stonefellow_refund_request']??'');
    if(!preg_match('/^[A-Za-z0-9._:-]{16,96}$/D',$requestKey))throw new RuntimeException('Refund missing its request reference.');
    sf_store_refund_with_lock($orderId,static function()use($refund,$eventType,$orderId,$requestKey,$verifiedLiveMode){
        $order=sf_read_order($orderId);
        if(!$order||($order['payment']['provider']??'')!=='stripe')throw new RuntimeException('Stripe refund order not found.');
        $record=$order['payment']['refund_requests'][$requestKey]??null;
        if(!is_array($record))throw new RuntimeException('Refund has no previously authorized request.');
        $id=(string)($refund['id']??'');
        if(!preg_match('/^re_[A-Za-z0-9]+$/D',$id) ||
            (!empty($record['refund_id'])&&!hash_equals((string)$record['refund_id'],$id)) ||
            (string)($refund['payment_intent']??'')!==(string)($order['payment']['payment_intent']??'') ||
            (int)($refund['amount']??-1)!==(int)$record['amount_cents'] ||
            strtoupper((string)($refund['currency']??''))!==strtoupper((string)$order['quote']['currency']) ||
            $verifiedLiveMode!==((sf_store_config()['mode']??'test')==='production'))
            throw new RuntimeException('Signed Stripe refund does not match the authorized payment.');
        $status=(string)($refund['status']??'');
        if($eventType==='refund.failed')$status='failed';
        if(!in_array($status,['pending','requires_action','succeeded','failed','canceled'],true))
            throw new RuntimeException('Unrecognized Stripe refund state.');
        $old=(string)($record['status']??'');
        if($old==='succeeded')return; // Delayed provider events cannot reverse completed refunds.
        if(in_array($old,['failed','canceled'],true)&&$status==='succeeded')
            throw new RuntimeException('Refund changed from failed to succeeded; manual reconciliation required.');
        if($old===$status && (string)($record['refund_id']??'')===$id)return;
        $record['refund_id']=$id;$record['status']=$status;
        $record['updated_at']=gmdate('c');
        $order['payment']['refund_requests'][$requestKey]=$record;
        $done=sf_store_refund_total((array)$order['payment']['refund_requests'],['succeeded']);
        $total=(int)($order['quote']['total_cents']??0);
        if($done>$total)throw new RuntimeException('Refund totals exceed the original charge.');
        $order['payment']['refunded_cents']=$done;
        if($done>0){
            $order['payment']['status']=$done===$total?'refunded':'partially_refunded';
            $order['status']=$done===$total?'refunded':'partially_refunded';
        }
        $order['timeline'][]=['type'=>'stripe_refund','status'=>$status,'amount_cents'=>(int)$record['amount_cents'],
            'label'=>'Stripe refund '.$status,'created_at'=>gmdate('c')];
        // Never release physical stock or revoke entitlements automatically on a refund.
        sf_write_json(SF_ROOT.'/storage/orders/'.$orderId.'.json',$order);
    });
}
