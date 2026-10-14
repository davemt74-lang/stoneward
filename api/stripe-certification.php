<?php
declare(strict_types=1);
/**
 * Section 75E7 — evidence-backed Stripe sandbox certification.
 * Verification makes only read-only Stripe API requests. It never charges or
 * refunds cards, creates Checkout Sessions, or grants customer entitlements.
 * Evidence must originate in Stripe AND the signed local webhook ledger.
 */
function sf_cert_probe_context_valid():bool {
    // Only the authenticated certification endpoints set this per-request
    // runtime flag. Never stored as a site-wide payment setting.
    if(empty($GLOBALS['sf_cert_probe_runtime']))return false;
    try {
        sf_cert_require_run();
        return sf_merchant_direct_key_ready()
            &&(string)(sf_store_config()['mode']??'')==='stripe_test';
    }catch(Throwable $e){return false;}
}
function sf_cert_new_test_checkout(int $adminId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access required.');
    $run=sf_cert_require_run();
    if(!str_starts_with(sf_stripe_secret(),'sk_test_'))
        throw new RuntimeException('Live Stripe keys may not create certification checkouts.');
    require_once __DIR__.'/store-payments.php';
    $id='SF-CERT-'.strtoupper(bin2hex(random_bytes(10)));
    $quote=['items'=>[],'total_cents'=>100,'currency'=>'USD','physical'=>false];
    $order=['schema'=>'stonefellow.order.v1','id'=>$id,'user_id'=>null,
       'status'=>'payment_pending','created_at'=>gmdate('c'),
       'certification_probe'=>true,'certification_run_id'=>(string)$run['run_id'],
       'customer'=>['name'=>'Stonefellow Stripe Test','email'=>'stripe-test@example.invalid'],
       'quote'=>$quote,'campaign_code'=>'',
       'payment'=>['method'=>'stripe','provider'=>'stripe','status'=>'pending',
           'livemode'=>false,'amount_cents'=>100,'currency'=>'USD',
           'checkout_session_id'=>'','checkout_url'=>''],
       'fulfillment'=>['status'=>'not_required'],'timeline'=>[]];
    sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);
    $GLOBALS['sf_cert_probe_runtime']=true;
    try {$checkout=sf_store_stripe_checkout_start($order);}
    finally {unset($GLOBALS['sf_cert_probe_runtime']);}
    return ['order_id'=>$id,'checkout_url'=>$checkout['checkout_url'],'amount_cents'=>100,'currency'=>'USD'];
}
function sf_cert_refund_test_checkout(int $adminId,string $orderId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access required.');
    $run=sf_cert_require_run();
    $order=sf_read_order($orderId);
    if(!$order||empty($order['certification_probe'])
       ||(string)($order['certification_run_id']??'')!==(string)$run['run_id']
       ||(bool)($order['payment']['livemode']??true)!==false
       ||(int)($order['quote']['total_cents']??0)!==100
       ||(string)($order['payment']['status']??'')!=='paid')
        throw new RuntimeException('Only an exactly verified paid $1 test order from this run can be refunded.');
    if(empty($run['cases']['paid']['verified'])
       ||(string)$run['cases']['paid']['order_id']!==$orderId)
        throw new RuntimeException('Verify the signed successful payment event first.');
    require_once __DIR__.'/store-refunds.php';
    $GLOBALS['sf_cert_probe_runtime']=true;
    try {
        $request='sf-cert-refund-'.$orderId;
        return sf_store_refund_request($orderId,100,'requested_by_customer',$request,$adminId);
    }finally{unset($GLOBALS['sf_cert_probe_runtime']);}
}

