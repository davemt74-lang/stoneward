<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/fan-conversion-diagnostics.php';
$payments=['days'=>30,'orders'=>['verified_live_orders'=>2,'verified_live_gross_cents'=>7500,
'verified_live_refund_cents'=>1000,'verified_live_net_cents'=>6500,'stripe_test_or_simulated_orders'=>3,
'pending_or_unverified_orders'=>1,'live_partially_refunded_orders'=>1],
'order_categories'=>['track'=>1,'custom_media'=>0,'album_vinyl'=>0]];
$intent=['segments'=>[['stage'=>'checkout_review','kind'=>'vinyl','accounts'=>6,'events'=>9],
['stage'=>'cart_review','kind'=>'digital','accounts'=>null,'events'=>null,'insufficient_sample'=>true]]];
$r=sf_87d_admin_conversion_diagnostics($payments,$intent);
$ok=function(bool $x,string $why):void{if(!$x)throw new RuntimeException('87D3 FAILED '.$why);};
$ok($r['verified_orders']['live_paid']===2&&$r['verified_orders']['net_cents']===6500,'real refund-adjusted sales');
$ok(in_array('review_vinyl_handoff',$r['insight_codes'],true),'vinyl observations justify review only');
$ok(in_array('review_payment_exceptions',$r['insight_codes'],true),'payment exceptions review only');
$ok($r['observed_intent'][1]['accounts']===null&&$r['observed_intent'][1]['events']===null,'small audience suppressed');
$ok($r['verified_checkout_conversion_rate']===null&&!$r['changes_executed'],'no invented conversion rate');
$brief=sf_87d_admin_agent_projection($r);
$ok($brief['live_orders']===2&&!$brief['attribution_verified']&&!$brief['actions_executed'],'read-only bounded facts');
$injected=sf_87d_admin_agent_projection(['insight_codes'=>['DO_PRIVATE_OUTREACH','review_vinyl_handoff'],
'verified_orders'=>['live_paid'=>2,'net_cents'=>'999999;DELETE']]);
$ok(!str_contains(json_encode($injected),'DO_PRIVATE_OUTREACH')&&!isset($injected['net_cents']),'no model prompt injection or malformed financial values');
$text=sf_87d_admin_agent_message($brief);
$ok(str_contains($text,'$65.00')&&str_contains($text,'no validated end-to-end conversion rate'),'honest Agent guidance');
$api=file_get_contents(dirname(__DIR__).'/admin/api/analytics.php');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/analytics.js');
$brain=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ok(str_contains($api,'sf_admin_require_auth(false)')&&str_contains($api,"'verified_conversion_intelligence'"),'admin authorization');
$ok(str_contains($js,'verifiedConversionPanel(c,verified)')&&str_contains($js,'c.esc(String(i.suggestion'),'native safely-escaped dashboard');
$ok(str_contains($brain,'sf_87d_admin_agent_projection($conversion)')&&str_contains($brain,'sf_87d_admin_agent_message('),'existing Admin Agent integrated');
echo "87D3 diagnostics and Agent regression green\n";
