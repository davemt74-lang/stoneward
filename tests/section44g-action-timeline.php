<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr((string)$v,0,$max);}
function sf_campaign_graph_default():array{return ['nodes'=>[],'edges'=>[]];}
function sf_campaign_graph_validate(array $g):array{return ['ok'=>true,'graph'=>$g,'errors'=>[]];}
function sf_campaign_save(array $p,int $id):array{return ['id'=>25,'name'=>$p['name']];}
require dirname(__DIR__).'/api/agent-actions-core.php';
$a=sf_agent_action_prepare(1,'timeline-owner-one-001','campaign_draft_save',['name'=>'Owner One']);
$b=sf_agent_action_prepare(2,'timeline-owner-two-001','campaign_draft_save',['name'=>'Owner Two']);
$my=sf_agent_action_recent(1);$other=sf_agent_action_recent(2);
$checks=[
 'Admin sees only own proposals'=>count($my)===1&&$my[0]['id']===$a['id'],
 'Other admin sees only own proposals'=>count($other)===1&&$other[0]['id']===$b['id'],
 'History excludes SQL ownership keys'=>!isset($my[0]['admin_user_id'])&&!isset($my[0]['request_key']),
 'Unknown admin sees no proposals'=>sf_agent_action_recent(0)===[],
];
sf_agent_action_approve(1,$a['id'],$a['payload_hash'],'APPROVE');
sf_agent_action_execute(1,$a['id']);
$last=sf_agent_action_recent(1)[0];
$checks['Completed action result visible']=$last['status']==='completed'&&($last['result']['campaign_id']??0)===25;
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-actions.js');
$brain=(string)file_get_contents(dirname(__DIR__).'/admin/assets/admin.js');
$checks['Agent Brain links action history']=str_contains($brain,'id="agentProposals"')&&str_contains($brain,'SFAgentCampaigns?.showHistory');
$checks['Interrupted work never auto-retries']=str_contains($ui,'do not retry automatically');
$checks['UI can open completed canonical campaign']=str_contains($ui,'SFCampaignAdmin?.openEditor');
$fail=0;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$fail++;}
exit($fail?1:0);
