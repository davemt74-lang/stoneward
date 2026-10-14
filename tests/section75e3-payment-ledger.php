<?php
declare(strict_types=1);
/** Section 75E3: signed Stripe event -> billing ledger -> real Store state files.
 * Uses an isolated SQLite billing ledger; sends no network requests or charges.
 */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf75e3-ledger-'.bin2hex(random_bytes(6));
if(!mkdir($tmp.'/storage/orders',0700,true))throw new RuntimeException('Fixture directory unavailable');
define('SF_ROOT',$tmp);
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE subscription_packages (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
$pdo->exec('INSERT INTO users(id) VALUES(7)');
$GLOBALS['e3']=['library'=>0,'crm'=>0,'pod'=>0,'notify'=>0,'redeem'=>0,'released'=>0,'failLibrary'=>false,'mode'=>'stripe_test'];
function sf_db():PDO{global $pdo;return $pdo;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_schema_add_column(...$ignored):void{}
function sf_clean_text(mixed $text,int $max=120):string{return substr((string)$text,0,$max);}
function sf_store_config():array{return ['mode'=>$GLOBALS['e3']['mode']];}
function sf_write_json(string $path,array $data):void{
    $temporary=$path.'.'.bin2hex(random_bytes(5)).'.tmp';
    if(file_put_contents($temporary,json_encode($data,JSON_THROW_ON_ERROR))===false||!rename($temporary,$path))
        throw new RuntimeException('Fixture could not commit order');
}
function sf_read_order(string $id):?array{
    $path=SF_ROOT.'/storage/orders/'.$id.'.json';
    return is_file($path)?json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR):null;
}
function sf_commerce_release_order_inventory(string $id,string $reason):int{$GLOBALS['e3']['released']++;return 1;}
function sf_account_library_add_order(int $uid,array $order):void{
    if($GLOBALS['e3']['failLibrary'])throw new RuntimeException('temporary library read failure');
    $GLOBALS['e3']['library']++;
}
function sf_commerce_record_purchase(string $id,?int $uid,array $customer,array $quote):void{$GLOBALS['e3']['crm']++;}
function sf_build_pod_handoffs(string $id,array $quote,array $customer):array{$GLOBALS['e3']['pod']++;return [];}
function sf_notify_user(int $uid,string $kind,string $title,string $message,string $url):void{$GLOBALS['e3']['notify']++;}
function sf_campaign_redeem_code(string $code,string $id):void{$GLOBALS['e3']['redeem']++;}
require $root.'/api/billing.php';
require $root.'/api/store-payments.php';
require $root.'/api/store-refunds.php';
$checks=0;$failed=0;
function ok75(bool $condition,string $label):void{
    global $checks,$failed;$checks++;if(!$condition)$failed++;
    echo ($condition?'PASS ':'FAIL ').$label.PHP_EOL;
}
function blocked75(callable $fn):bool{
    try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}
}
function order75(string $id,string $sid):void{
    $o=['id'=>$id,'user_id'=>7,'created_at'=>gmdate('c'),'status'=>'payment_pending',
        'customer'=>['email'=>'buyer@example.test'],'campaign_code'=>'',
        'quote'=>['total_cents'=>6000,'currency'=>'USD','physical'=>true,'items'=>[]],
        'payment'=>['provider'=>'stripe','method'=>'stripe','status'=>'pending','livemode'=>false,
            'checkout_session_id'=>$sid,'checkout_url'=>'https://checkout.stripe.com/c/pay/example'],
        'fulfillment'=>['status'=>'awaiting_payment'],'timeline'=>[]];
    sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$o);
}
function session75(string $id,string $sid,string $paymentStatus='paid'):array{
    return ['id'=>$sid,'livemode'=>false,'mode'=>'payment','status'=>'complete',
        'payment_status'=>$paymentStatus,'amount_total'=>6000,'currency'=>'usd','payment_intent'=>'pi_testone',
        'client_reference_id'=>$id,
        'metadata'=>['stonefellow_kind'=>'store_order','stonefellow_order_id'=>$id]];
}
function event75(string $eventId,string $type,array $object,bool $live=false):array{
    return ['id'=>$eventId,'livemode'=>$live,'type'=>$type,'data'=>['object'=>$object]];
}
function deliver75(array $event):void{
    sf_billing_process_stripe_event($event,hash('sha256',json_encode($event,JSON_THROW_ON_ERROR)));
}
function status75(string $eventId):string{
    $q=sf_db()->prepare('SELECT status FROM billing_webhook_events WHERE event_id=?');
    $q->execute([$eventId]);return (string)$q->fetchColumn();
}
$secret='whsec_section75e3';
$invalid=json_encode(event75('evt_invalid','checkout.session.completed',[]),JSON_THROW_ON_ERROR);
$timestamp=time();$sig=hash_hmac('sha256',$timestamp.'.'.$invalid,$secret);
ok75(sf_stripe_verify_signature($invalid,'t='.$timestamp.',v1='.$sig,$secret),'HMAC valid before signed event routing');
ok75(!sf_stripe_verify_signature($invalid.'tampered','t='.$timestamp.',v1='.$sig,$secret),'Modified Stripe event rejected before dispatch');
$id='SF-E3-PAID';$sid='cs_test_'.str_repeat('a',20);
order75($id,$sid);
$unpaid=event75('evt_e3_unpaid','checkout.session.completed',session75($id,$sid,'unpaid'));
deliver75($unpaid);
ok75(status75('evt_e3_unpaid')==='processed'&&sf_read_order($id)['payment']['status']==='pending','Unpaid Checkout does not grant paid order');
ok75($GLOBALS['e3']['library']===0&&$GLOBALS['e3']['pod']===0,'Unpaid Checkout does not fulfill or grant');
$wrong=session75($id,$sid);$wrong['amount_total']=500;
ok75(blocked75(fn()=>deliver75(event75('evt_e3_bad_amount','checkout.session.completed',$wrong))),'Signed event with wrong total rejected');
ok75(status75('evt_e3_bad_amount')==='failed'&&sf_read_order($id)['payment']['status']==='pending','Rejected event tracked as failed without altering order');
$missing=session75($id,$sid);unset($missing['payment_intent']);
ok75(blocked75(fn()=>deliver75(event75('evt_e3_missing_pi','checkout.session.completed',$missing))),'Paid status without PaymentIntent rejected');
$wrongMode=session75($id,$sid);$wrongMode['livemode']=true;
ok75(blocked75(fn()=>deliver75(event75('evt_e3_live','checkout.session.completed',$wrongMode,true))),'Incorrect test/live environment cannot mark order paid');
$wrongReference=session75($id,$sid);$wrongReference['client_reference_id']='SF-E3-ANOTHER';
ok75(blocked75(fn()=>deliver75(event75('evt_e3_reference','checkout.session.completed',$wrongReference))),'Checkout with another order reference rejected');
$good=event75('evt_e3_paid','checkout.session.completed',session75($id,$sid));
deliver75($good);
$paid=sf_read_order($id);
ok75(status75('evt_e3_paid')==='processed'&&$paid['status']==='paid'&&$paid['payment']['payment_intent']==='pi_testone','Verified paid event persists correct order');
ok75($GLOBALS['e3']['library']===1&&$GLOBALS['e3']['crm']===1&&$GLOBALS['e3']['pod']===1&&$GLOBALS['e3']['notify']===1,'Library, CRM, POD and notification run once');
deliver75($good);
deliver75(event75('evt_e3_paid_different','checkout.session.async_payment_succeeded',session75($id,$sid)));
ok75($GLOBALS['e3']['library']===1&&$GLOBALS['e3']['crm']===1,'Duplicate and reordered paid events do not duplicate entitlements');
$expired=session75($id,$sid,'unpaid');$expired['status']='expired';
deliver75(event75('evt_e3_late_expiry','checkout.session.expired',$expired));
ok75(sf_read_order($id)['status']==='paid'&&$GLOBALS['e3']['released']===0,'Late expiry cannot cancel paid order');
$id2='SF-E3-RETRY';$sid2='cs_test_'.str_repeat('b',20);order75($id2,$sid2);
$GLOBALS['e3']['failLibrary']=true;
$temporary=event75('evt_e3_retry','checkout.session.completed',session75($id2,$sid2));
ok75(blocked75(fn()=>deliver75($temporary)),'Interrupted library grant surfaces as failed signed event');
$pending=sf_read_order($id2);
ok75($pending['status']==='paid'&&empty($pending['payment']['postpayment_done']['library'])
    &&!empty($pending['payment']['postpayment_done']['crm'])&&status75('evt_e3_retry')==='failed',
    'Failed side effect preserves paid order and completed checkpoints');
