<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/fan-conversion-verified.php';
$now=time();$t=gmdate('c',$now);
$o=['id'=>'SF-LIVE-ONE','created_at'=>$t,'status'=>'paid','quote'=>['total_cents'=>4200,'currency'=>'USD','items'=>[['type'=>'track'],['type'=>'custom_media']]],
 'payment'=>['provider'=>'stripe','status'=>'paid','livemode'=>true,'checkout_session_id'=>'cs_live_ABC','payment_intent'=>'pi_abc','paid_at'=>$t,'amount_cents'=>4200,'currency'=>'USD'],
 'timeline'=>[['type'=>'payment_confirmed']]];
$pass=0;
$check=function(bool $ok,string $why)use(&$pass):void{if(!$ok)throw new RuntimeException('87D1 FAILED: '.$why);$pass++;};
$check(sf_87d_order_payment_class($o)==='live_paid','webhook evidence');
$p=$o;$p['id']='SF-PENDING-ONE';$p['status']='payment_pending';$p['payment']['status']='pending';$check(sf_87d_order_payment_class($p)==='unverified','pending not paid');
$test=$o;$test['id']='SF-TEST-ONE';$test['payment']['livemode']=false;$test['payment']['checkout_session_id']='cs_test_X';$check(sf_87d_order_payment_class($test)==='stripe_test_or_unverified','test isolation');
$s=$o;$s['id']='SF-SIM-ONE';$s['payment']['provider']='cash_simulation';$s['payment']['status']='simulated_paid';
$refund=$o;$refund['id']='SF-REFUND-ONE';$refund['status']='partially_refunded';$refund['payment']['status']='partially_refunded';$refund['payment']['refunded_cents']=900;
$full=$o;$full['id']='SF-FULL-ONE';$full['status']='refunded';$full['payment']['status']='refunded';$full['payment']['refunded_cents']=4200;
$probe=$o;$probe['id']='SF-CERT-ONE';$probe['certification_probe']=true;
$old=$o;$old['id']='SF-OLD-ONE';$old['payment']['paid_at']=gmdate('c',$now-32*86400);
$r=sf_87d_verified_conversion_report([$o,$o,$p,$test,$s,$refund,$full,$probe,$old],30,$now);
$check($r['orders']['verified_live_orders']===3,'unique paid orders');
$check($r['orders']['verified_live_gross_cents']===12600&&$r['orders']['verified_live_net_cents']===7500,'refund aware');
$check($r['orders']['stripe_test_or_simulated_orders']===2,'test excluded from real revenue');
$check($r['orders']['pending_or_unverified_orders']===1,'pending tracked');
$check($r['order_categories']['track']===3&&$r['order_categories']['custom_media']===3,'item class counted by order');
$check($r['attribution_rate']===null&&!$r['changes_executed'],'no false attribution');
$check(!str_contains(json_encode($r),'SF-LIVE-ONE'),'no order identifiers in aggregate');
echo "87D1 $pass assertions passed\n";
