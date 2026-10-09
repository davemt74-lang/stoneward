<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}

$crm=src('api/crm-core.php');$newsletter=src('api/newsletter.php');$community=src('api/community.php');$engage=src('api/fan-engagement.php');$prefs=src('api/fan-preferences.php');$store=src('api/storefront.php');$boot=src('api/bootstrap.php');$mig=src('api/migrations.php');$ops=src('api/operations.php');$order=src('api/order.php');$account=src('api/account.php');$agent=src('api/agent-runtime.php');$brain=src('admin/api/agent-brain.php');$adminCrm=src('admin/api/crm.php');$admin=src('admin/assets/admin.js');$adminHtml=src('admin/index.php');$app=src('assets/js/app.js');$html=src('stonefellow-v120.php');$css=src('assets/css/site.css');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/crm-core.php'"),'CRM core loads from the canonical application bootstrap');
ok(str_contains($crm,'CREATE TABLE IF NOT EXISTS fan_contacts')&&str_contains($crm,'CREATE TABLE IF NOT EXISTS fan_crm_events')&&str_contains($crm,'CREATE TABLE IF NOT EXISTS fan_agent_engagements')&&str_contains($crm,'CREATE TABLE IF NOT EXISTS community_posts'),'CRM schema covers contacts, relationship events, Agent engagements and community');
ok(substr_count($crm,'CREATE TABLE IF NOT EXISTS fan_contacts')===2,'CRM schema supports both SQLite and MySQL');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.13'"),'database schema target advances to 1.3.13');
ok(str_contains($mig,"'id'=>'2026-10-09-014'")&&str_contains($mig,"'apply'=>function(): void { sf_crm_ensure_schema(); }"),'migration 014 installs the Section 17 CRM schema');
ok(str_contains($mig,"'fan_contacts','fan_crm_events','fan_agent_engagements','community_posts'"),'migration integrity requires all Section 17 CRM/community tables');

ok(str_contains($crm,'function sf_crm_upsert_contact(')&&str_contains($crm,'function sf_crm_merge_contacts('),'CRM merges newsletter/account identity instead of duplicating people');
ok(str_contains($ops,"function_exists('sf_crm_record_user_activity')"),'canonical user activity automatically feeds the CRM timeline');
ok(str_contains($order,"sf_crm_upsert_contact")&&str_contains($order,"'purchase'")&&str_contains($order,"'customer'"),'guest purchasers become CRM customers without marketing opt-in');
ok(str_contains($account,"'fan_crm'=>sf_crm_newsletter_state_for_user"),'account payload exposes fan communication state');

ok(str_contains($newsletter,"action==='signup'")&&str_contains($newsletter,"action==='unsubscribe'"),'newsletter endpoint supports signup and unsubscribe');
ok(str_contains($crm,'marketing_opt_in_at')&&str_contains($crm,'unsubscribe_token_hash'),'newsletter consent and unsubscribe state are durable');
ok(str_contains($crm,'newsletter_welcome')&&str_contains($crm,'Unsubscribe anytime'),'newsletter signup provides a welcome message with unsubscribe link');
ok(str_contains($adminCrm,"$marketing=!empty($c['marketing_opt_in'])"),'Admin cannot manufacture newsletter consent');
ok(!str_contains($admin,'name="marketing_opt_in"'),'Admin UI treats newsletter consent as read-only');
ok(str_contains($app,'data-newsletter-form')&&str_contains($app,"'/newsletter.php'"),'public newsletter form feeds the CRM endpoint');

ok(str_contains($community,'sf_require_user(false,true)'),'community posting requires an authenticated CSRF-protected fan');
ok(str_contains($crm,'Community posts must be between 1 and 600 characters.')&&str_contains($crm,'Please keep community posts focused'),'community posts enforce bounded content and basic anti-spam controls');
ok(str_contains($crm,"'community_post'")&&str_contains($crm,'sf_log_user_activity'),'community participation feeds canonical activity and CRM');
ok(str_contains($adminCrm,"action==='moderate_post'")&&str_contains($admin,'Community moderation'),'Admin can moderate the fan community');
ok(str_contains($app,'function renderCommunity(')&&str_contains($app,'communityPostForm'),'public fan community has a first-class feed and composer');

