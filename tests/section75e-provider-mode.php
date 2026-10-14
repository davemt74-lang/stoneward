<?php
declare(strict_types=1);
/** Provider environment remains bound to the original payment, not Admin's new mode. */
function sf_store_config():array{return ['mode'=>$GLOBALS['switchMode']??'stripe_test'];}
require __DIR__.'/../api/store-payment-mode.php';
$checks=[];
$GLOBALS['switchMode']='production';
$checks['Delayed test payment stays in test environment']=!sf_store_order_expected_livemode(['payment'=>['checkout_session_id'=>'cs_test_12345']]);
$GLOBALS['switchMode']='stripe_test';
$checks['Delayed live payment stays in live environment']=sf_store_order_expected_livemode(['payment'=>['checkout_session_id'=>'cs_live_12345']]);
$checks['Recorded live mode persists before session creation']=sf_store_order_expected_livemode(['payment'=>['livemode'=>true,'checkout_session_id'=>'']]);
$GLOBALS['switchMode']='production';
$checks['Recorded test mode persists before session creation']=!sf_store_order_expected_livemode(['payment'=>['livemode'=>false,'checkout_session_id'=>'']]);
$checks['Legacy fallback only where no provider identifier exists']=sf_store_order_expected_livemode(['payment'=>[]]);
$root=dirname(__DIR__);
$payment=(string)file_get_contents($root.'/api/store-payments.php');
$refund=(string)file_get_contents($root.'/api/store-refunds.php');
$checks['Checkout checks stored provider mode']=str_contains($payment,'sf_store_order_expected_livemode($order)');
$checks['Refund checks stored provider mode']=str_contains($refund,'sf_store_order_expected_livemode($order)');
$checks['Both modules use one common mode resolver']=str_contains($payment,"require_once __DIR__.'/store-payment-mode.php'")&&str_contains($refund,"require_once __DIR__.'/store-payment-mode.php'");
$failed=0;foreach($checks as $label=>$ok){echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$failed++;}
echo 'Section 75E provider-mode continuity: '.(count($checks)-$failed).'/'.count($checks).' passed'.PHP_EOL;
exit($failed?1:0);
