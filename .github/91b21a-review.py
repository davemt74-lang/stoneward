from pathlib import Path
root=Path('.')
def patch(path, old, new, count=1):
 p=root/path;s=p.read_text();assert s.count(old)==count,(path,old[:90],s.count(old));p.write_text(s.replace(old,new))
patch('api/membership-core.php','SELECT s.user_id,s.package_id,s.status,s.grace_ends_at,p.name,p.membership_enabled','SELECT s.*,p.monthly_price_cents,p.name,p.membership_enabled')
patch('admin/api/membership.php','s.status,s.current_period_end,s.cancel_at_period_end,s.grace_ends_at,p.name package_name','s.status,s.current_period_end,s.trial_ends_at,s.cancel_at_period_end,s.grace_ends_at,s.provider,s.provider_subscription_id,p.monthly_price_cents,p.name package_name')
patch('admin/api/membership.php',"$r['active']=sf_membership_status_active($r);", "$r['active']=sf_membership_status_active($r);$r['stored_status']=$r['status'];$r['status']=sf_subscription_effective_status($r);")
patch('api/subscription-policy.php',"&& array_key_exists('monthly_price_cents',$sub) && (int)$sub['monthly_price_cents']===0", "&& isset($sub['monthly_price_cents']) && (int)$sub['monthly_price_cents']===0")
p=root/'api/billing.php';s=p.read_text();a=s.index('function sf_billing_bind_stripe_subscription(');b=s.index('function sf_billing_process_stripe_event(',a)
s=s[:a]+'''function sf_billing_bind_stripe_subscription(int $uid,int $pkg,string $customer,string $subId,string $status,string $now): void {
    if($uid<=0||$customer===''||$subId==='')throw new InvalidArgumentException('Complete Stripe subscription identity is required.');
    sf_billing_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();
    try{
        sf_subscription_lock_user($pdo,$uid);
        $q=$pdo->prepare('SELECT * FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);$bound=$q->fetch();
        if($bound&&sf_subscription_provider_linked($bound)){
            if((string)$bound['provider_subscription_id']!==$subId||(string)$bound['provider_customer_id']!==$customer)
                throw new RuntimeException('A different Stripe billing relationship already exists. Reconcile before binding another subscription.');
        }elseif($bound){
            $pdo->prepare("UPDATE user_subscriptions SET package_id=CASE WHEN ? > 0 THEN ? ELSE package_id END,status=?,provider='stripe',provider_customer_id=?,provider_subscription_id=?,trial_started_at=NULL,trial_ends_at=NULL,current_period_start=NULL,current_period_end=NULL,cancel_at_period_end=0,canceled_at=NULL,grace_ends_at=NULL,updated_at=? WHERE user_id=?")
                ->execute([$pkg,$pkg,$status,$customer,$subId,$now,$uid]);
        }else{
            $pdo->prepare("INSERT INTO user_subscriptions(user_id,package_id,status,trial_started_at,trial_ends_at,current_period_start,current_period_end,created_at,updated_at,provider,provider_customer_id,provider_subscription_id,cancel_at_period_end) VALUES(?,?,?,NULL,NULL,NULL,NULL,?,?,'stripe',?,?,0)")
                ->execute([$uid,$pkg?:null,$status,$now,$now,$customer,$subId]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('sf_membership_sync_crm'))sf_membership_sync_crm($uid);
}
''' +s[b:]
a=s.index("        elseif($type==='customer.subscription.created'){");b=s.index("        elseif(in_array($type,['invoice.paid'",a)
s=s[:a]+'''        elseif($type==='customer.subscription.created'){
            [$uid,$pkg]=sf_billing_event_identity($obj);
            $subId=(string)($obj['id']??'');$customer=is_string($obj['customer']??null)?$obj['customer']:'';
            if($uid>0){
                sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,(string)($obj['status']??'pending_payment'),$now);
                sf_subscription_record_creation($uid,$obj);
            }
        }
'''+s[b:];p.write_text(s)
addition='''
/** Creation may supply a pending trial's dates but must not rewind a paid renewal. */
function sf_subscription_record_creation(int $uid,array $object): void {
    $pdo=sf_db();$pdo->beginTransaction();
    try {
        sf_subscription_lock_user($pdo,$uid);
        $q=$pdo->prepare('SELECT * FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);$sub=$q->fetch();
        if(!$sub||(string)$sub['provider_subscription_id']!==(string)($object['id']??'')
            ||(string)$sub['provider_customer_id']!==(string)($object['customer']??''))
            throw new RuntimeException('Subscription creation has a conflicting billing identity.');
        $start=(int)($object['current_period_start']??0);$end=(int)($object['current_period_end']??0);
        $currentEnd=sf_subscription_deadline($sub['current_period_end']??null);
        if(!in_array((string)$sub['status'],['canceled','incomplete_expired'],true)
            && $start>0 && $end>$start && ($currentEnd===0||$end>=$currentEnd)){
            $status=(string)$sub['status'];
            // Checkout can arrive before a trial's creation event. Only advance pending -> trial.
            if($status==='pending_payment'&&($object['status']??'')==='trialing'&&(int)($object['trial_end']??0)>time())$status='trialing';
            $pdo->prepare('UPDATE user_subscriptions SET status=?,current_period_start=?,current_period_end=?,trial_started_at=COALESCE(?,trial_started_at),trial_ends_at=COALESCE(?,trial_ends_at),updated_at=? WHERE user_id=?')
                ->execute([$status,gmdate('c',$start),gmdate('c',$end),sf_timestamp_iso($object['trial_start']??0),sf_timestamp_iso($object['trial_end']??0),gmdate('c'),$uid]);
        }
        sf_billing_log($uid,'customer.subscription.created','stripe',(string)$object['id'],['status'=>$sub['status']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
'''
p=root/'api/subscription-policy.php';s=p.read_text();assert 'function sf_subscription_record_creation(' not in s;p.write_text(s+addition)
# Execute the exact roster helper, isolated from the HTTP route's output and auth dispatch.
tests=r'''
$checkout=['id'=>'cs_trial_first','mode'=>'subscription','payment_status'=>'no_payment_required','customer'=>'cus_9','subscription'=>'sub_9','metadata'=>['stonefellow_user_id'=>9,'stonefellow_package_id'=>100]];
sf_billing_process_stripe_event(['id'=>'evt_checkout_first','type'=>'checkout.session.completed','data'=>['object'=>$checkout]]);
$trialFirst=$trial;$trialFirst['id']='sub_9';$trialFirst['customer']='cus_9';$trialFirst['metadata']['stonefellow_user_id']=9;
sf_billing_process_stripe_event(['id'=>'evt_trial_second','type'=>'customer.subscription.created','data'=>['object'=>$trialFirst]]);
check(sf_membership_state(9)['active']&&row(9)['status']==='trialing','Checkout-before-creation cannot strand a valid Stripe trial');
$renewed=row(8);$oldCreation=['id'=>'sub_8','customer'=>'cus_8','status'=>'active','metadata'=>['stonefellow_user_id'=>8,'stonefellow_package_id'=>100],'current_period_start'=>time()-7200,'current_period_end'=>time()-3600];
sf_billing_process_stripe_event(['id'=>'evt_old_creation','type'=>'customer.subscription.created','data'=>['object'=>$oldCreation]]);
check(row(8)['current_period_end']===$renewed['current_period_end'],'Delayed creation cannot rewind a paid renewal period');
$rosterSource=file_get_contents($root.'/admin/api/membership.php');$a=strpos($rosterSource,'function sf_admin_membership_members(');$b=strpos($rosterSource,"if(\$method==='GET')",$a);
if($a===false||$b===false)throw new RuntimeException('Cannot isolate production Admin roster helper');
eval(substr($rosterSource,$a,$b-$a));
$future=gmdate('c',time()+3600);$past=gmdate('c',time()-3600);
seed(1);seed(2,['status'=>'trialing','trial_ends_at'=>$past]);seed(3,['status'=>'trialing','trial_ends_at'=>$future]);
seed(4,['cancel_at_period_end'=>1,'current_period_end'=>$past]);seed(5,['package_id'=>101,'current_period_end'=>$past]);
seed(6,['current_period_end'=>$past]);seed(7,['status'=>'past_due','grace_ends_at'=>$future]);
seed(8,['provider'=>'stripe','provider_customer_id'=>'cus_8','provider_subscription_id'=>'sub_8']);
seed(9,['current_period_end'=>null]);seed(10,['status'=>'trialing','trial_ends_at'=>'invalid']);
$expected=[1=>true,2=>false,3=>true,4=>false,5=>true,6=>false,7=>true,8=>true,9=>false,10=>false];
$members=sf_admin_membership_members();check(count($members)===10,'Admin roster returns all isolated membership fixtures');
foreach($members as $member){$uid=(int)$member['user_id'];check($member['active']===$expected[$uid]&&$member['status']===sf_membership_state($uid)['status'],'Admin roster and member access agree for user '.$uid);}
$summary=sf_membership_summary();check($summary['active_members']===5&&array_sum($summary['tiers'])===5,'Dashboard totals count current paid, trial, free and grace periods consistently');
'''
patch('tests/section91b21a-runtime.php','echo "91B21A $driver runtime: $checks assertions passed\\n";',tests+'\necho "91B21A $driver runtime: $checks assertions passed\\n";')
patch('tests/section91b21a-policy.php','echo "91B21A pure policy: $checks checks passed\\n";',"check(!sf_subscription_access_active(['status'=>'active','monthly_price_cents'=>null]),'Missing package price is not a free plan');\n"+'echo "91B21A pure policy: $checks checks passed\\n";')
p=root/'docs/section91b21a-subscription-correctness.md';p.write_text(p.read_text()+'\n## Final review additions\nAdmin roster and dashboard queries include all effective-date/provider/price fields and are behavior-tested against member access. Initial provider binding uses the same user lock as local assignments and resets inherited local dates. Checkout-before-trial creation is supported; delayed creation cannot rewind a paid renewal. A missing package is not inferred to be free.\n')
