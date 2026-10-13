<?php
declare(strict_types=1);
/** Section 46I: two independent Brains under authenticated ownership. */
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user'),(10,'admin'),(11,'admin')");
$db->exec('CREATE TABLE agent_conversations(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT,created_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE agent_brain_decisions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,conversation_id TEXT,phase TEXT,route TEXT,action TEXT,provider_status TEXT,created_at TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_agent_brain_ensure_schema():void{}
function sf_privacy_personalization_enabled(int $id):bool{return in_array($id,[1,2],true);}
function sf_crm_admin_summary():array{return ['contacts'=>2,'newsletter'=>1];}
function sf_campaign_admin_summary():array{return ['campaigns'=>0,'active'=>0];}
function sf_agent_action_recent(int $admin,int $n=30):array{return [];}
require dirname(__DIR__).'/api/agent-customer-brain.php';
require dirname(__DIR__).'/api/agent-admin-brain.php';
$errors=0;
$check=static function(string $name,bool $ok)use(&$errors):void{echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$errors++;};
sf_agent_customer_memory_save(1,'music preference','Acoustic versions only');
sf_agent_customer_memory_save(2,'music preference','Heavy electric versions');
$check('Customer one reads only own memory',str_contains(sf_agent_customer_memory_context(1),'Acoustic versions')&&!str_contains(sf_agent_customer_memory_context(1),'Heavy electric'));
$check('Customer two reads only own memory',str_contains(sf_agent_customer_memory_context(2),'Heavy electric')&&!str_contains(sf_agent_customer_memory_context(2),'Acoustic versions'));
$db->exec("INSERT INTO agent_conversations VALUES('conv_priv_aaaa1111',1,'First fan', '2026-10-12','2026-10-12')");
$db->exec("INSERT INTO agent_conversations VALUES('conv_priv_bbbb2222',2,'Second fan','2026-10-12','2026-10-12')");
$db->exec("INSERT INTO agent_brain_decisions(user_id,conversation_id,phase,route,action,provider_status,created_at) VALUES(1,'conv_priv_aaaa1111','route','playlist','none','completed','2026-10-12'),(2,'conv_priv_bbbb2222','route','play','none','completed','2026-10-12')");
$first=sf_agent_customer_brain_snapshot(1);$second=sf_agent_customer_brain_snapshot(2);
$check('Fan snapshots show exactly one owned thread',count($first['conversations'])===1&&count($second['conversations'])===1);
$check('Fan decisions are owner-isolated',count($first['decisions'])===1&&count($second['decisions'])===1&&$first['decisions'][0]['user_id']===1);
$adminThread=sf_admin_agent_thread(10);
sf_admin_agent_save_message(10,$adminThread,'user','How many opted-in contacts?');
$check('Admin conversations stored separately',(int)$db->query('SELECT COUNT(*) FROM admin_agent_messages')->fetchColumn()===1);
$check('Fan conversation table unaffected by Admin',(int)$db->query('SELECT COUNT(*) FROM agent_conversations')->fetchColumn()===2);
$check('Second Admin cannot see first Admin thread',sf_admin_agent_thread_list(11)===[]);
try{sf_admin_agent_thread_messages(11,$adminThread);$check('Cross-admin history is rejected',false);}
catch(RuntimeException $e){$check('Cross-admin history is rejected',true);}
$check('Admin cannot see fan memory in its thread',!str_contains(json_encode(sf_admin_agent_thread_messages(10,$adminThread)),'Acoustic versions'));
$check('Admin Brain read model avoids private customer memories',!str_contains(json_encode(sf_admin_agent_brain_snapshot(10)),'Heavy electric'));
$check('Clearing one customer memory cannot clear another',sf_agent_customer_memory_clear(1)===1&&count(sf_agent_customer_memory_list(2))===1);
$adminApi=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-brain.php');
$adminChat=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-chat.php');
$fanApi=(string)file_get_contents(dirname(__DIR__).'/api/agent-brain.php');
$customerRuntime=(string)file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$recovery=(string)file_get_contents(dirname(__DIR__).'/api/automation-core.php');
$check('Admin operations hide fan conversation identifiers',!str_contains($adminApi,'b.conversation_id')&&!str_contains($adminApi,'b.request_excerpt')&&!str_contains($adminApi,'e.message_text'));
$check('Admin cannot globally erase fan decisions',!str_contains($adminApi,'DELETE FROM agent_brain_decisions'));
$check('Both Brain APIs authenticate separately',str_contains($adminChat,'sf_admin_require_auth($write)')&&str_contains($fanApi,'sf_require_user(false,$write)'));
$check('No client-supplied actor ID accepted',!str_contains($fanApi,"$"."body['user_id']")&&!str_contains($adminChat,"$"."b['user_id']"));
$check('Customer Agent context resolves authenticated user',str_contains($customerRuntime,'sf_agent_customer_memory_context($userId)'));
$check('Stale automation review remains no-replay',str_contains($recovery,"'reviewed_no_retry'")&&str_contains($recovery,'sf_automation_quarantine_stale()'));
$check('Agent API has no unapproved publish or send',!str_contains($adminChat,'sf_campaign_send_email_node(')&&!str_contains($adminChat,'sf_automation_publish('));
exit($errors?1:0);
