<?php
declare(strict_types=1);

/** 91B21A: one date-aware subscription policy, shared by billing and membership. */
function sf_subscription_deadline(mixed $value): int {
    if (!is_string($value) || trim($value)==='') return 0;
    $time=strtotime($value);
    return $time===false?0:$time;
}
function sf_subscription_provider_linked(array $sub): bool {
    return ($sub['provider']??'local')==='stripe'
        || trim((string)($sub['provider_subscription_id']??''))!=='';
}
function sf_subscription_effective_status(array $sub,?int $now=null): string {
    $now=$now??time();$status=(string)($sub['status']??'none');
    $end=sf_subscription_deadline($sub['current_period_end']??null);
    if ($status==='trialing') {
        if (sf_subscription_deadline($sub['trial_ends_at']??null)<=$now) return 'trial_expired';
        if (!empty($sub['cancel_at_period_end']) && $end<=$now) return 'canceled';
        return 'trialing';
    }
    if ($status==='past_due') {
        if (!empty($sub['cancel_at_period_end']) && $end<=$now) return 'canceled';
        return sf_subscription_deadline($sub['grace_ends_at']??null)>$now?'past_due':'unpaid';
    }
    if ($status!=='active') return $status;
    if ($end>$now) return 'active';
    if (!empty($sub['cancel_at_period_end'])) return 'canceled';
    // Only an explicitly free, unlinked local plan renews without a payment.
    if (!sf_subscription_provider_linked($sub)
        && array_key_exists('monthly_price_cents',$sub) && (int)$sub['monthly_price_cents']===0) return 'active';
    return 'expired';
}
function sf_subscription_access_active(array $sub,?int $now=null): bool {
    return in_array(sf_subscription_effective_status($sub,$now),['active','trialing','past_due'],true);
}
function sf_subscription_require_resumable(array $sub): void {
    if (empty($sub['cancel_at_period_end'])
        || !in_array((string)($sub['status']??''),['active','trialing'],true)
        || sf_subscription_effective_status($sub)!==($sub['status']??'')) {
        throw new RuntimeException('Only a current subscription with a scheduled cancellation can be resumed. Choose a package or manage Stripe billing instead.');
    }
}
function sf_subscription_guard_local_assignment(?array $sub): void {
    if ($sub && sf_subscription_provider_linked($sub)) {
        throw new RuntimeException('This account is linked to Stripe. Manage its package in Stripe billing; local assignment cannot remove the billing relationship. Free token grants remain available.');
    }
}
/** Serialize balance, package and period changes on the same user in both engines. */
function sf_subscription_lock_user(PDO $pdo,int $userId): void {
    $mysql=(sf_db_config()['driver']??'')==='mysql';
    if (!$mysql) $pdo->prepare('UPDATE users SET id=id WHERE id=?')->execute([$userId]);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.($mysql?' FOR UPDATE':''));
    $q->execute([$userId]);
    if ($q->fetchColumn()===false) throw new InvalidArgumentException('Subscription account does not exist.');
}
function sf_subscription_refresh_local(int $userId): void {
    $pdo=sf_db();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try {
        sf_subscription_lock_user($pdo,$userId);
        $q=$pdo->prepare('SELECT s.*,p.monthly_price_cents,p.monthly_tokens FROM user_subscriptions s LEFT JOIN subscription_packages p ON p.id=s.package_id WHERE s.user_id=?');
        $q->execute([$userId]);$sub=$q->fetch();$now=gmdate('c');
        if ($sub && !sf_subscription_provider_linked($sub)) {
            $effective=sf_subscription_effective_status($sub);
            if ($effective!==$sub['status']) {
                $pdo->prepare('UPDATE user_subscriptions SET status=?,canceled_at=CASE WHEN ?=\'canceled\' THEN ? ELSE canceled_at END,updated_at=? WHERE user_id=?')
                    ->execute([$effective,$effective,$now,$now,$userId]);
            } elseif ($effective==='active' && (int)$sub['monthly_price_cents']===0
                && !empty($sub['current_period_end']) && sf_subscription_deadline($sub['current_period_end'])<=time()) {
                $end=gmdate('c',strtotime('+1 month'));
                $pdo->prepare('UPDATE user_subscriptions SET current_period_start=?,current_period_end=?,updated_at=? WHERE user_id=?')->execute([$now,$end,$now,$userId]);
                $tokens=max(0,(int)$sub['monthly_tokens']);
                if($tokens>0)sf_token_adjust($userId,$tokens,'monthly_grant','local_renewal:'.$sub['current_period_end'],[],'Free local package renewal');
            }
        }
        if($owns)$pdo->commit();
    } catch(Throwable $e) {if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
/** Prefer the billed subscription line, not the invoice's historical aggregation period. */
function sf_subscription_invoice_period(array $invoice,array $package): array {
    $periods=[];$price=(string)($package['provider_price_id']??'');
    foreach((array)($invoice['lines']['data']??[]) as $line) {
        if(!is_array($line))continue;
        $parent=(array)($line['parent']['subscription_item_details']??[]);
        if(($line['type']??'')!=='subscription' && !$parent)continue;
        if(!empty($line['proration'])||!empty($parent['proration']))continue;
        $linePrice=$line['price']['id']??$line['pricing']['price_details']['price']??'';
        if($price===''||!is_string($linePrice)||$linePrice!==$price)continue;
        $start=(int)($line['period']['start']??0);$end=(int)($line['period']['end']??0);
        if($start>0&&$end>$start)$periods[$start.':'.$end]=[$start,$end];
    }
    if(!empty($invoice['lines']['has_more']) || count($periods)!==1)
        throw new RuntimeException('Recurring invoice period or Stripe price mapping is ambiguous. Reconcile the package and retry this event.');
    return array_values($periods)[0];
}
/** Both payment event names converge here; the period credit and receipt commit together. */
function sf_subscription_apply_paid_invoice(array $invoice): void {
    $invoiceId=(string)($invoice['id']??'');$subId=sf_stripe_subscription_id_from_invoice($invoice);
    if($invoiceId===''||($invoice['status']??'')!=='paid')
        throw new InvalidArgumentException('A paid subscription invoice with stable identity is required.');
    if($subId===''){sf_billing_log(null,'non_subscription_invoice_ignored','stripe',$invoiceId);return;}
    $local=sf_billing_find_user_by_provider_subscription($subId);
    if(!$local) {
        [$uid,$pkg]=sf_billing_event_identity($invoice);
        $customer=is_string($invoice['customer']??null)?$invoice['customer']:'';
        if($uid<1||$pkg<1||$customer==='')throw new RuntimeException('Invoice cannot be matched to a subscription. Reconcile and retry.');
        sf_billing_bind_stripe_subscription($uid,$pkg,$customer,$subId,'pending_payment',gmdate('c'));
        $local=sf_billing_find_user_by_provider_subscription($subId);
    }
    if(!$local)throw new RuntimeException('Subscription binding is unavailable.');
    $uid=(int)$local['user_id'];$pkg=(int)$local['package_id'];$package=sf_package($pkg);
    if(!$package)throw new RuntimeException('Subscription package is unavailable.');
    $recurring=in_array((string)($invoice['billing_reason']??''),['subscription_create','subscription_cycle'],true);
    [$start,$end]=$recurring?sf_subscription_invoice_period($invoice,$package):[0,0];
    $pdo=sf_db();$pdo->beginTransaction();$isNew=false;
    try {
        sf_subscription_lock_user($pdo,$uid);
        $q=$pdo->prepare('SELECT * FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);$fresh=$q->fetch();
        if(!$fresh || (string)$fresh['provider_subscription_id']!==$subId || (int)$fresh['package_id']!==$pkg
            || (string)$fresh['provider_customer_id']!==(string)($invoice['customer']??''))
            throw new RuntimeException('Invoice billing identity changed. Reconcile and retry.');
        $q=$pdo->prepare('SELECT user_id,provider_subscription_id FROM billing_invoices WHERE provider_invoice_id=?');$q->execute([$invoiceId]);$prior=$q->fetch();
        if($prior&&((int)$prior['user_id']!==$uid||(string)$prior['provider_subscription_id']!==$subId))
            throw new RuntimeException('Invoice is already associated with a different billing identity.');
        $record=$invoice;if($recurring){$record['period_start']=$start;$record['period_end']=$end;}
        $isNew=sf_billing_store_invoice($uid,$pkg,$record,$subId);
        $now=gmdate('c');$current=$recurring&&$start<=time()&&$end>time()
            && $end>=sf_subscription_deadline($fresh['current_period_end']??null)
            && !in_array((string)$fresh['status'],['canceled','incomplete_expired'],true);
        if($current) {
            $pdo->prepare("UPDATE user_subscriptions SET status='active',current_period_start=?,current_period_end=?,grace_ends_at=NULL,last_invoice_id=?,last_payment_at=?,updated_at=? WHERE user_id=?")
                ->execute([gmdate('c',$start),gmdate('c',$end),$invoiceId,sf_timestamp_iso($invoice['status_transitions']['paid_at']??0)??$now,$now,$uid]);
            $source='stripe_period:'.hash('sha256',$subId.'|'.$start.'|'.$end);
            // Existing v1.3.235 invoice credits remain authoritative when an alias is replayed.
            if(!sf_token_source_exists($uid,'monthly_grant','stripe_invoice:'.$invoiceId)) {
                $tokens=max(0,(int)$package['monthly_tokens']);
                if($tokens>0)sf_token_adjust($uid,$tokens,'monthly_grant',$source,[],'Stripe subscription period allocation');
            }
        }
        sf_billing_log($uid,'invoice_paid','stripe',$invoiceId,['new_invoice'=>$isNew,'period_eligible'=>$current,'billing_reason'=>$invoice['billing_reason']??'']);
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('sf_membership_sync_crm'))sf_membership_sync_crm($uid);
    if($isNew)sf_billing_notify($uid,'Stonefellow subscription payment received','Your payment was recorded. Open My Account to review your invoice and current membership.','subscription_payment');
}

function sf_subscription_resume(array $user): array {
    sf_billing_ensure_schema();$uid=(int)$user['id'];$sub=sf_billing_subscription_row($uid);
    if(!$sub)throw new RuntimeException('No subscription is available.');
    sf_subscription_require_resumable($sub);
    $linked=sf_subscription_provider_linked($sub);
    if($linked) {
        $id=(string)($sub['provider_subscription_id']??'');
        if($id==='')throw new RuntimeException('Stripe subscription identity is missing. Manage billing first.');
        $result=sf_stripe_request('POST','subscriptions/'.rawurlencode($id),['cancel_at_period_end'=>'false']);
        if((string)($result['id']??'')!==$id || !array_key_exists('cancel_at_period_end',$result)
            || $result['cancel_at_period_end']!==false || !in_array((string)($result['status']??''),['active','trialing'],true))
            throw new RuntimeException('Stripe did not confirm reversal of the scheduled cancellation. Refresh billing before retrying.');
    }
    sf_subscription_require_resumable($sub);
    $pdo=sf_db();$pdo->beginTransaction();
    try {
        $q=$pdo->prepare('UPDATE user_subscriptions SET cancel_at_period_end=0,canceled_at=NULL,updated_at=? WHERE user_id=? AND package_id=? AND status=? AND cancel_at_period_end=1 AND COALESCE(current_period_end,\'\')=? AND COALESCE(trial_ends_at,\'\')=? AND COALESCE(provider_subscription_id,\'\')=?');
        $q->execute([gmdate('c'),$uid,(int)$sub['package_id'],(string)$sub['status'],(string)($sub['current_period_end']??''),(string)($sub['trial_ends_at']??''),(string)($sub['provider_subscription_id']??'')]);
        if($q->rowCount()!==1)throw new RuntimeException('Subscription changed while resuming. Refresh billing to confirm its current state.');
        sf_billing_log($uid,'resume',$linked?'stripe':'local',(string)($sub['provider_subscription_id']??''));
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    sf_billing_notify($uid,'Stonefellow subscription resumed','Your scheduled cancellation has been reversed.','subscription_resumed');
    return sf_entitlement_state($uid);
}
function sf_subscription_enroll_user(int $userId,?int $packageId=null): array {
    sf_entitlements_ensure_schema();sf_billing_ensure_schema();
    $p=$packageId?sf_package($packageId):null;if(!$p){$rows=sf_packages(true);$p=$rows[0]??null;}
    if(!$p||empty($p['is_active'])||empty($p['is_public']))throw new InvalidArgumentException('Selected package is not available.');
    $pdo=sf_db();$pdo->beginTransaction();
    try {
        sf_subscription_lock_user($pdo,$userId);
        $q=$pdo->prepare('SELECT id FROM user_subscriptions WHERE user_id=?');$q->execute([$userId]);
        if($q->fetchColumn()===false) {
            $t=$pdo->prepare('SELECT user_id FROM user_trial_history WHERE user_id=?');$t->execute([$userId]);
            $days=$t->fetchColumn()!==false?0:max(0,(int)$p['trial_days']);$now=gmdate('c');
            $status=$days>0?'trialing':((int)$p['monthly_price_cents']===0?'active':'pending_payment');
            $trialEnd=$days>0?gmdate('c',time()+$days*86400):null;
            $end=$status==='active'?gmdate('c',strtotime('+1 month')):null;
            if($days>0)$pdo->prepare('INSERT INTO user_trial_history(user_id,first_trial_package_id,first_trial_started_at) VALUES(?,?,?)')->execute([$userId,(int)$p['id'],$now]);
            $pdo->prepare("INSERT INTO user_subscriptions(user_id,package_id,status,trial_started_at,trial_ends_at,current_period_start,current_period_end,created_at,updated_at,provider,cancel_at_period_end) VALUES(?,?,?,?,?,?,?,?,?,'local',0)")
                ->execute([$userId,(int)$p['id'],$status,$days?$now:null,$trialEnd,$status==='active'?$now:null,$end,$now,$now]);
            $grant=$days>0?max(0,(int)$p['trial_tokens']):($status==='active'?max(0,(int)$p['monthly_tokens']):0);
            if($grant>0)sf_token_adjust($userId,$grant,$days>0?'trial_grant':'monthly_grant','package:'.$p['slug'],[],$days>0?'Free trial allocation':'Monthly allocation');
        }
        $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // Enrollment is initial-only: an existing subscription is never overwritten.
    return sf_entitlement_state($userId);
}

/** Normalize pre-Basil and item-level periods without guessing across mixed intervals. */
function sf_subscription_provider_periods(array $object): array {
    $start=(int)($object['current_period_start']??0);$end=(int)($object['current_period_end']??0);
    if($start>0&&$end>$start)return $object;
    $periods=[];
    foreach((array)($object['items']['data']??[]) as $item){
        if(!is_array($item))continue;
        $a=(int)($item['current_period_start']??0);$b=(int)($item['current_period_end']??0);
        if($a>0&&$b>$a)$periods[$a.':'.$b]=[$a,$b];
    }
    if(empty($object['items']['has_more'])&&count($periods)===1){
        [$object['current_period_start'],$object['current_period_end']]=array_values($periods)[0];
    }
    return $object;
}
