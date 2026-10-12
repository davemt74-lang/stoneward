<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1),(2)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_crm_admin_summary():array{return ['contacts'=>41,'newsletter'=>17];}
function sf_campaign_admin_summary():array{return ['campaigns'=>6,'active'=>2];}
function sf_fan_journey_counts(int $days=30):array{return ['counts'=>['journeys_started'=>12,'journeys_completed'=>8,'journeys_failed'=>1],'rates'=>[]];}
require dirname(__DIR__).'/api/agent-admin-brain.php';
$errors=0;$check=function(bool $ok,string $label)use(&$errors):void{echo ($ok?'PASS':'FAIL').' '.$label.PHP_EOL;if(!$ok)$errors++;};
$a=sf_admin_agent_reply(1,'','Show CRM subscribers');
$cid=$a['conversation_id'];
$check(str_starts_with($cid,'admin_')&&strlen($cid)===38,'Admin conversation has separate namespace');
$check($a['brain']['scope']==='admin'&&$a['brain']['owner_id']===1,'Server-generated admin scope and owner');
$check(str_contains($a['text'],'41 contacts')&&str_contains($a['text'],'17 opted-in'),'Aggregate CRM summary used');
$check(count(sf_admin_agent_thread_messages(1,$cid))===2,'Admin messages persisted independently');
$check(sf_admin_agent_thread_list(1)[0]['id']===$cid,'Admin conversation available after reconnect');
$check(sf_admin_agent_thread_list(2)===[],'Other admin cannot list this conversation');
try{sf_admin_agent_thread_messages(2,$cid);$check(false,'Cross-admin conversation denied');}catch(RuntimeException $e){$check(true,'Cross-admin conversation denied');}
try{sf_admin_agent_reply(2,$cid,'Read another admin thread');$check(false,'Cross-admin thread cannot be reused');}catch(RuntimeException $e){$check(true,'Cross-admin thread cannot be reused');}
$p=sf_admin_agent_reply(1,$cid,'Create a newsletter campaign');
$check($p['action']['type']==='prepare_campaign'&&$p['action']['requires_confirmation']===true,'Campaign routes to proposal, not execution');
$j=sf_admin_agent_reply(1,$cid,'Build a follow-up journey');
$check($j['action']['type']==='prepare_journey'&&$j['action']['view']==='automations','Journey proposal uses existing editor');
$check((int)$db->query('SELECT COUNT(*) FROM admin_agent_threads')->fetchColumn()===1,'No duplicate admin threads');
$check((int)$db->query('SELECT COUNT(*) FROM admin_agent_messages')->fetchColumn()===6,'Messages append to same independent thread');
$check(!str_contains(json_encode(sf_admin_agent_thread_messages(1,$cid)),'other fan memory'),'No fan memory fetched');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-chat.php');
$check(str_contains($api,'sf_admin_require_auth($write)'),'Admin API requires authorization and write CSRF');
$check(!str_contains($api,"$"."b['user_id']"),'Client cannot select Admin actor');
$check(!str_contains(file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php'),'sf_campaign_save('),'Admin chat cannot directly perform campaign writes');
exit($errors?1:0);
