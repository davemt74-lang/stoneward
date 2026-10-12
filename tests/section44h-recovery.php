<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $s,int $n=120):string{return substr(trim((string)$s),0,$n);}
function sf_campaign_graph_default():array{return ['nodes'=>[],'edges'=>[]];}
function sf_campaign_graph_validate(array $g):array{return ['ok'=>true,'errors'=>[],'graph'=>$g];}
function sf_campaign_save(array $p,int $admin):array{global $saves;$saves++;return ['id'=>5,'name'=>$p['name']];}
require dirname(__DIR__).'/api/agent-actions-core.php';
$saves=0;$fail=0;
$check=function(bool $ok,string $msg)use(&$fail):void{echo ($ok?'PASS':'FAIL').' '.$msg.PHP_EOL;if(!$ok)$fail++;};
$a=sf_agent_action_prepare(1,'cancel-pending-key-0001','campaign_draft_save',['name'=>'Pending']);
$cancelled=sf_agent_action_cancel(1,$a['id'],'CANCEL');
$check($cancelled['status']==='cancelled','Unexecuted proposal cancelled');
$check(sf_agent_action_cancel(1,$a['id'],'CANCEL')['status']==='cancelled','Cancellation idempotent');
try{sf_agent_action_execute(1,$a['id']);$check(false,'Cancelled cannot execute');}catch(RuntimeException $e){$check(true,'Cancelled cannot execute');}
$b=sf_agent_action_prepare(1,'cancel-approved-key-0002','campaign_draft_save',['name'=>'Approved']);
sf_agent_action_approve(1,$b['id'],$b['payload_hash'],'APPROVE');
$check(sf_agent_action_cancel(1,$b['id'],'CANCEL')['status']==='cancelled','Approved but unclaimed draft cancellable');
$c=sf_agent_action_prepare(1,'started-work-key-00003','campaign_draft_save',['name'=>'Ambiguous']);
sf_agent_action_approve(1,$c['id'],$c['payload_hash'],'APPROVE');
$db->prepare("UPDATE agent_action_proposals SET status='executing' WHERE id=?")->execute([$c['id']]);
try{sf_agent_action_cancel(1,$c['id'],'CANCEL');$check(false,'Started action cannot cancel');}catch(RuntimeException $e){$check(true,'Started action cannot cancel');}
try{sf_agent_action_reconcile(1,$c['id'],'WRONG');$check(false,'Exact reconcile required');}catch(InvalidArgumentException $e){$check(true,'Exact reconcile required');}
try{sf_agent_action_reconcile(2,$c['id'],'RECONCILED');$check(false,'Other admin cannot reconcile');}catch(InvalidArgumentException $e){$check(true,'Other admin cannot reconcile');}
try{sf_agent_action_reconcile(1,$c['id'],'RECONCILED');$check(false,'Active operation cannot be reconciled');}catch(RuntimeException $e){$check(true,'Active operation cannot be reconciled');}
$db->prepare('UPDATE agent_action_proposals SET approved_at=? WHERE id=?')->execute(['2000-01-01T00:00:00Z',$c['id']]);
$reviewed=sf_agent_action_reconcile(1,$c['id'],'RECONCILED');
$check($reviewed['status']==='reviewed_no_retry','Started action reconciled manually');
$check(sf_agent_action_reconcile(1,$c['id'],'RECONCILED')['status']==='reviewed_no_retry','Reconcile idempotent');
try{sf_agent_action_execute(1,$c['id']);$check(false,'Reconciled never replayed');}catch(RuntimeException $e){$check(true,'Reconciled never replayed');}
$check($saves===0,'Recovery actions never create campaign');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-actions.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-actions.js');
$check(str_contains($api,"sf_agent_action_reconcile("),'Reconciliation controller available');
$check(str_contains($ui,'data-agent-reconcile-id'),'Manual review visible in Agent UI');
$check(str_contains($ui,'This does NOT undo anything.'),'No rollback promised');
exit($fail?1:0);
