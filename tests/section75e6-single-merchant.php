<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$GLOBALS['merchantMeta']=[];
$GLOBALS['merchantCalls']=[];
$GLOBALS['merchantContext']='stripe_test';
$GLOBALS['merchantAccount']='acct_Stonefellow123';
$GLOBALS['merchantCharges']=true;
$GLOBALS['merchantDuplicateWebhook']=false;
$GLOBALS['merchantHasMore']=false;
$GLOBALS['merchantSite']='https://stonefellow.example';
function sf_ai_meta_get(string $key,string $default=''):string{return (string)($GLOBALS['merchantMeta'][$key]??$default);}
function sf_ai_meta_set(string $key,string $value):void{$GLOBALS['merchantMeta'][$key]=$value;}
function sf_ai_encrypt(string $text):string{return 'encrypted:'.$text;}
function sf_ai_decrypt(string $cipher):string{return str_replace('encrypted:','',$cipher);}
function sf_ai_hint(string $value):string{return substr($value,0,4).'…';}
function sf_store_config():array{return ['mode'=>$GLOBALS['merchantContext']];}
function sf_public_base_url():string{return $GLOBALS['merchantSite'];}
function sf_http_form_request(string $method,string $url,array $headers=[],array $fields=[]):array{
 $GLOBALS['merchantCalls'][]=[$method,$url,$headers,$fields];
 $auth=(string)($headers[0]??'');
 if(!str_starts_with($auth,'Authorization: Bearer sk_test_'))throw new RuntimeException('Test fixture rejects non-test authorization.');
 if(str_ends_with($url,'/v1/account'))return ['id'=>$GLOBALS['merchantAccount'],
    'charges_enabled'=>$GLOBALS['merchantCharges'],'details_submitted'=>true,'payouts_enabled'=>false,'livemode'=>false];
 if(str_contains($url,'/v1/webhook_endpoints?limit=100'))return ['has_more'=>$GLOBALS['merchantHasMore'],
    'data'=>$GLOBALS['merchantDuplicateWebhook']?[['url'=>'https://stonefellow.example/api/billing-webhook.php']]:[]];
 if(str_ends_with($url,'/v1/webhook_endpoints')&&$method==='POST')
   return ['id'=>'we_123456789','secret'=>'whsec_123456789'];
 throw new RuntimeException('Unexpected network request in merchant fixture.');
}
require $root.'/api/stripe-connect.php';
require $root.'/api/stripe-merchant.php';
$passed=0;$failed=0;
function e6(bool $cond,string $title):void{global $passed,$failed;if($cond)$passed++;else $failed++;echo ($cond?'PASS ':'FAIL ').$title.PHP_EOL;}
function e6bad(callable $fn):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}}
putenv('STONEFELLOW_STRIPE_CONNECT_CLIENT_ID');putenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET');putenv('STONEFELLOW_STRIPE_CONNECT_MODE');
$initial=sf_merchant_status();
e6($initial['provider']==='none'&&!$initial['connected']&&!$initial['payments_enabled'],'Brand new single-artist merchant shows unconnected state');
e6(count($GLOBALS['merchantCalls'])===0,'Rendering pre-account wizard does not send network calls');
e6(e6bad(fn()=>sf_merchant_save_test_key('pk_test_public')),'Publishable key never accepted');
e6(e6bad(fn()=>sf_merchant_save_test_key('sk_live_secret_key_abc123456')),'Live key not accepted through test wizard');
e6(!isset($GLOBALS['merchantMeta']['billing.stripe_secret_payload']),'Invalid key cannot be saved');
$GLOBALS['merchantContext']='production';
e6(e6bad(fn()=>sf_merchant_save_test_key('sk_test_valid123456')),'Server must be explicitly set to stripe_test mode');
$GLOBALS['merchantContext']='stripe_test';
$save=sf_merchant_save_test_key('sk_test_valid123456');
e6($save['provider']==='direct'&&$save['direct_ready']&&$save['account_id']==='acct_Stonefellow123',
 'Verified merchant linked directly without a platform registration');
e6($save['payments_enabled']===false&&$save['sandbox_certified']===false,'Merchant link never turns on payment processing');
e6(sf_ai_meta_get('billing.stripe_secret_payload')==='encrypted:sk_test_valid123456',
 'Merchant secret encrypted before persistence');
e6(!str_contains(json_encode($save,JSON_THROW_ON_ERROR),'sk_test_valid123456'),
 'Status API contains no original secret');
e6(sf_ai_meta_get('billing.mode')==='test'&&sf_ai_meta_get('billing.provider')==='stripe',
 'Billing and provider switch to safe test-only merchant identity');
