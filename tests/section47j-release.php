<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(!defined('SF_ROOT'))define('SF_ROOT',$root);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL,password_hash TEXT NOT NULL,role TEXT NOT NULL)");
$hash1='$2y$10$protected_password_hash_cannot_change';
$hash2='$2y$10$second_admin_password_unchanged';
$q=$db->prepare('INSERT INTO users(id,email,password_hash,role) VALUES(?,?,?,?)');
$q->execute([1,'fan@example.org',$hash1,'user']);$q->execute([10,'admin@example.org',$hash2,'admin']);
function sf_db():PDO {global $db;return $db;}
function sf_db_config():array {return ['driver'=>'sqlite'];}
require $root.'/api/migrations.php';
$defs=sf_migration_definitions();
$matches=array_values(array_filter($defs,fn($m)=>$m['id']==='2026-10-12-037'));
$old=array_values(array_filter($defs,fn($m)=>$m['id']==='2026-10-12-036'));
$checks=[
 'Release target is 1.3.46'=>SF_DB_SCHEMA_TARGET==='1.3.46',
 'Exactly one additive migration 037'=>count($matches)===1,
 'Prior migration 036 remains unmodified'=>count($old)===1&&$old[0]['app_version']==='1.3.45'&&$old[0]['revision']==='1',
 'Migration 037 version/revision fixed'=>count($matches)===1&&$matches[0]['app_version']==='1.3.46'&&$matches[0]['revision']==='1',
 'Missing new tables on pre-upgrade database'=>!sf_migration_table_exists('agent_mission_plans')&&!sf_migration_table_exists('agent_response_feedback'),
];
if(count($matches)===1){
    $m=$matches[0];($m['apply'])();($m['apply'])();
}
$checks['Migration creates durable owner-scoped mission plans']=sf_migration_table_exists('agent_mission_plans');
$checks['Migration creates scoped reply-feedback storage']=sf_migration_table_exists('agent_response_feedback');
$missionCols=sf_migration_columns('agent_mission_plans');
$feedbackCols=sf_migration_columns('agent_response_feedback');
$checks['Mission schema contains owner and scope boundaries']=in_array('scope',$missionCols,true)&&in_array('owner_user_id',$missionCols,true)&&in_array('conversation_id',$missionCols,true);
$checks['Feedback schema contains owner/role and exact response ID']=in_array('scope',$feedbackCols,true)&&in_array('owner_user_id',$feedbackCols,true)&&in_array('response_message_id',$feedbackCols,true);
$checks['Privacy export is complete beyond recent display limit']=str_contains($read('api/privacy-core.php'),"'agent_response_feedback'=>sf_agent_feedback_export('customer',");
$checks['No raw private response text in feedback schema']=!in_array('content',$feedbackCols,true)&&!in_array('message_text',$feedbackCols,true);
$checks['Existing users and password hashes preserved']=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===2
    && $db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()===$hash1
    && $db->query('SELECT password_hash FROM users WHERE id=10')->fetchColumn()===$hash2;
$id=str_repeat('a',64);
$q=$db->prepare('INSERT INTO agent_mission_plans(mission_id,scope,owner_user_id,conversation_id,plan_json,created_at) VALUES(?,?,?,?,?,?)');
$q->execute([$id,'customer',1,'conv_aaaaaaaaaaaa','{"schema":"stonefellow.mission-plan.v1"}',gmdate('c')]);
$q=$db->prepare('INSERT INTO agent_response_feedback(scope,owner_user_id,response_message_id,rating,created_at,updated_at) VALUES(?,?,?,?,?,?)');
$q->execute(['customer',1,99,'helpful',gmdate('c'),gmdate('c')]);
if(count($matches)===1)($matches[0]['apply'])();
$checks['Repeated migration never deletes existing mission history']=(int)$db->query('SELECT COUNT(*) FROM agent_mission_plans')->fetchColumn()===1;
$checks['Repeated migration never deletes feedback history']=(int)$db->query('SELECT COUNT(*) FROM agent_response_feedback')->fetchColumn()===1;
$checks['User hashes also survive repeat migration']=$db->query('SELECT password_hash FROM users WHERE id=10')->fetchColumn()===$hash2;
$checks['Migration checksum is stable']=count($matches)===1&&sf_migration_checksum($matches[0])===sf_migration_checksum($matches[0]);
$build='1.3.68';$schema='1.3.46';
$checks['Public and Admin release version synchronized']=str_contains($read('stonefellow-v120.php'),"define('SF_BUILD', '".$build."')")
   &&str_contains($read('admin/index.php'),"define('SF_ADMIN_BUILD','".$build."')");
$checks['PWA and Apache headers synchronized']=str_contains($read('service-worker.js'),'stonefellow-static-v'.$build)
   &&str_contains($read('.htaccess'),'X-Stonefellow-Build "'.$build.'"');
$checks['Version endpoint declares application and schema target']=str_contains($read('version.php'),"'stonefellow'=>'".$build."'")
   &&str_contains($read('version.php'),"'database_schema_target'=>'".$schema."'");
$ci=$read('.github/workflows/release-gate.yml');
$sections=['47a-capability-audit','47b-skill-registry','47c-mission-planning','47d-customer-context','47e-admin-intelligence','47f-proactive-research','47g-specialist-delegation','47h-chat-handoffs','47i-feedback-recovery','47j-release'];
$all=true;foreach($sections as $s)$all=$all&&is_file($root.'/tests/section'.$s.'.php')&&str_contains($ci,'php tests/section'.$s.'.php');
$checks['47A-J all retained in release gate']=$all;
$packaging=$read('scripts/release-zip.sh');
$checks['ZIP packaging excludes CI and source tests']=str_contains($packaging,'rm -rf "$stage/site/.github" "$stage/site/tests" "$stage/site/scripts"');
$checks['Release packaging verifies ZIP and SHA-256']=str_contains($packaging,'unzip -tqq')&&str_contains($packaging,'sha256sum stonefellow-deploy.zip');
$workflow=$read('.github/workflows/certified-deploy.yml');
$checks['Certified packaging tied to successful main commit']=str_contains($workflow,"github.event.workflow_run.conclusion == 'success'")
    &&str_contains($workflow,'github.event.workflow_run.head_sha');
$checks['No password-reset deployment hook']=$db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()===$hash1
    &&!str_contains($read('api/migrations.php'),"UPDATE users SET password_hash=");
$checks['Migration 037 is additive without altering users']=!str_contains($read('api/agent-feedback-core.php'),'ALTER TABLE users')
    &&!str_contains($read('api/agent-mission-planner.php'),'ALTER TABLE users');
$failed=0;
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL').' '.$name.PHP_EOL;if(!$pass)$failed++;}
echo '47J: '.(count($checks)-$failed).'/'.count($checks).' checks passed'.PHP_EOL;
exit($failed?1:0);
