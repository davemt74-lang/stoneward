<?php
declare(strict_types=1);
/** Executable Store 75C recovery acceptance: real file state, mocked authenticated Stripe GET. */
$root=dirname(__DIR__);$tmp=sys_get_temp_dir().'/sf75c-'.bin2hex(random_bytes(6));
mkdir($tmp.'/storage/orders',0700,true);define('SF_ROOT',$tmp);
$GLOBALS['db']=['mode'=>'stripe_test','stripe_mode'=>'test','request_count'=>0,'post_count'=>0,'get_count'=>0,'inventory_release'=>0,'library'=>0,'crm'=>0,'pod'=>0,'notify'=>0,'post_has_lock'=>false,'responses'=>[]];
function sf_store_config():array{return ['mode'=>$GLOBALS['db']['mode']];}
function sf_billing_settings():array{return ['mode'=>$GLOBALS['db']['stripe_mode']];}
function sf_stripe_configured():bool{return true;}
function sf_stripe_secret():string{return 'sk_test_this_is_a_test';}
function sf_public_base_url():string{return 'https://stonefellow.test';}
function sf_ai_secret_key():string{return str_repeat('x',32);}
function sf_read_order(string $id):?array{$f=SF_ROOT.'/storage/orders/'.$id.'.json';return is_file($f)?json_decode((string)file_get_contents($f),true,512,JSON_THROW_ON_ERROR):null;}
function sf_write_json(string $f,array $data):void{file_put_contents($f,json_encode($data,JSON_THROW_ON_ERROR));}
function sf_commerce_release_order_inventory(string $id,string $reason):int{$GLOBALS['db']['inventory_release']++;return 1;}
function sf_account_library_add_order(int $id,array $o):void{$GLOBALS['db']['library']++;}
function sf_commerce_record_purchase(string $id,?int $uid,array $c,array $q):void{$GLOBALS['db']['crm']++;}
function sf_build_pod_handoffs(string $id,array $q,array $c):array{$GLOBALS['db']['pod']++;return [];}
function sf_notify_user(int $id,string $kind,string $title,string $msg,string $url):void{$GLOBALS['db']['notify']++;}
function sf_stripe_request(string $method,string $path,array $params=[],string $key=''):array{
    $GLOBALS['db']['request_count']++;
    if($method==='POST'){
        $GLOBALS['db']['post_count']++;
        $id=str_replace('sf-store-','',$key);
        $lock=fopen(SF_ROOT.'/storage/orders/'.$id.'.stripe.lock','c');
        $GLOBALS['db']['post_has_lock']=!flock($lock,LOCK_EX|LOCK_NB);
        if(!$GLOBALS['db']['post_has_lock'])flock($lock,LOCK_UN);
        fclose($lock);
        if(!empty($GLOBALS['db']['delay_post'])){file_put_contents(SF_ROOT.'/post-lock-ready','yes');usleep(250000);}
        return ['id'=>'cs_test_'.str_repeat('c',20),'url'=>'https://checkout.stripe.com/c/pay/one'];
    }
    if($method!=='GET')throw new RuntimeException('Unexpected method');
    $GLOBALS['db']['get_count']++;
    if(!isset($GLOBALS['db']['responses'][$path]))throw new RuntimeException('Stripe fixture missing: '.$path);
    return $GLOBALS['db']['responses'][$path];
}
require $root.'/api/store-payments.php';require $root.'/api/store-reconciliation.php';
$failed=0;$count=0;function check(bool $pass,string $name):void{global $failed,$count;$count++;if(!$pass)$failed++;echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;}
function orderFixture(string $id,string $session,string $status='pending'):array{return [
 'id'=>$id,'user_id'=>7,'created_at'=>'2026-01-01T00:00:00+00:00','status'=>$status==='pending'?'payment_pending':'paid',
 'customer'=>['email'=>'customer@example.test'],'campaign_code'=>'','quote'=>['currency'=>'USD','total_cents'=>4900,'physical'=>false,'items'=>[]],
 'payment'=>['provider'=>'stripe','status'=>$status,'checkout_session_id'=>$session,'checkout_url'=>'','payment_intent'=>'pi_mock'],
 'fulfillment'=>['status'=>'not_required'], 'timeline'=>[]];}
