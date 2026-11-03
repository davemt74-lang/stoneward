from pathlib import Path
root=Path('.')
def patch(path, old, new, count=1):
    p=root/path;s=p.read_text();assert s.count(old)==count,(path,old[:80],s.count(old));p.write_text(s.replace(old,new))
def function(path,name,next_name,body):
    p=root/path;s=p.read_text();a=s.index('function '+name+'(');b=s.index('function '+next_name+'(',a);p.write_text(s[:a]+body+'\n'+s[b:])
for p in ['api/entitlements.php','api/billing.php','api/membership-core.php']:
    patch(p,'declare(strict_types=1);','declare(strict_types=1);\nrequire_once __DIR__.\'/subscription-policy.php\';')
function('api/entitlements.php','sf_enroll_user','sf_entitlement_state', '''function sf_enroll_user(int $userId,?int $packageId=null): array {
    return sf_subscription_enroll_user($userId,$packageId);
}''')
p=root/'api/entitlements.php';s=p.read_text();a=s.index('function sf_entitlement_state(');b=s.index('    $ledger=',a)
s=s[:a]+'''function sf_entitlement_state(int $userId): array {
    sf_entitlements_ensure_schema();sf_billing_ensure_schema();
    sf_subscription_refresh_local($userId);
    $q=sf_db()->prepare('SELECT s.*,p.name package_name,p.slug package_slug,p.monthly_price_cents,p.monthly_tokens,p.trial_days,p.trial_tokens,p.provider_price_id FROM user_subscriptions s LEFT JOIN subscription_packages p ON p.id=s.package_id WHERE s.user_id=? LIMIT 1');
    $q->execute([$userId]);$s=$q->fetch()?:null;
    if($s){$s['stored_status']=$s['status'];$s['status']=sf_subscription_effective_status($s);}
'''+s[b:];p.write_text(s)
patch('api/entitlements.php',"SELECT id,package_id,status,current_period_end FROM user_subscriptions WHERE user_id=? LIMIT 1", "SELECT * FROM user_subscriptions WHERE user_id=? LIMIT 1")
patch('api/entitlements.php',"        // A locked subscription check", "        sf_subscription_guard_local_assignment($existing);\n        // A locked subscription check")
patch('api/entitlements.php',"provider='local',provider_customer_id='',provider_subscription_id='',cancel_at_period_end=0", "provider='local',cancel_at_period_end=0")
function('api/billing.php','sf_billing_resume','sf_stripe_verify_signature', '''function sf_billing_resume(array $user): array {
    return sf_subscription_resume($user);
}''')
patch('api/billing.php',"throw new InvalidArgumentException('Package is unavailable.');if((int)$p['monthly_price_cents']<=0)", "throw new InvalidArgumentException('Package is unavailable.');$managed=sf_billing_subscription_row((int)$user['id']);if($managed&&sf_subscription_provider_linked($managed))return sf_billing_portal($user);if((int)$p['monthly_price_cents']<=0)")
p=root/'api/billing.php';s=p.read_text();a=s.index("        elseif($type==='invoice.paid'){");b=s.index("        elseif($type==='invoice.payment_failed')",a)
s=s[:a]+'''        elseif(in_array($type,['invoice.paid','invoice.payment_succeeded'],true)){
            sf_subscription_apply_paid_invoice($obj);
        }
'''+s[b:];p.write_text(s)
# A delayed creation/checkout event must not erase a known billing identity or cancellation.
patch('api/billing.php',"if($uid<=0)return;$pdo=sf_db();$q=$pdo->prepare('SELECT id FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);\n    if($q->fetchColumn()!==false)", """if($uid<=0||$customer===''||$subId==='')throw new InvalidArgumentException('Complete Stripe subscription identity is required.');
    $pdo=sf_db();$q=$pdo->prepare('SELECT * FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);$bound=$q->fetch();
    if($bound&&sf_subscription_provider_linked($bound)){
        if((string)$bound['provider_subscription_id']!==$subId||(string)$bound['provider_customer_id']!==$customer)
            throw new RuntimeException('A different Stripe billing relationship already exists. Reconcile before binding another subscription.');
        return;
    }
    if($bound)""")
