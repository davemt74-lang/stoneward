<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users(id) VALUES(1)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
function sf_campaign_graph_default():array{return ['nodes'=>[],'edges'=>[]];}
function sf_campaign_graph_validate(array $v):array{return ['ok'=>true,'graph'=>$v,'errors'=>[]];}
function sf_campaign_save(array $draft,int $adminId):array{global $writes;$writes++;return ['id'=>99,'name'=>$draft['name']];}
require dirname(__DIR__).'/api/agent-actions-core.php';
$writes=0;$bad=0;
$ok=function(bool $pass,string $msg)use(&$bad):void{echo ($pass?'PASS':'FAIL').' '.$msg.PHP_EOL;if(!$pass)$bad++;};
$ok(!sf_agent_tool_executable('campaign_send_email','admin',true),'Agent cannot send marketing emails');
$ok(!sf_agent_tool_executable('campaign_publish','admin',true),'Agent cannot publish campaigns');
$ok(!sf_agent_tool_executable('campaign_draft_save','admin',false),'Agent draft needs exact approval');
$ok(sf_agent_tool_executable('campaign_draft_save','admin',true),'Approved Agent draft allowed');
$p=sf_agent_action_prepare(1,'tampered-proposal-key-001','campaign_draft_save',['name'=>'Original']);
sf_agent_action_approve(1,$p['id'],$p['payload_hash'],'APPROVE');
$db->prepare('UPDATE agent_action_proposals SET payload_json=? WHERE id=?')->execute([json_encode(['name'=>'Changed']),$p['id']]);
try{sf_agent_action_execute(1,$p['id']);$ok(false,'tampered payload rejected');}catch(RuntimeException $e){$ok(true,'tampered payload rejected');}
$p2=sf_agent_action_prepare(1,'expired-approval-key-002','campaign_draft_save',['name'=>'Aged']);
sf_agent_action_approve(1,$p2['id'],$p2['payload_hash'],'APPROVE');
$db->prepare('UPDATE agent_action_proposals SET approved_at=? WHERE id=?')->execute(['2000-01-01T00:00:00Z',$p2['id']]);
try{sf_agent_action_execute(1,$p2['id']);$ok(false,'expired execution rejected');}catch(RuntimeException $e){$ok(true,'expired execution rejected');}
$ok(sf_agent_action_get(1,$p2['id'])['status']==='review_required','expired action flagged for manual review');
$p3=sf_agent_action_prepare(1,'expired-proposal-key-003','campaign_draft_save',['name'=>'Old']);
$db->prepare('UPDATE agent_action_proposals SET created_at=? WHERE id=?')->execute(['2000-01-01T00:00:00Z',$p3['id']]);
try{sf_agent_action_approve(1,$p3['id'],$p3['payload_hash'],'APPROVE');$ok(false,'old proposal rejected');}catch(RuntimeException $e){$ok(true,'old proposal rejected');}
$ok($writes===0,'no unapproved or tampered work executed');
if($bad)exit(1);
