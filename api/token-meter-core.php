<?php
declare(strict_types=1);

/** Read-only account meter: never enroll, renew, grant, settle or initialize billing. */
function sf_token_meter_table_exists(PDO $db,string $name): bool {
    if(!in_array($name,['user_token_balances','ai_token_reservations','user_subscriptions','token_ledger'],true))throw new InvalidArgumentException('Unknown token meter table.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    }else{
        $q=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    }
    $q->execute([$name]);$exists=$q->fetchColumn()!==false;$q->closeCursor();return $exists;
}
function sf_token_meter_date(mixed $date): ?int {
    if(!is_string($date)||trim($date)==='')return null;
    $value=strtotime($date);return $value===false?null:$value;
}
function sf_token_meter(array $user,?int $now=null): array {
    $uid=(int)($user['id']??0);if($uid<1)throw new DomainException('Sign in to view token availability.');
    $db=sf_db();if($db->inTransaction())throw new LogicException('Token meter requires a fresh read snapshot.');
    $now??=time();
    // A consistent read prevents a balance before settlement being mixed with holds after it.
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT role,status FROM users WHERE id=?');$q->execute([$uid]);$account=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$account||$account['status']!=='active')throw new DomainException('This account is unavailable.');
        $result=['user_id'=>$uid,'unlimited'=>$account['role']==='admin','available_tokens'=>null,'held_tokens'=>0,
            'allowance_tokens'=>null,'allowance_basis'=>null,'allowance_label'=>'No current credited allowance',
            'percentage_remaining'=>null,'bar_percentage'=>null,'as_of'=>gmdate('c',$now)];
        if($result['unlimited']){$result['allowance_label']='Unlimited administrator access';$db->commit();return $result;}
        if(!sf_token_meter_table_exists($db,'user_token_balances'))throw new RuntimeException('Token balance storage is not available.');
        $q=$db->prepare('SELECT balance FROM user_token_balances WHERE user_id=?');$q->execute([$uid]);$balance=$q->fetchColumn();$q->closeCursor();
        // No balance row is the canonical zero-token state; an absent table is unavailable.
        $result['available_tokens']=$balance===false?0:max(0,(int)$balance);
        if(sf_token_meter_table_exists($db,'ai_token_reservations')){
            $q=$db->prepare("SELECT COALESCE(SUM(reserved),0) FROM ai_token_reservations WHERE user_id=? AND status='held'");$q->execute([$uid]);$result['held_tokens']=max(0,(int)$q->fetchColumn());$q->closeCursor();
        }
        if(sf_token_meter_table_exists($db,'user_subscriptions')&&sf_token_meter_table_exists($db,'token_ledger')){
            $q=$db->prepare('SELECT * FROM user_subscriptions WHERE user_id=? LIMIT 1');$q->execute([$uid]);$subscription=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($subscription){
                $trial=$subscription['status']==='trialing';$basis=$trial?'trial':'plan';
                $start=sf_token_meter_date($subscription[$trial?'trial_started_at':'current_period_start']??null);
                $end=sf_token_meter_date($subscription[$trial?'trial_ends_at':'current_period_end']??null);
                if(in_array($subscription['status'],['trialing','active','past_due'],true)&&$start!==null&&$end!==null&&$start<=$now&&$now<$end){
                    $type=$trial?'trial_grant':'monthly_grant';$params=[$uid,$type,gmdate('c',$start),gmdate('c',$end)];$where='';
                    // Stripe grants have an exact immutable period identity; unrelated local credits cannot stand in for them.
                    if(!$trial&&(($subscription['provider']??'local')==='stripe'||($subscription['provider_subscription_id']??'')!=='')){
                        $source='stripe_period:'.hash('sha256',(string)$subscription['provider_subscription_id'].'|'.$start.'|'.$end);
                        $where=' AND (source=?';$params[]=$source;
                        if(!empty($subscription['last_invoice_id'])){$where.=' OR source=?';$params[]='stripe_invoice:'.$subscription['last_invoice_id'];}
                        $where.=')';
                    }
                    $q=$db->prepare('SELECT delta FROM token_ledger WHERE user_id=? AND entry_type=? AND delta>0 AND created_at>=? AND created_at<?'.$where.' ORDER BY id DESC LIMIT 1');$q->execute($params);$allocation=$q->fetchColumn();$q->closeCursor();
                    if($allocation!==false&&(int)$allocation>0){
                        $result['allowance_tokens']=(int)$allocation;$result['allowance_basis']=$basis;
                        $result['allowance_label']=$trial?'Current credited trial allowance':'Current credited plan allowance';
                        // Extra grants/carryover may exceed the allowance; preserve the numeric percentage.
                        $result['percentage_remaining']=round($result['available_tokens']/$result['allowance_tokens']*100,1);
                        $result['bar_percentage']=max(0,min(100,$result['percentage_remaining']));
                    }
                }
            }
        }
        $db->commit();return $result;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
