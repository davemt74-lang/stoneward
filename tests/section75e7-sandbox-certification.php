<?php
declare(strict_types=1);
/** 75E7: Stripe certification acceptance, entirely mocked; no external API calls. */
$root=dirname(__DIR__);
$state=['mode'=>'stripe_test','account'=>'acct_StoneTest0123','webhook'=>'we_StoneTest0123','key'=>'enc_test_key','secret'=>'enc_whsec_test','cert'=>[]];
$state['cert']['billing.stripe_direct_mode']='test';
$GLOBALS['state']=$state;$GLOBALS['calls']=[];$GLOBALS['orders']=[];$GLOBALS['events']=[];$GLOBALS['sessions']=[];$GLOBALS['refunds']=[];
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE billing_webhook_events (event_id TEXT PRIMARY KEY,provider TEXT,event_type TEXT,status TEXT,payload_sha256 TEXT,created_at TEXT)');
function sf_ai_meta_get(string $k,string $d=''):string{
  $fields=['billing.stripe_direct_account_id'=>'account','billing.stripe_direct_webhook_id'=>'webhook',
    'billing.stripe_secret_payload'=>'key','billing.stripe_webhook_payload'=>'secret'];
  if(isset($fields[$k]))return (string)($GLOBALS['state'][$fields[$k]]??$d);
  return (string)($GLOBALS['state']['cert'][$k]??$d);
}
function sf_ai_meta_set(string $k,string $v):void{$GLOBALS['state']['cert'][$k]=$v;}
function sf_store_config():array{return ['mode'=>$GLOBALS['state']['mode']];}
function sf_stripe_secret():string{return 'sk_test_fixture_abcdef';}
function sf_merchant_direct_key_ready():bool{return $GLOBALS['state']['account']!=='';}
function sf_merchant_stored_test_key():string{return 'sk_test_fixture_abcdef';}
function sf_merchant_direct_id():string{return $GLOBALS['state']['account'];}
function sf_merchant_test_account(string $key):array{return ['id'=>$GLOBALS['state']['account'],'charges_enabled'=>true];}
function sf_billing_ensure_schema():void{}
function sf_db():PDO{global $pdo;return $pdo;}
function sf_read_order(string $id):?array{return $GLOBALS['orders'][$id]??null;}
function sf_stripe_request(string $method,string $path,array $fields=[],string $key=''):array{
 $GLOBALS['calls'][]=[$method,$path];
 if($method!=='GET')throw new RuntimeException('Certification verification attempted to mutate Stripe.');
 if(str_starts_with($path,'events/'))return $GLOBALS['events'][substr($path,7)]??[];
 if(str_starts_with($path,'checkout/sessions/'))return $GLOBALS['sessions'][substr($path,18)]??[];
 if(str_starts_with($path,'refunds/'))return $GLOBALS['refunds'][substr($path,8)]??[];
 throw new RuntimeException('Unapproved Stripe read path.');
}
require $root.'/api/stripe-certification.php';
$passes=0;$fails=0;
function ok7(bool $yes,string $name):void{global $passes,$fails;if($yes)$passes++;else $fails++;echo ($yes?'PASS ':'FAIL ').$name.PHP_EOL;}
function denied7(callable $fn):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}}
function e7order(string $id,string $sid,string $status,string $pi):void{
 $GLOBALS['orders'][$id]=[
   'id'=>$id,'created_at'=>gmdate('c'),'certification_probe'=>true,'certification_run_id'=>sf_cert_load()['run_id'],
   'status'=>$status,'user_id'=>null,
   'quote'=>['total_cents'=>100,'currency'=>'USD','items'=>[],'physical'=>false],
   'payment'=>['provider'=>'stripe','livemode'=>false,
      'status'=>$status==='paid'?'paid':($status==='canceled'?'failed':'pending'),
      'payment_intent'=>$pi,'checkout_session_id'=>$sid,
      'postpayment_done'=>array_fill_keys(['library','crm','campaign','pod','notification'],true)]
 ];
 $GLOBALS['sessions'][$sid]=[
   'id'=>$sid,'livemode'=>false,'mode'=>'payment','metadata'=>['stonefellow_order_id'=>$id],
   'amount_total'=>100,'currency'=>'usd','payment_status'=>$status==='paid'?'paid':'unpaid',
   'status'=>$status==='paid'?'complete':($status==='canceled'?'expired':'open'),'payment_intent'=>$pi
 ];
}
function e7event(string $id,string $type,array $object,bool $live=false,int $created=0):void{
 global $pdo;
 $created=$created?:time();
 $GLOBALS['events'][$id]=['id'=>$id,'type'=>$type,'livemode'=>$live,'created'=>$created,'data'=>['object'=>$object]];
 $q=$pdo->prepare('INSERT INTO billing_webhook_events(event_id,provider,event_type,status,payload_sha256,created_at) VALUES(?,?,?,?,?,?)');
 $q->execute([$id,'stripe',$type,'processed',str_repeat('a',64),gmdate('c',$created)]);
}
ok7(!sf_cert_certified()&&sf_cert_status()['complete']===false,'No account cannot have a fabricated certification');
ok7(denied7(fn()=>sf_cert_verify_case(0,'paid','SF-CERT-INVALID','evt_bad0000000')),'Anonymous caller cannot submit receipts');
ok7(denied7(fn()=>sf_cert_finalize(7)),'No certification run cannot be finalized');
$begin=sf_cert_start(7);
ok7(strlen($begin['run_id'])===32&&!$begin['complete'],'New run receives unpredictable ID and starts pending');
ok7(sf_ai_meta_get('billing.stripe_sandbox_certified','0')==='0','Beginning new run prevents activation');
ok7(denied7(fn()=>sf_cert_finalize(7)),'Cannot finalize with zero verified cases');
$id1='SF-CERT-PAID00123';$sid1='cs_test_'.str_repeat('a',22);$pi1='pi_'.str_repeat('a',20);
e7order($id1,$sid1,'paid',$pi1);
$event1='evt_'.str_repeat('a',22);
e7event($event1,'checkout.session.completed',['id'=>$sid1,'metadata'=>['stonefellow_order_id'=>$id1]]);
$GLOBALS['events'][$event1]['livemode']=true;
ok7(denied7(fn()=>sf_cert_verify_case(7,'paid',$id1,$event1)),'Live Stripe event refused for test certification');
$GLOBALS['events'][$event1]['livemode']=false;
$GLOBALS['sessions'][$sid1]['amount_total']=999;
ok7(denied7(fn()=>sf_cert_verify_case(7,'paid',$id1,$event1)),'Altered Checkout total cannot pass provider verification');
$GLOBALS['sessions'][$sid1]['amount_total']=100;
$pdo->exec("UPDATE billing_webhook_events SET status='failed' WHERE event_id=".$pdo->quote($event1));
ok7(denied7(fn()=>sf_cert_verify_case(7,'paid',$id1,$event1)),'Unprocessed signed webhook blocks test receipt');
$pdo->exec("UPDATE billing_webhook_events SET status='processed' WHERE event_id=".$pdo->quote($event1));
ok7(sf_cert_verify_case(7,'paid',$id1,$event1)['cases']['paid']['verified'],'Real provider plus signed paid receipt is accepted');
ok7(!sf_cert_certified(),'Single successful payment is not full certification');
$id2='SF-CERT-FAIL0012';$sid2='cs_test_'.str_repeat('b',22);$pi2='pi_'.str_repeat('b',20);
e7order($id2,$sid2,'payment_pending',$pi2);
$event2='evt_'.str_repeat('b',22);
e7event($event2,'payment_intent.payment_failed',['id'=>$pi2,'metadata'=>['stonefellow_order_id'=>$id2]]);
ok7(sf_cert_verify_case(7,'declined',$id2,$event2)['cases']['declined']['verified'],'Test-mode declined card receipt accepted without marking order paid');
$id3='SF-CERT-EXPIRE12';$sid3='cs_test_'.str_repeat('c',22);$pi3='pi_'.str_repeat('c',20);
e7order($id3,$sid3,'canceled',$pi3);
$event3='evt_'.str_repeat('c',22);
e7event($event3,'checkout.session.expired',['id'=>$sid3,'metadata'=>['stonefellow_order_id'=>$id3]]);
ok7(sf_cert_verify_case(7,'expired',$id3,$event3)['cases']['expired']['verified'],'Stripe expiry and locally canceled order reconciled');
$refundId='re_'.str_repeat('r',20);$event4='evt_'.str_repeat('d',22);
$GLOBALS['orders'][$id1]['status']='partially_refunded';
$GLOBALS['orders'][$id1]['payment']['status']='partially_refunded';
$GLOBALS['orders'][$id1]['payment']['refunded_cents']=100;
$GLOBALS['orders'][$id1]['payment']['refund_requests']=['request_key'=>['refund_id'=>$refundId,'status'=>'succeeded','amount_cents'=>100]];
$GLOBALS['refunds'][$refundId]=['id'=>$refundId,'status'=>'succeeded','amount'=>100,'currency'=>'usd','payment_intent'=>$pi1];
e7event($event4,'refund.updated',['id'=>$refundId,'status'=>'succeeded','amount'=>100,
    'metadata'=>['stonefellow_order_id'=>$id1]]);