$crmBefore=$GLOBALS['e3']['crm'];$GLOBALS['e3']['failLibrary']=false;
deliver75($temporary);
ok75(status75('evt_e3_retry')==='processed'&&$GLOBALS['e3']['crm']===$crmBefore&&$GLOBALS['e3']['library']===2,
    'Same Stripe event retries only unfinished library checkpoint');
$id3='SF-E3-EXPIRED';$sid3='cs_test_'.str_repeat('c',20);order75($id3,$sid3);
$expiry=session75($id3,$sid3,'unpaid');$expiry['status']='expired';
deliver75(event75('evt_e3_expired','checkout.session.expired',$expiry));
deliver75(event75('evt_e3_expired_late','checkout.session.expired',$expiry));
ok75(sf_read_order($id3)['status']==='canceled'&&$GLOBALS['e3']['released']===1,'Expired order inventory released exactly once');
ok75(blocked75(fn()=>deliver75(event75('evt_e3_late_pay','checkout.session.completed',session75($id3,$sid3)))),'Late paid event cannot reactivate canceled order');
$ref='refund_e3_partial_1234567';$refundId='re_'.str_repeat('x',18);
$ready=sf_read_order($id);
$ready['payment']['refund_requests']=[$ref=>['refund_id'=>$refundId,'amount_cents'=>2500,'reason'=>'requested_by_customer','status'=>'pending']];
sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$ready);
$refund=['id'=>$refundId,'livemode'=>false,'status'=>'succeeded','amount'=>2500,
    'currency'=>'usd','payment_intent'=>'pi_testone','metadata'=>[
        'stonefellow_kind'=>'store_refund','stonefellow_order_id'=>$id,'stonefellow_refund_request'=>$ref]];
