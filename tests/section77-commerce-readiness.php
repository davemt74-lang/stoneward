<?php
declare(strict_types=1);
/** Section 77: deterministic, side-effect-free commerce release certification. */
require dirname(__DIR__).'/api/commerce-release-gates.php';
$passed=0;$failed=0;
function c77(bool $ok,string $message):void {global $passed,$failed;$ok?$passed++:$failed++;echo ($ok?'PASS ':'FAIL ').$message.PHP_EOL;}
function merchant77():array {return ['provider'=>'direct','direct_ready'=>true,'connected'=>true,
  'account_id'=>'acct_Stonefellow123','store_mode'=>'stripe_test','environment'=>'test',
  'charges_enabled'=>true,'webhook_url'=>'https://example.org/api/billing-webhook.php',
  'webhook_ready'=>true,'sandbox_certified'=>true,'payments_enabled'=>false];}
function cert77():array{return ['complete'=>true,'binding_valid'=>true];}
function health77():array{return ['window_days'=>7,'sample_limit'=>100,'counts'=>['processed'=>2,'failed'=>0,'processing'=>0]];}
function ops77():array{return ['summary'=>['customer_orders'=>3,'payment_attention'=>0,
 'refund_attention'=>0,'fulfillment_pending'=>0,'open_followups'=>0]];}
function report77(?array $m=null,?array $c=null,?array $h=null,?array $o=null):array {
 return sf_commerce_release_report($m??merchant77(),$c??cert77(),$h??health77(),$o??ops77());
}
$r=report77();
c77($r['passed']===12&&$r['total']===12&&$r['test_activation_ready']&&$r['operations_clear'],'Complete read-only evidence clears sandbox and operations gates');
c77(!$r['live_activation_available']&&$r['live_activation_reason']!=='','Production activation always remains unavailable');
c77(!$r['test_checkout_active'],'Evidence does not independently turn on test checkout');
$m=merchant77();$m['payments_enabled']=true;
c77(report77($m)['test_checkout_active'],'Activated test checkout is reported only when local evidence passes');
$m['store_mode']='production';
c77(!report77($m)['test_activation_ready']&&!report77($m)['test_checkout_active'],'Production environment cannot reuse test certificate');
$m=merchant77();$m['provider']='connect';
c77(!report77($m)['test_activation_ready'],'Optional platform connection cannot masquerade as direct merchant certification');
$m=merchant77();$m['account_id']='acct_/forged';
c77(!report77($m)['test_activation_ready'],'Malformed merchant account ID rejected');
$m=merchant77();$m['webhook_url']='https://alice:secret@example.org/api/billing-webhook.php';
c77(!report77($m)['test_activation_ready'],'Credential-bearing webhook URL rejected');
$m=merchant77();$m['webhook_url']='https://alice@example.org/api/billing-webhook.php';
c77(!report77($m)['test_activation_ready'],'Username-only webhook URL rejected');
$m=merchant77();$m['webhook_url']='https://example.org/api/billing-webhook.php?token=123';
c77(!report77($m)['test_activation_ready'],'Query-bearing webhook URL rejected');
$m=merchant77();$m['webhook_ready']=false;
c77(!report77($m)['test_activation_ready'],'Unverified webhook binding blocks test readiness');
$c=cert77();$c['binding_valid']=false;
c77(!report77(null,$c)['test_activation_ready'],'Changed signing or merchant binding blocks test readiness');
$h=health77();$h['counts']['failed']=1;
c77(!report77(null,null,$h)['test_activation_ready'],'Recent failed webhook blocks test readiness');
$h=health77();$h['counts']['processing']=1;
c77(!report77(null,null,$h)['test_activation_ready'],'Unresolved processing webhook blocks readiness');
c77(!report77(null,null,[])['test_activation_ready'],'Missing webhook evidence fails closed');
$o=ops77();$o['summary']['refund_attention']=2;
c77(!report77(null,null,null,$o)['operations_clear']&&report77(null,null,null,$o)['test_activation_ready'],'Refund queue blocks operational clearance, not sandbox certificate');
$o=ops77();$o['summary']['fulfillment_pending']=1;
c77(!report77(null,null,null,$o)['operations_clear'],'Pending merchandise fulfillment needs review');
c77(!report77(null,null,null,[])['operations_clear'],'Unavailable Orders service is not reported as a clear queue');
try {sf_commerce_release_snapshot(0);c77(false,'Anonymous Admin refused');}
catch(InvalidArgumentException $e){c77(true,'Anonymous Admin refused');}
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/stripe-connect.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stripe-connect.js');
$connect=file_get_contents(dirname(__DIR__).'/api/stripe-connect.php');
c77(str_contains($endpoint,'sf_admin_require_auth($write)')&&str_contains($endpoint,'sf_commerce_release_snapshot((int)$admin'), 'Readiness endpoint uses existing Admin authentication');
c77(str_contains($ui,'stripeReleasePanel')&&str_contains($ui,'releasePanel(release||{})'), 'Readiness panel integrated in original Stripe canvas');
c77(str_contains($connect,"!empty($known['has_more'])")&&str_contains($connect,'array_key_exists(\'data\',$known)'), 'Incomplete Connect webhook discovery must fail closed');
echo 'Section 77 commerce readiness: '.$passed.'/'.($passed+$failed).' checks'.PHP_EOL;
exit($failed?1:0);
