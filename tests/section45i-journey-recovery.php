<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $length=120):string{return substr(trim((string)$v),0,$length);}
function sf_crm_ensure_schema():void{sf_db()->exec('CREATE TABLE IF NOT EXISTS fan_contacts(id INTEGER PRIMARY KEY)');}
function sf_campaign_ensure_schema():void{sf_db()->exec('CREATE TABLE IF NOT EXISTS campaign_segments(id INTEGER PRIMARY KEY)');}
require dirname(__DIR__).'/api/automation-core.php';
sf_automation_ensure_schema();
$db->exec('INSERT INTO fan_contacts VALUES(101)');
$a=sf_automation_save(['name'=>'Recovery fixture','status'=>'published','steps'=>[['type'=>'exit','config'=>[]]]],1);
$id=(int)$a['id'];$now=gmdate('c');$old=gmdate('c',time()-10800);
$q=$db->prepare('INSERT INTO lifecycle_automation_runs(automation_id,contact_id,status,current_step,dedupe_key,details_json,started_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
$q->execute([$id,101,'running',0,'stale-test','{}',$old,$old]);$staleId=(int)$db->lastInsertId();
$q->execute([$id,101,'running',0,'fresh-test','{}',$now,$now]);$freshId=(int)$db->lastInsertId();
$q->execute([$id,101,'waiting',1,'waiting-test','{}',$old,$old]);$waitingId=(int)$db->lastInsertId();
$bad=0;$check=function(bool $p,string $n)use(&$bad):void{echo ($p?'PASS':'FAIL').' '.$n.PHP_EOL;if(!$p)$bad++;};
$check(sf_automation_quarantine_stale()===1,'Only stale running job quarantined');
$status=function(int $runId)use($db):string{$q=$db->prepare('SELECT status FROM lifecycle_automation_runs WHERE id=?');$q->execute([$runId]);return (string)$q->fetchColumn();};
$check($status($staleId)==='review_required','Stale worker enters manual review');
$check($status($freshId)==='running','Recently running worker protected');
$check($status($waitingId)==='waiting','Waiting worker never prematurely quarantined');
$check(sf_automation_quarantine_stale()===0,'Repeated quarantine cannot double-process job');
$check(sf_automation_continue($staleId)['status']==='review_required','Uncertain job cannot resume or send');
$check(sf_automation_continue($waitingId)['status']==='waiting','Direct waiting job cannot bypass scheduler claim');
try{sf_automation_acknowledge_interrupted($staleId,'yes');$check(false,'Exact acknowledgment required');}
catch(InvalidArgumentException $e){$check(true,'Exact acknowledgment required');}
$check(sf_automation_acknowledge_interrupted($staleId,'REVIEWED'),'Owner can acknowledge only quarantined work');
$check($status($staleId)==='reviewed_no_retry','Reviewed job is terminal');
try{sf_automation_acknowledge_interrupted($staleId,'REVIEWED');$check(false,'Duplicate acknowledgment not accepted');}
catch(RuntimeException $e){$check(true,'Duplicate acknowledgment not accepted');}
$check(sf_automation_continue($staleId)['status']==='reviewed_no_retry','Reviewed job never resumes');
$admin=(string)file_get_contents(dirname(__DIR__).'/admin/api/automations.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/automations.js');
$check(str_contains($admin,'sf_automation_acknowledge_interrupted('),'Only authenticated Admin endpoint acknowledges');
$check(str_contains($ui,'data-run-reconcile'),'Owner UI shows manual review action');
$check(str_contains($ui,'This does not undo or retry any action'),'UI does not promise rollback or retry');
exit($bad?1:0);