function('api/membership-core.php','sf_membership_status_active','sf_membership_state', '''function sf_membership_status_active(array $sub): bool {
    return sf_subscription_access_active($sub);
}''')
patch('api/membership-core.php',"'status'=>(string)($sub['status']??'none')", "'status'=>$sub?sf_subscription_effective_status($sub):'none'")
# Continue supporting the existing endpoint subscription while enabling the canonical paid event.
patch('api/stripe-connect.php',"'invoice.payment_succeeded','invoice.payment_failed'", "'invoice.paid','invoice.payment_succeeded','invoice.payment_failed'")
patch('admin/api/packages.php',"sf_admin_require_auth($write);", "$me=sf_admin_require_auth($write);")
# Cached PHP identity is not independent authorization: validate its persistent token every time.
p=root/'api/database.php';s=p.read_text();a=s.index("    $id=(int)($_SESSION['sf_user_id']??0);",s.index('function sf_current_user('));b=s.index('    $q=sf_db()->prepare',a)
s=s[:a]+'''    $cachedId=(int)($_SESSION['sf_user_id']??0);
    $id=sf_auth_restore_user_id();
    if($id<1){unset($_SESSION['sf_user_id'],$_SESSION['sf_csrf']);return null;}
    if($cachedId!==$id){
        session_regenerate_id(true);
        $_SESSION['sf_user_id']=$id;
        $_SESSION['sf_csrf']=bin2hex(random_bytes(32));
    }
'''+s[b:];p.write_text(s)
patch('api/database.php',"if(strtotime((string)$row['expires_at'])<time())", "if(strtotime((string)$row['expires_at'])<=time())")
patch('api/database.php',"SELECT user_id,expires_at,user_agent_hash FROM user_sessions", "SELECT user_id,expires_at,user_agent_hash,last_seen_at FROM user_sessions")
patch('api/database.php',"        sf_db()->prepare('UPDATE user_sessions SET last_seen_at=?,user_agent_hash=? WHERE token_hash=?')->execute", "        if(strtotime((string)($row['last_seen_at']??''))<=time()-60)sf_db()->prepare('UPDATE user_sessions SET last_seen_at=?,user_agent_hash=? WHERE token_hash=?')->execute")
function('api/database.php','sf_auth_revoke_all_user_sessions','sf_auth_sessions', '''function sf_auth_revoke_all_user_sessions(int $userId): void {
    sf_auth_ensure_schema();
    sf_db()->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$userId]);
    if((int)($_SESSION['sf_user_id']??0)===$userId){
        unset($_SESSION['sf_user_id'],$_SESSION['sf_csrf']);sf_auth_forget_cookie();
    }
}''')
for p in ['version.php','stonefellow-v120.php','admin/index.php','service-worker.js','.htaccess','assets/js/app.js','assets/css/turntable-builder.css']:
    path=root/p;s=path.read_text();assert '1.3.235' in s,p;path.write_text(s.replace('1.3.235','1.3.236'))
# These fixtures now explicitly prove a current period; status-only access is deliberately denied.
p=root/'tests/v1322-section23-membership-vip.php'
if p.exists():
    patch(str(p),"sf_membership_status_active(['status'=>'active'])===true", "sf_membership_status_active(['status'=>'active','current_period_end'=>gmdate('c',time()+3600)])===true")
    patch(str(p),"sf_membership_status_active(['status'=>'trialing'])===true", "sf_membership_status_active(['status'=>'trialing','trial_ends_at'=>gmdate('c',time()+3600)])===true")
for name in ['tests/section91b14c-ios-lunar-mobile.cjs','tests/section91b20h-visual-style-library.test.cjs']:
    p=root/name
    if p.exists():
        s=p.read_text();p.write_text(s.replace('1.3.235','1.3.236').replace(r'1\.3\.235',r'1\.3\.236'))

# Password updates and their revocation commit together, or neither does.
patch('api/lifecycle.php',"$now=gmdate('c');$pdo=sf_db();$pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET password_hash=", "$now=gmdate('c');$pdo=sf_db();sf_auth_ensure_schema();$pdo->beginTransaction();try{$pdo->prepare('UPDATE users SET password_hash=")
patch('api/lifecycle.php',"$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}sf_auth_revoke_all_user_sessions((int)$row['user_id']);", "sf_auth_revoke_all_user_sessions((int)$row['user_id']);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}")
patch('api/auth.php',"    $q=sf_db()->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?');", "    sf_auth_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();\n    try {\n    $q=$pdo->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?');")
patch('api/auth.php',"    sf_auth_revoke_all_user_sessions((int)$u['id']);\n    session_regenerate_id(true);", "    sf_auth_revoke_all_user_sessions((int)$u['id']);$pdo->commit();\n    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}\n    session_regenerate_id(true);")

# Do not execute schema DDL while an invoice/allocation transaction is holding locks.
patch('api/entitlements.php',"    sf_entitlements_ensure_schema();$q=sf_db()->prepare('SELECT id FROM token_ledger", "    if(!sf_db()->inTransaction())sf_entitlements_ensure_schema();$q=sf_db()->prepare('SELECT id FROM token_ledger")
# Current Stripe payloads use subscription-item periods; retain legacy payload compatibility.
patch('api/billing.php',"    try{$obj=(array)($event['data']['object']??[]);", "    try{$obj=(array)($event['data']['object']??[]);\n        if(str_starts_with($type,'customer.subscription.'))$obj=sf_subscription_provider_periods($obj);")
patch('api/billing.php',"current_period_end=COALESCE(?,current_period_end),updated_at=? WHERE user_id=?", "current_period_end=COALESCE(?,current_period_end),trial_started_at=COALESCE(?,trial_started_at),trial_ends_at=COALESCE(?,trial_ends_at),updated_at=? WHERE user_id=?",2)
patch('api/billing.php',"->execute([$start,$end,$now,$uid]);", "->execute([$start,$end,sf_timestamp_iso($obj['trial_start']??0),sf_timestamp_iso($obj['trial_end']??0),$now,$uid]);")
patch('api/billing.php',"->execute([$status,$cancel,$canceled,$start,$end,$now,$uid]);", "->execute([$status,$cancel,$canceled,$start,$end,sf_timestamp_iso($obj['trial_start']??0),sf_timestamp_iso($obj['trial_end']??0),$now,$uid]);")