ok7(sf_cert_verify_case(7,'refund',$id1,$event4)['cases']['refund']['verified'],'Full test refund confirmed by Stripe, webhook and Store state');
ok7(!sf_cert_certified(),'All receipts do not auto-enable payment processing');
$finish=sf_cert_finalize(7);
ok7($finish['complete']&&sf_cert_certified(),'Explicit Admin finalization rechecks all receipts and certifies account');
ok7(count(array_filter($GLOBALS['calls'],fn($x)=>$x[0]==='POST'))===0,'Evidence verification never issues Stripe charge/refund requests');
$GLOBALS['state']['key']='different_key';
ok7(!sf_cert_certified()&&!sf_cert_status()['binding_valid'],'Changing test secret immediately invalidates certification');
$GLOBALS['state']['key']='enc_test_key';
$GLOBALS['state']['webhook']='we_newdifferent';
ok7(!sf_cert_certified(),'Changing signing webhook invalidates certification');
$GLOBALS['state']['webhook']='we_StoneTest0123';
ok7(sf_cert_certified(),'Original bound account, webhook and key restore original valid evidence');
$run=sf_cert_load();$run['certified_at']=time()-32*86400;sf_cert_save($run);
ok7(!sf_cert_certified(),'Certificate expires automatically after 30 days');
$again=sf_cert_start(7);
ok7($again['run_id']!==$begin['run_id']&&!sf_cert_certified(),'New verification run invalidates old receipts');
ok7(denied7(fn()=>sf_cert_verify_case(7,'paid',$id1,$event1)),'Old Store orders cannot be reused for a new certification run');
$gateway=file_get_contents($root.'/api/store-payments.php');
$admin=file_get_contents($root.'/admin/api/stripe-connect.php');
$ui=file_get_contents($root.'/admin/assets/stripe-connect.js');
ok7(str_contains($gateway,"certification_probe")&&str_contains($gateway,"sf_cert_probe_context_valid"),
    'Certification orders are isolated from customer fulfillment and payment activation');
ok7(str_contains($ui,'stripeBackOrders')&&str_contains($ui,"ctx.openView('orders')"),
    'Certification wizard returns directly to existing Orders canvas');
ok7(str_contains($admin,"'cert_verify'")&&str_contains($admin,"'cert_finalize'")&&str_contains($ui,'stripeCertCreate'),
    'Admin certification wizard is connected to server evidence verification and test checkout');
ok7(str_contains(file_get_contents($root.'/api/stripe-merchant.php'),'payment_intent.payment_failed'),
    'Stripe subscription receives declined-card event deliveries for verification');
echo 'Section 75E7 evidence-backed sandbox certification: '.$passes.'/'.($passes+$fails).' passed'.PHP_EOL;
exit($fails?1:0);
