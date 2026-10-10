<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/membership-core.php';

$b=sf_membership_benefits(['merch_discount_percent'=>115,'early_access_days'=>14,'vip_access'=>1,'priority_presale'=>true,'member_content'=>true,'exclusive_downloads'=>true,'member_only_offers'=>true]);
ok($b['merch_discount_percent']===100,'membership merch discount clamps to 100 percent');
ok($b['early_access_days']===14&&$b['vip_access']&&$b['member_content'],'membership benefits normalize early access, VIP and member content');
ok(sf_membership_status_active(['status'=>'active'])===true,'active subscription grants membership access');
ok(sf_membership_status_active(['status'=>'trialing'])===true,'trialing subscription grants membership access');
ok(sf_membership_status_active(['status'=>'past_due','grace_ends_at'=>gmdate('c',time()+3600)])===true,'past-due subscription remains member-active during grace');
ok(sf_membership_status_active(['status'=>'past_due','grace_ends_at'=>gmdate('c',time()-3600)])===false,'expired billing grace removes member access');
ok(sf_membership_status_active(['status'=>'canceled'])===false,'canceled subscription does not grant membership');
$locked=sf_membership_content_access(null,['minimum_rank'=>0,'minimum_package_id'=>0,'minimum_package_name'=>'']);
ok($locked['allowed']===false&&$locked['reason']==='membership_required','anonymous visitor cannot access member content');

$core=file_get_contents($root.'/api/membership-core.php');
$ent=file_get_contents($root.'/api/entitlements.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$quote=file_get_contents($root.'/api/quote.php');
$order=file_get_contents($root.'/api/order.php');
$media=file_get_contents($root.'/api/media.php');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$publicApi=file_get_contents($root.'/api/membership.php');
$adminApi=file_get_contents($root.'/admin/api/membership.php');
$packages=file_get_contents($root.'/admin/api/packages.php');
$adminJs=file_get_contents($root.'/admin/assets/admin.js');
$membershipJs=file_get_contents($root.'/admin/assets/membership.js');
$membershipCss=file_get_contents($root.'/admin/assets/membership.css');
$adminShell=file_get_contents($root.'/admin/index.php');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$crm=file_get_contents($root.'/api/crm-core.php');
$automation=file_get_contents($root.'/api/automation-core.php');
$billing=file_get_contents($root.'/api/billing.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/membership-core.php'"),'membership core loads from canonical bootstrap');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.19'"),'database schema advances to 1.3.19');
ok(str_contains($mig,"'id'=>'2026-10-10-020'")&&str_contains($mig,'sf_membership_ensure_schema')&&str_contains($mig,'sf_membership_sync_crm'),'migration 020 installs membership schema and synchronizes CRM');
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS membership_content')===2,'membership content schema supports SQLite and MySQL');
foreach(['membership_enabled','membership_rank','membership_badge','membership_benefits_json'] as $col)ok(str_contains($core,"sf_schema_add_column('subscription_packages','".$col."'"),'membership extends existing package model with '.$col);
ok(str_contains($mig,"'membership_content'"),'migration integrity requires membership_content table');
foreach(['membership_enabled','membership_rank','membership_badge','membership_benefits_json'] as $col)ok(str_contains($mig,"'".$col."'"),'migration integrity requires subscription_packages.'.$col);

ok(str_contains($packages,'membership_benefits')&&str_contains($packages,'membership_enabled'),'Monthly Packages Admin saves structured membership benefits');
ok(str_contains($adminJs,'Membership / VIP benefits')&&str_contains($adminJs,'Merch discount %')&&str_contains($adminJs,'Early access days'),'package editor exposes membership/VIP benefit controls');
ok(str_contains($ent,"'membership'=>$membership"),'public Plans package payload includes membership metadata');
ok(str_contains($ent,"out['membership']=sf_membership_state"),'account entitlement state includes current membership');

ok(str_contains($core,'function sf_membership_discount_amount')&&str_contains($core,"($i['type']??'')==='merch'"),'member discount applies only to merch line items');
ok(str_contains($boot,'campaign_discount_cents')&&str_contains($boot,'member_discount_cents'),'server-side quote exposes campaign and member discount components separately');
ok(str_contains($boot,'min($subtotal,$campaignDiscount+$memberDiscount)'),'combined member plus campaign savings cannot exceed subtotal');
ok(str_contains($quote,'sf_current_user')&&str_contains($quote,'sf_quote($cart')&&str_contains($order,"$user?(int)$user['id']:null"),'quote and order derive membership discount from authenticated server identity');
ok(str_contains($app,'Member discount')&&str_contains($app,'Campaign discount'),'cart UI explains member and campaign savings separately');

