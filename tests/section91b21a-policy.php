<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/subscription-policy.php';
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
$now=time();$future=gmdate('c',$now+3600);$past=gmdate('c',$now-3600);
foreach(['pending_payment','trial_expired','canceled','unpaid','incomplete','incomplete_expired','paused'] as $status){
    $s=['status'=>$status,'current_period_end'=>$future,'cancel_at_period_end'=>1];
    check(!sf_subscription_access_active($s),'No access for '.$status);
    rejects(fn()=>sf_subscription_require_resumable($s),'No resume for '.$status);
}
check(!sf_subscription_access_active(['status'=>'active']),'Status alone cannot grant paid access');
check(!sf_subscription_access_active(['status'=>'trialing']),'Trial requires a deadline');
check(!sf_subscription_access_active(['status'=>'trialing','trial_ends_at'=>$past]),'Expired trial denied');
check(!sf_subscription_access_active(['status'=>'trialing','trial_ends_at'=>'invalid']),'Malformed trial denied');
check(sf_subscription_access_active(['status'=>'trialing','trial_ends_at'=>$future]),'Current trial permitted');
check(!sf_subscription_access_active(['status'=>'active','current_period_end'=>gmdate('c',$now)],$now),'Exact period boundary denies paid access');
check(sf_subscription_access_active(['status'=>'active','current_period_end'=>$future]),'Current paid period permitted');
check(!sf_subscription_access_active(['status'=>'past_due','grace_ends_at'=>$past]),'Expired grace denied');
check(sf_subscription_access_active(['status'=>'past_due','grace_ends_at'=>$future]),'Current grace permitted');
check(sf_subscription_access_active(['status'=>'active','provider'=>'local','monthly_price_cents'=>0,'current_period_end'=>$past]),'Free local plan can renew');
check(!sf_subscription_access_active(['status'=>'active','provider'=>'local','monthly_price_cents'=>999,'current_period_end'=>$past]),'Paid local plan cannot renew itself');
check(!sf_subscription_access_active(['status'=>'active','provider'=>'stripe','monthly_price_cents'=>0,'current_period_end'=>$past]),'Stripe remains provider controlled even at zero price');
check(!sf_subscription_access_active(['status'=>'active','monthly_price_cents'=>0,'cancel_at_period_end'=>1,'current_period_end'=>$past]),'Free plan scheduled cancellation takes effect');
$s=['status'=>'active','current_period_end'=>$future,'cancel_at_period_end'=>1];sf_subscription_require_resumable($s);check(true,'Current scheduled cancellation is resumable');
rejects(fn()=>sf_subscription_require_resumable(['status'=>'active','current_period_end'=>$future]),'No resume without scheduled cancellation');
rejects(fn()=>sf_subscription_guard_local_assignment(['provider'=>'stripe']),'Provider marker blocks local assignment');
rejects(fn()=>sf_subscription_guard_local_assignment(['provider'=>'local','provider_subscription_id'=>'sub_existing']),'Stored Stripe identity blocks local assignment');
sf_subscription_guard_local_assignment(['provider'=>'local']);check(true,'Unlinked local assignment allowed');
$p=['provider_price_id'=>'price_monthly'];$line=['type'=>'subscription','price'=>['id'=>'price_monthly'],'period'=>['start'=>$now-10,'end'=>$now+3600]];
check(sf_subscription_invoice_period(['lines'=>['data'=>[$line]]],$p)===[$now-10,$now+3600],'Legacy subscription line period parsed');
$basil=['parent'=>['subscription_item_details'=>['proration'=>false]],'pricing'=>['price_details'=>['price'=>'price_monthly']],'period'=>$line['period']];
check(sf_subscription_invoice_period(['lines'=>['data'=>[$basil]]],$p)===[$now-10,$now+3600],'Basil subscription line period parsed');
rejects(fn()=>sf_subscription_invoice_period(['lines'=>['data'=>[$line],'has_more'=>true]],$p),'Incomplete invoice page rejected');
rejects(fn()=>sf_subscription_invoice_period(['lines'=>['data'=>[$line]]],['provider_price_id'=>'price_other']),'Unmapped Stripe price rejected');
$line['proration']=true;rejects(fn()=>sf_subscription_invoice_period(['lines'=>['data'=>[$line]]],$p),'Proration cannot become full monthly allocation');
check(sf_subscription_deadline('not-a-date')===0,'Invalid timestamps fail closed');
$normalized=sf_subscription_provider_periods(['items'=>['data'=>[['current_period_start'=>$now-10,'current_period_end'=>$now+3600]]]]);
check($normalized['current_period_end']===$now+3600,'Item-level subscription period normalized');
$ambiguous=sf_subscription_provider_periods(['items'=>['data'=>[['current_period_start'=>$now-10,'current_period_end'=>$now+3600],['current_period_start'=>$now-10,'current_period_end'=>$now+7200]]]]);
check(!isset($ambiguous['current_period_end']),'Mixed intervals are not guessed');
$legacy=sf_subscription_provider_periods(['current_period_start'=>$now-10,'current_period_end'=>$now+3600]);
check($legacy['current_period_end']===$now+3600,'Legacy subscription periods preserved');
check(!sf_subscription_access_active(['status'=>'active','monthly_price_cents'=>null]),'Missing package price is not a free plan');
echo "91B21A pure policy: $checks checks passed\n";
