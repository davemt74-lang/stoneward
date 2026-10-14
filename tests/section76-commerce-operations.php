<?php
declare(strict_types=1);
/** Section 76 Ecommerce Operations functional acceptance on ephemeral storage. */
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/sf-commerce76-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
define('SF_ROOT',$tmp);
$GLOBALS['orders']=[];
function sf_read_order(string $id):?array{return $GLOBALS['orders'][$id]??null;}
function sf_write_json(string $path,array $data):void{
 $dir=dirname($path);if(!is_dir($dir))mkdir($dir,0700,true);
 $new=$path.'.temp';file_put_contents($new,json_encode($data,JSON_THROW_ON_ERROR));rename($new,$path);
}
require $root.'/api/commerce-operations.php';
$passes=0;$fails=0;
function ok76(bool $ok,string $label):void{global $passes,$fails;if($ok)$passes++;else $fails++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
function reject76(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
$now=time();
$paid=['id'=>'SF-REAL-123','status'=>'paid','created_at'=>gmdate('c',$now-7200),
 'customer'=>['name'=>'Customer A'],'quote'=>['total_cents'=>2500,'physical'=>true],
 'payment'=>['provider'=>'stripe','status'=>'paid','postpayment_done'=>array_fill_keys(['library','crm','campaign','pod','notification'],true)],
 'fulfillment'=>['status'=>'preparing']];
$stale=['id'=>'SF-REAL-456','status'=>'payment_pending','created_at'=>gmdate('c',$now-3600),
 'customer'=>['name'=>'Customer B'],'quote'=>['total_cents'=>2000,'physical'=>false],
 'payment'=>['provider'=>'stripe','status'=>'pending']];
$ref=['id'=>'SF-REAL-789','status'=>'partially_refunded','created_at'=>gmdate('c',$now-6000),
 'customer'=>['name'=>'Customer C'],'quote'=>['total_cents'=>5000,'physical'=>false],
 'payment'=>['provider'=>'stripe','status'=>'partially_refunded','postpayment_done'=>array_fill_keys(['library','crm','campaign','pod','notification'],true),
 'refund_requests'=>[['status'=>'pending','amount_cents'=>1400]]]];
$probe=['id'=>'SF-CERT-TEST','status'=>'paid','created_at'=>gmdate('c',$now-10000),'certification_probe'=>true,
 'quote'=>['total_cents'=>100,'physical'=>false],'payment'=>['provider'=>'stripe','status'=>'paid']];
$GLOBALS['orders']=array_column([$paid,$stale,$ref,$probe],null,'id');
$snap=sf_commerce_ops_snapshot(array_values($GLOBALS['orders']),$now);
ok76($snap['summary']['customer_orders']===3,'Certification probe excluded from operations totals');
ok76($snap['summary']['payment_attention']===1,'Stale Stripe payment surfaced');
ok76($snap['summary']['refund_attention']===1,'Pending refund surfaced');
ok76($snap['summary']['fulfillment_pending']===1,'Verified paid physical order surfaces in fulfillment');
ok76($snap['summary']['refund_completed']===1,'Partially refunded order remains visible');
ok76(count($snap['items'])===3,'Exception queue shows only actionable customers');
ok76(reject76(fn()=>sf_commerce_ops_update('SF-CERT-TEST',5,'add_followup',[])),'Internal probe cannot get a customer-service note');
ok76(reject76(fn()=>sf_commerce_ops_update('SF-REAL-123',0,'add_followup',[])),'Only authenticated Admin can write follow-ups');
$input=['request_key'=>'followup_request_00001','note'=>'Verify tracking with warehouse','priority'=>'high','due_date'=>'2026-10-20'];
$items=sf_commerce_ops_update('SF-REAL-123',5,'add_followup',$input);
ok76(count($items)===1&&($items[0]['status']??'')==='open','New customer follow-up persists');
ok76(($items[0]['created_by']??0)===5&&($items[0]['priority']??'')==='high','Follow-up has owner and priority');
$replay=sf_commerce_ops_update('SF-REAL-123',5,'add_followup',$input);
ok76(count($replay)===1&&$replay[0]['id']===$items[0]['id'],'Exact request retry cannot duplicate follow-up');
$modified=$input;$modified['note']='Changed text';
ok76(reject76(fn()=>sf_commerce_ops_update('SF-REAL-123',5,'add_followup',$modified)),'Reuse of request key with changed content fails closed');
$invalid=$input;$invalid['request_key']='followup_request_00002';$invalid['due_date']='2026-13-98';
ok76(reject76(fn()=>sf_commerce_ops_update('SF-REAL-123',5,'add_followup',$invalid)),'Invalid due dates rejected');
ok76(sf_commerce_ops_snapshot(array_values($GLOBALS['orders']))['summary']['open_followups']===1,
 'Follow-up is visible in operations summary after persistence');
$done=sf_commerce_ops_update('SF-REAL-123',5,'resolve_followup',['followup_id'=>$items[0]['id']]);
ok76($done[0]['status']==='done'&&$done[0]['resolved_by']===5,'Follow-up resolution persists Admin audit identity');
$again=sf_commerce_ops_update('SF-REAL-123',5,'resolve_followup',['followup_id'=>$items[0]['id']]);
ok76(count($again)===1&&$again[0]['resolved_at']===$done[0]['resolved_at'],'Repeated resolve is idempotent');
ok76(sf_commerce_ops_snapshot(array_values($GLOBALS['orders']))['summary']['open_followups']===0,
 'Resolved follow-up disappears from open queue');
ok76(reject76(fn()=>sf_commerce_ops_update('SF-REAL-123',5,'resolve_followup',['followup_id'=>'fu_'.str_repeat('a',16)])),
 'Unknown follow-up resolution fails closed');
$api=file_get_contents($root.'/admin/api/commerce-ops.php');
$admin=file_get_contents($root.'/admin/assets/admin.js');
$view=file_get_contents($root.'/admin/assets/commerce-ops.js');
$index=file_get_contents($root.'/admin/index.php');
ok76(str_contains($api,'sf_admin_require_auth($write)')&&str_contains($api,'sf_request_json()'),
 'Admin-only action endpoint keeps CSRF and identity checks');
ok76(str_contains($api,"'check_stripe'")&&str_contains($api,"sf_store_reconcile_order("),
 'Stripe reconciliation reuses existing provider-backed order workflow');
ok76(!str_contains($api,"sf_store_refund_request(")&&!str_contains($api,"sf_commerce_mark_order_sold("),
 'Commerce dashboard cannot bypass refund or fulfillment approvals');
ok76(str_contains($admin,"'commerce-ops'")&&str_contains($index,'commerce-ops.js')&&str_contains($view,"commerceBack"),
 'Operations workspace reachable from Orders and Admin canvas');
ok76(str_contains($view,"dataset.requestKey")&&str_contains($view,"resolve_followup"),
 'UI preserves retry ID and handles completed follow-up action');
foreach(glob($tmp.'/storage/commerce-ops/*')?:[] as $path)unlink($path);
@rmdir($tmp.'/storage/commerce-ops');@rmdir($tmp.'/storage');@rmdir($tmp);
echo 'Section 76 Commerce Operations: '.$passes.'/'.($passes+$fails).' passed'.PHP_EOL;
exit($fails?1:0);
