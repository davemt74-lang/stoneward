<?php
declare(strict_types=1);
$root=dirname(__DIR__);
define('SF_ROOT',$root);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'user'),(2,'user'),(10,'admin')");
$db->exec('CREATE TABLE agent_conversations(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT,created_at TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE agent_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id TEXT NOT NULL,user_id INTEGER NOT NULL,role TEXT,content TEXT,provider TEXT,model TEXT,input_tokens INTEGER,output_tokens INTEGER,created_at TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
require $root.'/api/agent-customer-brain.php';
require $root.'/api/agent-admin-brain.php';
require $root.'/api/privacy-core.php';
require $root.'/api/migrations.php';
$failed=0;$check=static function(string $name,bool $ok)use(&$failed):void{echo ($ok?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$ok)$failed++;};
$ci=$read('.github/workflows/release-gate.yml');$migrationCode=$read('api/migrations.php');
$sections=['a-brain-isolation','b-customer-brain','c-admin-brain','d-shared-orchestration','e-customer-canvas','f-admin-missions','g-customer-chat-canvas','h-admin-canvas','i-dual-brain-isolation'];
$wired=true;
foreach($sections as $x){$p='tests/section46'.$x.'.php';$wired=$wired&&is_file($root.'/'.$p)&&str_contains($ci,'php '.$p);}
$check('Sections 46A-I all tested in release gate',$wired);
$check('Section 46J release audit itself release-gated',str_contains($ci,'php tests/section46j-release.php'));
preg_match("/define\\('SF_BUILD',\\s*'([0-9.]+)'\\)/",$read('stonefellow-v120.php'),$vMatch);$build=$vMatch[1]??'';
$check('46J baseline retained with synchronized release versions',version_compare($build,'1.3.45','>=')
    &&str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$build."')")
    &&str_contains($read('version.php'),"'stonefellow'=>'".$build."'")
    &&str_contains($read('service-worker.js'),'stonefellow-static-v'.$build)
    &&str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$build.'"'));
$check('Database target and endpoint remain synchronized after 46J',version_compare(SF_DB_SCHEMA_TARGET,'1.3.35','>=')&&str_contains($read('version.php'),"'database_schema_target'=>'".SF_DB_SCHEMA_TARGET."'"));
$definitions=sf_migration_definitions();$found=null;
foreach($definitions as $definition)if($definition['id']==='2026-10-12-036')$found=$definition;
$check('New dual Brain migration registered exactly once',count(array_filter($definitions,fn($x)=>$x['id']==='2026-10-12-036'))===1);
$check('Migration description/version/revision consistent',($found['app_version']??null)==='1.3.45'&&($found['revision']??null)==='1');
if($found){$found['apply']();$found['apply']();}
$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$check('Migration 036 creates private customer memories',in_array('agent_customer_memories',$tables,true));
$check('Migration 036 creates Admin threads and messages',in_array('admin_agent_threads',$tables,true)&&in_array('admin_agent_messages',$tables,true));
$check('Migration 036 is idempotent',count(array_filter($tables,fn($x)=>$x==='agent_customer_memories'))===1);
$now=gmdate('c');
$q=$db->prepare('INSERT INTO agent_conversations(id,user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)');
$q->execute(['conv_aaaaaaaaaaaa',1,'Private first user',$now,$now]);
$q->execute(['conv_bbbbbbbbbbbb',2,'Private second user',$now,$now]);
$q=$db->prepare('INSERT INTO agent_messages(conversation_id,user_id,role,content,provider,model,input_tokens,output_tokens,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
$q->execute(['conv_aaaaaaaaaaaa',1,'user','acoustic only','','',0,0,$now]);
$q->execute(['conv_bbbbbbbbbbbb',2,'user','electric only','','',0,0,$now]);
$export=sf_privacy_agent_export(1);$serialized=json_encode($export,JSON_THROW_ON_ERROR);
$check('Privacy export preserves string conversation IDs',count($export)===1&&$export[0]['id']==='conv_aaaaaaaaaaaa');
$check('Privacy export includes own conversation messages',count($export[0]['messages']??[])===1&&$export[0]['messages'][0]['content']==='acoustic only');
$check('Privacy export excludes other customer private messages',!str_contains($serialized,'electric only')&&!str_contains($serialized,'conv_bbbbbbbbbbbb'));
$check('Privacy export constrains both conversation and message ownership',str_contains($read('api/privacy-core.php'),'WHERE conversation_id=? AND user_id=? ORDER BY id ASC'));
$docs=$read('docs/section46-dual-agent-brain-acceptance.md');
$check('Deployment guide describes upgrade and privacy',stripos($docs,'migration 036')!==false&&str_contains($docs,'customer')&&str_contains($docs,'no-replay'));
$check('Only green merged main generates certified deployment ZIP',str_contains($read('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.conclusion == 'success'")&&str_contains($read('.github/workflows/certified-deploy.yml'),"github.event.workflow_run.head_branch == 'main'"));
$check('Admin Brain and customer Brain use separate tables',str_contains($read('api/agent-admin-brain.php'),'admin_agent_threads')&&str_contains($read('api/agent-customer-brain.php'),'agent_customer_memories'));
$check('No unrestricted customer Agent messages in Admin queries',!str_contains($read('admin/api/agent-brain.php'),'b.conversation_id')&&!str_contains($read('admin/api/agent-brain.php'),'b.request_excerpt'));
echo (17-$failed).'/17 checks passed'.PHP_EOL;
exit($failed?1:0);
