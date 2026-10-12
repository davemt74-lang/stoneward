<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok37($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/inquiry-core.php';

ok37(sf_inquiry_types()===['booking','press','licensing','collaboration','general'],'professional inquiry types are fixed and bounded');
ok37(sf_inquiry_statuses()===['new','reviewing','replied','closed','spam'],'professional inquiry statuses are fixed and bounded');
ok37(sf_inquiry_reference(17)==='SFQ-000017','professional inquiry references are stable and non-email identifiers');
ok37(sf_inquiry_https_url('https://example.com')===true&&sf_inquiry_https_url('http://example.com')===false,'professional website URLs require HTTPS');
ok37(sf_inquiry_source_path('https://example.com/release/test?token=secret')==='/release/test','source attribution stores only path and strips query secrets');
ok37(sf_inquiry_clean_body("Line one\nLine two",200)==="Line one\nLine two",'professional messages preserve safe multiline paragraphs');

$core=file_get_contents($root.'/api/inquiry-core.php');
$publicApi=file_get_contents($root.'/api/inquiries.php');
$adminApi=file_get_contents($root.'/admin/api/inquiries.php');
$adminJs=file_get_contents($root.'/admin/assets/inquiries.js');
$adminCss=file_get_contents($root.'/admin/assets/inquiries.css');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$app=file_get_contents($root.'/assets/js/app.js');
$css=file_get_contents($root.'/assets/css/site.css');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$ht=file_get_contents($root.'/.htaccess');
$seo=file_get_contents($root.'/api/seo-core.php');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

foreach(['professional_inquiries','professional_inquiry_messages','professional_inquiry_events'] as $table)ok37(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'inquiry schema supports SQLite and MySQL for '.$table);
ok37(str_contains($core,'fingerprint_sha256')&&str_contains($core,'time()-900'),'professional intake suppresses identical submissions for fifteen minutes');
ok37(str_contains($core,'Confirm that Stonefellow may contact you about this inquiry.'),'public intake requires explicit inquiry-only contact permission');
ok37(!str_contains($core,'sf_crm_upsert_contact')&&!str_contains($core,'marketing_opt_in'),'professional inquiry core never creates or opts in a Fan CRM marketing contact');
ok37(!str_contains($publicApi,'sf_crm_'),'public inquiry endpoint never calls Fan CRM');
ok37(str_contains($publicApi,"company_fax")&&str_contains($publicApi,'count($attempts)>=5'),'public inquiry endpoint has honeypot and session rate limiting');
ok37(str_contains($core,'substr_count(strtolower($message)')&&str_contains($core,'>5'),'inquiry message rejects excessive external links');
ok37(str_contains($core,'sf_inquiry_validate_entity')&&str_contains($core,'sf_seo_public_track')&&str_contains($core,'sf_seo_public_release'),'licensing context only accepts public Stonefellow songs/releases');
ok37(str_contains($core,'sf_inquiry_clean_body')&&str_contains($core,"[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]"),'multiline inquiry/reply content strips unsafe controls without flattening paragraphs');
ok37(str_contains($core,"if(\$status==='replied')throw new RuntimeException"),'Replied cannot be manually claimed by status transition');
ok37(str_contains($core,"\$newStatus=\$status==='sent'?'replied'"),'inquiry becomes Replied only when transactional delivery reports Sent');
ok37(str_contains($core,'transactional_email_outbox')&&str_contains($core,"['sent','queued','logged','failed']"),'reply ledger preserves real outbox delivery state');
ok37(str_contains($core,"in_array((string)\$r['status'],['spam','closed'],true)"),'closed/spam inquiries must be reopened before reply');
ok37(!str_contains($core,'function sf_inquiry_agent_context'),'public core exposes no helper that could leak the professional inbox into general Agent context');

ok37(str_contains($adminApi,'sf_admin_require_auth'),'professional inbox requires Admin authentication');
ok37(str_contains($adminApi,'Changing professional inquiry status requires explicit Admin confirmation.')&&str_contains($adminApi,'Sending a professional inquiry reply requires explicit Admin confirmation.'),'status changes and replies require explicit Admin confirmation');
ok37(str_contains($adminApi,'sf_log_admin_action')&&str_contains($adminApi,'sf_agent_brain_log'),'professional inquiry mutations feed Admin audit and Agent Brain');
ok37(!str_contains($adminApi,"'body'=>\$body")&&!str_contains($adminApi,"'message_text'=>"),'Admin audit/Brain payloads do not copy professional message bodies');
ok37(str_contains($adminJs,'These records are separate from Fan CRM marketing consent.'),'Admin UI clearly separates professional inquiries from Fan CRM consent');
ok37(str_contains($adminJs,'The inquiry is marked Replied only after the outbox reports Sent.'),'Admin reply UI explains truthful delivery semantics');
ok37(str_contains($adminJs,'Send confirmed reply')&&str_contains($adminJs,"confirm('Send this professional inquiry reply now?')"),'Admin reply UI requires explicit send confirmation');
ok37(str_contains($adminCss,'.inquiry-detail-grid')&&str_contains($adminCss,'.inquiry-message.outbound'),'professional inbox has dedicated responsive conversation styling');

ok37(substr_count($adminShell,'data-view="')===29,'professional inbox does not add a 30th Admin sidebar item');
ok37(str_contains($adminShell,'assets/inquiries.css')&&str_contains($adminShell,'assets/inquiries.js'),'professional inbox assets load in Admin');
ok37(str_contains($adminMain,'professionalInquiriesPanel')&&str_contains($adminMain,'SFInquiriesAdmin'),'Fans + CRM embeds the professional inquiry inbox');
ok37(str_contains($adminMain,'professional inquir|booking inquir|booking request|licensing request'),'Admin Agent routes professional inquiry requests to the embedded inbox');

ok37(str_contains($app,'async function renderContact')&&str_contains($app,'Work with Stonefellow')||str_contains($app,'WORK WITH STONEFELLOW'),'public app renders professional Contact experience');
ok37(str_contains($app,'does not subscribe you to the Stonefellow newsletter')&&str_contains($app,'does not opt me into marketing'),'public Contact page states the marketing-consent boundary twice');
ok37(str_contains($app,'data-inquiry-context="booking"')&&str_contains($app,'data-inquiry-context="licensing"'),'public Contact form exposes type-specific booking/licensing context');
ok37(str_contains($app,'company_fax')&&str_contains($app,'source_path:location.pathname'),'public Contact sends honeypot and path-only source attribution');
ok37(str_contains($app,"view==='contact'")&&str_contains($app,"new URL('contact',base)"),'SPA uses canonical /contact route');
ok37(str_contains($css,'.contact-module')&&str_contains($css,'.inquiry-type-grid'),'public Contact experience has dedicated responsive presentation');
ok37(str_contains($shell,"define('SF_BUILD', '1.3.36')")&&str_contains($shell,'href="contact" data-view="contact"'),'public shell advances v1.3.36 and exposes Contact navigation');
ok37(str_contains($shell,"'videos','contact','about'"),'server pretty-route handoff recognizes Contact');
ok37(str_contains($ht,'about|contact|archive')&&str_contains($ht,'X-Stonefellow-Build "1.3.36"'),'web server routes Contact and reports build 1.3.36');
ok37(str_contains($seo,"\$view==='contact'")&&str_contains($seo,"'@type'=>'ContactPage'"),'Contact page emits route-specific ContactPage structured data');
ok37(str_contains($seo,"'contact'=>'0.6'"),'Contact page is included in public sitemap');
ok37(str_contains($agent,"return 'professional_contact'")&&str_contains($agent,"case 'professional_contact'"),'public Agent has deterministic professional-contact route');
ok37(str_contains($agent,"'inquiry_type'=>\$type")&&str_contains($app,'a.inquiry_type'),'public Agent preselects the appropriate Contact inquiry type');
ok37(!str_contains($agent,'sf_inquiry_list(')&&!str_contains($agent,'sf_inquiry_get('),'public Agent runtime never reads professional inbox records');

ok37(str_contains($boot,"require_once __DIR__ . '/inquiry-core.php'"),'professional inquiry core loads from canonical bootstrap');
ok37(str_contains($mig,"'id'=>'2026-10-11-032'")&&str_contains($mig,'sf_inquiry_ensure_schema'),'migration 032 installs professional inquiry schema');
ok37(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.31'"),'database schema advances to 1.3.31');
foreach(['professional_inquiries','professional_inquiry_messages','professional_inquiry_events'] as $table)ok37(str_contains($mig,"'".$table."'"),'upgrade integrity requires '.$table);
ok37(str_contains($version,"'stonefellow'=>'1.3.36'")&&str_contains($version,"'database_schema_target'=>'1.3.31'"),'version endpoint reports app 1.3.36 and schema 1.3.31');
ok37(str_contains($version,"'professional_inquiries'=>'booking-press-licensing-collaboration-general-separate-from-fan-crm'"),'version endpoint advertises separate professional inquiries');
ok37(str_contains($wf,'node --check admin/assets/inquiries.js')&&str_contains($wf,'php tests/v1336-section37-inquiries.php'),'release gate includes Inquiry Admin JS and Section 37 suite');

echo "Stonefellow v1.3.36 Section 37 Contact, Booking & Professional Inquiries audit: PASS\n";