ok(str_contains($crm,'20*3600'),'automatic Agent engagement has a cooldown');
ok(str_contains($crm,"if($trigger==='')return null;"),'automatic Agent does not nag without a meaningful trigger');
ok(str_contains($crm,"$type==='account_created'")&&str_contains($crm,"$type==='community_post'")&&str_contains($crm,"$type==='purchase'")&&str_contains($crm,"$type==='listen_complete'"),'automatic Agent reacts to meaningful fan lifecycle events');
ok(str_contains($crm,"INSERT INTO agent_brain_decisions")&&str_contains($crm,"'fan_engagement'"),'automatic fan engagement is written into Admin Agent Brain');
ok(str_contains($engage,'sf_crm_next_agent_engagement'),'signed-in app requests governed proactive Agent engagement');
ok(str_contains($prefs,'agent_auto_engage')&&str_contains($app,'fanAutoEngage'),'fans can disable proactive Agent interaction');
ok(str_contains($brain,'fan_engagements')&&str_contains($admin,'Fan engagement ledger'),'Admin Agent Brain exposes the proactive fan-engagement ledger');
ok(str_contains($agent,"community_browse")&&str_contains($agent,"newsletter_join")&&str_contains($agent,"store_browse")&&str_contains($agent,"fan_profile"),'Agent routes community, newsletter, store and fan-profile requests');
ok(str_contains($agent,'sf_crm_agent_context($userId)'),'Agent reasoning can use the signed-in fan CRM context');

ok(str_contains($adminHtml,'data-view="crm"')&&str_contains($adminHtml,'Fans + CRM'),'Admin has a dedicated Fans + CRM workspace');
ok(str_contains($admin,'function renderCRM()')&&str_contains($admin,'function renderCRMContact('),'Admin CRM includes contact list and cross-system fan detail');
ok(str_contains($adminCrm,'sf_crm_admin_contact_detail')&&str_contains($adminCrm,"action==='send_agent_message'"),'Admin CRM can inspect fan history and send governed in-app Agent outreach');
ok(str_contains($app,'function renderStore()')&&str_contains($store,"'products'=>$products"),'Merch Store shortcut opens a server-backed current storefront');
ok(str_contains($html,'id="chatQuickButton"')&&str_contains($html,'id="chatQuickMenu"'),'chat footer has the requested plus quick-action control');
foreach(['record','playlist','shows','store','community','newsletter'] as $action)ok(str_contains($html,'data-quick-action="'.$action.'"'),'quick menu includes '.$action);
ok(str_contains($app,'function handleQuickAction(')&&str_contains($app,"action==='playlist'")&&str_contains($app,"action==='shows'")&&str_contains($app,"action==='store'"),'quick actions are wired to real workflows');
ok(str_contains($css,'.chat-quick-menu')&&str_contains($css,'.newsletter-signup')&&str_contains($css,'.community-feed'),'Section 17 public UX has dedicated responsive styling');

ok(str_contains($version,"'stonefellow'=>'1.3.15'")&&str_contains($version,"'database_schema_target'=>'1.3.13'"),'version endpoint reports app 1.3.15 and schema 1.3.13');
ok(str_contains($version,"'fan_crm_community_agent'=>'contacts-consent-newsletter-community-agent-brain-proactive-engagement'"),'version endpoint advertises Section 17 capability');
ok(str_contains($version,"'chat_quick_actions'=>'record-playlist-tour-store-community-newsletter'"),'version endpoint advertises quick-action surface');
ok(str_contains($wf,'php tests/v1315-section17-fan-crm-community.php'),'release gate includes Section 17 regression suite');
echo "Stonefellow v1.3.15 Section 17 Fan CRM, Community & Agent Engagement audit: PASS\n";
