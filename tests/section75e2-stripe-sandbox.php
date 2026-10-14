<?php
declare(strict_types=1);
/** Manual, non-charging real Stripe TEST API probe. Run on installed staging script. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if((string)getenv('SF_RUN_STRIPE_SANDBOX')!=='I_UNDERSTAND_THIS_CREATES_A_TEST_SESSION'){
 fwrite(STDERR,"SKIP: explicitly set SF_RUN_STRIPE_SANDBOX to authorize a TEST session.\n");exit(3);
}
require dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/store-payments.php';
try{
 if((string)(sf_store_config()['mode']??'')!=='stripe_test')throw new RuntimeException('Store is not in stripe_test mode.');
 sf_store_stripe_ready();
 if(!str_starts_with(sf_stripe_secret(),'sk_test_'))throw new RuntimeException('Refusing a non-test secret key.');
 $base=rtrim(sf_public_base_url(),'/');
 $id='E2-'.strtoupper(bin2hex(random_bytes(8)));
 $fields=['mode'=>'payment','payment_method_types[0]'=>'card',
  'customer_email'=>'sandbox-checkout@example.invalid','client_reference_id'=>'SANDBOX-'.$id,
  'success_url'=>$base.'/?view=store&probe=complete','cancel_url'=>$base.'/?view=store&probe=cancel',
  'line_items[0][price_data][currency]'=>'usd','line_items[0][price_data][unit_amount]'=>'100',
  'line_items[0][price_data][product_data][name]'=>'Stonefellow test-only payment API probe',
  'line_items[0][quantity]'=>'1','metadata[stonefellow_kind]'=>'sandbox_probe'];
 $session=sf_stripe_request('POST','checkout/sessions',$fields,'sf-e2-probe-'.$id);
 $sid=(string)($session['id']??'');
 if(!preg_match('/^cs_test_[A-Za-z0-9]+$/D',$sid)||($session['livemode']??null)!==false
    ||($session['mode']??'')!=='payment'||(int)($session['amount_total']??-1)!==100)
    throw new RuntimeException('Test session response failed strict verification.');
 $lookedUp=sf_stripe_request('GET','checkout/sessions/'.$sid);
 if(($lookedUp['id']??'')!==$sid||($lookedUp['livemode']??null)!==false)
    throw new RuntimeException('Test session retrieval failed strict verification.');
 $expired=sf_stripe_request('POST','checkout/sessions/'.$sid.'/expire',[],'sf-e2-expire-'.$id);
 if(($expired['id']??'')!==$sid||($expired['status']??'')!=='expired')
    throw new RuntimeException('Test session could not be confirmed expired.');
 echo "PASS: real Stripe test session created, verified and expired without charging a card or creating a local order.\n";
}catch(Throwable $e){
 $ref='E2PROBE-'.strtoupper(bin2hex(random_bytes(4)));
 error_log('Stonefellow '.$ref.' sandbox probe '.get_class($e).' '.$e->getMessage());
 fwrite(STDERR,'FAIL: Stripe test API probe. Reference '.$ref.PHP_EOL);exit(1);
}
