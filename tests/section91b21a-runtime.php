<?php
declare(strict_types=1);
/** Disposable real-database acceptance. No provider credentials or network calls. */
ob_start();
$root=dirname(__DIR__);$worker=($argv[1]??'')==='worker';
$fixture=$worker?(string)$argv[2]:sys_get_temp_dir().'/sf91b21a-'.bin2hex(random_bytes(6));
$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true))throw new RuntimeException('Unsupported isolated test engine.');
if(!extension_loaded('pdo_'.$driver))throw new RuntimeException('Real PDO '.$driver.' is required.');
if(!$worker){mkdir($fixture.'/config',0700,true);mkdir($fixture.'/sessions',0700,true);
    $cfg=$driver==='sqlite'?['driver'=>'sqlite','path'=>$fixture.'/test.sqlite']:['driver'=>'mysql','host'=>'127.0.0.1','port'=>33306,'database'=>'stonefellow_91b21a_test','username'=>'root','password'=>(string)getenv('SF_TEST_MYSQL_PASSWORD')];
    if($driver==='mysql'&&$cfg['password']==='')throw new RuntimeException('Isolated MySQL password required.');
    file_put_contents($fixture.'/config/database.php','<?php return '.var_export($cfg,true).';');
}
define('SF_ROOT',$fixture);
ini_set('session.save_path',$fixture.'/sessions');
putenv('STONEFELLOW_STRIPE_CONNECT_CLIENT_ID=');putenv('STONEFELLOW_STRIPE_CONNECT_PLATFORM_SECRET=');
$GLOBALS['test_meta']=[];
function sf_ai_meta_get(string $key,string $default=''):string{return (string)($GLOBALS['test_meta'][$key]??$default);}
function sf_ai_meta_set(string $key,string $value):void{$GLOBALS['test_meta'][$key]=$value;}
function sf_store_config():array{return ['mode'=>'test'];}
function sf_crm_sync_user(int $id):?array{return null;}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
require $root.'/api/database.php';require $root.'/api/lifecycle.php';
require $root.'/api/entitlements.php';require $root.'/api/billing.php';require $root.'/api/membership-core.php';
$pdo=sf_db();if($driver==='sqlite')$pdo->exec('PRAGMA busy_timeout=15000');
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function rejects(callable $fn,string $label):void{try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function row(int $uid):array{$q=sf_db()->prepare('SELECT * FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);return $q->fetch()?:[];}
function seed(int $uid,array $fields=[]):void {
    $now=gmdate('c');$defaults=['package_id'=>100,'status'=>'active','provider'=>'local','provider_customer_id'=>'','provider_subscription_id'=>'','current_period_start'=>$now,'current_period_end'=>gmdate('c',time()+3600),'trial_ends_at'=>null,'cancel_at_period_end'=>0,'grace_ends_at'=>null];
    $data=array_replace($defaults,$fields);$q=sf_db()->prepare('SELECT id FROM user_subscriptions WHERE user_id=?');$q->execute([$uid]);
    if($q->fetchColumn()===false)sf_db()->prepare('INSERT INTO user_subscriptions(user_id,package_id,status,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$uid,100,'active',$now,$now]);
    $sql=[];$values=[];foreach($data as $k=>$v){$sql[]=$k.'=?';$values[]=$v;}$values[]=$uid;
    sf_db()->prepare('UPDATE user_subscriptions SET '.implode(',',$sql).' WHERE user_id=?')->execute($values);
}
function invoice(int $uid,string $id,int $start,int $end,string $reason='subscription_cycle'):array {
    return ['id'=>$id,'status'=>'paid','customer'=>'cus_'.$uid,'subscription'=>'sub_'.$uid,'amount_paid'=>1000,'currency'=>'usd','billing_reason'=>$reason,'period_start'=>$start,'period_end'=>$end,'lines'=>['data'=>[['type'=>'subscription','price'=>['id'=>'price_monthly'],'proration'=>false,'period'=>['start'=>$start,'end'=>$end]]],'has_more'=>false]];
}
function event(array $invoice,string $name,string $id):void{sf_billing_process_stripe_event(['id'=>$id,'type'=>$name,'livemode'=>false,'data'=>['object'=>$invoice]],hash('sha256',$id));}
function login_browser(int $uid):array {
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$_COOKIE=[];session_id('');
    sf_login_user(sf_user_by_email('user'.$uid.'@example.invalid'));
    $b=['id'=>session_id(),'token'=>sf_auth_cookie_value()];session_write_close();return $b;
}
function use_browser(array $b):void {
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $_COOKIE=[SF_AUTH_COOKIE=>$b['token']];session_id($b['id']);sf_user_session();
}
if($worker){
    sf_billing_ensure_schema();sf_membership_ensure_schema();sf_customer_lifecycle_ensure_schema();
    $number=(int)$argv[3];file_put_contents($fixture.'/ready'.$number,'1');
    $until=microtime(true)+15;while(!is_file($fixture.'/go')&&microtime(true)<$until)usleep(10000);
    if(!is_file($fixture.'/go'))throw new RuntimeException('Race barrier timeout');
    $inv=json_decode(file_get_contents($fixture.'/race.json'),true,512,JSON_THROW_ON_ERROR);
    event($inv,$number===1?'invoice.paid':'invoice.payment_succeeded','evt_race_'.$number);
    echo "Worker $number complete\n";exit;
}
$pdo->exec($driver==='sqlite'?"CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT,password_hash TEXT)":"CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(100),role VARCHAR(30),status VARCHAR(30),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40),password_hash VARCHAR(255)) ENGINE=InnoDB");
$insert=$pdo->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at,password_hash) VALUES(?,?,?,?,?,?,?,?)');
for($i=1;$i<=10;$i++)$insert->execute([$i,'user'.$i.'@example.invalid','Fixture '.$i,'user','active',gmdate('c'),gmdate('c'),password_hash('fixture-password',PASSWORD_DEFAULT)]);
sf_customer_lifecycle_ensure_schema();sf_billing_ensure_schema();sf_membership_ensure_schema();sf_auth_ensure_schema();
$pdo->prepare("INSERT INTO subscription_packages(id,name,slug,description,monthly_price_cents,monthly_tokens,trial_days,trial_tokens,is_public,is_active,is_featured,sort_order,features_json,created_at,updated_at,provider_price_id,membership_enabled,membership_rank,membership_badge,membership_benefits_json) VALUES(?,?,?,?,?,?,?,?,1,1,0,1,'[]',?,?,'price_monthly',1,1,'Member','{}')")
    ->execute([100,'Paid','paid','Fixture',1000,100,0,0,gmdate('c'),gmdate('c')]);
$pdo->prepare("INSERT INTO subscription_packages(id,name,slug,description,monthly_price_cents,monthly_tokens,trial_days,trial_tokens,is_public,is_active,is_featured,sort_order,features_json,created_at,updated_at,provider_price_id,membership_enabled,membership_rank,membership_badge,membership_benefits_json) VALUES(?,?,?,?,?,?,?,?,1,1,0,1,'[]',?,?,'',1,1,'Free','{}')")
    ->execute([101,'Free','free','Fixture',0,10,0,0,gmdate('c'),gmdate('c')]);
seed(1,['cancel_at_period_end'=>1]);$before=sf_token_balance(1);sf_billing_resume(['id'=>1]);
check(row(1)['status']==='active'&&(int)row(1)['cancel_at_period_end']===0,'Valid local cancellation reversal succeeds');
check(sf_token_balance(1)===$before,'Resume creates no token grant');
rejects(fn()=>sf_billing_resume(['id'=>1]),'Repeated resume rejected');
foreach(['pending_payment','trial_expired','canceled','unpaid'] as $status){seed(1,['status'=>$status,'cancel_at_period_end'=>1]);rejects(fn()=>sf_billing_resume(['id'=>1]),'Invalid resume rejected for '.$status);check(row(1)['status']===$status,'Invalid resume preserves '.$status);}
seed(1,['current_period_end'=>gmdate('c',time()-10),'cancel_at_period_end'=>1]);
check(!sf_membership_state(1)['active'],'Expired canceled period denied before entitlement read');
rejects(fn()=>sf_billing_resume(['id'=>1]),'Elapsed cancellation cannot be resumed');
check(sf_entitlement_state(1)['subscription']['status']==='canceled','Entitlement and membership agree on elapsed cancellation');
seed(1,['status'=>'trialing','trial_ends_at'=>gmdate('c',time()-1)]);
check(!sf_membership_state(1)['active'],'Trial expiry enforced without visiting Account');
check(sf_entitlement_state(1)['subscription']['status']==='trial_expired','Expired trial stays expired');
seed(1,['current_period_end'=>gmdate('c',time()-10)]);$before=sf_token_balance(1);
check(sf_entitlement_state(1)['subscription']['status']==='expired'&&sf_token_balance(1)===$before,'Paid local plan cannot silently renew or grant tokens');
seed(1,['package_id'=>101,'current_period_end'=>gmdate('c',time()-20)]);sf_entitlement_state(1);$balance=sf_token_balance(1);sf_entitlement_state(1);
check($balance===$before+10&&sf_token_balance(1)===$balance,'Free local period renews exactly once');
seed(2,['provider'=>'stripe','provider_customer_id'=>'cus_2','provider_subscription_id'=>'sub_2']);$original=row(2);
rejects(fn()=>sf_activate_package(2,101),'Admin cannot replace a Stripe package locally');
check(row(2)===$original,'Blocked assignment preserves every billing field');
rejects(fn()=>sf_billing_checkout(['id'=>2,'email'=>'user2@example.invalid'],101),'Free checkout routes to managed billing instead of local activation');
check(row(2)===$original,'Free checkout preserves Stripe identity');
sf_enroll_user(2,101);check(row(2)===$original,'Repeat enrollment cannot overwrite existing subscription');
rejects(fn()=>sf_billing_bind_stripe_subscription(2,100,'cus_wrong','sub_wrong','active',gmdate('c')),'Conflicting webhook identity rejected');
sf_token_adjust(2,25,'admin_grant','fixture-grant',[],'Complimentary');
check(sf_token_balance(2)===25&&row(2)===$original,'Free token grants retain managed billing');
$start=time()-60;$end=time()+7200;
seed(3,['status'=>'pending_payment','provider'=>'stripe','provider_customer_id'=>'cus_3','provider_subscription_id'=>'sub_3','current_period_end'=>null]);
$inv=invoice(3,'in_alias',$start,$end);event($inv,'invoice.payment_succeeded','evt_alias1');event($inv,'invoice.paid','evt_alias2');
check(sf_token_balance(3)===100,'Both Stripe payment events share one period allocation');
check((int)$pdo->query("SELECT COUNT(*) FROM billing_invoices WHERE provider_invoice_id='in_alias'")->fetchColumn()===1,'Both aliases share one invoice receipt');
$second=$inv;$second['id']='in_same_period';event($second,'invoice.paid','evt_period2');
check(sf_token_balance(3)===100,'Different invoices for one subscription period cannot double-grant');
$proration=invoice(3,'in_proration',$start,$end,'subscription_update');event($proration,'invoice.paid','evt_proration');
check(sf_token_balance(3)===100,'Proration receipt does not grant a full monthly allocation');
seed(3,['status'=>'canceled','provider'=>'stripe','provider_customer_id'=>'cus_3','provider_subscription_id'=>'sub_3']);
$late=invoice(3,'in_late',$start,$end);event($late,'invoice.paid','evt_late');
check(row(3)['status']==='canceled'&&sf_token_balance(3)===100,'Late paid receipt cannot reactivate canceled subscription');
seed(5,['provider'=>'stripe','provider_customer_id'=>'cus_5','provider_subscription_id'=>'sub_5']);
sf_token_adjust(5,100,'monthly_grant','stripe_invoice:in_legacy',[],'Legacy credit');event(invoice(5,'in_legacy',$start,$end),'invoice.payment_succeeded','evt_legacy');
check(sf_token_balance(5)===100,'Previously issued invoice-key credit is not reissued by alias');
seed(6,['provider'=>'stripe','provider_customer_id'=>'cus_6','provider_subscription_id'=>'sub_6']);
$bad=invoice(6,'in_mapping',$start,$end);$bad['lines']['data'][0]['price']['id']='price_unknown';
rejects(fn()=>event($bad,'invoice.paid','evt_mapping'),'Unknown price fails for explicit reconciliation');
check(sf_token_balance(6)===0&&$pdo->query("SELECT status FROM billing_webhook_events WHERE event_id='evt_mapping'")->fetchColumn()==='failed','Unknown mapping is failed, never falsely processed');
event(invoice(6,'in_mapping',$start,$end),'invoice.paid','evt_mapping');check(sf_token_balance(6)===100,'Failed invoice can be corrected and retried');
seed(7,['provider'=>'stripe','provider_customer_id'=>'cus_7','provider_subscription_id'=>'sub_7']);$original=row(7);
$pdo->exec($driver==='sqlite'?"CREATE TRIGGER reject_audit BEFORE INSERT ON billing_audit_log WHEN NEW.reference_id='in_rollback' BEGIN SELECT RAISE(ABORT,'Injected audit failure'); END":"CREATE TRIGGER reject_audit BEFORE INSERT ON billing_audit_log FOR EACH ROW BEGIN IF NEW.reference_id='in_rollback' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'; END IF; END");
rejects(fn()=>event(invoice(7,'in_rollback',$start,$end),'invoice.paid','evt_rollback'),'Injected audit failure propagates');
check(sf_token_balance(7)===0&&row(7)===$original,'Failed audit rolls back allocation and subscription state');
check((int)$pdo->query("SELECT COUNT(*) FROM billing_invoices WHERE provider_invoice_id='in_rollback'")->fetchColumn()===0,'Failed audit also rolls back invoice receipt');
$pdo->exec('DROP TRIGGER reject_audit');event(invoice(7,'in_rollback',$start,$end),'invoice.paid','evt_rollback');check(sf_token_balance(7)===100,'Retry after rollback grants once');
seed(8,['provider'=>'stripe','provider_customer_id'=>'cus_8','provider_subscription_id'=>'sub_8']);
file_put_contents($fixture.'/race.json',json_encode(invoice(8,'in_race',$start,$end)));$processes=[];
for($i=1;$i<=2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',$fixture,(string)$i],[0=>['pipe','r'],1=>['file',$fixture.'/worker'.$i.'.log','w'],2=>['file',$fixture.'/worker'.$i.'.err','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Unable to start concurrent worker');fclose($pipes[0]);$processes[]=$process;}
$until=microtime(true)+20;while((!is_file($fixture.'/ready1')||!is_file($fixture.'/ready2'))&&microtime(true)<$until)usleep(10000);
check(is_file($fixture.'/ready1')&&is_file($fixture.'/ready2'),'Two independent workers reached payment barrier');touch($fixture.'/go');
foreach($processes as $i=>$process){$rc=proc_close($process);if($rc!==0)throw new RuntimeException('Worker failed: '.file_get_contents($fixture.'/worker'.($i+1).'.err'));}
check(sf_token_balance(8)===100,'Concurrent payment aliases allocate exactly once');
check((int)$pdo->query("SELECT COUNT(*) FROM billing_invoices WHERE provider_invoice_id='in_race'")->fetchColumn()===1,'Concurrent payment aliases persist one receipt');
$trial=['id'=>'sub_10','customer'=>'cus_10','status'=>'trialing','metadata'=>['stonefellow_user_id'=>10,'stonefellow_package_id'=>100],'trial_start'=>$start,'trial_end'=>$end,'items'=>['data'=>[['current_period_start'=>$start,'current_period_end'=>$end]]]];
sf_billing_process_stripe_event(['id'=>'evt_trial','type'=>'customer.subscription.created','data'=>['object'=>$trial]]);
check(sf_membership_state(10)['active'],'Stripe trial and item period dates grant only current trial access');
check(sf_token_balance(10)===0,'Stripe trial event does not grant a paid monthly allocation');
$oneTime=invoice(10,'in_one_time',$start,$end);unset($oneTime['subscription']);event($oneTime,'invoice.paid','evt_one_time');
check(sf_token_balance(10)===0,'Unrelated invoice cannot become subscription credit');
$a=login_browser(4);$b=login_browser(4);use_browser($b);check(sf_current_user()['id']==4,'Second PHP session authenticates');sf_auth_revoke_other_sessions(4);
use_browser($a);check((int)($_SESSION['sf_user_id']??0)===4,'Old PHP session really retains cached identity before validation');
check(sf_current_user()===null&&!isset($_SESSION['sf_user_id']),'Revoked token overrides cached PHP identity');
use_browser($b);check(sf_current_user()['id']==4,'Revoking other sessions preserves current session');
$a=login_browser(4);$b=login_browser(4);$token=sf_account_action_token_issue(4,'password_reset',3600);sf_reset_password_token($token,'replacement-password','replacement-password');
use_browser($a);check(sf_current_user()===null,'Password reset revokes first existing PHP session');use_browser($b);check(sf_current_user()===null,'Password reset revokes second existing PHP session');
$c=login_browser(4);$pdo->prepare('UPDATE user_sessions SET expires_at=? WHERE token_hash=?')->execute([gmdate('c',time()-1),hash('sha256',$c['token'])]);use_browser($c);check(sf_current_user()===null,'Expired persistent token cannot retain cached authorization');
$d=login_browser(9);$token=sf_account_action_token_issue(9,'password_reset',3600);$old=sf_user_by_email('user9@example.invalid')['password_hash'];
$pdo->exec($driver==='sqlite'?"CREATE TRIGGER reject_revoke BEFORE DELETE ON user_sessions WHEN OLD.user_id=9 BEGIN SELECT RAISE(ABORT,'Injected revocation failure'); END":"CREATE TRIGGER reject_revoke BEFORE DELETE ON user_sessions FOR EACH ROW BEGIN IF OLD.user_id=9 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected revocation failure'; END IF; END");
rejects(fn()=>sf_reset_password_token($token,'new-fixture-password','new-fixture-password'),'Failed session revocation aborts password reset');
check(sf_user_by_email('user9@example.invalid')['password_hash']===$old,'Failed revocation rolls back password change');
check(sf_account_action_token_lookup('password_reset',$token)!==null,'Failed reset preserves retryable reset token');
$pdo->exec('DROP TRIGGER reject_revoke');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
echo "91B21A $driver runtime: $checks assertions passed\n";
