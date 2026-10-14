<?php
declare(strict_types=1);
require_once __DIR__.'/store-payment-mode.php';
/** Section 75A: one-time Stripe Checkout. Uses the SAME credentials as subscriptions.
 * The only trusted payment confirmation comes from a signed Stripe webhook.
 */
/** Opaque, ownerless receipt token derived from the private site encryption key. */
function sf_store_receipt_token(string $orderId): string {
    return hash_hmac('sha256','sf-store-receipt|'.$orderId,sf_ai_secret_key());
}
function sf_store_stripe_ready(): void {
    $settings=sf_billing_settings();
    $mode=(string)(sf_store_config()['mode']??'test');
    if(!in_array($mode,['production','stripe_test'],true)
        || $settings['mode']!==($mode==='production'?'production':'test')
        || !sf_stripe_configured())throw new RuntimeException('Store Stripe payments are not enabled in Admin.');
    $expected=$mode==='production'?'sk_live_':'sk_test_';
    if(!str_starts_with(sf_stripe_secret(),$expected))
        throw new RuntimeException('The Stripe secret key does not match the Store payment mode.');
    $base=sf_public_base_url();
    if(!str_starts_with($base,'https://'))throw new RuntimeException('A public HTTPS Base URL is required for Stripe checkout.');
}
function sf_store_stripe_session_fields(array $order): array {
    $base=rtrim(sf_public_base_url(),'/');
    $quote=(array)$order['quote'];$total=(int)($quote['total_cents']??0);
    $currency=strtolower((string)($quote['currency']??'USD'));
    if($total<50 || !preg_match('/^[a-z]{3}$/',$currency))throw new InvalidArgumentException('The Store payment total or currency is unsupported.');
    $id=(string)$order['id'];
    $url=$base.'/?view=store';
    return [
        'mode'=>'payment',
        'payment_method_types[0]'=>'card',
        'client_reference_id'=>$id,
        'customer_email'=>(string)$order['customer']['email'],
        'success_url'=>$url.'&checkout=return',
        'cancel_url'=>$url.'&checkout=canceled',
        'line_items[0][price_data][currency]'=>$currency,
        'line_items[0][price_data][unit_amount]'=>(string)$total,
        'line_items[0][price_data][product_data][name]'=>'Stonefellow order '.$id,
        'line_items[0][quantity]'=>'1',
        'metadata[stonefellow_order_id]'=>$id,
        'metadata[stonefellow_kind]'=>'store_order',
        'payment_intent_data[metadata][stonefellow_order_id]'=>$id,
    ];
}
/** Serialize the provider request and order mutation with Stripe's webhook lock.
 * A fast webhook cannot observe an uncommitted checkout_session_id or lose a paid
 * transition to a stale post-create order write. */
