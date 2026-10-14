<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/store-order-visibility.php';
$n=0;$failed=0;
function check8(bool $yes,string $msg):void{global $n,$failed;$n++;if(!$yes)$failed++;echo ($yes?'PASS ':'FAIL ').$msg.PHP_EOL;}
function fails8(callable $fn):bool{try{$fn();return false;}catch(RuntimeException $e){return true;}}
$normal=['id'=>'SF-ORD-ABC123','quote'=>['total_cents'=>1200],'payment'=>['provider'=>'stripe']];
$probe=['id'=>'SF-CERT-ABC123','certification_probe'=>true];
check8(sf_store_customer_order_visible($normal),'Real customer Stripe order remains visible');
check8(!sf_store_customer_order_visible($probe),'Internal Stripe test probe hidden from customer order lists');
check8(!sf_store_customer_order_visible(['id'=>'SF-CERT-SPOOF','certification_probe'=>false]),'Internal prefix cannot bypass certification visibility');
check8(!sf_store_customer_order_visible(['id'=>'SF-ORD-ABC123','certification_probe'=>true]),'Certification flag cannot bypass by changing its order ID');
check8(fails8(fn()=>sf_store_customer_order_required($probe)),'Normal Admin workflows reject certification probe');
check8(sf_store_customer_order_required($normal)===$normal,'Normal Admin workflow keeps real order');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/orders.php');
check8(str_contains($api,'if(sf_store_customer_order_visible($o))$rows[]='),'Admin Orders excludes probes from list');
check8(substr_count($api,'!sf_store_customer_order_visible($o)')>=3,'Admin detail and ordinary fulfillment/status actions exclude probes');
check8(substr_count($api,'!sf_store_customer_order_visible($candidate)')===2,'Admin refund and reconciliation cannot target test probe orders');
check8(str_contains($api,'sf_admin_require_auth($write)')&&str_contains($api,'sf_request_json()'),'Orders API retains Admin authentication and write CSRF');
$gateway=(string)file_get_contents(dirname(__DIR__).'/api/store-payments.php');
check8(str_contains($gateway,'certification_probe')&&str_contains($gateway,'postpayment_done'),'Test probe remains separated from real customer purchase entitlements');
$billing=(string)file_get_contents(dirname(__DIR__).'/api/billing.php');
check8(str_contains($billing,"sf_store_config()['mode']")&&str_contains($billing,"$"."mode!=='test'"),
    'Direct merchant cannot switch test credentials into production billing');
echo 'Section 75E8 Stripe test-order isolation: '.($n-$failed).'/'.$n.' passed'.PHP_EOL;
exit($failed?1:0);
