<?php
declare(strict_types=1);
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db():PDO{global $pdo;return $pdo;}
function sf_crm_ensure_schema():void{
 $d=sf_db();$d->exec('CREATE TABLE IF NOT EXISTS fan_contacts(id INTEGER PRIMARY KEY,marketing_opt_in INTEGER)');
 $d->exec('CREATE TABLE IF NOT EXISTS fan_crm_events(id INTEGER PRIMARY KEY,contact_id INTEGER,event_type TEXT,created_at TEXT)');
}
function sf_campaign_ensure_schema():void{
 sf_db()->exec('CREATE TABLE IF NOT EXISTS campaign_participants(id INTEGER PRIMARY KEY,entered_at TEXT,converted_at TEXT)');
}
function sf_automation_ensure_schema():void{
 sf_db()->exec('CREATE TABLE IF NOT EXISTS lifecycle_automation_runs(id INTEGER PRIMARY KEY,started_at TEXT,status TEXT)');
}
require dirname(__DIR__).'/api/fan-journey-intelligence.php';
$now=gmdate('c');$past=gmdate('c',time()-40*86400);
sf_crm_ensure_schema();sf_campaign_ensure_schema();sf_automation_ensure_schema();
$pdo->exec("INSERT INTO fan_contacts VALUES(1,1),(2,0),(3,1)");
$q=$pdo->prepare('INSERT INTO fan_crm_events(contact_id,event_type,created_at) VALUES(?,?,?)');
foreach([[1,'listen_complete',$now],[1,'listen_start',$now],[2,'purchase',$now],[3,'newsletter_signup',$past]] as $ev)$q->execute($ev);
$q=$pdo->prepare('INSERT INTO campaign_participants(id,entered_at,converted_at) VALUES(?,?,?)');
$q->execute([1,$now,$now]);$q->execute([2,$now,null]);$q->execute([3,$past,$past]);
$q=$pdo->prepare('INSERT INTO lifecycle_automation_runs(started_at,status) VALUES(?,?)');
$q->execute([$now,'completed']);$q->execute([$now,'failed']);$q->execute([$past,'completed']);
$k=sf_fan_journey_counts(30);$c=$k['counts'];
$checks=[
 'Three contacts counted independently'=>$c['known_contacts']===3,
 'One listening contact despite two events'=>$c['listening_contacts']===1,
 'Two active contacts in period'=>$c['active_contacts']===2,
 'Only opted-in counted as newsletter subscriber'=>$c['newsletter_opted_in']===2,
 'Purchase contact counted'=>$c['purchasing_contacts']===1,
 'Campaign entry window respected'=>$c['campaign_participants']===2,
 'Conversion window respected'=>$c['campaign_conversions']===1,
 'Journey counts respect period'=>$c['journeys_started']===2&&$c['journeys_completed']===1&&$c['journeys_failed']===1,
 'Rates computed with real denominator'=>$k['rates']['campaign_conversion_pct']===50.0,
 'Rate never divides by zero'=>sf_fan_journey_counts(7)['rates']['journey_failure_pct']===50.0,
 'No identifiable contacts returned'=>!str_contains(json_encode($k),'email')&&!str_contains(json_encode($k),'display_name'),
 'No invented sequential funnel'=>str_contains(implode(' ',$k['measurement_notes']),'not a person-by-person sequential funnel'),
 'Days bound'=>sf_fan_journey_counts(900)['period_days']===365,
];
$bad=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$bad++;}
exit($bad?1:0);