function sf_store_stripe_checkout_start(array $order): array {
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id))throw new InvalidArgumentException('Invalid Stripe order ID.');
    $handle=@fopen(SF_ROOT.'/storage/orders/'.$id.'.stripe.lock','c');
    if(!$handle)throw new RuntimeException('Stripe checkout lock could not be opened.');
    try {
        if(!flock($handle,LOCK_EX))throw new RuntimeException('Stripe checkout lock was unavailable.');
        try{return sf_store_stripe_checkout_start_locked($order);}
        finally{flock($handle,LOCK_UN);}
    }finally{fclose($handle);}
}
/** Called only while holding the same order lock used by signed webhooks. */
function sf_store_stripe_checkout_start_locked(array $order): array {
    sf_store_stripe_ready();
    $id=(string)$order['id'];
    $stored=sf_read_order($id);
    if(!$stored || ($stored['payment']['provider']??'')!=='stripe')throw new RuntimeException('Stripe order could not be recovered.');
    // A persisted Checkout URL is not authorization to resurrect a closed order.
    // Check the authoritative local state BEFORE reusing an older Stripe URL.
    if((string)($stored['status']??'')!=='payment_pending'
        ||(string)($stored['payment']['status']??'')!=='pending')
        throw new RuntimeException('This checkout order is no longer pending.');
    $current=(string)($stored['payment']['checkout_url']??'');
    if($current!=='' && preg_match('~^https://checkout\.stripe\.com/~',$current))return ['checkout_url'=>$current,'order_id'=>$id,'receipt_token'=>sf_store_receipt_token($id)];
    if(strtotime((string)($stored['created_at']??''))<time()-20*3600)
        throw new RuntimeException('This payment attempt needs manual reconciliation before another Checkout Session.');
    // The ID is unique to the persisted order. Retries cannot generate a second Stripe Session.
    $session=sf_stripe_request('POST','checkout/sessions',sf_store_stripe_session_fields($stored),'sf-store-'.$id);
    $url=(string)($session['url']??'');$sid=(string)($session['id']??'');
    if(!preg_match('~^https://checkout\\.stripe\\.com/~',$url)||!preg_match('/^cs_(?:live|test)_[A-Za-z0-9]+$/',$sid)
        ||!str_starts_with($sid,sf_store_order_expected_livemode($stored)?'cs_live_':'cs_test_')
        ||(isset($session['livemode'])&&(bool)$session['livemode']!==sf_store_order_expected_livemode($stored))
        ||(isset($session['amount_total'])&&(int)$session['amount_total']!==(int)$stored['quote']['total_cents'])
        ||(isset($session['currency'])&&strtoupper((string)$session['currency'])!==strtoupper((string)$stored['quote']['currency'])))
        throw new RuntimeException('Stripe returned an invalid payment session.');
    $stored['payment']['checkout_session_id']=$sid;
    $stored['payment']['checkout_url']=$url;
    sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$stored);
    return ['checkout_url'=>$url,'order_id'=>$id,'receipt_token'=>sf_store_receipt_token($id)];
}
/** Stable order identity survives a crash after reserving stock but before request-key completion.
 * This is not an authorization token; owner/email and quote identity still checked. */
