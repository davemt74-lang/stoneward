<?php
declare(strict_types=1);
/** Section 75C — provider-authoritative recovery for missed Stripe Store events.
 * Read-only Stripe API calls; uses the same locked transitions as signed webhooks.
 * Nothing here creates new sessions, makes charges, issues refunds, or guesses expiry.
 */
function sf_store_reconcile_order(string $orderId): array {
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$orderId))throw new InvalidArgumentException('Invalid order identifier.');
    sf_store_stripe_ready();
    $order=sf_read_order($orderId);
    if(!$order || ($order['payment']['provider']??'')!=='stripe')throw new RuntimeException('Stripe order not found.');
    $result=['order_id'=>$orderId,'checked_at'=>gmdate('c'),'checkout'=>'unchanged',
        'payment_status'=>(string)($order['payment']['status']??''),'refunds_checked'=>0,
        'pending_tasks'=>[],'warnings'=>[]];
    $sessionId=(string)($order['payment']['checkout_session_id']??'');
    if(!preg_match('/^cs_(?:test|live)_[A-Za-z0-9]+$/D',$sessionId)){
        $result['warnings'][]='Checkout session ID not yet recorded; do not create a replacement or release stock.';
        $result['checkout']='manual_review';
    } else {
        $session=sf_stripe_request('GET','checkout/sessions/'.$sessionId);
        if(!is_array($session) || (string)($session['id']??'')!==$sessionId)
            throw new RuntimeException('Stripe returned a session different from the order.');
        // Stripe server API is authenticated with the site's private Stripe key. Its
        // response still passes all the signed-webhook transition checks.
        $status=(string)($session['status']??'');
        $paid=(string)($session['payment_status']??'')==='paid';
        if($paid && $status==='complete') {
            sf_store_stripe_webhook_event($session,'checkout.session.completed');
            $result['checkout']='paid_verified';
        } elseif($status==='expired') {
            sf_store_stripe_webhook_event($session,'checkout.session.expired');
            $result['checkout']='expired_verified';
        } elseif(in_array($status,['open','complete'],true)) {
            $result['checkout']=$status==='complete'?'awaiting_async_payment':'awaiting_customer_payment';
        } else {
            $result['checkout']='manual_review';
            $result['warnings'][]='Stripe returned an unknown Checkout Session status; no local state changed.';
        }
    }
    $order=sf_read_order($orderId)??$order;
    $result['payment_status']=(string)($order['payment']['status']??'');
    $required=['library','crm','campaign','pod','notification'];
    if($result['payment_status']==='paid'){
        foreach($required as $name)if(empty($order['payment']['postpayment_done'][$name]))$result['pending_tasks'][]=$name;
        if($result['pending_tasks'])$result['warnings'][]='Verified payment has unfinished fulfillment checkpoints.';
    }
    foreach((array)($order['payment']['refund_requests']??[]) as $requestKey=>$record){
        if(!is_array($record))continue;
        if(!in_array((string)($record['status']??''),['requested','pending','requires_action'],true))continue;
        $refundId=(string)($record['refund_id']??'');
        if(!preg_match('/^re_[A-Za-z0-9]+$/D',$refundId)){
            $result['warnings'][]='Refund request requires manual Stripe reconciliation before another attempt.';
            continue;
        }
        $refund=sf_stripe_request('GET','refunds/'.$refundId);
        if((string)($refund['id']??'')!==$refundId)
            throw new RuntimeException('Stripe returned a refund different from the authorized request.');
        require_once __DIR__.'/store-refunds.php';
        sf_store_refund_webhook_event($refund,'refund.updated',(bool)($refund['livemode']??false));
        $result['refunds_checked']++;
    }
    $final=sf_read_order($orderId)??$order;
    $result['payment_status']=(string)($final['payment']['status']??'');
    $result['refunded_cents']=(int)($final['payment']['refunded_cents']??0);
    return $result;
}
/** Bounded recovery of only Stripe orders that might need attention. */
function sf_store_reconcile_batch(int $maxOrders=20,int $minAgeSeconds=120): array {
    $maxOrders=max(1,min(50,$maxOrders));
    $minAgeSeconds=max(0,min(86400,$minAgeSeconds));
    $paths=glob(SF_ROOT.'/storage/orders/SF-*.json')?:[];
    // Oldest first reduces starvation of a failed payment or refund.
    usort($paths,static fn($a,$b)=>((int)@filemtime($a))<=>((int)@filemtime($b)));
    $report=['scanned'=>0,'processed'=>0,'ok'=>0,'needs_attention'=>0,'records'=>[]];
    foreach($paths as $path){
        if($report['processed'] >= $maxOrders)break;
        $id=basename($path,'.json');
        if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id))continue;
        $order=sf_read_order($id);
        if(!$order || ($order['payment']['provider']??'')!=='stripe')continue;
        $report['scanned']++;
        $created=strtotime((string)($order['created_at']??''));
        if($created!==false && time()-$created<$minAgeSeconds)continue;
        $payment=(string)($order['payment']['status']??'');
        $refunds=(array)($order['payment']['refund_requests']??[]);
        $hasPendingRefund=false;
        foreach($refunds as $r)if(is_array($r)&&in_array((string)($r['status']??''),['requested','pending','requires_action'],true)){$hasPendingRefund=true;break;}
        $tasks=(array)($order['payment']['postpayment_done']??[]);
        $hasPendingTasks=$payment==='paid'&&count(array_filter(['library','crm','campaign','pod','notification'],static fn($name)=>empty($tasks[$name])))>0;
        if(!$hasPendingRefund&&!$hasPendingTasks&&!($payment==='pending' && !in_array((string)($order['status']??''),['canceled','refunded'],true)))continue;
        // A canceled/failed order may reflect an irreversible local stock release.
        // Reconciliation can report it, but cannot resurrect an already-canceled order.
        $report['processed']++;
        try{
            $item=sf_store_reconcile_order($id);
            $report['ok']++;
            if($item['warnings'])$report['needs_attention']++;
            $report['records'][]=$item;
        }catch(Throwable $e){
            $ref='PAYREC-'.strtoupper(bin2hex(random_bytes(4)));
            error_log('Stonefellow '.$ref.' reconciliation '.get_class($e).' '.$e->getMessage());
            $report['needs_attention']++;
            $report['records'][]=['order_id'=>$id,'status'=>'manual_review','error_ref'=>$ref];
        }
    }
    return $report;
}
