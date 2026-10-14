<?php
declare(strict_types=1);
/** No credentials or network. Exercises Admin refund state/retry semantics and signed webhook target. */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf-refund75-'.bin2hex(random_bytes(5));
mkdir($tmp.'/storage/orders',0700,true);define('SF_ROOT',$tmp);
$GLOBALS['refundMock']=['requests'=>[],'ids'=>[],'failNext'=>false,'restock'=>0,'configMode'=>'stripe_test'];
function sf_store_config():array{return ['mode'=>$GLOBALS['refundMock']['configMode']];}
function sf_store_stripe_ready():void {}
function sf_read_order(string $id):?array { $p=SF_ROOT.'/storage/orders/'.$id.'.json';return is_file($p)?json_decode((string)file_get_contents($p),true):null; }
function sf_write_json(string $path,array $obj):void {file_put_contents($path,json_encode($obj,JSON_THROW_ON_ERROR));}
function sf_stripe_request(string $method,string $path,array $fields=[],string $idempotencyKey=''):array {
    if($method!=='POST'||$path!=='refunds')throw new RuntimeException('Wrong Stripe refund endpoint.');
    $GLOBALS['refundMock']['requests'][]=['fields'=>$fields,'key'=>$idempotencyKey];
    if($GLOBALS['refundMock']['failNext']){$GLOBALS['refundMock']['failNext']=false;throw new RuntimeException('Provider response uncertain');}
    $id=$GLOBALS['refundMock']['ids'][$idempotencyKey]??'re_'.bin2hex(random_bytes(7));
    $GLOBALS['refundMock']['ids'][$idempotencyKey]=$id;
    return ['id'=>$id,'amount'=>(int)$fields['amount'],'payment_intent'=>$fields['payment_intent'],'currency'=>'usd','status'=>'succeeded'];
}
function assert75(bool $ok,string $message):void {
    if(!$ok){fwrite(STDERR,'FAIL '.$message.PHP_EOL);$GLOBALS['fail']++;} else echo 'PASS '.$message.PHP_EOL;
}
$GLOBALS['fail']=0;
require $root.'/api/store-refunds.php';
$id='SF-20261013-REFUND1';
$order=['id'=>$id,'user_id'=>11,'status'=>'paid','quote'=>['total_cents'=>10000,'currency'=>'USD','physical'=>true],
    'payment'=>['provider'=>'stripe','status'=>'paid','payment_intent'=>'pi_mock123'],'fulfillment'=>['status'=>'pending'],
    'timeline'=>[]];
sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);
function evt(string $id,string $key,int $amount,string $status,string $pi='pi_mock123',string $currency='usd'):array{
    $o=sf_read_order($id);
    return ['id'=>(string)($o['payment']['refund_requests'][$key]['refund_id']??'re_pending'),
        'payment_intent'=>$pi,'amount'=>$amount,'currency'=>$currency,'status'=>$status,'livemode'=>false,
        'metadata'=>['stonefellow_kind'=>'store_refund','stonefellow_order_id'=>$id,'stonefellow_refund_request'=>$key]];
}
function bad(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
$k1='partial-refund-00000001';$k2='final-refund-00000002';
assert75(bad(fn()=>sf_store_refund_request($id,500,'requested_by_customer',$k1,0)),'Unauthenticated refund disallowed');
assert75(bad(fn()=>sf_store_refund_request($id,0,'requested_by_customer',$k1,4)),'Zero refund blocked');
assert75(bad(fn()=>sf_store_refund_request($id,10001,'requested_by_customer',$k1,4)),'Over-refund blocked before API call');
assert75(bad(fn()=>sf_store_refund_request($id,100,'friendly_exception',$k1,4)),'Only Stripe reasons permitted');
assert75(count($GLOBALS['refundMock']['requests'])===0,'Bad input never calls provider');
$a=sf_store_refund_request($id,4000,'requested_by_customer',$k1,4);
assert75($a['status']==='pending' && str_starts_with($a['refund_id'],'re_'),'Admin request obtains provider refund ID');
assert75(sf_read_order($id)['payment']['status']==='paid','Provider API response alone never confirms a refund');
$again=sf_store_refund_request($id,4000,'requested_by_customer',$k1,4);
assert75(count($GLOBALS['refundMock']['requests'])===1 && $again['refund_id']===$a['refund_id'],'Repeated request never calls Stripe twice');
assert75(bad(fn()=>sf_store_refund_request($id,4001,'requested_by_customer',$k1,4)),'Existing key cannot silently change amount');
assert75(bad(fn()=>sf_store_refund_request($id,7000,'requested_by_customer',$k2,4)),'Pending refund reserves refundable balance');
assert75(bad(fn()=>sf_store_refund_webhook_event(evt($id,$k1,4000,'succeeded','pi_wrong'),'refund.updated',false)),'Mismatched payment intent rejected');
assert75(bad(fn()=>sf_store_refund_webhook_event(evt($id,$k1,4000,'succeeded'),'refund.updated',true)),'Live webhook mode cannot refund test-mode order');
assert75(bad(fn()=>sf_store_refund_webhook_event(evt($id,$k1,5000,'succeeded'),'refund.updated',false)),'Mismatched refund amount rejected');
assert75(bad(fn()=>sf_store_refund_webhook_event(evt($id,$k1,4000,'succeeded','pi_mock123','eur'),'refund.updated',false)),'Wrong currency rejected');
$r1=evt($id,$k1,4000,'succeeded');
sf_store_refund_webhook_event($r1,'refund.created',false);
$x=sf_read_order($id);
assert75($x['status']==='partially_refunded' && $x['payment']['refunded_cents']===4000,'Signed refund records partial amount without full cancellation');
$events=count($x['timeline']);sf_store_refund_webhook_event($r1,'refund.updated',false);
assert75(count(sf_read_order($id)['timeline'])===$events,'Duplicate refund success is idempotent');
$rLate=$r1;$rLate['status']='pending';sf_store_refund_webhook_event($rLate,'refund.updated',false);
assert75(sf_read_order($id)['payment']['refunded_cents']===4000,'Late pending event cannot reverse refunded balance');
$b=sf_store_refund_request($id,6000,'requested_by_customer',$k2,4);
assert75($b['status']==='pending','Remaining amount may be refunded');
sf_store_refund_webhook_event(evt($id,$k2,6000,'succeeded'),'refund.updated',false);
assert75(sf_read_order($id)['status']==='refunded' && sf_read_order($id)['payment']['refunded_cents']===10000,'Combined partial refunds equal full original payment');
assert75(bad(fn()=>sf_store_refund_request($id,1,'requested_by_customer','over-limit-00000003',4)),'Fully refunded order cannot request additional refunds');
assert75($GLOBALS['refundMock']['restock']===0,'Refund confirmation never automatically restocks merchandise');
// A distinct paid order verifies failed refunds release balance and network retries keep idempotency.
$id2='SF-20261013-REFUND2';$order['id']=$id2;sf_write_json(SF_ROOT.'/storage/orders/'.$id2.'.json',$order);
$retry='ambiguous-provider-00000001';$GLOBALS['refundMock']['failNext']=true;
assert75(bad(fn()=>sf_store_refund_request($id2,3500,'duplicate',$retry,5)),'Ambiguous provider response is surfaced');
assert75(sf_read_order($id2)['payment']['refund_requests'][$retry]['status']==='requested','Ambiguous provider response retains durable intent');
$res=sf_store_refund_request($id2,3500,'duplicate',$retry,5);
$tail=array_slice($GLOBALS['refundMock']['requests'],-2);
assert75($tail[0]['key']===$tail[1]['key'] && $res['status']==='pending','Provider retry uses exactly same idempotency key');
// Stripe may discard idempotency keys after 24 hours. An aged ambiguous
// refund must not be POSTed again, even with the original request identifier.
$agedKey='aged-ambiguous-refund-0001';
$aged=sf_read_order($id2);$aged['payment']['refund_requests'][$agedKey]=[
 'amount_cents'=>1500,'reason'=>'requested_by_customer','status'=>'requested','refund_id'=>'',
 'created_at'=>gmdate('c',time()-25*3600),'requested_by'=>5];
sf_write_json(SF_ROOT.'/storage/orders/'.$id2.'.json',$aged);
$beforeCount=count($GLOBALS['refundMock']['requests']);
assert75(bad(fn()=>sf_store_refund_request($id2,1500,'requested_by_customer',$agedKey,5)),
    'Ambiguous refund older than Stripe idempotency retention requires manual review');
assert75(count($GLOBALS['refundMock']['requests'])===$beforeCount,
    'Expired refund idempotency does not issue another Stripe POST');
// Clean up this synthetic stale request before the independent balance test;
// a real Admin would reconcile it with Stripe before changing its reservation.
$aged=sf_read_order($id2);unset($aged['payment']['refund_requests'][$agedKey]);
sf_write_json(SF_ROOT.'/storage/orders/'.$id2.'.json',$aged);


$failed=evt($id2,$retry,3500,'failed');sf_store_refund_webhook_event($failed,'refund.failed',false);
assert75(sf_read_order($id2)['payment']['status']==='paid','Failed refund does not change paid state');
assert75(bad(fn()=>sf_store_refund_request($id2,3500,'duplicate',$retry,5)),'Failed refund cannot reuse same request for new charge');
$next=sf_store_refund_request($id2,10000,'requested_by_customer','new-refund-00000002',5);
assert75($next['status']==='pending','Failed refund releases reserved balance for new approved request');
$billing=(string)file_get_contents($root.'/api/billing.php');$admin=(string)file_get_contents($root.'/admin/api/orders.php');
assert75(str_contains($billing,'sf_store_refund_webhook_event($obj,$type,(bool)'),'Existing signed Stripe event router dispatches refund updates');
assert75(str_contains($admin,"$"."action==='request_stripe_refund'") && str_contains($admin,'sf_admin_require_auth($write)'),'Refund request is restricted to authenticated Admin POST');
foreach(glob($tmp.'/storage/orders/*')?:[] as $file)unlink($file);rmdir($tmp.'/storage/orders');rmdir($tmp.'/storage');rmdir($tmp);
echo 'Section 75B refund safety: '.(31-$GLOBALS['fail']).'/31 checks passed'.PHP_EOL;
exit($GLOBALS['fail']?1:0);