function sf_cert_case_names():array {
    return ['paid'=>'Paid Checkout','declined'=>'Declined payment','expired'=>'Expired Checkout','refund'=>'Confirmed refund'];
}
function sf_cert_binding():array {
    $account=(string)sf_ai_meta_get('billing.stripe_direct_account_id','');
    $webhook=(string)sf_ai_meta_get('billing.stripe_direct_webhook_id','');
    $key=(string)sf_ai_meta_get('billing.stripe_secret_payload','');
    $signer=(string)sf_ai_meta_get('billing.stripe_webhook_payload','');
    return [
        'account_id'=>$account,'webhook_id'=>$webhook,
        'key_fingerprint'=>hash('sha256',$key),'webhook_fingerprint'=>hash('sha256',$signer),
        'store_mode'=>(string)(sf_store_config()['mode']??'test')
    ];
}
function sf_cert_load():array {
    $raw=(string)sf_ai_meta_get('billing.stripe_certification_run','');
    if($raw==='')return [];
    $data=json_decode($raw,true);
    return is_array($data)&&($data['schema']??'')==='stonefellow.stripe.cert.v1'?$data:[];
}
function sf_cert_save(array $state):void {
    sf_ai_meta_set('billing.stripe_certification_run',json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
function sf_cert_binding_valid(array $state):bool {
    return !empty($state['binding'])&&$state['binding']===sf_cert_binding()
       &&preg_match('/^acct_[A-Za-z0-9]+$/D',(string)($state['binding']['account_id']??''))
       &&preg_match('/^we_[A-Za-z0-9]+$/D',(string)($state['binding']['webhook_id']??''));
}
function sf_cert_ready_to_run():void {
    if(!function_exists('sf_merchant_direct_key_ready')||!sf_merchant_direct_key_ready())
        throw new RuntimeException('Verify a direct Stripe test merchant first.');
    if((string)(sf_store_config()['mode']??'')!=='stripe_test')
        throw new RuntimeException('Stripe certification requires Store stripe_test mode.');
    if((string)sf_ai_meta_get('billing.stripe_direct_mode','')!=='test')
        throw new RuntimeException('Certification requires a verified test account.');
    if(!str_starts_with(sf_stripe_secret(),'sk_test_'))
        throw new RuntimeException('A verified Stripe test secret is required.');
    if((string)sf_ai_meta_get('billing.stripe_direct_webhook_id','')===''
       ||(string)sf_ai_meta_get('billing.stripe_webhook_payload','')==='')
        throw new RuntimeException('A signed Stripe webhook must be configured.');
}
function sf_cert_certified():bool {
    $state=sf_cert_load();
    $cert=(int)($state['certified_at']??0);
    if(sf_ai_meta_get('billing.stripe_sandbox_certified','0')!=='1'
       ||$cert<=0||$cert<time()-30*86400||$cert>time()+60
       ||!sf_cert_binding_valid($state)||count((array)($state['cases']??[]))!==4)return false;
    foreach(array_keys(sf_cert_case_names()) as $name)
        if(($state['cases'][$name]['verified']??false)!==true)return false;
    return true;
}
function sf_cert_status():array {
    $state=sf_cert_load();$bindingOk=sf_cert_binding_valid($state);
    $items=[];
    foreach(sf_cert_case_names() as $name=>$label){
        $record=$bindingOk?(array)($state['cases'][$name]??[]):[];
        $items[$name]=[
            'label'=>$label,'verified'=>!empty($record['verified']),
            'order_id'=>(string)($record['order_id']??''),
            'event_id'=>(string)($record['event_id']??''),
            'verified_at'=>(string)($record['verified_at']??'')
        ];
    }
    return ['run_id'=>$bindingOk?(string)($state['run_id']??''):'',
        'started_at'=>$bindingOk?gmdate('c',(int)($state['started_at']??time())):null,
        'binding_valid'=>$bindingOk,'cases'=>$items,
        'complete'=>sf_cert_certified(),
        'certified_at'=>$bindingOk&&sf_cert_certified()?gmdate('c',(int)$state['certified_at']):null];
}
function sf_cert_start(int $adminId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access is required.');
    sf_cert_ready_to_run();
    // Validate account ownership again before associating test evidence.
    $account=sf_merchant_test_account(sf_merchant_stored_test_key());
    if($account['id']!==sf_merchant_direct_id())throw new RuntimeException('Stripe account ownership changed.');
    $state=['schema'=>'stonefellow.stripe.cert.v1','run_id'=>bin2hex(random_bytes(16)),
        'started_at'=>time(),'binding'=>sf_cert_binding(),'cases'=>[],
        'certified_at'=>0,'admin_id'=>$adminId];
    sf_ai_meta_set('billing.stripe_enabled','0');
    sf_ai_meta_set('billing.stripe_sandbox_certified','0');
    sf_cert_save($state);
    return sf_cert_status();
}
function sf_cert_require_run():array {
    sf_cert_ready_to_run();
    $state=sf_cert_load();
    if(!sf_cert_binding_valid($state)||empty($state['run_id']))
        throw new RuntimeException('Start a new certification run bound to this merchant and webhook.');
    if((int)($state['started_at']??0)<time()-30*86400)
        throw new RuntimeException('The certification run expired. Start a new run.');
    return $state;
}
function sf_cert_event_evidence(string $eventId,string $kind,array $state):array {
    if(!preg_match('/^evt_[A-Za-z0-9]{8,}$/D',$eventId))
        throw new InvalidArgumentException('A Stripe test Event ID (evt_) is required.');
    sf_billing_ensure_schema();
    $query=sf_db()->prepare('SELECT event_type,status,payload_sha256,created_at FROM billing_webhook_events WHERE event_id=? AND provider=? LIMIT 1');
    $query->execute([$eventId,'stripe']);$ledger=$query->fetch(PDO::FETCH_ASSOC);
    if(!is_array($ledger)||($ledger['status']??'')!=='processed'
       ||!preg_match('/^[a-f0-9]{64}$/D',(string)($ledger['payload_sha256']??'')))
        throw new RuntimeException('The event was not processed by Stonefellow signed-webhook verification.');
    $received=strtotime((string)($ledger['created_at']??''))?:0;
    if($received<(int)$state['started_at']-60)
        throw new RuntimeException('This webhook was received before the certification run.');
    $event=sf_stripe_request('GET','events/'.$eventId);
    $types=[
        'paid'=>['checkout.session.completed','checkout.session.async_payment_succeeded'],
        'declined'=>['payment_intent.payment_failed','checkout.session.async_payment_failed'],
        'expired'=>['checkout.session.expired'],
        'refund'=>['refund.updated']
    ];
    if((string)($event['id']??'')!==$eventId
       ||($event['livemode']??null)!==false
       ||!in_array((string)($event['type']??''),$types[$kind],true)
       ||(string)($event['type']??'')!==(string)$ledger['event_type']
       ||(int)($event['created']??0)<(int)$state['started_at']-60)
        throw new RuntimeException('Provider event is the wrong type, mode or age.');
    $object=(array)($event['data']['object']??[]);
    return ['event'=>$event,'object'=>$object];
}
function sf_cert_verify_case(int $adminId,string $kind,string $orderId,string $eventId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access is required.');
    if(!array_key_exists($kind,sf_cert_case_names()))
        throw new InvalidArgumentException('Unknown certification test case.');
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$orderId))
        throw new InvalidArgumentException('A Stonefellow test order ID is required.');
    $state=sf_cert_require_run();
    $order=sf_read_order($orderId);
    if(!$order||($order['payment']['provider']??'')!=='stripe'
       ||empty($order['certification_probe'])
       ||(string)($order['certification_run_id']??'')!==(string)$state['run_id']
       ||(int)($order['quote']['total_cents']??0)!==100
       ||($order['payment']['livemode']??null)!==false
       ||strtotime((string)($order['created_at']??''))<(int)$state['started_at']-60)
        throw new RuntimeException('This is not a test-mode Store order from the current run.');
    $sid=(string)($order['payment']['checkout_session_id']??'');
    if(!preg_match('/^cs_test_[A-Za-z0-9]+$/D',$sid))
        throw new RuntimeException('The order has no verified test-mode Stripe Checkout Session.');
    $evidence=sf_cert_event_evidence($eventId,$kind,$state);
    $object=$evidence['object'];
    $metadata=(array)($object['metadata']??[]);
    if(($metadata['stonefellow_order_id']??'')!==$orderId)
        throw new RuntimeException('The Stripe event does not belong to this Store order.');
    $session=sf_stripe_request('GET','checkout/sessions/'.$sid);
    if(($session['id']??'')!==$sid||($session['livemode']??null)!==false
       ||(string)($session['mode']??'')!=='payment'
       ||(string)($session['metadata']['stonefellow_order_id']??'')!==$orderId
       ||(int)($session['amount_total']??-1)!==(int)($order['quote']['total_cents']??-2)
       ||strtoupper((string)($session['currency']??''))!==strtoupper((string)($order['quote']['currency']??'')))
        throw new RuntimeException('Stripe Checkout does not match this Store order and amount.');
    if($kind==='paid'){
        if((string)($object['id']??'')!==$sid
           ||(string)($session['status']??'')!=='complete'
           ||(string)($session['payment_status']??'')!=='paid'
           ||!preg_match('/^pi_[A-Za-z0-9]+$/D',(string)($session['payment_intent']??''))
           ||(string)($session['payment_intent']??'')!==(string)($order['payment']['payment_intent']??'')
           ||!in_array((string)($order['payment']['status']??''),['paid','refunded','partially_refunded'],true)
           ||!in_array((string)($order['status']??''),['paid','refunded','partially_refunded'],true))
            throw new RuntimeException('Paid event is not confirmed across Stripe and Stonefellow.');
        foreach(['library','crm','campaign','pod','notification'] as $checkpoint)
            if(empty($order['payment']['postpayment_done'][$checkpoint]))
                throw new RuntimeException('The paid order has unfinished post-payment processing.');
    }elseif($kind==='declined'){
        if(!in_array((string)($order['payment']['status']??''),['pending','failed'],true)
           ||in_array((string)($order['status']??''),['paid','partially_refunded','refunded'],true)
           ||(string)($session['payment_status']??'')==='paid')
            throw new RuntimeException('A declined case must not grant paid order status.');
        if($evidence['event']['type']==='payment_intent.payment_failed'){
            if(!preg_match('/^pi_[A-Za-z0-9]+$/D',(string)($object['id']??'')))
                throw new RuntimeException('The payment failure lacks a PaymentIntent.');
            if(!empty($session['payment_intent'])
                &&(string)$session['payment_intent']!==(string)$object['id'])
                throw new RuntimeException('Declined PaymentIntent does not match Checkout.');
        }elseif((string)($object['id']??'')!==$sid)
            throw new RuntimeException('Failed asynchronous checkout ID mismatch.');
    }elseif($kind==='expired'){
        if((string)($object['id']??'')!==$sid||(string)($session['status']??'')!=='expired'
           ||(string)($order['status']??'')!=='canceled'
           ||(string)($order['payment']['status']??'')!=='failed')
            throw new RuntimeException('Expired Checkout was not canceled and reconciled.');
    }else{
        if(empty($state['cases']['paid']['verified'])
           ||(string)$state['cases']['paid']['order_id']!==$orderId)
            throw new RuntimeException('First verify the paid case for this same order.');
        $refundId=(string)($object['id']??'');
        if(!preg_match('/^re_[A-Za-z0-9]+$/D',$refundId))
            throw new RuntimeException('Refund event lacks a Stripe refund ID.');
        $confirmed=sf_stripe_request('GET','refunds/'.$refundId);
        if((string)($confirmed['id']??'')!==$refundId
           ||(string)($confirmed['status']??'')!=='succeeded'
           ||(string)($object['status']??'')!=='succeeded'
           ||(int)($object['amount']??-1)!==(int)($confirmed['amount']??-2)
           ||strtoupper((string)($confirmed['currency']??''))!==strtoupper((string)$order['quote']['currency'])
           ||(string)($confirmed['payment_intent']??'')!==(string)($order['payment']['payment_intent']??''))
            throw new RuntimeException('Stripe refund does not match the original successful charge.');
        $matched=false;
        foreach((array)($order['payment']['refund_requests']??[]) as $request)
            if((string)($request['refund_id']??'')===$refundId
               &&(string)($request['status']??'')==='succeeded'
               &&(int)($request['amount_cents']??-1)===(int)$confirmed['amount'])$matched=true;
        if(!$matched||(int)($order['payment']['refunded_cents']??0)<(int)$confirmed['amount']
           ||!in_array((string)($order['status']??''),['refunded','partially_refunded'],true))
            throw new RuntimeException('Refund has not been reconciled into the Store order.');
    }
    $state['cases'][$kind]=['verified'=>true,'order_id'=>$orderId,
        'event_id'=>$eventId,'verified_at'=>gmdate('c')];
    $state['certified_at']=0;
    sf_ai_meta_set('billing.stripe_sandbox_certified','0');
    sf_cert_save($state);
    return sf_cert_status();
}
function sf_cert_finalize(int $adminId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access is required.');
    $state=sf_cert_require_run();
    foreach(array_keys(sf_cert_case_names()) as $kind){
        $record=(array)($state['cases'][$kind]??[]);
        if(empty($record['verified']))throw new RuntimeException('All four verified Stripe test cases are required.');
        // Recheck live read-only Stripe and signed-ledger evidence; reject stale or
        // changed Store state before granting any certification.
        sf_cert_verify_case($adminId,$kind,(string)$record['order_id'],(string)$record['event_id']);
    }
    $state=sf_cert_require_run();
    if(!sf_cert_binding_valid($state))throw new RuntimeException('Merchant binding changed.');
    $account=sf_merchant_test_account(sf_merchant_stored_test_key());
    if(($account['id']??'')!==sf_merchant_direct_id()||!$account['charges_enabled'])
        throw new RuntimeException('Stripe merchant charges are not enabled.');
    $state['certified_at']=time();$state['certified_by']=$adminId;
    sf_cert_save($state);
    sf_ai_meta_set('billing.stripe_sandbox_certified','1');
    return sf_cert_status();
}


function sf_cert_webhook_health(int $adminId):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access required.');
    sf_billing_ensure_schema();
    $cutoff=gmdate('c',time()-7*86400);
    $q=sf_db()->prepare('SELECT event_type,status,created_at FROM billing_webhook_events WHERE provider=? AND created_at>=? ORDER BY created_at DESC LIMIT 100');
    $q->execute(['stripe',$cutoff]);
    $recent=[];$counts=['processed'=>0,'failed'=>0,'processing'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $status=(string)($row['status']??'');
        if(array_key_exists($status,$counts))$counts[$status]++;
        if(count($recent)<10)$recent[]=['type'=>(string)($row['event_type']??''),
           'status'=>$status,'created_at'=>(string)($row['created_at']??'')];
    }
    return ['window_days'=>7,'sample_limit'=>100,'counts'=>$counts,'recent'=>$recent];
}