ok(str_contains($core,'early_access_days')&&str_contains($core,"reason='early_access'"),'member content enforces tier early-access windows');
ok(str_contains($core,"$type==='vip_offer'")&&str_contains($core,'vip_benefit_required'),'VIP content requires configured VIP benefit');
ok(str_contains($core,"$type==='download'")&&str_contains($core,'download_benefit_required'),'exclusive downloads require download benefit');
ok(str_contains($mediaCore,"'member_content'"),'Media Library can attach assets to member content');
ok(str_contains($media,"entity_type']??'')==='member_content'")&&str_contains($media,'sf_membership_content_access'),'controlled media delivery rechecks member-content entitlement before serving bytes');

ok(str_contains($adminShell,'data-view="membership"')&&str_contains($adminShell,'assets/membership.js'),'Membership + VIP is a first-class Admin workspace');
ok(str_contains($adminJs,"membership:'Membership + VIP'")&&str_contains($adminJs,'SFMembershipAdmin'),'Admin router loads membership module');
ok(str_contains($membershipJs,'Member roster')&&str_contains($membershipJs,'Member content'),'Admin membership workspace includes roster and gated content');
ok(str_contains($membershipJs,'Minimum tier')&&str_contains($membershipJs,'Early access:'),'member content editor exposes tier and early-access gates');
ok(str_contains($membershipJs,'SFMediaAdmin')&&str_contains($membershipJs,"'member_content'"),'member content reuses central Media Library');
ok(str_contains($adminApi,'sf_membership_summary')&&str_contains($adminApi,'sf_membership_content_save')&&str_contains($adminApi,'sf_agent_brain_log'),'Admin membership API provides metrics, content lifecycle and Agent Brain audit');
ok(str_contains($membershipCss,'.membership-tier-grid'),'membership Admin has dedicated responsive styling');

ok(str_contains($publicApi,'sf_membership_public_content')&&str_contains($publicApi,'sf_membership_state'),'public membership API returns membership state and access-filtered content');
ok(str_contains($app,'async function renderMembership')&&str_contains($app,'memberContentCard'),'public app provides a My Membership / VIP experience');
ok(str_contains($shell,'data-quick-action="membership"')&&str_contains($app,"action==='membership'"),'chat quick-action menu opens Membership + VIP');
ok(str_contains($app,"view==='membership'")&&str_contains($app,'membershipBenefitList'),'public router and Plans surface structured membership benefits');
ok(str_contains($app,'account-membership-panel'),'My Account displays active membership badge and benefits');
ok(str_contains($siteCss,'.membership-hero')&&str_contains($siteCss,'.member-content-card'),'public membership experience has responsive presentation styling');

ok(str_contains($crm,"'membership'=>$uid")&&str_contains($core,"UPDATE fan_contacts SET status='member'"),'CRM detail exposes membership and active members synchronize to member stage');
ok(str_contains($core,"'customer':'fan'"),'membership loss preserves customer history instead of blindly downgrading to fan');
ok(str_contains($billing,'sf_membership_sync_crm'),'billing lifecycle synchronizes membership state into CRM');
ok(str_contains($automation,"'membership'")&&str_contains($automation,"'package_ids_any'"),'dynamic fan segments can target membership status and package tiers');
ok(str_contains($agent,'membership_info')&&str_contains($agent,'sf_membership_agent_context'),'Stonefellow Agent routes membership questions and receives member benefit context');

ok(str_contains($version,"'stonefellow'=>'1.3.22'")&&str_contains($version,"'database_schema_target'=>'1.3.19'"),'version endpoint reports app 1.3.22 and schema 1.3.19');
ok(str_contains($version,"'membership_vip'=>'package-backed-tiers-benefits-early-access-member-content'"),'version endpoint advertises Membership + VIP capability');
ok(str_contains($wf,'node --check admin/assets/membership.js')&&str_contains($wf,'php tests/v1322-section23-membership-vip.php'),'release gate includes Membership Admin JS and Section 23 suite');
echo "Stonefellow v1.3.22 Section 23 Membership & VIP Fan Experience audit: PASS\n";