function saveFixture(array $o):void{sf_write_json(SF_ROOT.'/storage/orders/'.$o['id'].'.json',$o);}
function sessionFixture(string $id,string $sid,string $status='open',int $amount=4900):array{return [
 'id'=>$sid,'mode'=>'payment','livemode'=>false,'status'=>$status,'payment_status'=>$status==='complete'?'paid':'unpaid',
 'amount_total'=>$amount,'currency'=>'usd','metadata'=>['stonefellow_order_id'=>$id], 'payment_intent'=>'pi_mock'];}
$id='SF-REC-A';$sid='cs_test_'.str_repeat('a',20);$path='checkout/sessions/'.$sid;
saveFixture(orderFixture($id,$sid));$GLOBALS['db']['responses'][$path]=sessionFixture($id,$sid);
$waiting=sf_store_reconcile_order($id);
check($waiting['checkout']==='awaiting_customer_payment','Open Checkout stays pending');
check(sf_read_order($id)['payment']['status']==='pending'&&$GLOBALS['db']['library']===0,'Unpaid recovery cannot grant library purchases');
$GLOBALS['db']['responses'][$path]=sessionFixture($id,$sid,'complete',1);
try{sf_store_reconcile_order($id);$reject=false;}catch(RuntimeException $e){$reject=true;}
check($reject&&sf_read_order($id)['payment']['status']==='pending','Wrong Stripe amount fails closed');
$GLOBALS['db']['responses'][$path]=sessionFixture($id,$sid,'complete');
$paid=sf_store_reconcile_order($id);
check($paid['checkout']==='paid_verified'&&$paid['payment_status']==='paid','Authenticated Stripe GET recovers missed paid webhook');
check($GLOBALS['db']['library']===1&&$GLOBALS['db']['crm']===1&&$GLOBALS['db']['notify']===1,'Paid postprocessing executed once');
sf_store_reconcile_order($id);
check($GLOBALS['db']['library']===1&&$GLOBALS['db']['crm']===1,'Repeated recovery never duplicates completed work');
$id2='SF-REC-B';$sid2='cs_test_'.str_repeat('b',20);$p2='checkout/sessions/'.$sid2;
saveFixture(orderFixture($id2,$sid2));$GLOBALS['db']['responses'][$p2]=sessionFixture($id2,$sid2,'expired');
$expired=sf_store_reconcile_order($id2);
check($expired['checkout']==='expired_verified'&&sf_read_order($id2)['status']==='canceled','Stripe-confirmed expiry closes unpaid checkout');
sf_store_reconcile_order($id2);
check($GLOBALS['db']['inventory_release']===1,'Duplicate confirmed expiry releases stock once');
$GLOBALS['db']['responses'][$p2]=sessionFixture($id2,$sid2,'complete');
try{sf_store_reconcile_order($id2);$reject=false;}catch(RuntimeException $e){$reject=true;}
check($reject && sf_read_order($id2)['status']==='canceled','Late payment cannot silently reopen canceled stock');
$id3='SF-REC-C';$o=orderFixture($id3,'');saveFixture($o);
$missing=sf_store_reconcile_order($id3);
check($missing['checkout']==='manual_review'&&$GLOBALS['db']['post_count']===0,'Missing session is flagged without issuing a charge');
$id4='SF-REC-D';$sid4='cs_test_'.str_repeat('d',20);$o=orderFixture($id4,$sid4,'paid');
$req='refund_request_123456789';$rid='re_'.str_repeat('r',18);
$o['payment']['refund_requests']=[$req=>['refund_id'=>$rid,'amount_cents'=>4900,'reason'=>'requested_by_customer','status'=>'pending']];saveFixture($o);
$GLOBALS['db']['responses']['checkout/sessions/'.$sid4]=sessionFixture($id4,$sid4,'complete');
$GLOBALS['db']['responses']['refunds/'.$rid]=['id'=>$rid,'status'=>'succeeded','livemode'=>false,'payment_intent'=>'pi_mock',
  'amount'=>4900,'currency'=>'usd','metadata'=>['stonefellow_order_id'=>$id4,'stonefellow_refund_request'=>$req]];
