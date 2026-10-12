<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{global $pdo;return $pdo;}
function sf_campaign_ensure_schema():void{}
function sf_automation_ensure_schema():void{}
function sf_campaign_decode(mixed $v,array $fallback=[]):array{$d=json_decode((string)$v,true);return is_array($d)?$d:$fallback;}
require dirname(__DIR__).'/api/growth-analytics-core.php';
$pdo->exec('CREATE TABLE campaigns(id INTEGER PRIMARY KEY,name TEXT,goal TEXT,status TEXT)');
$pdo->exec('CREATE TABLE campaign_participants(id INTEGER PRIMARY KEY,campaign_id INTEGER,entered_at TEXT,converted_at TEXT)');
$pdo->exec('CREATE TABLE campaign_events(id INTEGER PRIMARY KEY,campaign_id INTEGER,created_at TEXT,event_type TEXT,metadata_json TEXT)');
$pdo->exec('CREATE TABLE lifecycle_automations(id INTEGER PRIMARY KEY,name TEXT)');
$pdo->exec('CREATE TABLE lifecycle_automation_runs(id INTEGER PRIMARY KEY,automation_id INTEGER,status TEXT,started_at TEXT)');
$now=gmdate('c');$old=gmdate('c',time()-45*86400);
$pdo->exec("INSERT INTO campaigns VALUES(1,'Newsletter','newsletter_growth','published'),(2,'Merch Offer','merch_sales','published')");
$q=$pdo->prepare('INSERT INTO campaign_participants(campaign_id,entered_at,converted_at) VALUES(?,?,?)');
foreach([[1,$now,$now],[1,$now,null],[2,$now,null],[2,$old,$old]] as $p)$q->execute($p);
$q=$pdo->prepare('INSERT INTO campaign_events(campaign_id,created_at,event_type,metadata_json) VALUES(?,?,?,?)');
foreach([[1,$now,'purchase_attributed','order-A',1000],[2,$now,'purchase_attributed','order-A',9000],[2,$now,'purchase_attributed','order-B',2200],[1,$old,'purchase_attributed','old-order',10000]] as [$id,$date,$type,$order,$amount])$q->execute([$id,$date,$type,json_encode(['order_id'=>$order,'total_cents'=>$amount])]);
$pdo->exec("INSERT INTO lifecycle_automations VALUES(1,'Welcome journey'),(2,'Reactivation')");
$q=$pdo->prepare('INSERT INTO lifecycle_automation_runs(automation_id,status,started_at) VALUES(?,?,?)');
foreach([[1,'completed',$now],[1,'failed',$now],[2,'waiting',$now],[1,'completed',$old]] as $row)$q->execute($row);
$c=sf_growth_campaigns(30);$a=sf_growth_automation(30);
$checks=[
 'Growth funnel preserves same-period participant cohort'=>$c['participants']===3&&$c['converted']===1,
 'Growth conversion rate bounded'=>$c['conversion_rate']===33.3,
 'Attributed same order only once across two campaigns'=>$c['attributed_orders']===2,
 'Earlier event receives unique order attribution'=>$c['campaigns'][0]['attributed_orders']===1,
 'Unique attributed revenue not inflated by duplicate campaign'=>$c['attributed_revenue_cents']===3200,
 'Legacy older revenue excluded'=>$c['attributed_revenue_cents']<10000,
 'Attribution output explains method'=>str_contains($c['attribution_method'],'not causal credit'),
 'No order IDs leaked to dashboard'=>!str_contains(json_encode($c),'order-A')&&!str_contains(json_encode($c),'order-B'),
 'No truncation falsely reported for small dataset'=>$c['attribution_truncated']===false,
 'Lifecycle analytics query uses actual started_at schema'=>$a['runs']===3&&$a['completed']===1&&$a['failed']===1&&$a['waiting']===1,
 'Lifecycle completion rate correct'=>$a['completion_rate']===33.3,
 'Multiple lifecycle cohorts retained'=>count($a['automations'])===2
];
$bad=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$bad++;}
exit($bad?1:0);
