<?php
declare(strict_types=1);
/** 45G: reviewed Agent journey writes use the actual production automation saver. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
function sf_db(): PDO {global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120):string{return substr(trim((string)$v),0,$max);}
function sf_crm_ensure_schema():void{sf_db()->exec('CREATE TABLE IF NOT EXISTS fan_contacts(id INTEGER PRIMARY KEY)');}
function sf_campaign_ensure_schema():void{sf_db()->exec('CREATE TABLE IF NOT EXISTS campaign_segments(id INTEGER PRIMARY KEY, name TEXT)');}
require dirname(__DIR__).'/api/automation-core.php';
require dirname(__DIR__).'/api/agent-actions-core.php';
$bad=0;$check=static function(bool $ok,string $label)use(&$bad):void{echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;if(!$ok)$bad++;};
$draft=[
 'name'=>'Follow-up journey','status'=>'published','id'=>1234,
 'trigger_type'=>'manual','steps'=>[
  ['type'=>'wait','config'=>['hours'=>48]],
  ['type'=>'email','config'=>['subject'=>'Stonefellow','body'=>'Approved copy']],
  ['type'=>'exit','config'=>[]]
 ],
 'cooldown_hours'=>0,'max_runs_per_contact'=>500
];
$p=sf_agent_action_prepare(1,'journey-review-00000001','automation_draft_save',$draft);
$check($p['status']==='proposed'&&$p['payload']['status']==='draft'&&$p['payload']['id']===0,'Submitted publish request forced to new draft');
$check($p['payload']['cooldown_hours']>=24&&$p['payload']['max_runs_per_contact']<=100,'Agent budgets are capped');
$check((int)$db->query('SELECT COUNT(*) FROM lifecycle_automations')->fetchColumn()===0,'Prepare writes no journeys');
try{sf_agent_action_execute(1,$p['id']);$check(false,'Approval required before save');}catch(RuntimeException $e){$check(true,'Approval required before save');}
try{sf_agent_action_approve(1,$p['id'],str_repeat('a',64),'APPROVE');$check(false,'Exact hash required');}catch(RuntimeException $e){$check(true,'Exact hash required');}
sf_agent_action_approve(1,$p['id'],$p['payload_hash'],'APPROVE');
$check((int)$db->query('SELECT COUNT(*) FROM lifecycle_automations')->fetchColumn()===0,'Approval writes no journeys');
$done=sf_agent_action_execute(1,$p['id']);$id=(int)($done['result']['automation_id']??0);
$saved=sf_automation_get($id);
$check($id>0&&$saved!==null,'Actual lifecycle automation created');
$check(($saved['status']??'')==='draft'&&empty($saved['published_at']),'Saved journey unpublished and not scheduled');
$check(count($saved['steps']??[])===3&&$saved['steps'][1]['type']==='email','Canonical automation steps saved');
$again=sf_agent_action_execute(1,$p['id']);
$check($again['result']===$done['result']&&(int)$db->query('SELECT COUNT(*) FROM lifecycle_automations')->fetchColumn()===1,'Idempotent retry never doubles journey');
try{sf_agent_action_prepare(1,'journey-review-00000002','automation_draft_save',['name'=>'Bad','steps'=>[['type'=>'charge_card']]]);$check(false,'Unknown step denied');}catch(InvalidArgumentException $e){$check(true,'Unknown step denied');}
$check(!sf_agent_tool_executable('campaign_send_email','admin',true)&&!sf_agent_tool_executable('campaign_publish','admin',true),'Agent cannot publish or send');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-actions.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/automations.js');
$check(str_contains($api,"sf_agent_tool_executable($"."tool,'admin',true)"),'Controller checks executable allowlist');
$check(str_contains($ui,'agentJourneyPayload')&&str_contains($ui,'JSON.stringify(proposal.payload,null,2)'),'Full exact proposal displayed');
$check(str_contains($ui,"proposal.status!=='approved'"),'UI separately verifies exact approval');
$check(str_contains($ui,"automationAgentReview"),'Existing Automation Builder exposes reviewed creation');
exit($bad?1:0);
