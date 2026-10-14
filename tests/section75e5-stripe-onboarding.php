<?php
declare(strict_types=1);
/** Section 75E5 Stripe merchant OAuth onboarding: no network and no secrets. */
$root=dirname(__DIR__);
$GLOBALS['meta']=[];
$GLOBALS['network']=[];
$_SESSION=[];
function sf_ai_meta_get(string $key,string $fallback=''):string{return (string)($GLOBALS['meta'][$key]??$fallback);}
function sf_ai_meta_set(string $key,string $value):void{$GLOBALS['meta'][$key]=$value;}
function sf_ai_encrypt(string $value):string{return 'encrypted:'.$value;}
function sf_ai_hint(string $value):string{return substr($value,0,3).'…';}
function sf_public_base_url():string{return (string)($GLOBALS['siteURL']??'https://stonefellow.example');}
function sf_http_form_request(string $method,string $url,array $headers=[],array $fields=[]):array{
 $GLOBALS['network'][]=[$method,$url,$headers,$fields];
 if(str_contains($url,'/oauth/token'))return ['stripe_user_id'=>'acct_merchantA123','scope'=>'read_write','livemode'=>!empty($GLOBALS['simulateLive'])];
 if(str_contains($url,'/v1/account'))return ['id'=>'acct_merchantA123','charges_enabled'=>true,'payouts_enabled'=>false,'details_submitted'=>true];
 if(str_contains($url,'/v1/webhook_endpoints')&&$method==='GET')return ['data'=>[]];
 if(str_contains($url,'/v1/webhook_endpoints')&&$method==='POST')return ['id'=>'we_merchantWebhook123','secret'=>'whsec_signedWebhookSecret'];
 throw new RuntimeException('Unexpected provider fixture operation.');
}
require $root.'/api/stripe-connect.php';
$passes=0;$fails=0;
function verify(bool $pass,string $label):void{global $passes,$fails;if($pass)$passes++;else $fails++;echo ($pass?'PASS ':'FAIL ').$label.PHP_EOL;}
function denied(callable $f):bool{try{$f();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}}
putenv('STONEFELLOW_STRIPE_CONNECT_CLIENT_ID');putenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET');putenv('STONEFELLOW_STRIPE_CONNECT_MODE');
verify(!sf_connect_platform()['ready'],'Unregistered Connect platform cannot authorize OAuth');
verify(denied(fn()=>sf_connect_authorize_url(7)),'No synthetic authorization without platform credentials');
putenv('STONEFELLOW_STRIPE_CONNECT_CLIENT_ID=ca_platform12345');
putenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET=sk_test_mockplatform12345');
putenv('STONEFELLOW_STRIPE_CONNECT_MODE=test');
verify(sf_connect_platform()['ready'],'Configured test Connect platform passes validation');
verify(denied(fn()=>sf_connect_authorize_url(0)),'Only authenticated admin can initiate authorization');
$GLOBALS['siteURL']='http://stonefellow.example';
verify(denied(fn()=>sf_connect_authorize_url(7)),'HTTP site refused for OAuth return');
$GLOBALS['siteURL']='https://stonefellow.example';
$url=sf_connect_authorize_url(7);parse_str((string)parse_url($url,PHP_URL_QUERY),$args);
verify(str_starts_with($url,'https://connect.stripe.com/oauth/authorize?')&&($args['client_id']??'')==='ca_platform12345'
  &&($args['scope']??'')==='read_write','Authorization is official Stripe-hosted Connect OAuth with correct access scope');
