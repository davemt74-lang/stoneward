<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db(): PDO {global $pdo;return $pdo;}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);$s=preg_replace('/[\x00-\x1F\x7F]/u','',$s)??'';return substr($s,0,$max);}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,status TEXT,display_name TEXT)");
$pdo->exec("CREATE TABLE fan_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,email TEXT,display_name TEXT,source TEXT,status TEXT,marketing_opt_in INTEGER,marketing_opt_in_at TEXT NULL,unsubscribed_at TEXT NULL,agent_auto_engage INTEGER,last_engaged_at TEXT NULL,tags_json TEXT,notes TEXT,unsubscribe_token_hash TEXT NULL,created_at TEXT,updated_at TEXT)");
$pdo->exec("CREATE TABLE fan_crm_events(id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER,user_id INTEGER NULL,event_type TEXT,title TEXT,entity_type TEXT,entity_id TEXT,metadata_json TEXT,created_at TEXT)");
$pdo->exec("CREATE TABLE store_order_items(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id TEXT,line_index INTEGER,user_id INTEGER NULL,contact_id INTEGER NULL,customer_email TEXT,product_id TEXT,variant_id INTEGER NULL,sku TEXT,title TEXT,variant_title TEXT,quantity INTEGER,unit_price_cents INTEGER,subtotal_cents INTEGER,inventory_source TEXT,inventory_status TEXT,created_at TEXT,updated_at TEXT)");
$pdo->exec("CREATE TABLE listening_events(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,session_key TEXT,track_id TEXT,event_type TEXT,position_seconds INTEGER,duration_seconds INTEGER,source TEXT,created_at TEXT)");
$pdo->exec("CREATE TABLE user_notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,kind TEXT,title TEXT,body TEXT,link_url TEXT,is_read INTEGER DEFAULT 0,created_at TEXT,read_at TEXT NULL)");
$now=gmdate('c');$old=gmdate('c',time()-20*86400);
$pdo->exec("INSERT INTO users(id,email,status,display_name) VALUES(1,'buyer@example.com','active','Buyer Fan')");
$q=$pdo->prepare("INSERT INTO fan_contacts(user_id,email,display_name,source,status,marketing_opt_in,marketing_opt_in_at,unsubscribed_at,agent_auto_engage,last_engaged_at,tags_json,notes,unsubscribe_token_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$q->execute([1,'buyer@example.com','Buyer Fan','account','customer',1,$now,null,1,$old,'["buyer"]','',null,gmdate('c',time()-90*86400),$now]);
$q->execute([null,'lead@example.com','Lead Fan','newsletter','lead',0,null,$now,0,$old,'[]','',null,gmdate('c',time()-10*86400),$now]);
$buyerId=1;$leadId=2;
$pdo->prepare("INSERT INTO store_order_items(order_id,line_index,user_id,contact_id,customer_email,product_id,variant_id,sku,title,variant_title,quantity,unit_price_cents,subtotal_cents,inventory_source,inventory_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(['O1',0,1,$buyerId,'buyer@example.com','tour-shirt',1,'SKU-L','Tour Shirt','Large',2,2500,5000,'variant','sold',$now,$now]);
for($i=0;$i<3;$i++)$pdo->prepare("INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at) VALUES(1,?,?,?,?,?,?,?)")->execute(['s'.$i,'track-1','start',0,180,'test',$now]);

$GLOBALS['notifications']=0;$GLOBALS['agent_messages']=0;$GLOBALS['emails']=0;$GLOBALS['crm_events']=[];
function sf_crm_contact_by_id(int $id): ?array {global $pdo;$q=$pdo->prepare('SELECT * FROM fan_contacts WHERE id=?');$q->execute([$id]);$r=$q->fetch();return $r?:null;}
function sf_notify_user(int $uid,string $kind,string $title,string $body,string $link): int {global $pdo;$GLOBALS['notifications']++;$q=$pdo->prepare('INSERT INTO user_notifications(user_id,kind,title,body,link_url,is_read,created_at,read_at) VALUES(?,?,?,?,?,0,?,NULL)');$q->execute([$uid,$kind,$title,$body,$link,gmdate('c')]);return (int)$pdo->lastInsertId();}
function sf_notification_preferences_map(int $uid): array {return ['lifecycle'=>['in_app_enabled'=>true,'email_enabled'=>false]];}
function sf_notification_event(int $uid,?int $nid,string $type,array $meta=[]): void {}
function sf_crm_agent_log(int $contactId,?int $userId,string $channel,string $trigger,string $dedupe,string $message,string $reason,array $details=[]): int {$GLOBALS['agent_messages']++;return $GLOBALS['agent_messages'];}
function sf_transactional_email(int $uid,string $email,string $subject,string $body,string $kind): array {$GLOBALS['emails']++;return ['id'=>$GLOBALS['emails'],'status'=>'logged'];}
function sf_campaign_marketing_unsubscribe_link(array $contact): string {return 'https://example.test/?unsubscribe=test';}
function sf_crm_log_event(int $contactId,?int $userId,string $eventType,string $title,string $entityType='',string $entityId='',array $meta=[]): int {global $pdo;$GLOBALS['crm_events'][]=$eventType;$q=$pdo->prepare('INSERT INTO fan_crm_events(contact_id,user_id,event_type,title,entity_type,entity_id,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$contactId,$userId,$eventType,$title,$entityType,$entityId,json_encode($meta),gmdate('c')]);return (int)$pdo->lastInsertId();}

require $root.'/api/lifecycle-automation-core.php';
sf_lifecycle_automation_ensure_schema();
foreach(['fan_segments','lifecycle_automations','lifecycle_enrollments','lifecycle_action_log','lifecycle_runs'] as $table){$q=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table));ok((bool)$q->fetchColumn(),'lifecycle schema includes '.$table);}

$segment=sf_lifecycle_segment_save(['name'=>'Engaged buyers','rules'=>['mode'=>'all','conditions'=>[
 ['field'=>'status','operator'=>'equals','value'=>'customer'],
 ['field'=>'marketing_opt_in','operator'=>'equals','value'=>'true'],
 ['field'=>'tag','operator'=>'contains','value'=>'buyer'],
 ['field'=>'merch_product','operator'=>'contains','value'=>'tour-shirt'],
 ['field'=>'merch_spend_cents','operator'=>'gte','value'=>'4000'],
 ['field'=>'listen_count','operator'=>'gte','value'=>'2'],
]]],99);
$preview=sf_lifecycle_segment_preview((int)$segment['id'],100);
ok($preview['count']===1&&$preview['contacts'][0]['email']==='buyer@example.com','dynamic segment matches CRM + merch + listening criteria');
ok(!sf_lifecycle_segment_matches(sf_crm_contact_by_id($leadId),$segment),'non-matching lead is excluded from buyer segment');

$automation=sf_lifecycle_automation_save([
 'name'=>'Buyer nurture','trigger_type'=>'segment_entry','segment_id'=>(int)$segment['id'],'cooldown_hours'=>168,'max_per_contact'=>1,
 'actions'=>[
   ['type'=>'crm_tag','config'=>['tag'=>'nurtured']],
   ['type'=>'notification','config'=>['title'=>'Thanks','body'=>'Thanks for supporting Stonefellow.','link'=>'?view=store']],
   ['type'=>'agent_message','config'=>['message'=>'Thanks for supporting the music.','link'=>'?view=store']],
   ['type'=>'email','config'=>['subject'=>'Thanks for your support','body'=>'A quick Stonefellow thank you.']],
   ['type'=>'wait','config'=>['hours'=>1]],
   ['type'=>'exit','config'=>[]],
 ]
],99);
ok($automation['status']==='draft'&&$automation['version']===1,'new lifecycle automation starts as draft version 1');
$activationBlocked=false;try{sf_lifecycle_automation_status((int)$automation['id'],'active',99,false);}catch(RuntimeException $e){$activationBlocked=true;}
ok($activationBlocked,'automation activation requires explicit Admin confirmation');
$automation=sf_lifecycle_automation_status((int)$automation['id'],'active',99,true);
ok($automation['status']==='active'&&!empty($automation['activated_at']),'confirmed automation activation succeeds');

$run=sf_lifecycle_run_automation((int)$automation['id'],'cron',100,true);
ok($run['enrolled']===1,'segment-entry trigger enrolls the eligible buyer once');
ok($GLOBALS['notifications']===2,'journey delivered one lifecycle notification plus one Agent notification');
ok($GLOBALS['agent_messages']===1,'journey delivered one permission-approved Agent message');
ok($GLOBALS['emails']===1,'journey delivered one newsletter-consented lifecycle email');
$buyer=sf_crm_contact_by_id($buyerId);$tags=json_decode($buyer['tags_json'],true);
ok(in_array('nurtured',$tags,true),'journey CRM tag action updated canonical fan profile');
$enroll=sf_lifecycle_enrollments((int)$automation['id'],10)[0];
ok($enroll['status']==='active'&&(int)$enroll['current_step']===5&&strtotime($enroll['next_run_at'])>time(),'wait action persists journey position and future resume time');

$repeat=sf_lifecycle_run_automation((int)$automation['id'],'cron',100,true);
ok($repeat['enrolled']===0&&$GLOBALS['emails']===1&&$GLOBALS['agent_messages']===1,'repeat cron run does not duplicate enrollment or previously executed messages');

$pdo->prepare('UPDATE lifecycle_enrollments SET next_run_at=? WHERE id=?')->execute([gmdate('c',time()-10),(int)$enroll['id']]);
$resume=sf_lifecycle_run_automation((int)$automation['id'],'cron',100,true);
$enroll2=sf_lifecycle_enrollments((int)$automation['id'],10)[0];
ok($enroll2['status']==='completed'&&!empty($enroll2['completed_at']),'due journey resumes after wait and completes at Exit');
ok($GLOBALS['emails']===1&&$GLOBALS['agent_messages']===1,'resume does not repeat earlier email or Agent actions');

$edited=sf_lifecycle_automation_save([
 'id'=>(int)$automation['id'],'name'=>'Buyer nurture v2','trigger_type'=>'segment_entry','segment_id'=>(int)$segment['id'],'cooldown_hours'=>168,'max_per_contact'=>1,
 'actions'=>[['type'=>'crm_tag','config'=>['tag'=>'nurtured-v2']],['type'=>'exit','config'=>[]]]
],99);
ok($edited['status']==='paused'&&$edited['version']===2,'editing an active automation pauses it and increments version');

$optOutAutomation=sf_lifecycle_automation_save(['name'=>'Consent guard','trigger_type'=>'manual','segment_id'=>null,'cooldown_hours'=>0,'max_per_contact'=>1,'actions'=>[
 ['type'=>'notification','config'=>['title'=>'Hello','body'=>'Hi']],
 ['type'=>'agent_message','config'=>['message'=>'Hello from Agent']],
 ['type'=>'email','config'=>['subject'=>'Hello','body'=>'Marketing']],
 ['type'=>'exit','config'=>[]]
]],99);
$optOutAutomation=sf_lifecycle_automation_status((int)$optOutAutomation['id'],'active',99,true);
$lead=sf_crm_contact_by_id($leadId);$en=sf_lifecycle_enroll($optOutAutomation,$lead,'manual:test',[]);
$r=sf_lifecycle_process_enrollment((int)$en['enrollment_id'],20);
ok($r['skipped']===3&&$r['status']==='completed','unlinked/opted-out fan safely skips notification, Agent and marketing email actions');
ok($GLOBALS['emails']===1&&$GLOBALS['agent_messages']===1,'consent guard prevents additional email and Agent delivery');

sf_crm_log_event($buyerId,1,'newsletter_signup','Joined newsletter','newsletter','',[]);
$eventAuto=sf_lifecycle_automation_save(['name'=>'Newsletter event','trigger_type'=>'newsletter_signup','cooldown_hours'=>0,'max_per_contact'=>5,'actions'=>[['type'=>'crm_tag','config'=>['tag'=>'newsletter-journey']],['type'=>'exit','config'=>[]]]],99);
$eventAuto=sf_lifecycle_automation_status((int)$eventAuto['id'],'active',99,true);
sf_crm_log_event($buyerId,1,'newsletter_signup','Joined newsletter again','newsletter','',[]);
$candidates=sf_lifecycle_trigger_candidates($eventAuto,100);
ok(count($candidates)===1&&str_starts_with($candidates[0]['trigger_key'],'event:'),'event trigger uses durable CRM event ID and ignores pre-activation events');

$summary=sf_lifecycle_summary();
ok($summary['segments']===1&&$summary['automations']===3,'lifecycle summary reports segments and automations');

$core=src('api/lifecycle-automation-core.php');$api=src('admin/api/lifecycle-automations.php');$cron=src('cron-lifecycle.php');$adminJs=src('admin/assets/lifecycle-automations.js');$adminCss=src('admin/assets/lifecycle-automations.css');$adminShell=src('admin/index.php');$admin=src('admin/assets/admin.js');$notification=src('api/notification-core.php');$boot=src('api/bootstrap.php');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/lifecycle-automation-core.php'"),'lifecycle automation core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.18'"),'database schema advances to 1.3.18');
ok(str_contains($mig,"'id'=>'2026-10-10-019'")&&str_contains($mig,'sf_lifecycle_automation_ensure_schema'),'migration 019 installs lifecycle automation schema');
foreach(['fan_segments','lifecycle_automations','lifecycle_enrollments','lifecycle_action_log','lifecycle_runs'] as $table)ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS fan_segments')===2,'fan segment schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS lifecycle_automations')===2,'automation schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS lifecycle_enrollments')===2,'enrollment schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS lifecycle_action_log')===2,'action dedupe schema supports SQLite and MySQL');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS lifecycle_runs')===2,'run history schema supports SQLite and MySQL');

