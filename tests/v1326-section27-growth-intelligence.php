<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
require $root.'/api/growth-analytics-core.php';

ok(sf_growth_percent(1,4)===25.0,'growth percent computes deterministic rates');
ok(sf_growth_percent(0,0)===0.0,'growth percent handles empty denominator');

$fixture=[
  'orders'=>['paid_orders'=>10],
  'refunds'=>['confirmed'=>2],
  'campaigns'=>['campaigns'=>[['name'=>'Weak launch','participants'=>20,'conversion_rate'=>2.5]]],
  'automation'=>['failed'=>3],
  'ticketing'=>['offers'=>[['title'=>'Phoenix VIP','status'=>'published','capacity'=>20,'claimed_qty'=>19,'fill_rate'=>95.0]]],
  'care'=>['high_priority_cases'=>2],
  'membership'=>['cancel_at_period_end'=>1],
  'merch'=>['low_stock'=>[['id'=>'tee','title'=>'Tour Tee']]],
];
$ops=sf_growth_opportunities($fixture);
$titles=array_column($ops,'title');
ok(in_array('Refund rate needs attention',$titles,true),'opportunity engine flags material refund rate');
ok(in_array('Lifecycle automation failures',$titles,true),'opportunity engine flags failed automations');
ok(in_array('Low-converting campaign: Weak launch',$titles,true),'opportunity engine flags low-converting campaigns');
ok(in_array('Ticket offer nearly full: Phoenix VIP',$titles,true),'opportunity engine flags near-capacity ticket offers');
ok(in_array('Members scheduled to cancel',$titles,true),'opportunity engine flags membership cancellation risk');
ok(in_array('Low-stock products',$titles,true),'opportunity engine flags low stock');

$healthy=sf_growth_opportunities([
  'orders'=>['paid_orders'=>0],'refunds'=>['confirmed'=>0],'campaigns'=>['campaigns'=>[]],
  'automation'=>['failed'=>0],'ticketing'=>['offers'=>[]],'care'=>['high_priority_cases'=>0],
  'membership'=>['cancel_at_period_end'=>0],'merch'=>['low_stock'=>[]]
]);
ok(($healthy[0]['severity']??'')==='good','opportunity engine reports clear state when no thresholds are triggered');

$core=file_get_contents($root.'/api/growth-analytics-core.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$api=file_get_contents($root.'/admin/api/analytics.php');
$ui=file_get_contents($root.'/admin/assets/analytics.js');
$admin=file_get_contents($root.'/admin/assets/admin.js');
$index=file_get_contents($root.'/admin/index.php');
$css=file_get_contents($root.'/admin/assets/admin.css');
$version=file_get_contents($root.'/version.php');
$mig=file_get_contents($root.'/api/migrations.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(!str_contains($core,'CREATE TABLE'),'growth intelligence is read-only and creates no reporting data copy');
ok(str_contains($boot,"require_once __DIR__ . '/growth-analytics-core.php'"),'growth analytics core loads from canonical bootstrap');
ok(str_contains($core,'function sf_growth_orders')&&str_contains($core,'function sf_growth_membership')&&str_contains($core,'function sf_growth_crm'),'growth core covers orders, membership and CRM');
ok(str_contains($core,'function sf_growth_campaigns')&&str_contains($core,'function sf_growth_merch')&&str_contains($core,'function sf_growth_ticketing'),'growth core covers campaigns, merch and ticket demand');
ok(str_contains($core,'function sf_growth_automation')&&str_contains($core,'function sf_growth_care'),'growth core covers lifecycle automation and customer care');
ok(str_contains($core,'recorded_revenue')&&str_contains($core,'refund_cents')&&str_contains($core,'net_cents'),'recorded revenue model separates gross, refunds and net');
ok(str_contains($core,"billing_invoices")&&str_contains($core,"amount_paid_cents"),'membership revenue comes from paid billing invoices');
ok(str_contains($core,"inventory_status='sold'"),'merch analytics uses sold order items');
ok(str_contains($core,"event_type IN ('purchase_attributed','offer_redeemed')")&&str_contains($core,'created_at>=?'),'campaign attribution obeys the reporting window');
ok(str_contains($core,"reserved_at>=?")&&str_contains($core,'checked_in_qty'),'ticket demand and check-in obey the reporting window');
ok(str_contains($core,'function sf_growth_top_supporters')&&str_contains($core,'recorded_value_cents'),'top supporter value combines recorded order and membership value');
ok(str_contains($core,'function sf_growth_agent_context_from'),'growth intelligence exposes one grounded Agent-ready brief from computed metrics');

ok(str_contains($api,'sf_growth_analytics($days)')&&str_contains($api,"growth['agent_brief']"),'Admin Analytics returns growth intelligence and grounded Agent brief');
ok(str_contains($ui,'growthPanel')&&str_contains($ui,'Recorded net revenue'),'Admin Analytics renders business intelligence');
ok(str_contains($ui,'Revenue accounting:')&&str_contains($ui,'external ticket checkout is not counted as revenue'),'analytics UI documents revenue boundaries');
ok(str_contains($ui,'Top supporters')&&str_contains($ui,'What needs attention'),'analytics UI surfaces supporter value and deterministic opportunities');
ok(str_contains($ui,'Admin Agent brief'),'analytics UI exposes the grounded Admin Agent brief');
ok(str_contains($admin,"analytics:'Performance Intelligence'"),'Admin view title is upgraded to Performance Intelligence');
ok(str_contains($admin,'business performance')&&str_contains($admin,'campaign roi'),'Admin Agent routes business-performance questions to analytics');
ok(str_contains($index,'Performance Intelligence'),'Admin navigation labels the unified analytics workspace');
ok(str_contains($css,'.growth-primary-stats')&&str_contains($css,'.growth-opportunities'),'growth intelligence has dedicated responsive Admin styles');

ok(str_contains($version,"'stonefellow'=>'1.3.26'")&&str_contains($version,"'database_schema_target'=>'1.3.22'"),'version endpoint reports app 1.3.26 with unchanged schema 1.3.22');
ok(str_contains($version,"'growth_intelligence'=>'recorded-revenue-crm-campaign-merch-membership-ticket-automation-care-fan-value'"),'version endpoint advertises unified growth intelligence');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.22'"),'Section 27 does not require a database migration');
ok(str_contains($wf,'php tests/v1326-section27-growth-intelligence.php'),'release gate includes Section 27 regression suite');

echo "Stonefellow v1.3.26 Section 27 Business Intelligence & Growth Analytics audit: PASS\n";