$GLOBALS['merchantAccount']='acct_AnotherMerchant999';
e6(e6bad(fn()=>sf_merchant_save_test_key('sk_test_second123456')),'Different merchant cannot replace the verified payment recipient');
e6(sf_merchant_direct_id()==='acct_Stonefellow123','Original payment recipient ID remains unchanged');
$GLOBALS['merchantAccount']='acct_Stonefellow123';
$GLOBALS['merchantSite']='http://stonefellow.example';
e6(e6bad(fn()=>sf_merchant_create_test_webhook()),'HTTP webhook origin rejected');
$GLOBALS['merchantSite']='https://stonefellow.example';
$GLOBALS['merchantHasMore']=true;
e6(e6bad(fn()=>sf_merchant_create_test_webhook()),'Potential extra remote endpoints prevent blind webhook creation');
$GLOBALS['merchantHasMore']=false;
$GLOBALS['merchantDuplicateWebhook']=true;
$prior=count($GLOBALS['merchantCalls']);
e6(e6bad(fn()=>sf_merchant_create_test_webhook()),'Duplicate remote webhook URL cannot cause unknown second signing secret');
e6(count(array_filter(array_slice($GLOBALS['merchantCalls'],$prior),fn($x)=>$x[0]==='POST'))===0,
 'Duplicate provider endpoint detection issues no second POST');
$GLOBALS['merchantDuplicateWebhook']=false;
$webhook=sf_merchant_create_test_webhook();
e6($webhook['webhook_ready']&&!$webhook['payments_enabled'],'Stripe-created signed webhook remains safely disabled');
e6(sf_ai_meta_get('billing.stripe_webhook_payload')==='encrypted:whsec_123456789',
 'Stripe webhook signing secret encrypted');
$create=array_values(array_filter($GLOBALS['merchantCalls'],fn($c)=>$c[0]==='POST'));
e6(count($create)===1&&in_array('refund.updated',array_values($create[0][3]),true),
 'Merchant webhook includes refund processing and is created once');
$createAgain=sf_merchant_create_test_webhook();
e6($createAgain['webhook_ready']&&count(array_filter($GLOBALS['merchantCalls'],fn($c)=>$c[0]==='POST'))===1,
 'Retry after successful webhook setup does not create another endpoint');
e6(!str_contains(json_encode($webhook,JSON_THROW_ON_ERROR),'whsec_123456789'),
 'Signing secret never appears in Admin response');
$GLOBALS['merchantCharges']=false;
sf_ai_meta_set('billing.stripe_enabled','1');
$updated=sf_merchant_refresh_test_account();
e6(!$updated['charges_enabled']&&!$updated['payments_enabled'],
 'Revoked Stripe charges capability pauses merchant processing');
$GLOBALS['merchantCharges']=true;
$updated=sf_merchant_refresh_test_account();
e6($updated['charges_enabled']&&!$updated['can_enable_test'],
 'Charges + webhook do not bypass actual test-payment certification');
e6(e6bad(fn()=>sf_merchant_test_key_request('sk_test_valid123456','POST','refunds',[])),
 'Wizard cannot call refund or payment APIs');
e6(e6bad(fn()=>sf_merchant_test_key_request('sk_test_valid123456','GET','accounts',[])),
 'Provider request paths are strictly allowlisted');
$api=(string)file_get_contents($root.'/admin/api/stripe-connect.php');
$ui=(string)file_get_contents($root.'/admin/assets/stripe-connect.js');
e6(str_contains($api,'sf_admin_require_auth($write)')&&str_contains($api,'sf_request_json()'),
 'All state-changing setup endpoints require authenticated Admin CSRF enforcement');
e6(str_contains($api,"'save_test_key'")&&str_contains($api,"'create_test_webhook'"),
 'Credential verification and signed webhook actions are wired to Admin');
$billing=(string)file_get_contents($root.'/api/billing.php');
e6(str_contains($billing,"if($settings['provider']==='test')return")
    &&!str_contains($billing,"if($settings['mode']==='test'||$settings['provider']==='test')"),
    'Stripe selected as billing provider cannot silently grant simulated paid subscriptions');
e6(str_contains($billing,"billing.stripe_direct_account_id")
    &&str_contains($billing,"billing.stripe_sandbox_certified"),
    'Legacy billing configuration cannot bypass a direct merchant sandbox acceptance gate');
e6(str_contains($api,"'sandbox_certified'")&&str_contains($api,"'can_enable_test'"),
 'Test payment activation remains gated by certification');
e6(str_contains($ui,'stripeDirectKeyForm')&&str_contains($ui,'keyForm.reset()'),
 'Key input is removed from page immediately after Admin submits it');
e6(str_contains($ui,'stripeSignup')&&str_contains($ui,'stripeConnect'),
 'Direct merchant signup is default; optional Connect authorization retained');
e6(str_contains($ui,"event.origin!==window.location.origin"),'Optional OAuth messages remain same-origin restricted');
echo 'Section 75E6 single-merchant setup: '.$passed.'/'.($passed+$failed).' passed'.PHP_EOL;
exit($failed?1:0);