foreach(['status','marketing_opt_in','agent_auto_engage','linked_account','tag','source','contact_age_days','last_engaged_days','crm_event','merch_product','merch_spend_cents','merch_units','listen_count','last_listen_days'] as $field)ok(str_contains($core,"'".$field."'"),'segment engine supports '.$field);
foreach(['segment_entry','daily','newsletter_signup','merch_purchase','campaign_converted','inactivity','manual'] as $trigger)ok(str_contains($core,"'".$trigger."'"),'automation engine supports '.$trigger.' trigger');
foreach(['wait','crm_tag','crm_stage','notification','agent_message','email','exit'] as $action)ok(str_contains($core,"'".$action."'"),'journey engine supports '.$action.' action');
ok(str_contains($core,'UNIQUE(automation_id,contact_id,trigger_key)')||str_contains($core,'uq_lifecycle_enrollment'),'enrollment dedupe is database-enforced');
ok(str_contains($core,'dedupe_key TEXT NOT NULL UNIQUE')||str_contains($core,'dedupe_key VARCHAR(255) NOT NULL UNIQUE'),'action dedupe is database-enforced');
ok(str_contains($core,'attempts>=3')||str_contains($core,"attempts']>=3"),'failed actions have bounded retry protection');
ok(str_contains($core,'marketing_opt_in')&&str_contains($core,'marketing_opt_out'),'marketing email action has newsletter-consent guard');
ok(str_contains($core,'agent_auto_engage')&&str_contains($core,'proactive_agent_disabled'),'Agent action has proactive-permission guard');
ok(str_contains($core,'notification_preference_disabled'),'in-app lifecycle action honors notification preference');
ok(str_contains($notification,"'lifecycle'=>"),'user notification preferences include fan lifecycle messages');
ok(str_contains($core,'sf_campaign_marketing_unsubscribe_link'),'lifecycle marketing email includes unsubscribe path');

