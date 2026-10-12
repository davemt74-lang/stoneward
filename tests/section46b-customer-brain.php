<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users VALUES(1),(2)');
$db->exec('CREATE TABLE agent_conversations(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE agent_brain_decisions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,conversation_id TEXT,phase TEXT,route TEXT,action TEXT,provider_status TEXT,created_at TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_agent_brain_ensure_schema():void{}
function sf_privacy_personalization_enabled(int $uid):bool{return $uid===1;}
require dirname(__DIR__).'/api/agent-customer-brain.php';
$errors=0;$check=function(bool $ok,string $name)use(&$errors):void{echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$errors++;};
$m=sf_agent_customer_memory_save(1,'music preference','Acoustic Stonefellow songs');
$check($m['memory_key']==='music preference','Explicit opted-in memory saved');
$check(count(sf_agent_customer_memory_list(1))===1,'Owner sees memory');
$check(sf_agent_customer_memory_list(2)===[],'Other customer cannot read memory');
$check(str_contains(sf_agent_customer_memory_context(1),'Acoustic Stonefellow'),'Memory included for consenting owner only');
$check(sf_agent_customer_memory_context(2)==='','No private memory in other customer context');
try{sf_agent_customer_memory_save(2,'music preference','Private');$check(false,'Opt-out blocks storage');}
catch(RuntimeException $e){$check(true,'Opt-out blocks storage');}
$m=sf_agent_customer_memory_save(1,'music preference','Updated acoustic music');
$check(count(sf_agent_customer_memory_list(1))===1&&$m['memory_text']==='Updated acoustic music','Same key safely updated');
try{sf_agent_customer_memory_save(1,'secret','');$check(false,'Empty memory rejected');}
catch(InvalidArgumentException $e){$check(true,'Empty memory rejected');}
try{sf_agent_customer_memory_save(1,'secret',str_repeat('a',1201));$check(false,'Large memory rejected');}
catch(InvalidArgumentException $e){$check(true,'Large memory rejected');}
$db->exec("INSERT INTO agent_conversations VALUES('conv_user_1_123456',1,'Private fan conversation','2026-10-12','2026-10-12')");
$db->exec("INSERT INTO agent_conversations VALUES('conv_user_2_123456',2,'Another private fan conversation','2026-10-12','2026-10-12')");
$db->exec("INSERT INTO agent_brain_decisions(user_id,conversation_id,phase,route,action,provider_status,created_at) VALUES(1,'conv_user_1_123456','route','search','none','completed','2026-10-12'),(2,'conv_user_2_123456','route','search','none','completed','2026-10-12')");
$snap=sf_agent_customer_brain_snapshot(1);
$check(count($snap['conversations'])===1&&$snap['conversations'][0]['title']==='Private fan conversation','Customer conversation list owner-scoped');
$check(count($snap['decisions'])===1&&$snap['decisions'][0]['conversation_id']==='conv_user_1_123456','Customer Agent decisions owner-scoped');
$check(sf_agent_customer_brain_snapshot(2)['memories']===[],'Non-consenting customer has no memory export from Brain');
$check(!sf_agent_customer_memory_forget(2,'music preference'),'Other customer cannot erase memory');
$check(sf_agent_customer_memory_forget(1,'music preference'),'Owner can remove memory');
$check(sf_agent_customer_memory_list(1)===[],'Deletion is durable');
sf_agent_customer_memory_save(1,'temporary','Delete me');
$check(sf_agent_customer_memory_clear(1)===1,'Owner can clear stored memories');
$endpoint=(string)file_get_contents(dirname(__DIR__).'/api/agent-brain.php');
$check(str_contains($endpoint,'sf_require_user(false,$write)'),'Endpoint requires authenticated user and CSRF for writes');
$check(!str_contains($endpoint,"$"."body['user_id']"),'Client cannot choose a different memory owner');
$privacy=(string)file_get_contents(dirname(__DIR__).'/api/privacy-core.php');
$check(str_contains($privacy,"'agent_memories'=>"),'Personal data export includes memories');
$check(str_contains($privacy,"sf_agent_customer_memory_clear($"."userId)"),'Account cleanup removes memories');
$runtime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$check(str_contains($runtime,'sf_agent_customer_memory_context($userId)'),'Actual Agent runtime integrates scoped memories');
exit($errors?1:0);
