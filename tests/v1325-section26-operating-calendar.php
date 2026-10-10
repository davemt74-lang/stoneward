<?php
declare(strict_types=1);
$root=dirname(__DIR__);if(!defined('SF_ROOT'))define('SF_ROOT',$root);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function sf_db(): PDO {global $pdo;return $pdo;}
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=120): string {$s=trim((string)$v);return substr($s,0,$max);}
require $root.'/api/operating-calendar-core.php';

sf_calendar_ensure_schema();
foreach(['operating_plans','operating_milestones'] as $table){$q=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=".$pdo->quote($table));ok((bool)$q->fetchColumn(),'operating calendar schema includes '.$table);}

$templates=sf_calendar_templates();
ok(isset($templates['single_release'],$templates['album_release'],$templates['show_launch'],$templates['campaign_launch'],$templates['custom']),'calendar provides single, album, show, campaign and custom templates');
ok(count($templates['single_release'])>=9&&count($templates['album_release'])>=11,'release templates provide a substantive launch checklist');

$target=gmdate('c',time()+90*86400);
$plan=sf_calendar_save_plan(['name'=>'Test single launch','plan_type'=>'single_release','status'=>'active','target_at'=>$target,'timezone'=>'America/Phoenix','primary_entity_type'=>'release','primary_entity_id'=>'test-single'],1);
ok((int)$plan['id']>0&&count($plan['milestones'])===count($templates['single_release']),'new release plan seeds template milestones');
ok($plan['readiness']['percent']===0.0||$plan['readiness']['percent']===0,'new launch plan starts at zero percent complete');
$first=$plan['milestones'][0];$second=$plan['milestones'][1];
ok((int)$second['depends_on_id']===(int)$first['id'],'template milestones are dependency chained');
$blocked=false;try{sf_calendar_set_milestone_status((int)$second['id'],'completed');}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'dependency');}
ok($blocked,'downstream milestone cannot complete before its dependency');
sf_calendar_set_milestone_status((int)$first['id'],'completed');
$secondDone=sf_calendar_set_milestone_status((int)$second['id'],'completed');
ok($secondDone['status']==='completed','milestone completes after dependency is satisfied');
$plan=sf_calendar_plan((int)$plan['id']);
ok((int)$plan['readiness']['completed']===2&&$plan['readiness']['percent']>0,'readiness score reflects completed milestones');

$custom=sf_calendar_save_milestone(['plan_id'=>$plan['id'],'title'=>'Radio interview','milestone_type'=>'press','due_at'=>gmdate('c',time()+50*86400),'priority'=>'normal','blocking'=>false,'owner_label'=>'Press'],);
ok($custom['title']==='Radio interview'&&$custom['owner_label']==='Press','Admin can add custom operating milestones');
ok(sf_calendar_delete_milestone((int)$custom['id']),'custom milestone can be deleted without touching source entities');

$core=src('api/operating-calendar-core.php');$boot=src('api/bootstrap.php');$adminApi=src('admin/api/operating-calendar.php');$adminJs=src('admin/assets/admin.js');$calendarJs=src('admin/assets/operating-calendar.js');$calendarCss=src('admin/assets/operating-calendar.css');$adminShell=src('admin/index.php');$mig=src('api/migrations.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/operating-calendar-core.php'"),'operating calendar core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.22'"),'database schema advances to 1.3.22');
ok(str_contains($mig,"'id'=>'2026-10-10-023'")&&str_contains($mig,'sf_calendar_ensure_schema'),'migration 023 installs operating calendar schema');
foreach(['operating_plans','operating_milestones'] as $table){ok(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'operating calendar supports SQLite and MySQL for '.$table);ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);}

ok(str_contains($core,"'single_release'")&&str_contains($core,"'album_release'")&&str_contains($core,"'show_launch'")&&str_contains($core,"'campaign_launch'"),'operating calendar includes direct-to-fan launch templates');
ok(str_contains($core,'offset_days')&&str_contains($core,'sf_calendar_shift'),'template milestones are date-offset based around one launch target');
ok(str_contains($core,'depends_on_id')&&str_contains($core,'Complete the dependency first.'),'milestone dependencies are enforced server-side');
ok(str_contains($core,'blocking_open')&&str_contains($core,'overdue')&&str_contains($core,"'ready'"),'calendar computes launch readiness, blockers and overdue work');

ok(str_contains($core,"SF_ROOT.'/data/releases.json'")&&str_contains($core,'sf_live_shows'),'timeline reads canonical release and show dates rather than copying them');
ok(str_contains($core,'sf_campaign_list')&&str_contains($core,'ticket_offers'),'timeline reads campaign and ticket windows');
ok(str_contains($core,'membership_content')&&str_contains($core,'sf_automation_list'),'timeline reads member-content windows and scheduled automations');
ok(str_contains($core,"'source'=>'native'")&&str_contains($core,"'source'=>'plan'"),'unified timeline distinguishes native source dates from plan milestones');

ok(str_contains($adminShell,'data-view="calendar"')&&str_contains($adminShell,'assets/operating-calendar.js'),'Operating Calendar is a first-class Admin module');
ok(str_contains($adminJs,"calendar:'Operating Calendar'")&&str_contains($adminJs,'SFOperatingCalendar'),'Admin router loads Operating Calendar module');
ok(str_contains($calendarJs,'Unified timeline')&&str_contains($calendarJs,'Launch plans'),'Admin calendar combines source timeline and launch plans');
ok(str_contains($calendarJs,'New launch plan')&&str_contains($calendarJs,'Add milestone'),'Admin can build launch plans and milestones');
ok(str_contains($calendarJs,'data-milestone-complete')&&str_contains($calendarJs,'reorder'),'Admin supports milestone completion and ordering');
ok(str_contains($calendarCss,'.calendar-timeline')&&str_contains($calendarCss,'.milestone-row'),'Operating Calendar has dedicated responsive UI');
ok(str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,'operating_plan_saved'),'plan operations feed Admin audit and Agent Brain');
ok(str_contains($adminApi,'explicit Admin confirmation'),'plan deletion is governed');
ok(str_contains($adminJs,"openView('calendar')")&&str_contains($adminJs,'launch readiness'),'Admin Agent routes launch/calendar requests to Operating Calendar');
ok(str_contains($core,'function sf_calendar_agent_context')&&str_contains($core,'OPERATING CALENDAR'),'calendar exposes an internal Agent-ready operational summary');

preg_match("/'stonefellow'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$app26);
preg_match("/'database_schema_target'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$schema26);
ok(isset($app26[1],$app26[2],$app26[3],$schema26[1],$schema26[2],$schema26[3])&&[(int)$app26[1],(int)$app26[2],(int)$app26[3]]>=[1,3,25]&&[(int)$schema26[1],(int)$schema26[2],(int)$schema26[3]]>=[1,3,22],'version endpoint reports app v1.3.25 or later and schema v1.3.22 or later');
ok(str_contains($version,"'operating_calendar'=>'native-source-timeline-launch-plans-milestones-dependencies-readiness'"),'version endpoint advertises Operating Calendar capability');
ok(str_contains($wf,'node --check admin/assets/operating-calendar.js')&&str_contains($wf,'php tests/v1325-section26-operating-calendar.php'),'release gate includes Operating Calendar JS and Section 26 suite');
echo "Stonefellow v1.3.25 Section 26 Release & Promotion Operating Calendar audit: PASS\n";