ok(str_contains($adminShell,'data-view="automations"')&&str_contains($adminShell,'assets/lifecycle-automations.js'),'Segments + Automations is a first-class Admin module');
ok(str_contains($adminJs,'Dynamic fan segments')&&str_contains($adminJs,'Preview matching fans'),'Admin lifecycle UI supports dynamic segment creation and preview');
ok(str_contains($adminJs,'Journey actions')&&str_contains($adminJs,'Run now'),'Admin lifecycle UI supports ordered journey actions and manual run');
ok(str_contains($adminJs,'Activation boundary')&&str_contains($adminJs,'requires explicit Admin confirmation'),'UI explains consequential activation boundary');
ok(str_contains($adminCss,'.segment-condition')&&str_contains($adminCss,'.journey-action')&&str_contains($adminCss,'.life-history-grid'),'lifecycle workspace has dedicated responsive styling');
ok(str_contains($api,"action==='preview_rules'")&&str_contains($api,"action==='save_segment'")&&str_contains($api,"action==='save_automation'")&&str_contains($api,"action==='run_now'"),'Admin API manages segments, automations, preview and runs');
ok(str_contains($api,'sf_agent_brain_log'),'lifecycle authoring, activation and manual runs feed Admin Agent Brain');
ok(str_contains($cron,'sf_lifecycle_run_all'),'CLI lifecycle runner executes all active automations');
ok(str_contains($cron,"PHP_SAPI!=='cli'"),'lifecycle cron endpoint is CLI-only');

ok(str_contains($version,"'stonefellow'=>'1.3.21'")&&str_contains($version,"'database_schema_target'=>'1.3.18'"),'version endpoint reports app 1.3.21 and schema 1.3.18');
ok(str_contains($version,"'fan_segments'=>'crm-merch-listening-dynamic-rules'")&&str_contains($version,"'lifecycle_automations'=>'event-segment-inactivity-triggers-wait-resume-dedupe'"),'version endpoint advertises segments and lifecycle automations');
ok(str_contains($wf,'node --check admin/assets/lifecycle-automations.js')&&str_contains($wf,'php tests/v1321-section22-lifecycle-automations.php'),'release gate includes lifecycle JavaScript and Section 22 suite');
echo "Stonefellow v1.3.21 Section 22 Fan Segments, Automations & Lifecycle Journeys audit: PASS\n";
