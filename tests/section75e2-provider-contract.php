<?php
declare(strict_types=1);
function sf_store_config():array{return ['mode'=>$GLOBALS['mode']??'stripe_test'];}
function sf_billing_settings():array{return ['mode'=>$GLOBALS['billingMode']??'test'];}
function sf_stripe_configured():bool{return true;}
function sf_stripe_secret():string{return (string)($GLOBALS['stripeKey']??'sk_test_fixture');}
function sf_public_base_url():string{return (string)($GLOBALS['baseUrl']??'https://stonefellow.example.test');}
require dirname(__DIR__).'/api/store-payments.php';
$failed=0;$count=0;
function e2check(bool $ok,string $name):void{global $failed,$count;$count++;if(!$ok)$failed++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function e2rejects(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
$GLOBALS['mode']='stripe_test';$GLOBALS['billingMode']='test';$GLOBALS['stripeKey']='sk_test_fixture';
e2check(!e2rejects(fn()=>sf_store_stripe_ready()),'Test billing configuration is enabled');
$order=['id'=>'SF-E2-ORDER','customer'=>['email'=>'buyer@example.test'],'quote'=>['total_cents'=>1200,'currency'=>'USD']];
$payload=sf_store_stripe_session_fields($order);
e2check($payload['mode']==='payment'&&$payload['payment_method_types[0]']==='card','One-time hosted card checkout');
e2check($payload['line_items[0][price_data][unit_amount]']==='1200','Quoted amount is server-side');
e2check($payload['metadata[stonefellow_order_id]']==='SF-E2-ORDER','Checkout metadata binds order ID');
e2check($payload['payment_intent_data[metadata][stonefellow_order_id]']==='SF-E2-ORDER','PaymentIntent metadata binds same order');
e2check(str_starts_with($payload['success_url'],'https://'),'Return URL uses HTTPS');
e2check(e2rejects(fn()=>sf_store_stripe_session_fields(['id'=>'SF-E2','customer'=>['email'=>'x'],'quote'=>['total_cents'=>-1,'currency'=>'USD']])), 'Invalid amount rejected');
$GLOBALS['stripeKey']='sk_live_fixture';
e2check(e2rejects(fn()=>sf_store_stripe_ready()),'Live Stripe key refused in test mode');
$GLOBALS['stripeKey']='sk_test_fixture';$GLOBALS['mode']='production';$GLOBALS['billingMode']='production';
e2check(e2rejects(fn()=>sf_store_stripe_ready()),'Test Stripe key refused in production mode');
$GLOBALS['stripeKey']='sk_live_fixture';$GLOBALS['baseUrl']='http://stonefellow.example.test';
e2check(e2rejects(fn()=>sf_store_stripe_ready()),'Plain-HTTP Stripe checkout denied');
echo 'Section 75E2 provider contract: '.($count-$failed).'/'.$count.' passed'.PHP_EOL;
exit($failed?1:0);