function sf_store_stripe_order_id_for_request(string $requestKey): string {
    return 'SF-CK-'.strtoupper(substr(hash_hmac('sha256','stripe-checkout|'.$requestKey,sf_ai_secret_key()),0,24));
}
function sf_store_stripe_validate_replay(array $old, ?int $userId, array $customer, array $quote): void {
    if(($old['payment']['provider']??'')!=='stripe')throw new RuntimeException('This checkout attempt is not a Stripe order.');
    if((int)($old['user_id']??0)!==($userId??0)
      ||strtolower((string)($old['customer']['email']??''))!==strtolower((string)($customer['email']??'')))
      throw new RuntimeException('This checkout attempt belongs to another customer.');
    if(!hash_equals((string)($old['cart_fingerprint']??''),hash('sha256',json_encode($quote,JSON_THROW_ON_ERROR))))
      throw new RuntimeException('Your cart changed. Start a new checkout attempt.');
}
function sf_store_stripe_replay_order(array $old): array {
    $id=(string)$old['id'];
    if(($old['payment']['status']??'')==='paid')return ['ok'=>true,'order_id'=>$id,'status'=>'paid',
      'payment_provider'=>'stripe','payment_method'=>'stripe','receipt_token'=>sf_store_receipt_token($id)];
    $session=sf_store_stripe_checkout_start($old);
    return ['ok'=>true,'status'=>'payment_pending','payment_provider'=>'stripe','payment_method'=>'stripe',
      'mode'=>(string)sf_store_config()['mode']]+$session;
}
function sf_store_stripe_create_order(?array $user,array $customer,array $quote,string $requestKey,string $campaignCode): array {
    sf_store_stripe_ready();
    if((int)($quote['total_cents']??0)<50)
        throw new InvalidArgumentException('A Stripe Checkout order must have a chargeable total.');
    if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$requestKey))
        throw new InvalidArgumentException('A checkout request identifier is required for payment safety.');
    $uid=$user?(int)$user['id']:null;
    $claim=sf_order_request_claim($requestKey,$uid);
    if(($claim['state']??'')==='existing'){
        $old=sf_read_order((string)$claim['order_id']);
        if(!$old)throw new RuntimeException('This checkout attempt is no longer available.');
        sf_store_stripe_validate_replay($old,$uid,$customer,$quote);
        return sf_store_stripe_replay_order($old);
    }
    if(($claim['state']??'')!=='new')throw new RuntimeException('Checkout is already processing. Retry your existing checkout request.');
    $id=sf_store_stripe_order_id_for_request($requestKey);
    // A worker may have crashed after writing the order, before linking its
    // request ID in SQL. Recover the SAME order, NEVER reserve or bill again.
    $existing=sf_read_order($id);
    if($existing){
        sf_store_stripe_validate_replay($existing,$uid,$customer,$quote);
        sf_order_request_complete($requestKey,$id);
        return sf_store_stripe_replay_order($existing);
    }
    $now=gmdate('c');
    $order=['schema'=>'stonefellow.order.v1','id'=>$id,'user_id'=>$uid,'status'=>'payment_pending',
        'created_at'=>$now,'customer'=>$customer,'quote'=>$quote,'cart_fingerprint'=>hash('sha256',json_encode($quote,JSON_THROW_ON_ERROR)),
        'payment'=>['method'=>'stripe','provider'=>'stripe','status'=>'pending','amount_cents'=>(int)$quote['total_cents'],
            'currency'=>(string)$quote['currency'],'livemode'=>((sf_store_config()['mode']??'test')==='production'),'checkout_session_id'=>'','checkout_url'=>''],
        'fulfillment'=>['status'=>$quote['physical']?'awaiting_payment':'not_required','carrier'=>'','tracking_number'=>'','tracking_url'=>'','note'=>'','updated_at'=>$now],
        'timeline'=>[['type'=>'order_created','status'=>'payment_pending','label'=>'Awaiting verified Stripe payment','created_at'=>$now]],
        'pod'=>[],'campaign_code'=>$campaignCode,'confirmation_token_hash'=>hash('sha256',sf_token())];
    sf_commerce_reserve_order($id,$quote,$uid,(string)$customer['email']);
    try{sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);}
    catch(Throwable $e){sf_commerce_release_order_inventory($id,'order_write_failed');throw $e;}
    // Persist the idempotency mapping *before* talking to Stripe. An ambiguous network failure
    // is recovered with the same order + Stripe idempotency key, never a second order.
    sf_order_request_complete($requestKey,$id);
    $session=sf_store_stripe_checkout_start($order);
    return ['ok'=>true,'status'=>'payment_pending','payment_provider'=>'stripe','payment_method'=>'stripe',
        'mode'=>(string)sf_store_config()['mode'],'total_cents'=>$quote['total_cents'],'currency'=>$quote['currency']]+$session;
}
/** Called from a verified signed Stripe webhook, or from authenticated Stripe API GET reconciliation. */
function sf_store_stripe_webhook_event(array $session,string $eventType): void {
    $id=(string)($session['metadata']['stonefellow_order_id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]+$/',$id))throw new RuntimeException('Stripe store event lacks a valid order identifier.');
    $file=SF_ROOT.'/storage/orders/'.$id.'.json';
    $lockFile=SF_ROOT.'/storage/orders/'.$id.'.stripe.lock';
    $lock=fopen($lockFile,'c');if($lock===false)throw new RuntimeException('Cannot lock order payment state.');
    if(!flock($lock,LOCK_EX)){fclose($lock);throw new RuntimeException('Cannot lock order payment state.');}
    try {
        $order=sf_read_order($id);
        if(!$order || ($order['payment']['provider']??'')!=='stripe')throw new RuntimeException('Stripe event order was not found.');
        if((string)($session['mode']??'')!=='payment' || (string)($session['id']??'')!==($order['payment']['checkout_session_id']??''))
            throw new RuntimeException('Stripe checkout session does not match the stored order.');
        if((bool)($session['livemode']??false)!==sf_store_order_expected_livemode($order))
            throw new RuntimeException('Stripe checkout mode does not match Store mode.');
        if((int)($session['amount_total']??-1)!==(int)$order['quote']['total_cents']
            || strtoupper((string)($session['currency']??''))!==strtoupper((string)$order['quote']['currency']))
            throw new RuntimeException('Stripe checkout amount or currency differs from the order.');
        $paid=in_array($eventType,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)
            && ($session['payment_status']??'')==='paid';
        if($paid){
            if(!preg_match('/^pi_[A-Za-z0-9]+$/D',(string)($session['payment_intent']??''))
                ||(isset($session['status'])&&(string)$session['status']!=='complete')
                ||(isset($session['client_reference_id'])&&(string)$session['client_reference_id']!==$id))
                throw new RuntimeException('Stripe paid event is missing a matching completed PaymentIntent.');
            if(in_array((string)($order['status']??''),['refunded','partially_refunded'],true))return;
            if(($order['status']??'')==='canceled')throw new RuntimeException('Paid webhook arrived for a canceled order; manual reconciliation required.');
            if(($order['payment']['status']??'')!=='paid'){
                $order['payment']['status']='paid';
                $order['payment']['payment_intent']=(string)($session['payment_intent']??'');
                $order['payment']['paid_at']=gmdate('c');$order['status']='paid';
                $order['fulfillment']['status']=!empty($order['quote']['physical'])?'pending':'not_required';
                $order['timeline'][]=['type'=>'payment_confirmed','status'=>'paid','label'=>'Stripe verified payment','created_at'=>gmdate('c')];
                sf_write_json($file,$order);
            }
            // Each side effect is checkpointed in the order. If one fails, Stripe may
            // safely retry the signed event; order remains paid, without double grants.
            $uid=(int)($order['user_id']??0);$failed=[];
            foreach([
                'library'=>static function()use($uid,$order){if($uid>0)sf_account_library_add_order($uid,$order);},
                'crm'=>static function()use($order,$uid){sf_commerce_record_purchase((string)$order['id'],$uid?:null,$order['customer'],$order['quote']);},
                'campaign'=>static function()use($order,$id){$code=(string)($order['campaign_code']??'');if($code!=='')sf_campaign_redeem_code($code,$id);},
                'pod'=>static function()use($order,$id){if(!empty($order['quote']['physical']))sf_build_pod_handoffs($id,$order['quote'],$order['customer']);},
                'notification'=>static function()use($uid,$id){if($uid>0)sf_notify_user($uid,'order','Order '.$id.' paid','Your Stripe payment was confirmed.','?view=order&id='.rawurlencode($id));},
            ] as $name=>$work){
                if(!empty($order['payment']['postpayment_done'][$name]))continue;
                try{$work();$order['payment']['postpayment_done'][$name]=true;sf_write_json($file,$order);}
                catch(Throwable $e){$failed[]=$name;error_log('Stonefellow postpayment '.$name.' '.$id.' '.get_class($e));}
            }
            if($failed)throw new RuntimeException('Verified paid order requires post-payment reconciliation: '.implode(',',$failed));
        }elseif(in_array($eventType,['checkout.session.expired','checkout.session.async_payment_failed'],true)){
            if(($order['payment']['status']??'')==='paid')return;
            if(($order['status']??'')==='canceled')return;
            sf_commerce_release_order_inventory($id,'stripe_checkout_closed');
            $order['payment']['status']='failed';$order['status']='canceled';
            $order['timeline'][]=['type'=>'payment_closed','status'=>'canceled','label'=>'Stripe payment not completed','created_at'=>gmdate('c')];
            sf_write_json($file,$order);
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
