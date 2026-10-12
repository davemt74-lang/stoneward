<?php
declare(strict_types=1);
/** Section 44 full audit: security boundaries and realistic SQLite persistence. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $n=120):string{return substr(trim((string)$v),0,$n);}
function sf_campaign_graph_default():array{return ['nodes'=>[],'edges'=>[]];}
function sf_campaign_graph_validate(array $g):array{return ['ok'=>true,'graph'=>$g,'errors'=>[]];}
function sf_campaign_save(array $p,int $owner):array{global $writes;$writes++;return ['id'=>101,'name'=>$p['name']];}
require dirname(__DIR__).'/api/agent-actions-core.php';
$writes=0;$failed=0;
$check=function(bool $ok,string $name)use(&$failed):void{echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;};
$p=sf_agent_action_prepare(1,'section44-audit-race-0001','campaign_draft_save',['name'=>'Race']);
$check($p['status']==='proposed','Draft is only proposed before review');
$db->exec("CREATE TRIGGER block_approve BEFORE UPDATE OF status ON agent_action_proposals WHEN NEW.status='approved' BEGIN SELECT RAISE(IGNORE); END;");
try{sf_agent_action_approve(1,$p['id'],$p['payload_hash'],'APPROVE');$check(false,'Approval race rejected');}
catch(RuntimeException $e){$check(true,'Approval race rejected');}
$check(sf_agent_action_get(1,$p['id'])['status']==='proposed','Failed approval does not advance state');
$db->exec('DROP TRIGGER block_approve');
sf_agent_action_approve(1,$p['id'],$p['payload_hash'],'APPROVE');
$db->prepare("UPDATE agent_action_proposals SET status='executing' WHERE id=?")->execute([$p['id']]);
try{sf_agent_action_reconcile(1,$p['id'],'RECONCILED');$check(false,'Active operation cannot be reconciled');}
catch(RuntimeException $e){$check(true,'Active operation cannot be reconciled');}
$db->prepare('UPDATE agent_action_proposals SET approved_at=? WHERE id=?')->execute(['2000-01-01T00:00:00Z',$p['id']]);
$reviewed=sf_agent_action_reconcile(1,$p['id'],'RECONCILED');
$check($reviewed['status']==='reviewed_no_retry','Stale interrupted operation acknowledged');
try{sf_agent_action_execute(1,$p['id']);$check(false,'Stale operation never replayed');}
catch(RuntimeException $e){$check(true,'Stale operation never replayed');}
$check($writes===0,'No campaign saved during reconciliation');
$huge=['name'=>'Large','graph'=>['nodes'=>[['id'=>'n1','type'=>'trigger','config'=>['raw'=>str_repeat('x',140000)]]],'edges'=>[]]];
try{sf_agent_action_prepare(1,'section44-audit-oversize0002','campaign_draft_save',$huge);$check(false,'Oversized proposal rejected');}
catch(InvalidArgumentException $e){$check(true,'Oversized proposal rejected');}
for($i=0;$i<39;$i++)sf_agent_action_prepare(1,'section44-audit-limit-'.str_pad((string)$i,6,'0',STR_PAD_LEFT),'campaign_draft_save',['name'=>'Draft '.$i]);
try{sf_agent_action_prepare(1,'section44-audit-limit-overflow','campaign_draft_save',['name'=>'Overflow']);$check(false,'Per-admin quota enforced');}
catch(RuntimeException $e){$check(true,'Per-admin quota enforced');}
$again=sf_agent_action_prepare(1,'section44-audit-race-0001','campaign_draft_save',['name'=>'Race']);
$check($again['id']===$p['id'],'Idempotent repeat remains available at quota');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-actions.js');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-actions.php');
$engine=(string)file_get_contents(dirname(__DIR__).'/api/agent-actions-core.php');
$check(str_contains($ui,'agentCampaignExactPayload')&&str_contains($ui,'JSON.stringify(proposal.payload,null,2)'),'Review includes complete normalized payload');
$check(str_contains($ui,"proposal.status!=='approved'"),'UI checks server-confirmed approval state');
$check(str_contains($api,"$"."previous['status']!=='completed'"),'Already-completed replay cannot duplicate audit events');
$check(str_contains($engine,"$"."finish->rowCount()!==1"),'Completed result must be persisted exactly once');
exit($failed?1:0);
