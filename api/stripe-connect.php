<?php
declare(strict_types=1);
/**
 * Optional Stripe Connect OAuth for an already registered Stonefellow Connect
 * platform. No account or platform registration can be simulated locally.
 *
 * Single-artist stores can alternatively use direct Stripe API credentials.
 * OAuth is intentionally inert without server-side platform configuration.
 */
function sf_connect_platform():array {
    $client=trim((string)getenv('STONEFELLOW_STRIPE_CONNECT_CLIENT_ID'));
    $secret=trim((string)getenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET'));
    $mode=(string)(getenv('STONEFELLOW_STRIPE_CONNECT_MODE')?:'test');
    return ['client_id'=>$client,'secret'=>$secret,'mode'=>$mode,
        'ready'=>(bool)preg_match('/^ca_[A-Za-z0-9]+$/D',$client)
          &&str_starts_with($secret,$mode==='production'?'sk_live_':'sk_test_')
          &&in_array($mode,['test','production'],true)];
}
function sf_connect_id():string {
    $id=(string)sf_ai_meta_get('billing.stripe_connect_account_id','');
    return preg_match('/^acct_[A-Za-z0-9]+$/D',$id)?$id:'';
}
function sf_connect_mode():string {return (string)sf_ai_meta_get('billing.stripe_connect_mode','');}
function sf_connect_active():bool {
    $p=sf_connect_platform();
    return $p['ready']&&sf_connect_id()!==''&&sf_connect_mode()===$p['mode'];
}
function sf_connect_callback_url():string {
    $base=rtrim((string)sf_public_base_url(),'/');
    if(!str_starts_with($base,'https://')||parse_url($base,PHP_URL_USER)!==null
       ||!filter_var($base,FILTER_VALIDATE_URL))
        throw new RuntimeException('Set an HTTPS public site URL before connecting Stripe.');
    return $base.'/admin/stripe-connect-callback.php';
}
function sf_connect_authorize_url(int $adminId):string {
    $p=sf_connect_platform();
    if(!$p['ready'])throw new RuntimeException('Stripe Connect platform registration has not been configured on this server.');
    if($adminId<=0)throw new RuntimeException('Administrator authentication required.');
    if(sf_ai_meta_get('billing.stripe_secret_payload','')!=='')throw new RuntimeException('Direct Stripe credentials already exist. Review the existing payment account before switching to Connect.');
    $state=bin2hex(random_bytes(32));
    $_SESSION['sf_connect_oauth']=['state_hash'=>hash('sha256',$state),'admin_id'=>$adminId,
      'started_at'=>time(),'mode'=>$p['mode']];
    $fields=['response_type'=>'code','client_id'=>$p['client_id'],
        'scope'=>'read_write','state'=>$state,
        'redirect_uri'=>sf_connect_callback_url()];
    return 'https://connect.stripe.com/oauth/authorize?'.http_build_query($fields,'','&',PHP_QUERY_RFC3986);
}
function sf_connect_consume_state(string $state,int $adminId):string {
    $pending=(array)($_SESSION['sf_connect_oauth']??[]);
    unset($_SESSION['sf_connect_oauth']); // consume before any provider request
    if(!preg_match('/^[a-f0-9]{64}$/D',$state)
        ||!isset($pending['state_hash'])
        ||!hash_equals((string)$pending['state_hash'],hash('sha256',$state))
        ||(int)($pending['admin_id']??0)!==$adminId
        ||(int)($pending['started_at']??0)<time()-600
        ||(int)($pending['started_at']??0)>time()+30)
        throw new RuntimeException('The Stripe connection request expired or could not be verified.');
    return (string)$pending['mode'];
}
function sf_connect_provider_request(string $method,string $path,array $data=[],string $account=''):array {
    $p=sf_connect_platform();
    if(!$p['ready'])throw new RuntimeException('Stripe Connect platform is not configured.');
    if($account!==''&&!preg_match('/^acct_[A-Za-z0-9]+$/D',$account))
        throw new InvalidArgumentException('Invalid connected account.');
    $headers=['Authorization: Bearer '.$p['secret']];
    if($account!=='')$headers[]='Stripe-Account: '.$account;
    return sf_http_form_request($method,'https://api.stripe.com/v1/'.ltrim($path,'/'),$headers,$data);
}
function sf_connect_account_status(string $account):array {
    $a=sf_connect_provider_request('GET','account',[],$account);
    if((string)($a['id']??'')!==$account)throw new RuntimeException('Stripe returned a different account.');
    return ['account_id'=>$account,
        'charges_enabled'=>(bool)($a['charges_enabled']??false),
        'payouts_enabled'=>(bool)($a['payouts_enabled']??false),
        'details_submitted'=>(bool)($a['details_submitted']??false)];
}
function sf_connect_webhook_setup(string $account):bool {
    // Refuse a silent duplicate if an existing endpoint was already stored.
    if(sf_ai_meta_get('billing.stripe_connect_webhook_id','')!==''
       &&sf_ai_meta_get('billing.stripe_webhook_payload','')!=='')return true;
    $url=rtrim((string)sf_public_base_url(),'/').'/api/billing-webhook.php';
    if(!str_starts_with($url,'https://'))throw new RuntimeException('Stripe webhooks require HTTPS.');
    $types=['payment_intent.payment_failed','checkout.session.completed','checkout.session.async_payment_succeeded',
       'checkout.session.expired','refund.created','refund.updated','refund.failed',
       'invoice.payment_succeeded','invoice.payment_failed',
       'customer.subscription.created','customer.subscription.updated',
       'customer.subscription.deleted'];
    $data=['url'=>$url,'description'=>'Stonefellow ecommerce + subscriptions'];
    foreach($types as $i=>$type)$data['enabled_events['.$i.']']=$type;
    // A lost response might mean the provider already created the endpoint.
    // In that situation do not create an unknown duplicate signing secret.
    $known=sf_connect_provider_request('GET','webhook_endpoints',[],$account);
    foreach((array)($known['data']??[]) as $endpoint){
        if((string)($endpoint['url']??'')===$url)
            throw new RuntimeException('A Stripe webhook already exists for this URL; reconcile its signing secret in Stripe before continuing.');
    }
    $r=sf_connect_provider_request('POST','webhook_endpoints',$data,$account);
    $id=(string)($r['id']??'');$secret=(string)($r['secret']??'');
    if(!preg_match('/^we_[A-Za-z0-9]+$/D',$id)||!str_starts_with($secret,'whsec_'))
        throw new RuntimeException('Stripe webhook registration did not provide a signing secret.');
    sf_ai_meta_set('billing.stripe_webhook_payload',sf_ai_encrypt($secret));
    sf_ai_meta_set('billing.stripe_webhook_hint',sf_ai_hint($secret));
    sf_ai_meta_set('billing.stripe_connect_webhook_id',$id);
    return true;
}
function sf_connect_finish(int $adminId,string $state,string $code):array {
    $mode=sf_connect_consume_state($state,$adminId);
    $p=sf_connect_platform();
    if(!$p['ready']||$mode!==$p['mode'])
        throw new RuntimeException('Stripe connection environment changed during authorization.');
    if(!preg_match('/^ac_[A-Za-z0-9]+$/D',$code))
        throw new RuntimeException('Stripe did not return a valid authorization code.');
    // This call is intentionally *not* retried: Stripe consumes OAuth codes once.
    $token=sf_http_form_request('POST','https://connect.stripe.com/oauth/token',
      ['Authorization: Bearer '.$p['secret']],
      ['grant_type'=>'authorization_code','code'=>$code]);
    $id=(string)($token['stripe_user_id']??'');
    if(!preg_match('/^acct_[A-Za-z0-9]+$/D',$id)
       ||(bool)($token['livemode']??false)!==($mode==='production')
       ||(string)($token['scope']??'')!=='read_write')
        throw new RuntimeException('Stripe OAuth response did not match the requested account permissions and environment.');
    $account=sf_connect_account_status($id);
    $existing=sf_connect_id();
    if(sf_ai_meta_get('billing.stripe_secret_payload','')!=='')
        throw new RuntimeException('Direct Stripe credentials are already configured; automatic account switching is blocked.');
    if($existing!==''&&$existing!==$id)
        throw new RuntimeException('Another Stripe account is already linked. Review and disconnect it before changing merchants.');
    sf_ai_meta_set('billing.stripe_connect_account_id',$id);
    sf_ai_meta_set('billing.stripe_connect_mode',$mode);
    sf_ai_meta_set('billing.stripe_connect_charge_status',$account['charges_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_connect_payout_status',$account['payouts_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_connect_details_status',$account['details_submitted']?'1':'0');
    // Never enable payments just because OAuth returned. Webhook signature and
    // real sandbox acceptance are still mandatory.
    sf_ai_meta_set('billing.stripe_enabled','0');
    try{sf_connect_webhook_setup($id);}catch(Throwable $e){
        error_log('Stonefellow Stripe webhook setup failed for '.substr(hash('sha256',$id),0,12).': '.get_class($e));
    }
    return sf_connect_public_status();
}
function sf_connect_public_status():array {
    $p=sf_connect_platform();$id=sf_connect_id();
    $webhook=sf_ai_meta_get('billing.stripe_webhook_payload','')!=='' &&
       sf_ai_meta_get('billing.stripe_connect_webhook_id','')!=='';
    return ['platform_ready'=>$p['ready'],'connected'=>sf_connect_active(),
       'account_id'=>$id,'environment'=>sf_connect_mode()?:$p['mode'],
       'charges_enabled'=>sf_ai_meta_get('billing.stripe_connect_charge_status','0')==='1',
       'payouts_enabled'=>sf_ai_meta_get('billing.stripe_connect_payout_status','0')==='1',
       'details_submitted'=>sf_ai_meta_get('billing.stripe_connect_details_status','0')==='1',
       'webhook_ready'=>$webhook,'sandbox_certified'=>sf_ai_meta_get('billing.stripe_sandbox_certified','0')==='1','payments_enabled'=>sf_ai_meta_get('billing.stripe_enabled','0')==='1',
       'signup_url'=>'https://stripe.com/'];
}
function sf_connect_refresh_status():array {
    if(!sf_connect_active())throw new RuntimeException('A Stripe Connect account has not been linked.');
    $account=sf_connect_account_status(sf_connect_id());
    sf_ai_meta_set('billing.stripe_connect_charge_status',$account['charges_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_connect_payout_status',$account['payouts_enabled']?'1':'0');
    sf_ai_meta_set('billing.stripe_connect_details_status',$account['details_submitted']?'1':'0');
    if(!$account['charges_enabled'])sf_ai_meta_set('billing.stripe_enabled','0');
    if(sf_ai_meta_get('billing.stripe_connect_webhook_id','')==='')sf_connect_webhook_setup(sf_connect_id());
    return sf_connect_public_status();
}
