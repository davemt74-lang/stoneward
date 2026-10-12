<?php
declare(strict_types=1);
/** 46I — cross-scope security and approved action authority integration. */
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user'),(3,'admin'),(4,'admin')");
$db->exec('CREATE TABLE agent_conversations(id TEXT PRIMARY KEY,user_id INTEGER,title TEXT,created_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE agent_brain_decisions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,conversation_id TEXT,phase TEXT,route TEXT,action TEXT,provider_status TEXT,created_at TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_agent_brain_ensure_schema():void{}
function sf_privacy_personalization_enabled(int $userId):bool{return $userId===1;}
function sf_crm_admin_summary():array{return ['contacts'=>2,'newsletter'=>1];}
function sf_campaign_admin_summary():array{return ['campaigns'=>5,'active'=>1];}
require dirname(__DIR__).'/api/agent-customer-brain.php';
require dirname(__DIR__).'/api/agent-admin-brain.php';
$bad=0;$test=function(bool $pass,string $name)use(&$bad):void{echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$bad++;};
sf_agent_customer_memory_save(1,'favorite music','Private acoustic preference');
sf_agent_customer_memory_ensure_schema();
$db->prepare('INSERT INTO agent_customer_memories(user_id,memory_key,memory_text,created_at,updated_at) VALUES(?,?,?,?,?)')
    ->execute([2,'hidden','Never link my behavior','2026-10-12','2026-10-12']);
$db->exec("INSERT INTO agent_conversations VALUES('conv_customer_one',1,'Private artist conversation','2026-10-12','2026-10-12'),('conv_customer_two',2,'Another fan conversation','2026-10-12','2026-10-12')");
$db->exec("INSERT INTO agent_brain_decisions(user_id,conversation_id,phase,route,action,provider_status,created_at) VALUES(1,'conv_customer_one','route','music_search','none','local','2026-10-12'),(2,'conv_customer_two','route','music_search','none','local','2026-10-12')");
$customer=sf_agent_customer_brain_snapshot(1);
$other=sf_agent_customer_brain_snapshot(2);
$test(count($customer['memories'])===1&&str_contains(sf_agent_customer_memory_context(1),'acoustic'),'Customer memory visible only to opted-in owner');
$test($other['memories']===[]&&sf_agent_customer_memory_context(2)==='','Opted-out customer memory not injected or returned');
$test(count($customer['conversations'])===1&&$customer['conversations'][0]['id']==='conv_customer_one','Conversation scope prevents cross-fan read');
$test(count($other['decisions'])===1&&$other['decisions'][0]['conversation_id']==='conv_customer_two','Brain decision scope prevents cross-fan read');
$admin=sf_admin_agent_reply(3,'','Show our CRM overview');
$thread=$admin['conversation_id'];
$test(!str_contains(json_encode($admin),'Private acoustic preference'),'Admin operational context cannot read customer memories');
$test(!str_contains(json_encode($admin),'Never link my behavior'),'Admin operational context cannot read opted-out fan memories');
$test(!str_contains(json_encode($admin),'Private artist conversation'),'Admin operational context excludes fan conversation');
$test(str_contains($admin['text'],'2 contacts'),'Admin can still research permitted aggregates');
try{sf_admin_agent_thread_messages(4,$thread);$test(false,'Other Admin cannot access existing thread');}
catch(RuntimeException $e){$test(true,'Other Admin cannot access existing thread');}
$test(sf_agent_orchestration_contract('customer','prepare_campaign')['may_execute_directly']===false,'Customer cannot execute Admin campaign tool');
$test(sf_agent_orchestration_contract('admin','prepare_campaign')['may_prepare_reviewed_proposal']===true,'Admin can prepare reviewed, not direct, campaign');
$test(sf_agent_orchestration_contract('admin','prepare_journey')['may_execute_directly']===false,'Admin cannot bypass exact-reviewed journey save');
$test(sf_agent_tool_executable('campaign_send_email','admin',true)===false,'Admin Agent cannot directly send marketing emails');
$test(sf_agent_tool_executable('campaign_publish','admin',true)===false,'Admin Agent cannot directly publish campaign');
$operational=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-brain.php');
$test(!str_contains($operational,'b.conversation_id,b.phase'),'Admin operational report hides customer thread ID');
$test(!str_contains($operational,'b.request_excerpt')&&!str_contains($operational,'b.response_excerpt'),'Admin operational report hides fan conversation messages');
$controller=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-chat.php');
$test(str_contains($controller,'sf_admin_require_auth($write)'),'Admin conversation controller enforces authenticated role and CSRF');
$fanController=(string)file_get_contents(dirname(__DIR__).'/api/agent-brain.php');
$test(str_contains($fanController,'sf_require_user(false,$write)'),'Fan Brain controller enforces authenticated user and CSRF');
$test(sf_agent_customer_memory_forget(1,'favorite music')&&sf_agent_customer_memory_list(1)===[],'Customer can remove their private memory permanently');
exit($bad?1:0);