$result=sf_store_reconcile_order($id4);
check($result['refunds_checked']===1&&$result['payment_status']==='refunded','Provider GET recovers signed-event refund status');
check(sf_read_order($id4)['payment']['refunded_cents']===4900,'Refund reconciliation uses exact authorized amount');
$before=$GLOBALS['db']['get_count'];sf_store_reconcile_order($id4);
check($GLOBALS['db']['get_count']===$before+1,'Terminal refund is not reprocessed again');
$id5='SF-REC-E';$o=orderFixture($id5,'');$o['created_at']=gmdate('c');saveFixture($o);
// Exercise serialized Stripe session start; webhook uses the same .stripe.lock.
$session=sf_store_stripe_checkout_start($o);
check($GLOBALS['db']['post_has_lock']===true&&$GLOBALS['db']['post_count']===1,'Session creation holds webhook order lock across Stripe POST');
check(sf_read_order($id5)['payment']['checkout_session_id']==='cs_test_'.str_repeat('c',20),'Session is durably persisted while order lock is held');
$session2=sf_store_stripe_checkout_start($o);
check($session2['checkout_url']===$session['checkout_url']&&$GLOBALS['db']['post_count']===1,'Repeated session starts reuse persisted URL without a second Stripe POST');
// Exercise real process contention: webhook must wait for session creation to
// persist the Stripe session ID rather than rejecting a valid fast payment.
if(function_exists('pcntl_fork')){
    $id6='SF-REC-F';$o=orderFixture($id6,'');$o['created_at']=gmdate('c');saveFixture($o);
    $GLOBALS['db']['delay_post']=true;$pid=pcntl_fork();
    if($pid===0){try{sf_store_stripe_checkout_start($o);exit(0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(3);}}
    if($pid<0)throw new RuntimeException('Could not start child process for lock test.');
    $ready=false;for($i=0;$i<50;$i++){if(is_file(SF_ROOT.'/post-lock-ready')){$ready=true;break;}usleep(10000);}
    $lockEvent=sessionFixture($id6,'cs_test_'.str_repeat('c',20),'complete');
    if($ready)sf_store_stripe_webhook_event($lockEvent,'checkout.session.completed');
    pcntl_waitpid($pid,$childStatus);
    check($ready&&pcntl_wifexited($childStatus)&&pcntl_wexitstatus($childStatus)===0,'Concurrent Stripe session process completed successfully');
    check(($o=sf_read_order($id6))&&$o['payment']['checkout_session_id']===$lockEvent['id']&&$o['payment']['status']==='paid',
        'Concurrent webhook waited for session ID commit then verified payment safely');
    @unlink(SF_ROOT.'/post-lock-ready');
    $GLOBALS['db']['delay_post']=false;
}
$batch=sf_store_reconcile_batch(50,0);
check($batch['processed']>=1&&$batch['processed']<=50,'Recovery batch is bounded and identifies unfinished orders');
$cron=(string)file_get_contents($root.'/cron-store-reconciliation.php');
check(str_contains($cron,"PHP_SAPI!=='cli'")&&str_contains($cron,'sf_store_reconcile_batch('),'Worker rejects public HTTP execution');
$admin=(string)file_get_contents($root.'/admin/api/orders.php');
check(str_contains($admin,"reconcile_stripe_payment")&&str_contains($admin,'sf_admin_require_auth($write)'), 'Admin payment recovery requires authenticated, write-protected route');
$ui=(string)file_get_contents($root.'/admin/assets/admin.js');
check(str_contains($ui,'stripeRefundForm')&&str_contains($ui,'stripeReconcileButton'),'Admin offers reviewed refund and recovery controls');
foreach(glob($tmp.'/storage/orders/*')?:[] as $f)unlink($f);rmdir($tmp.'/storage/orders');rmdir($tmp.'/storage');rmdir($tmp);
echo 'Section 75C / 75D: '.($count-$failed).'/'.$count.' passed'.PHP_EOL;exit($failed?1:0);