verify(($args['redirect_uri']??'')==='https://stonefellow.example/admin/stripe-connect-callback.php','Callback URL stays on HTTPS Stonefellow domain');
verify(isset($_SESSION['sf_connect_oauth']['state_hash'])&&!str_contains(serialize($_SESSION),$args['state']),'Browser OAuth state is hashed server-side, not persisted in plaintext');
verify(denied(fn()=>sf_connect_consume_state(str_repeat('a',64),7)),'Forged OAuth state denied');
verify(denied(fn()=>sf_connect_consume_state((string)$args['state'],7)),'Forged state consumes pending authorization safely');
$url=sf_connect_authorize_url(7);parse_str((string)parse_url($url,PHP_URL_QUERY),$args);
verify(denied(fn()=>sf_connect_consume_state((string)$args['state'],8)),'OAuth state bound to initiating administrator');
$url=sf_connect_authorize_url(7);parse_str((string)parse_url($url,PHP_URL_QUERY),$args);
$state=(string)$args['state'];
$done=sf_connect_finish(7,$state,'ac_Authorized123');
verify($done['connected']&&$done['account_id']==='acct_merchantA123'&&!$done['payments_enabled'],
  'Validated OAuth connection recorded but payments remain disabled');
verify($done['charges_enabled']&&$done['webhook_ready']&&$done['details_submitted'],
  'Account permissions and signed webhook checked before activation');
verify(!isset($done['access_token'])&&!isset($done['secret'])&&!isset($done['state'])&&!isset($done['authorize_url']),
  'Status API contains no OAuth token, platform key, or authorization state');
verify(str_starts_with(sf_ai_meta_get('billing.stripe_webhook_payload'),'encrypted:whsec_')
  &&sf_ai_meta_get('billing.stripe_enabled')==='0','Webhook secret encrypted and no premature charge activation');
$operations=array_filter($GLOBALS['network'],fn($x)=>str_contains($x[1],'/webhook_endpoints')&&$x[0]==='POST');
verify(count($operations)===1,'Exactly one webhook endpoint was registered');
$provider=array_values($operations)[0];
verify(in_array('Stripe-Account: acct_merchantA123',$provider[2],true)
  &&in_array('Authorization: Bearer sk_test_mockplatform12345',$provider[2],true),'Stripe-Account header scopes webhook to authorized merchant');
verify(in_array('refund.updated',$provider[3],true)||in_array('refund.updated',array_values($provider[3]),true),
  'Webhook registers Store refund status events');
verify(denied(fn()=>sf_connect_finish(7,$state,'ac_Authorized123')),'Consumed OAuth state cannot replay token exchange');
$before=count($GLOBALS['network']);$updated=sf_connect_refresh_status();
verify($updated['connected']&&$updated['webhook_ready']&&count($GLOBALS['network'])===$before+1,
  'Status refresh reads account without repeating webhook POST');
verify(!$updated['sandbox_certified'],'Test payment certification remains blocked until real provider acceptance');
$GLOBALS['meta']['billing.stripe_secret_payload']='encrypted:manual_direct_key';
verify(denied(fn()=>sf_connect_authorize_url(7)),'An existing direct Stripe key cannot silently switch merchant accounts');
unset($GLOBALS['meta']['billing.stripe_secret_payload']);
putenv('STONEFELLOW_STRIPE_CONNECT_MODE=production');putenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET=sk_live_platformMock');
verify(!sf_connect_active(),'Existing test-mode connection cannot silently become live connection');
verify(denied(fn()=>sf_connect_refresh_status()),'Wrong environment cannot query or activate the stored account');
$billing=(string)file_get_contents($root.'/api/billing.php');
$settingsApi=(string)file_get_contents($root.'/admin/api/stripe-connect.php');
$callback=(string)file_get_contents($root.'/admin/stripe-connect-callback.php');
$js=(string)file_get_contents($root.'/admin/assets/stripe-connect.js');
verify(str_contains($billing,"'Stripe-Account: '.sf_connect_id()")&&str_contains($billing,'sf_connect_active()'),
  'Existing Stripe Checkout and subscription API use scoped connected-account authorization');
verify(str_contains($settingsApi,'sf_admin_require_auth($write)')&&str_contains($settingsApi,'sf_request_json()')
  &&str_contains($settingsApi,"sandbox_certified"),'Connection and payment activation API require Admin access and actual sandbox certification');
verify(str_contains($callback,'sf_connect_finish(')&&str_contains($js,"event.origin!==window.location.origin"),
  'OAuth callback and popup messages require verified same-origin linkage');
echo 'Section 75E5 Stripe connected merchant onboarding: '.$passes.'/'.($passes+$fails).' passed'.PHP_EOL;
exit($fails?1:0);
