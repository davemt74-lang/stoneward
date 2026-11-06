<?php
declare(strict_types=1);
function sf_db():PDO{return $GLOBALS['pdo'];}
require dirname(__DIR__).'/api/token-meter-core.php';
$checks=0;$now=strtotime('2040-01-15T12:00:00+00:00');
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function rejected(callable $run,string $type,string $label):void{try{$run();}catch(Throwable $e){if($e instanceof $type){check(true,$label);return;}throw $e;}throw new RuntimeException('Expected rejection: '.$label);}
function reset_meter():PDO{
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$GLOBALS['pdo']=$pdo;
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT);INSERT INTO users VALUES(1,'user','active'),(2,'user','active'),(3,'admin','active'),(4,'user','disabled')");
    $pdo->exec('CREATE TABLE user_token_balances(user_id INTEGER PRIMARY KEY,balance INTEGER,updated_at TEXT)');
    $pdo->exec('CREATE TABLE ai_token_reservations(user_id INTEGER,reserved INTEGER,status TEXT)');
    $pdo->exec('CREATE TABLE user_subscriptions(user_id INTEGER,status TEXT,trial_started_at TEXT,trial_ends_at TEXT,current_period_start TEXT,current_period_end TEXT,provider TEXT,provider_subscription_id TEXT,last_invoice_id TEXT)');
    $pdo->exec('CREATE TABLE token_ledger(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,delta INTEGER,entry_type TEXT,source TEXT,created_at TEXT)');
    return $pdo;
}
function balance(int $id,int $amount):void{sf_db()->prepare('INSERT OR REPLACE INTO user_token_balances VALUES(?,?,?)')->execute([$id,$amount,'2040-01-15T12:00:00+00:00']);}
function allocation(string $type,int $amount,string $source='fixture',string $date='2040-01-01T00:00:00+00:00'):void{sf_db()->prepare('INSERT INTO token_ledger(user_id,delta,entry_type,source,created_at) VALUES(1,?,?,?,?)')->execute([$amount,$type,$source,$date]);}
function subscription(string $status='active',string $provider='local',string $subId='',string $invoice=''):void{
    sf_db()->prepare('INSERT INTO user_subscriptions VALUES(?,?,?,?,?,?,?,?,?)')->execute([1,$status,'2040-01-01T00:00:00+00:00','2040-02-01T00:00:00+00:00','2040-01-01T00:00:00+00:00','2040-02-01T00:00:00+00:00',$provider,$subId,$invoice]);
}
$db=reset_meter();balance(1,700);balance(2,42);subscription();allocation('monthly_grant',1000);
$meter=sf_token_meter(['id'=>1],$now);
check($meter['user_id']===1&&$meter['available_tokens']===700&&$meter['percentage_remaining']===70.0&&$meter['bar_percentage']===70.0,'Current plan shows exact available tokens and percentage of actual credited allowance');
check($meter['allowance_tokens']===1000&&$meter['allowance_basis']==='plan','Plan denominator comes from immutable ledger allocation');
$db->exec("INSERT INTO ai_token_reservations VALUES(1,200,'held'),(1,500,'settled'),(2,999,'held')");
$meter=sf_token_meter(['id'=>1],$now);
check($meter['available_tokens']===700&&$meter['held_tokens']===200&&$meter['percentage_remaining']===70.0,'Escrow is already net and never deducted a second time; other/closed holds excluded');
$other=sf_token_meter(['id'=>2],$now);check($other['available_tokens']===42&&$other['held_tokens']===999&&$other['allowance_tokens']===null,'Another account gets only its own balance and reservations');
balance(1,1700);allocation('admin_grant',1000,'extra','2040-01-10T00:00:00+00:00');$meter=sf_token_meter(['id'=>1],$now);
check($meter['available_tokens']===1700&&$meter['percentage_remaining']===170.0&&$meter['bar_percentage']===100,'Top-ups and carryover can exceed100%; numeric value stays accurate while bar is bounded');
$db->exec("CREATE TABLE subscription_packages(id INTEGER,monthly_tokens INTEGER);INSERT INTO subscription_packages VALUES(1,999999)");
check(sf_token_meter(['id'=>1],$now)['allowance_tokens']===1000,'Later package allowance edits cannot rewrite the credited percentage baseline');
$db=reset_meter();balance(1,250);subscription('trialing');allocation('trial_grant',500);$meter=sf_token_meter(['id'=>1],$now);
check($meter['allowance_basis']==='trial'&&$meter['allowance_tokens']===500&&$meter['percentage_remaining']===50.0,'Trial uses actual trial grant and trial window');
$expired=sf_token_meter(['id'=>1],strtotime('2040-02-02T00:00:00+00:00'));
check($expired['available_tokens']===250&&$expired['percentage_remaining']===null,'Expired trial keeps exact balance without inventing a current allocation percentage');
$db->exec("UPDATE user_subscriptions SET status='active'");$expired=sf_token_meter(['id'=>1],strtotime('2040-02-02T00:00:00+00:00'));
check($expired['available_tokens']===250&&$expired['allowance_tokens']===null,'Expired local plan read does not renew or grant tokens');
check((int)$db->query('SELECT COUNT(*) FROM token_ledger')->fetchColumn()===1&&$db->query('SELECT status FROM user_subscriptions')->fetchColumn()==='active','Read leaves stored entitlement and ledger unchanged');
$db=reset_meter();balance(1,80);$meter=sf_token_meter(['id'=>1],$now);
check($meter['available_tokens']===80&&$meter['allowance_tokens']===null&&$meter['percentage_remaining']===null,'Grant-only account displays count with no fabricated percentage');
$admin=sf_token_meter(['id'=>3],$now);
check($admin['unlimited']&&$admin['available_tokens']===null&&$admin['percentage_remaining']===null,'Admin unlimited state has no fabricated count or percentage');
check(!sf_token_meter(['id'=>1,'role'=>'admin'],$now)['unlimited'],'Database role remains authoritative over supplied role hints');
$empty=sf_token_meter(['id'=>2],$now);check($empty['available_tokens']===0&&$empty['percentage_remaining']===null,'Existing balance storage with no account row is canonical zero');
rejected(fn()=>sf_token_meter([], $now),DomainException::class,'Guests cannot inspect a token account');
rejected(fn()=>sf_token_meter(['id'=>4], $now),DomainException::class,'Disabled account balance is not disclosed');
$db->exec('DROP TABLE user_token_balances');rejected(fn()=>sf_token_meter(['id'=>1],$now),RuntimeException::class,'Missing storage is unavailable rather than a false zero');
check(sf_token_meter(['id'=>3],$now)['unlimited'],'Unlimited administrator status does not depend on balance storage initialization');
$db=reset_meter();balance(1,600);subscription('active','stripe','sub-fixture','in-fixture');
allocation('monthly_grant',9999,'unrelated-credit');$meter=sf_token_meter(['id'=>1],$now);
check($meter['percentage_remaining']===null,'Unrelated allocation cannot stand in for a Stripe period credit');
$source='stripe_period:'.hash('sha256','sub-fixture|'.strtotime('2040-01-01T00:00:00+00:00').'|'.strtotime('2040-02-01T00:00:00+00:00'));allocation('monthly_grant',1200,$source);
check(sf_token_meter(['id'=>1],$now)['percentage_remaining']===50.0,'Stripe percentage binds the exact credited subscription period');
$db->exec('DELETE FROM token_ledger');allocation('monthly_grant',1000,'stripe_invoice:in-fixture');check(sf_token_meter(['id'=>1],$now)['percentage_remaining']===60.0,'Legacy exact Stripe invoice credit remains a valid baseline');
$db->exec('DELETE FROM token_ledger');allocation('monthly_grant',0);check(sf_token_meter(['id'=>1],$now)['percentage_remaining']===null,'Zero allocation never divides by zero');
$db->exec('DROP TABLE ai_token_reservations');$meter=sf_token_meter(['id'=>1],$now);check($meter['held_tokens']===0,'Uninitialized optional reservation storage means no held reservations');
$before=$db->query('SELECT total_changes()')->fetchColumn();$tables=$db->query("SELECT name,sql FROM sqlite_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$db->exec('PRAGMA query_only=ON');$meter=sf_token_meter(['id'=>1],$now);$db->exec('PRAGMA query_only=OFF');
check($db->query('SELECT total_changes()')->fetchColumn()===$before&&$db->query("SELECT name,sql FROM sqlite_master ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)===$tables,'Meter works under database read-only enforcement and writes no rows or schema');
$db->beginTransaction();rejected(fn()=>sf_token_meter(['id'=>1],$now),LogicException::class,'Caller transactions cannot supply a stale meter snapshot');check($db->inTransaction(),'Nested call rejection preserves the caller transaction');$db->rollBack();
echo "$checks token meter checks passed\n";
