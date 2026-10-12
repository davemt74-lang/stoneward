<?php
declare(strict_types=1);
// Section 44B integration: durable exact-review campaign draft, no external services.
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $x,int $length=120):string{return substr(trim((string)$x),0,$length);}
function sf_campaign_graph_default():array{return ['nodes'=>[],'edges'=>[]];}
function sf_campaign_graph_validate(array $graph):array{return ['ok'=>true,'errors'=>[],'graph'=>$graph];}
function sf_campaign_save(array $campaign,int $adminId):array{global $writes;$writes++;if(($campaign['status']??'')!=='draft'||$adminId!==1)throw new RuntimeException('unsafe write');return ['id'=>123,'name'=>$campaign['name']];}
require dirname(__DIR__).'/api/agent-actions-core.php';
$writes=0;$errors=0;$assert=function(bool $ok,string $label)use(&$errors):void{echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;if(!$ok)$errors++;};
$input=['name'=>'October Fan Newsletter','headline'=>'New Stonefellow Music','graph'=>['nodes'=>[],'edges'=>[]],'status'=>'published'];
$key='campaign-request-key-0001';
$a=sf_agent_action_prepare(1,$key,'campaign_draft_save',$input);
$assert($a['status']==='proposed'&&$a['payload']['status']==='draft','proposal always draft');
$assert($writes===0,'preparation never edits campaign');
$again=sf_agent_action_prepare(1,$key,'campaign_draft_save',$input);
$assert($again['id']===$a['id'],'repeated prepare returns original');
try{sf_agent_action_prepare(1,$key,'campaign_draft_save',['name'=>'different']);$assert(false,'key collision rejected');}catch(RuntimeException $e){$assert(true,'key collision rejected');}
$assert(sf_agent_action_get(2,$a['id'])===null,'cross-admin access rejected');
try{sf_agent_action_approve(1,$a['id'],str_repeat('0',64),'APPROVE');$assert(false,'digest mismatch blocked');}catch(RuntimeException $e){$assert(true,'digest mismatch blocked');}
try{sf_agent_action_approve(1,$a['id'],$a['payload_hash'],'YES');$assert(false,'exact typed approval required');}catch(InvalidArgumentException $e){$assert(true,'exact typed approval required');}
try{sf_agent_action_execute(1,$a['id']);$assert(false,'execution without approval denied');}catch(RuntimeException $e){$assert(true,'execution without approval denied');}
$approved=sf_agent_action_approve(1,$a['id'],$a['payload_hash'],'APPROVE');
$assert($approved['status']==='approved','exact approval persisted');
$done=sf_agent_action_execute(1,$a['id']);
$assert($done['status']==='completed'&&$done['result']['campaign_id']===123,'approved draft executed');
$retry=sf_agent_action_execute(1,$a['id']);
$assert($retry['result']===$done['result']&&$writes===1,'completed execution safely deduplicated');
$db->prepare("UPDATE agent_action_proposals SET status='executing' WHERE id=?")->execute([$a['id']]);
try{sf_agent_action_execute(1,$a['id']);$assert(false,'interrupted work requires review');}catch(RuntimeException $e){$assert(true,'interrupted work requires review');}
if($errors)exit(1);
