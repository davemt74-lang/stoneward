<?php
declare(strict_types=1);
/** Standalone executable Section 75A acceptance. No network, Stripe key, or live DB. */
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/stonefellow75-'.bin2hex(random_bytes(5));
mkdir($tmp.'/storage/orders',0700,true);define('SF_ROOT',$tmp);
$GLOBALS['fake']=['mode'=>'stripe_test','billing_mode'=>'test','secret'=>'sk_test_123','enabled'=>true,'request_keys'=>[],'orders'=>[],'reserved'=>[],'released'=>[],'sessions'=>0,'library'=>0,'crm'=>0,'notify'=>0,'pod'=>0,'campaign'=>0,'fail_session'=>false,'fail_library'=>false];
function sf_store_config():array{return ['mode'=>$GLOBALS['fake']['mode']];}
function sf_billing_settings():array{return ['mode'=>$GLOBALS['fake']['billing_mode']];}
function sf_stripe_configured():bool{return $GLOBALS['fake']['enabled'];}
function sf_stripe_secret():string{return $GLOBALS['fake']['secret'];}
function sf_public_base_url():string{return 'https://stonefellow.example';}
function sf_ai_secret_key():string{return str_repeat('k',32);}
function sf_order_id():string{return 'SF-TEST-'.strtoupper(bin2hex(random_bytes(4)));}
function sf_token():string{return bin2hex(random_bytes(16));}
function sf_write_json(string $file,array $data):void{file_put_contents($file,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
function sf_read_order(string $id):?array{$f=SF_ROOT.'/storage/orders/'.$id.'.json';return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
function sf_order_request_claim(string $key,?int $uid):array{
  if(isset($GLOBALS['fake']['request_keys'][$key]))return ['state'=>'existing','order_id'=>$GLOBALS['fake']['request_keys'][$key]];
  $GLOBALS['fake']['request_keys'][$key]='';return ['state'=>'new','order_id'=>''];
}
function sf_order_request_complete(string $key,string $id):void{$GLOBALS['fake']['request_keys'][$key]=$id;}
function sf_commerce_reserve_order(string $id,array $q,?int $uid,string $email):array{$GLOBALS['fake']['reserved'][$id]=($GLOBALS['fake']['reserved'][$id]??0)+1;return ['reserved'=>1];}
function sf_commerce_release_order_inventory(string $id,string $reason):int{$GLOBALS['fake']['released'][$id]=($GLOBALS['fake']['released'][$id]??0)+1;return 1;}
function sf_account_library_add_order(int $uid,array $order):void{if($GLOBALS['fake']['fail_library'])throw new RuntimeException('temporary library unavailable');$GLOBALS['fake']['library']++;}
function sf_commerce_record_purchase(string $id,?int $uid,array $customer,array $quote):void{$GLOBALS['fake']['crm']++;}
function sf_notify_user(int $uid,string $type,string $subject,string $body,string $url):void{$GLOBALS['fake']['notify']++;}
function sf_build_pod_handoffs(string $id,array $quote,array $customer):array{$GLOBALS['fake']['pod']++;return [];}
function sf_campaign_redeem_code(string $code,string $id):void{$GLOBALS['fake']['campaign']++;}
function sf_stripe_request(string $method,string $path,array $fields=[],string $key=''):array{
  $GLOBALS['fake']['sessions']++;
  if($GLOBALS['fake']['fail_session'])throw new RuntimeException('Injected Stripe transport failure');
  if($method!=='POST'||$path!=='checkout/sessions'||!str_starts_with($key,'sf-store-SF-'))throw new RuntimeException('Missing safe idempotency');
  if(($fields['mode']??'')!=='payment'||($fields['payment_method_types[0]']??'')!=='card')throw new RuntimeException('Not hosted card Checkout');
  $GLOBALS['fake']['fields']=$fields;
  if(!empty($GLOBALS['fake']['wrong_session_mode']))return ['id'=>'cs_live_'.str_repeat('a',20),'url'=>'https://checkout.stripe.com/c/pay/cs_live_'.str_repeat('a',20),'livemode'=>true];
  return ['id'=>'cs_test_'.str_repeat('a',20),'url'=>'https://checkout.stripe.com/c/pay/cs_test_'.str_repeat('a',20)];
}
require $root.'/api/store-payments.php';
$errors=[];
function yes(bool $condition,string $description):void{global $errors;echo ($condition?'PASS ':'FAIL ').$description.PHP_EOL;if(!$condition)$errors[]=$description;}
$quote=['ok'=>true,'currency'=>'USD','total_cents'=>5700,'physical'=>true,'items'=>[['type'=>'custom_media','label'=>'Vinyl']]];
$customer=['name'=>'Customer','email'=>'fan@example.org','shipping'=>['line1'=>'Main Street']];
$key='checkout_idempotency_abc12345678';
try{sf_store_stripe_create_order(['id'=>7],$customer,['total_cents'=>0],$key,'');$blocked=false;}catch(InvalidArgumentException $e){$blocked=true;}
yes($blocked && !isset($GLOBALS['fake']['request_keys'][$key]),'Zero-total Stripe charge blocked before reservation or idempotency claim');
$first=sf_store_stripe_create_order(['id'=>7],$customer,$quote,$key,'');
$id=$first['order_id'];$order=sf_read_order($id);
yes($order['status']==='payment_pending'&&$order['payment']['status']==='pending','Order remains unpaid after Stripe session creation');
yes(strlen((string)$first['receipt_token'])===64,'Receipt token supplied only to checkout initiator');
yes($first['checkout_url']===$order['payment']['checkout_url']&&str_starts_with($first['checkout_url'],'https://checkout.stripe.com/'),'Hosted Stripe URL persisted');
yes($GLOBALS['fake']['fields']['line_items[0][price_data][unit_amount]']==='5700','Server quote total used as payment amount');
yes(($GLOBALS['fake']['fields']['metadata[stonefellow_order_id]']??'')===$id,'Immutable internal order ID links payment');
yes($GLOBALS['fake']['sessions']===1&&$GLOBALS['fake']['reserved'][$id]===1,'One session and one inventory reservation');
yes($GLOBALS['fake']['library']===0&&$GLOBALS['fake']['crm']===0,'No entitlement/CRM purchase granted before paid webhook');
$again=sf_store_stripe_create_order(['id'=>7],$customer,$quote,$key,'');
yes($again['receipt_token']===$first['receipt_token'],'Retry reuses original guest receipt capability');
yes($again['order_id']===$id&&$GLOBALS['fake']['sessions']===1&&$GLOBALS['fake']['reserved'][$id]===1,'Idempotent order replay cannot charge/reserve twice');
// Simulate a worker crash after writing the order but before completing the
// SQL request-key mapping. Deterministic ID must recover without new stock.
unset($GLOBALS['fake']['request_keys'][$key]);
$crashReplay=sf_store_stripe_create_order(['id'=>7],$customer,$quote,$key,'');
yes($crashReplay['order_id']===$id&&$GLOBALS['fake']['reserved'][$id]===1&&$GLOBALS['fake']['sessions']===1,'Crash after order write cannot create duplicate order, stock hold, or session');

try{sf_store_stripe_create_order(['id'=>8],$customer,$quote,$key,'');$blocked=false;}catch(RuntimeException $e){$blocked=true;}
yes($blocked,'Another user cannot hijack checkout request key');
try{sf_store_stripe_create_order(['id'=>7],$customer,['currency'=>'USD','total_cents'=>5000,'items'=>[]],$key,'');$blocked=false;}catch(RuntimeException $e){$blocked=true;}
yes($blocked,'Changed cart cannot replay previous checkout');
$webhook=['id'=>$order['payment']['checkout_session_id'],'mode'=>'payment','livemode'=>false,'payment_status'=>'unpaid','amount_total'=>5700,'currency'=>'usd','metadata'=>['stonefellow_order_id'=>$id],'payment_intent'=>'pi_1'];
sf_store_stripe_webhook_event($webhook,'checkout.session.completed');
yes(sf_read_order($id)['payment']['status']==='pending','Completion without paid status does not unlock order');
try{$tampered=$webhook;$tampered['amount_total']=5;$tampered['payment_status']='paid';sf_store_stripe_webhook_event($tampered,'checkout.session.completed');$blocked=false;}catch(RuntimeException $e){$blocked=true;}
yes($blocked&&sf_read_order($id)['payment']['status']==='pending','Forged amount blocked before grant');
try{$tampered=$webhook;$tampered['id']='cs_test_bad';$tampered['payment_status']='paid';sf_store_stripe_webhook_event($tampered,'checkout.session.completed');$blocked=false;}catch(RuntimeException $e){$blocked=true;}
yes($blocked&&sf_read_order($id)['payment']['status']==='pending','Mismatched session ID blocked');
$webhook['payment_status']='paid';sf_store_stripe_webhook_event($webhook,'checkout.session.completed');
yes(sf_read_order($id)['status']==='paid'&&sf_read_order($id)['payment']['payment_intent']==='pi_1','Verified paid webhook updates order');
yes($GLOBALS['fake']['library']===1&&$GLOBALS['fake']['crm']===1&&$GLOBALS['fake']['notify']===1&&$GLOBALS['fake']['pod']===1,'Post-payment work executes exactly once');
sf_store_stripe_webhook_event($webhook,'checkout.session.completed');sf_store_stripe_webhook_event($webhook,'checkout.session.async_payment_succeeded');
yes($GLOBALS['fake']['library']===1&&$GLOBALS['fake']['crm']===1,'Duplicate Stripe events cannot grant again');
sf_store_stripe_webhook_event($webhook,'checkout.session.expired');
yes(sf_read_order($id)['status']==='paid'&&!isset($GLOBALS['fake']['released'][$id]),'Late expiration cannot reverse paid order');
$next=sf_store_stripe_create_order(['id'=>7],$customer,$quote,'checkout_second_abcdef1234567','');$nid=$next['order_id'];
$w=$webhook;$w['metadata']['stonefellow_order_id']=$nid;$w['payment_status']='unpaid';
sf_store_stripe_webhook_event($w,'checkout.session.expired');sf_store_stripe_webhook_event($w,'checkout.session.expired');
yes(sf_read_order($nid)['status']==='canceled'&&$GLOBALS['fake']['released'][$nid]===1,'Expired session releases stock once');
$priorSessions=$GLOBALS['fake']['sessions'];
try{sf_store_stripe_replay_order(sf_read_order($nid));$closedRefused=false;}
catch(RuntimeException $e){$closedRefused=true;}
yes($closedRefused&&$GLOBALS['fake']['sessions']===$priorSessions,'Closed Stripe order cannot replay its cached Checkout URL');

$maybe='checkout_failed_request_abcdef456777';$GLOBALS['fake']['fail_session']=true;
try{sf_store_stripe_create_order(['id'=>7],$customer,$quote,$maybe,'');$failed=false;}catch(RuntimeException $e){$failed=true;}
$GLOBALS['fake']['fail_session']=false;
yes($failed&&!empty($GLOBALS['fake']['request_keys'][$maybe]),'Ambiguous Stripe failure preserves order/retry key');
$recovered=sf_store_stripe_create_order(['id'=>7],$customer,$quote,$maybe,'');
yes(isset($recovered['checkout_url'])&&$GLOBALS['fake']['reserved'][$recovered['order_id']]===1,'Retry recovers same order without double reservation');
$deferred=sf_store_stripe_create_order(['id'=>7],$customer,$quote,'checkout_library_fail_1234567','');
$dw=$webhook;$dw['metadata']['stonefellow_order_id']=$deferred['order_id'];$GLOBALS['fake']['fail_library']=true;
try{sf_store_stripe_webhook_event($dw,'checkout.session.completed');$retryNeeded=false;}catch(RuntimeException $e){$retryNeeded=true;}
yes($retryNeeded && sf_read_order($deferred['order_id'])['payment']['status']==='paid','Paid order survives library outage and requests webhook retry');
$before=$GLOBALS['fake']['crm'];$GLOBALS['fake']['fail_library']=false;
sf_store_stripe_webhook_event($dw,'checkout.session.completed');
yes(!empty(sf_read_order($deferred['order_id'])['payment']['postpayment_done']['library']),'Later webhook retries incomplete library fulfillment');
yes($GLOBALS['fake']['crm']===$before,'Already-completed CRM action is not duplicated on retry');
$wrongKey='checkout_wrong_environment_123456';
$GLOBALS['fake']['wrong_session_mode']=true;
try{sf_store_stripe_create_order(['id'=>7],$customer,$quote,$wrongKey,'');$badAccepted=true;}
catch(RuntimeException $e){$badAccepted=false;}
$wrongId=sf_store_stripe_order_id_for_request($wrongKey);
yes(!$badAccepted&&sf_read_order($wrongId)['payment']['checkout_session_id']===''
  &&($GLOBALS['fake']['reserved'][$wrongId]??0)===1,
  'Stripe session from wrong environment is refused before persistence without new stock hold');
$GLOBALS['fake']['wrong_session_mode']=false;
$goodRetry=sf_store_stripe_create_order(['id'=>7],$customer,$quote,$wrongKey,'');
yes($goodRetry['order_id']===$wrongId&&($GLOBALS['fake']['reserved'][$wrongId]??0)===1,
  'Correct environment retry recovers original order without re-reserving stock');
$GLOBALS['fake']['mode']='production';$GLOBALS['fake']['billing_mode']='production';
try{sf_store_stripe_ready();$blocked=false;}catch(RuntimeException $e){$blocked=true;}
yes($blocked,'Live mode rejects Stripe test secret');
$GLOBALS['fake']['secret']='sk_live_123';sf_store_stripe_ready();
yes(true,'Live mode accepts configured Stripe live-key prefix');
$src=(string)file_get_contents($root.'/api/billing.php');
yes(str_contains($src,'sf_store_stripe_webhook_event($obj,$type)')&&str_contains($src,"'checkout.session.async_payment_succeeded'"),'Unified signed subscription webhook routes Store events');
$webhookSrc=(string)file_get_contents($root.'/api/billing-webhook.php');
yes(str_contains($webhookSrc,'sf_stripe_verify_signature('),'Webhook requires Stripe HMAC signature check');
$app=(string)file_get_contents($root.'/assets/js/app.js');
yes(str_contains($app,'store-payment-status.php') && str_contains($app,"payment.payment_status==='paid'"),'Browser checks receipt capability before clearing cart');
yes(str_contains($app,'window.location.assign(r.checkout_url)')&&str_contains($app,"'stonefellow.pending.stripe'"),'Browser uses hosted Checkout without marking order paid');
foreach(glob($tmp.'/storage/orders/*') as $f)unlink($f);rmdir($tmp.'/storage/orders');rmdir($tmp.'/storage');rmdir($tmp);
echo 'Section 75A Stripe Store: '.(count($errors)?'FAIL '.count($errors):'PASS').PHP_EOL;
exit(count($errors)?1:0);
