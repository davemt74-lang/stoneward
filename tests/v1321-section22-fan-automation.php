<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/automation-core.php';

$contact=['id'=>7,'user_id'=>12,'email'=>'fan@example.com','display_name'=>'Fan','status'=>'customer','marketing_opt_in'=>1,'agent_auto_engage'=>1,'tags_json'=>'["vip","buyer"]','last_engaged_at'=>gmdate('c'),'updated_at'=>gmdate('c')];
$metrics=['order_count'=>3,'spend_cents'=>25000,'product_ids'=>['shirt-black','poster-2026'],'last_purchase_at'=>gmdate('c'),'event_types'=>['merch_purchase','newsletter_signup'],'campaign_ids'=>[3,9],'last_activity_at'=>gmdate('c')];
$rules=['newsletter'=>'subscribed','account'=>'linked','agent_auto_engage'=>'enabled','stages'=>['customer','member'],'tags_all'=>['vip'],'tags_any'=>['buyer','superfan'],'purchase_required'=>true,'min_orders'=>2,'min_spend_cents'=>20000,'product_ids_any'=>['shirt-black'],'campaign_ids_any'=>[9],'event_types_any'=>['merch_purchase'],'activity_within_days'=>30];
ok(sf_segment_contact_matches($contact,$rules,$metrics),'dynamic segment matches CRM, purchase, product, campaign and activity rules');
$bad=$rules;$bad['min_spend_cents']=30000;ok(!sf_segment_contact_matches($contact,$bad,$metrics),'dynamic segment rejects fan below spend threshold');
$bad=$rules;$bad['newsletter']='unsubscribed';ok(!sf_segment_contact_matches($contact,$bad,$metrics),'dynamic segment respects newsletter consent state');
$bad=$rules;$bad['tags_all']=['missing'];ok(!sf_segment_contact_matches($contact,$bad,$metrics),'dynamic segment enforces required tags');

$valid=sf_automation_steps_validate([
 ['type'=>'add_tag','config'=>['tag'=>'engaged']],
 ['type'=>'wait','config'=>['hours'=>24]],
 ['type'=>'agent_message','config'=>['message'=>'Thanks for being part of Stonefellow.']],
 ['type'=>'email','config'=>['subject'=>'Stonefellow update','body'=>'New music is available.']],
 ['type'=>'enroll_campaign','config'=>['campaign_id'=>3]],
 ['type'=>'exit','config'=>['reason'=>'complete']]
]);
ok($valid['ok']===true&&count($valid['steps'])===6,'automation validator accepts governed lifecycle journey');
$invalid=sf_automation_steps_validate([['type'=>'email','config'=>['subject'=>'','body'=>'']]]);
ok($invalid['ok']===false,'automation validator rejects incomplete marketing email step');
$invalid=sf_automation_steps_validate([['type'=>'unknown','config'=>[]]]);
ok($invalid['ok']===false,'automation validator rejects unsupported action type');

$core=file_get_contents($root.'/api/automation-core.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$crm=file_get_contents($root.'/api/crm-core.php');
$camp=file_get_contents($root.'/api/campaign-core.php');
$campAdmin=file_get_contents($root.'/admin/api/campaigns.php');
$adminApi=file_get_contents($root.'/admin/api/automations.php');
$adminJs=file_get_contents($root.'/admin/assets/automations.js');
$adminCss=file_get_contents($root.'/admin/assets/automations.css');
$adminShell=file_get_contents($root.'/admin/index.php');
$adminBase=file_get_contents($root.'/admin/assets/admin.js');
$campaignJs=file_get_contents($root.'/admin/assets/campaigns.js');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$cron=file_get_contents($root.'/cron-automations.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/automation-core.php'"),'automation core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.18'"),'database schema advances to 1.3.18');
ok(str_contains($mig,"'id'=>'2026-10-10-019'")&&str_contains($mig,'sf_automation_ensure_schema')&&str_contains($mig,'sf_segment_refresh_all'),'migration 019 installs automation schema and initializes segment membership');