deliver75(event75('evt_e3_refund','refund.updated',$refund));
ok75(sf_read_order($id)['status']==='partially_refunded'&&sf_read_order($id)['payment']['refunded_cents']===2500,'Signed refund updates exact partial paid amount');
deliver75(event75('evt_e3_refund_dup','refund.updated',$refund));
ok75(sf_read_order($id)['payment']['refunded_cents']===2500,'Duplicate refund event does not add money twice');
$forged=$refund;$forged['amount']=3000;
ok75(blocked75(fn()=>deliver75(event75('evt_e3_refund_wrong','refund.updated',$forged))),'Refund with wrong amount is rejected by authorized intent');
$forged=$refund;$forged['livemode']=true;
ok75(blocked75(fn()=>deliver75(event75('evt_e3_refund_live','refund.updated',$forged,true))),'Refund event in wrong Stripe environment rejected');
ok75($GLOBALS['e3']['released']===1,'Refund does not silently restock sold merchandise');
$rows=(int)sf_db()->query("SELECT COUNT(*) FROM billing_webhook_events WHERE status='processed'")->fetchColumn();
ok75($rows>=7,'Billing event ledger persists processed and duplicate events');
foreach(glob($tmp.'/storage/orders/*')?:[] as $path)@unlink($path);
@rmdir($tmp.'/storage/orders');@rmdir($tmp.'/storage');@rmdir($tmp);
echo 'Section 75E3 integrated signed-event ledger: '.($checks-$failed).'/'.$checks.' passed'.PHP_EOL;
exit($failed?1:0);
