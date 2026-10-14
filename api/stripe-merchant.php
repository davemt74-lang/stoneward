<?php
declare(strict_types=1);
/**
 * Stonefellow Section 75E6 — single merchant Stripe onboarding.
 * Stripe does not offer direct merchant OAuth without a registered platform.
 * Direct test keys never leave the authenticated Admin POST or server's encrypted metadata.
 */
function sf_merchant_direct_id():string {
    $id=(string)sf_ai_meta_get('billing.stripe_direct_account_id','');
    return preg_match('/^acct_[A-Za-z0-9]+$/D',$id)?$id:'';
}
function sf_merchant_direct_key_ready():bool {
    $payload=(string)sf_ai_meta_get('billing.stripe_secret_payload','');
    return $payload!=='' && sf_merchant_direct_id()!=='';
}
function sf_merchant_test_key(string $key):string {
    $key=trim($key);
    if(!preg_match('/^sk_test_[A-Za-z0-9]{8,}$/D',$key))
        throw new InvalidArgumentException('A Stripe secret TEST key (sk_test_) is required. Live keys and publishable keys cannot be submitted here.');
    return $key;
}
function sf_merchant_test_key_request(string $key,string $method,string $endpoint,array $fields=[]):array {
    $url='https://api.stripe.com/v1/'.ltrim($endpoint,'/');
    // Only explicit allowlisted Stripe API paths and methods in this setup module.
    if(!in_array([$method,$endpoint],[['GET','account'],['GET','webhook_endpoints?limit=100'],['POST','webhook_endpoints']],true))
        throw new InvalidArgumentException('Unsupported merchant setup provider action.');
    return sf_http_form_request($method,$url,['Authorization: Bearer '.sf_merchant_test_key($key)],$fields);
}
function sf_merchant_test_account(string $key):array {
    $result=sf_merchant_test_key_request($key,'GET','account');
    $id=(string)($result['id']??'');
    if(!preg_match('/^acct_[A-Za-z0-9]+$/D',$id))throw new RuntimeException('Stripe did not return a valid merchant account identifier.');
    if(isset($result['livemode'])&&$result['livemode']!==false)
        throw new RuntimeException('Stripe returned a live account result while test mode was requested.');
    return ['id'=>$id,'charges_enabled'=>(bool)($result['charges_enabled']??false),
      'details_submitted'=>(bool)($result['details_submitted']??false),
      'payouts_enabled'=>(bool)($result['payouts_enabled']??false)];
}
function sf_merchant_ensure_test_context():void {
    if((string)(sf_store_config()['mode']??'')!=='stripe_test')
        throw new RuntimeException('Set STONEFELLOW_STORE_MODE=stripe_test on this server before configuring test Stripe payments.');
    if(sf_connect_active()||sf_connect_id()!=='')
        throw new RuntimeException('A Connect merchant is already linked. Do not automatically switch payment recipients.');
}
function sf_merchant_save_test_key(string $raw):array {
    sf_merchant_ensure_test_context();
    $key=sf_merchant_test_key($raw);
    $info=sf_merchant_test_account($key); // verify with Stripe BEFORE storing credentials
    $prior=sf_merchant_direct_id();
    if($prior!==''&&!hash_equals($prior,$info['id']))
        throw new RuntimeException('The new credential belongs to a different Stripe account. Review the existing merchant before changing recipients.');
    $oldPayload=(string)sf_ai_meta_get('billing.stripe_secret_payload','');
    if($oldPayload!==''&&$prior==='')throw new RuntimeException('Existing Stripe credentials have no verified account binding; manual review is required.');
    sf_ai_meta_set('billing.stripe_enabled','0');
    sf_ai_meta_set('billing.stripe_sandbox_certified','0');
    sf_ai_meta_set('billing.stripe_secret_payload',sf_ai_encrypt($key));
    sf_ai_meta_set('billing.stripe_key_hint',sf_ai_hint($key));
    sf_ai_meta_set('billing.stripe_direct_account_id',$info['id']);
    sf_ai_meta_set('billing.stripe_direct_mode','test');
    sf_ai_meta_set('billing.stripe_direct_charge_status',$info['charges_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_direct_details_status',$info['details_submitted']?'1':'0');
    sf_ai_meta_set('billing.stripe_direct_payout_status',$info['payouts_enabled']?'1':'0');
    sf_ai_meta_set('billing.provider','stripe');
    sf_ai_meta_set('billing.mode','test');
    return sf_merchant_status();
}
function sf_merchant_stored_test_key():string {
    if(!sf_merchant_direct_key_ready())throw new RuntimeException('Configure a verified Stripe test merchant first.');
    $key=sf_ai_decrypt((string)sf_ai_meta_get('billing.stripe_secret_payload',''));
    return sf_merchant_test_key($key);
}
function sf_merchant_refresh_test_account():array {
    sf_merchant_ensure_test_context();
    $info=sf_merchant_test_account(sf_merchant_stored_test_key());
    if(!hash_equals(sf_merchant_direct_id(),$info['id']))
        throw new RuntimeException('Stripe returned a different merchant account; payment processing remains blocked.');
    sf_ai_meta_set('billing.stripe_direct_charge_status',$info['charges_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_direct_details_status',$info['details_submitted']?'1':'0');
    sf_ai_meta_set('billing.stripe_direct_payout_status',$info['payouts_enabled']?'1':'0');
    if(!$info['charges_enabled'])sf_ai_meta_set('billing.stripe_enabled','0');
    return sf_merchant_status();
}
function sf_merchant_webhook_url():string {
    $base=rtrim((string)sf_public_base_url(),'/');
    if(!filter_var($base,FILTER_VALIDATE_URL)
       ||strtolower((string)parse_url($base,PHP_URL_SCHEME))!=='https'
       ||parse_url($base,PHP_URL_QUERY)!==null||parse_url($base,PHP_URL_FRAGMENT)!==null
       ||parse_url($base,PHP_URL_USER)!==null||parse_url($base,PHP_URL_PASS)!==null)
        throw new RuntimeException('Configure a public HTTPS Base URL without a query, fragment or credentials before setting up webhooks.');
    return $base.'/api/billing-webhook.php';
}
function sf_merchant_webhook_events():array {
    return ['checkout.session.completed','checkout.session.async_payment_succeeded',
       'checkout.session.async_payment_failed','checkout.session.expired',
       'refund.created','refund.updated','refund.failed','invoice.payment_succeeded',
       'invoice.payment_failed','customer.subscription.created',
       'customer.subscription.updated','customer.subscription.deleted'];
}
function sf_merchant_create_test_webhook():array {
    sf_merchant_ensure_test_context();
    $key=sf_merchant_stored_test_key();
    $account=sf_merchant_test_account($key);
    if($account['id']!==sf_merchant_direct_id())throw new RuntimeException('Connected Stripe merchant changed.');
    $url=sf_merchant_webhook_url();
    if((string)sf_ai_meta_get('billing.stripe_direct_webhook_id','')!==''
       &&(string)sf_ai_meta_get('billing.stripe_webhook_payload','')!=='')return sf_merchant_status();
    // A lost HTTP response could have created an endpoint already. In that case
    // Stripe won't redisplay the signing secret. Fail closed; never create duplicates.
    $existing=sf_merchant_test_key_request($key,'GET','webhook_endpoints?limit=100');
    foreach((array)($existing['data']??[]) as $row){
        if(is_array($row)&&($row['url']??null)===$url)
            throw new RuntimeException('A Stripe webhook already exists for this URL. Open Stripe Webhooks to reconcile the existing signing secret; no duplicate was created.');
    }
    if(!empty($existing['has_more']))
        throw new RuntimeException('Stripe has additional webhook endpoints. Review the existing endpoints before creating another.');
    $fields=['url'=>$url,'description'=>'Stonefellow single-artist Store + subscriptions'];
    foreach(sf_merchant_webhook_events() as $i=>$event)$fields['enabled_events['.$i.']']=$event;
    $created=sf_merchant_test_key_request($key,'POST','webhook_endpoints',$fields);
    if(!preg_match('/^we_[A-Za-z0-9]+$/D',(string)($created['id']??''))
       ||!preg_match('/^whsec_[A-Za-z0-9]+$/D',(string)($created['secret']??'')))
        throw new RuntimeException('Stripe did not provide the new webhook endpoint and signing secret. Review Stripe Webhooks before retrying.');
    sf_ai_meta_set('billing.stripe_enabled','0');
    sf_ai_meta_set('billing.stripe_sandbox_certified','0');
    sf_ai_meta_set('billing.stripe_webhook_payload',sf_ai_encrypt((string)$created['secret']));
    sf_ai_meta_set('billing.stripe_webhook_hint',sf_ai_hint((string)$created['secret']));
    sf_ai_meta_set('billing.stripe_direct_webhook_id',(string)$created['id']);
    return sf_merchant_status();
}
function sf_merchant_status():array {
    $connect=sf_connect_public_status();
    $id=sf_merchant_direct_id();
    $direct=sf_merchant_direct_key_ready();
    $provider=$direct?'direct':(!empty($connect['connected'])?'connect':'none');
    $webhook=$provider==='direct'
        ?(sf_ai_meta_get('billing.stripe_direct_webhook_id','')!==''&&sf_ai_meta_get('billing.stripe_webhook_payload','')!=='')
        :($provider==='connect'&&!empty($connect['webhook_ready']));
    $charge=$provider==='direct'
        ?sf_ai_meta_get('billing.stripe_direct_charge_status','0')==='1'
        :(!empty($connect['charges_enabled']));
    $mode=$provider==='direct'?'test':(string)($connect['environment']??'test');
    $certified=sf_ai_meta_get('billing.stripe_sandbox_certified','0')==='1';
    $storeMode=(string)(sf_store_config()['mode']??'test');
    return ['provider'=>$provider,'direct_ready'=>$direct,'account_id'=>$provider==='direct'?$id:(string)($connect['account_id']??''),
       'environment'=>$mode,'store_mode'=>$storeMode,'platform_ready'=>(bool)($connect['platform_ready']??false),
       'connected'=>$provider!=='none','charges_enabled'=>$charge,'details_submitted'=>$provider==='direct'
          ?sf_ai_meta_get('billing.stripe_direct_details_status','0')==='1':(!empty($connect['details_submitted'])),
       'webhook_ready'=>$webhook,'webhook_url'=>sf_merchant_webhook_safe_url(),
       'sandbox_certified'=>$certified,'payments_enabled'=>sf_ai_meta_get('billing.stripe_enabled','0')==='1',
       'can_enable_test'=>$provider!=='none'&&$storeMode==='stripe_test'&&$mode==='test'
          &&$charge&&$webhook&&$certified];
}
function sf_merchant_webhook_safe_url():string {
    try{return sf_merchant_webhook_url();}catch(Throwable $e){return '';}
}