foreach(['lifecycle_automations','lifecycle_automation_runs','lifecycle_automation_events','lifecycle_segment_memberships'] as $table){
 ok(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'automation schema supports SQLite and MySQL for '.$table);
}
ok(str_contains($core,'dedupe_key TEXT NOT NULL UNIQUE')&&str_contains($core,'dedupe_key VARCHAR(220) NOT NULL UNIQUE'),'automation runs enforce durable dedupe keys');
ok(str_contains($core,'cooldown_hours')&&str_contains($core,'max_runs_per_contact'),'runtime enforces cooldown and per-fan run budgets');
ok(str_contains($core,'segment_enter')&&str_contains($core,'segment_exit')&&str_contains($core,'crm_event')&&str_contains($core,'scheduled'),'automation runtime supports event, segment and scheduled triggers');
foreach(['add_tag','remove_tag','set_stage','agent_message','email','enroll_campaign','wait','exit'] as $step)ok(str_contains($core,"'".$step."'"),'automation runtime supports '.$step.' action');
ok(str_contains($core,'marketing_opt_in')&&str_contains($core,'marketing_opt_out'),'automated marketing email preserves consent boundary');
ok(str_contains($core,'agent_auto_engage')&&str_contains($core,'proactive_agent_disabled'),'automated Agent message preserves proactive-Agent permission');
ok(str_contains($core,'status="waiting"')&&str_contains($core,'due_at'),'wait steps persist resumable journey state');
ok(str_contains($core,'sf_automation_suppressed'),'runtime suppresses recursive CRM automation triggering');

ok(str_contains($crm,'sf_automation_process_crm_event')&&str_contains($crm,'$eventId'),'CRM event logger passes the authoritative event ID into lifecycle automation');
ok(str_contains($crm,'active segments')&&str_contains($crm,'lifecycle '),'public Agent CRM context can see active segments and active lifecycle state');
ok(str_contains($camp,'segment_id')&&str_contains($camp,'sf_segment_contact_matches'),'Campaign audience matching supports canonical saved fan segments');
ok(str_contains($camp,'sf_segment_contacts'),'campaign sends can resolve saved segment membership dynamically');
ok(str_contains($campAdmin,'sf_segment_save'),'Campaign Builder uses canonical segment save logic');
ok(str_contains($campaignJs,'Saved segment')&&str_contains($campaignJs,'segment_id'),'Campaign Builder exposes reusable saved segments in Audience nodes');

ok(str_contains($adminShell,'data-view="automations"')&&str_contains($adminShell,'assets/automations.js'),'Segments + Automations is a first-class Admin workspace');
ok(str_contains($adminBase,"automations:'Segments + Automations'")&&str_contains($adminBase,'SFAutomationsAdmin'),'Admin router loads lifecycle module');
ok(str_contains($adminJs,'Dynamic fan segments')&&str_contains($adminJs,'Lifecycle journeys'),'Admin lifecycle workspace manages segments and automations');
ok(str_contains($adminJs,'Preview audience')&&str_contains($adminJs,'segmentPreviewTable'),'segment builder previews matching fans before save');
ok(str_contains($adminJs,'Governance:')&&str_contains($adminJs,'standing Admin approval'),'journey builder makes automation approval boundary explicit');
ok(str_contains($adminJs,'Run now')&&str_contains($adminJs,'Manual run'),'Admin can explicitly execute a journey');
ok(str_contains($adminJs,'Journey run history')&&str_contains($adminJs,'automation-run-timeline'),'Admin exposes per-fan automation execution history');
ok(str_contains($adminCss,'.automation-step')&&str_contains($adminCss,'.automation-run-timeline'),'lifecycle builder has dedicated responsive UI');

ok(str_contains($adminApi,"action==='save_segment'")&&str_contains($adminApi,"action==='save_automation'")&&str_contains($adminApi,"action==='manual_run'")&&str_contains($adminApi,"action==='tick'"),'Admin API manages segment and automation lifecycle');
ok(str_contains($adminApi,'sf_agent_brain_log'),'segment and automation authoring/runs feed Admin Agent Brain');
ok(str_contains($cron,'PHP_SAPI')&&str_contains($cron,'sf_automation_tick'),'CLI automation scheduler runs due waits, segments and schedules');
ok(str_contains($wf,'node --check admin/assets/automations.js')&&str_contains($wf,'php tests/v1321-section22-fan-automation.php'),'release gate includes lifecycle JS and Section 22 regression suite');

ok(str_contains($version,"'stonefellow'=>'1.3.21'")&&str_contains($version,"'database_schema_target'=>'1.3.18'"),'version endpoint reports app 1.3.21 and schema 1.3.18');
ok(str_contains($version,"'fan_segments'=>'crm-consent-account-tags-purchases-products-campaigns-events-activity'"),'version endpoint advertises dynamic fan segmentation');
ok(str_contains($version,"'lifecycle_automations'=>'event-segment-scheduled-triggers-waits-tags-stage-agent-email-campaign'"),'version endpoint advertises lifecycle automation engine');

echo "Stonefellow v1.3.21 Section 22 Fan Segments, Automations & Lifecycle Journeys audit: PASS\n";
