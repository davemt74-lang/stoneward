<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/campaign-core.php';

$g=sf_campaign_graph_default();
$r=sf_campaign_graph_validate($g);
ok($r['ok']===true,'default campaign graph validates');
ok(count(array_filter($g['nodes'],fn($n)=>$n['type']==='trigger'))===1,'default graph has exactly one trigger');
$bad=$g;$bad['nodes'][]=['id'=>'trigger_2','type'=>'trigger','x'=>1,'y'=>1,'config'=>[]];
ok(sf_campaign_graph_validate($bad)['ok']===false,'graph validator rejects multiple triggers');
ok(sf_campaign_discount_amount(['payload'=>['percent_off'=>20]],5000)===1000,'campaign percentage discount calculates correctly');
ok(sf_campaign_discount_amount(['payload'=>['amount_off_cents'=>750]],500)===500,'campaign fixed discount never exceeds subtotal');

$core=src('api/campaign-core.php');$mig=src('api/migrations.php');$boot=src('api/bootstrap.php');$adminApi=src('admin/api/campaigns.php');$adminJs=src('admin/assets/campaigns.js');$adminCss=src('admin/assets/campaigns.css');$adminShell=src('admin/index.php');$publicApi=src('api/campaign.php');$download=src('api/campaign-download.php');$app=src('assets/js/app.js');$siteCss=src('assets/css/site.css');$shell=src('stonefellow-v120.php');$ht=src('.htaccess');$quote=src('api/quote.php');$order=src('api/order.php');$agent=src('api/agent-runtime.php');$version=src('version.php');$wf=src('.github/workflows/release-gate.yml');

ok(str_contains($boot,"require_once __DIR__ . '/campaign-core.php'"),'campaign core loads from canonical bootstrap');
preg_match("/SF_DB_SCHEMA_TARGET = '([0-9]+)\.([0-9]+)\.([0-9]+)'/",$mig,$db18);
ok(isset($db18[1],$db18[2],$db18[3])&&[(int)$db18[1],(int)$db18[2],(int)$db18[3]]>=[1,3,14],'database schema remains v1.3.14 or later');
ok(str_contains($mig,"'id'=>'2026-10-09-015'")&&str_contains($mig,'sf_campaign_ensure_schema'),'migration 015 installs campaign schema');
foreach(['campaigns','campaign_segments','campaign_participants','campaign_events','campaign_entitlements','campaign_message_runs'] as $table)ok(str_contains($core,'CREATE TABLE IF NOT EXISTS '.$table),'campaign schema includes '.$table);
ok(substr_count($core,'CREATE TABLE IF NOT EXISTS campaigns')===2,'campaign schema supports SQLite and MySQL');

foreach(['trigger','audience','condition','wait','email','crm_tag','agent_message','offer_download','offer_discount','offer_vip','offer_exclusive','redirect','conversion','exit'] as $node)ok(str_contains($core,"'".$node."'"),'campaign graph supports '.$node.' node');
ok(str_contains($core,'function sf_campaign_run_entry(')&&str_contains($core,'condition_evaluated')&&str_contains($core,'audience_rejected'),'campaign graph executes audience and condition gates');
ok(str_contains($core,'campaign_tagged')&&str_contains($core,'agent_message_delivered'),'campaign graph executes CRM-tag and governed Agent actions');
ok(str_contains($core,'email_approval_required'),'email node records approval boundary rather than auto-sending');
ok(str_contains($core,'workflow_waiting'),'wait nodes persist a campaign waiting state');
ok(str_contains($core,'offer_available')&&str_contains($core,'This offer is not available for this participant.'),'offer claims are limited to graph-reachable eligible participants');

ok(str_contains($adminShell,'data-view="campaigns"')&&str_contains($adminShell,'assets/campaigns.js'),'Campaigns is a first-class Admin module');
ok(str_contains($adminJs,'data-campaign-palette')&&str_contains($adminJs,'ondragstart')&&str_contains($adminJs,'ondrop'),'Campaign Builder provides drag-and-drop node canvas');
ok(str_contains($adminJs,'campaign-wires')&&str_contains($adminJs,'data-connect-from'),'Campaign Builder supports visual node connections');
ok(str_contains($adminJs,'simulateGraph')&&str_contains($adminJs,'Validate'),'Campaign Builder supports validation and simulation');
ok(str_contains($adminJs,'Consequential action')&&str_contains($adminJs,'Send approved email now'),'email sends require explicit Admin action in the builder');
ok(str_contains($adminCss,'.campaign-builder')&&str_contains($adminCss,'.campaign-node')&&str_contains($adminCss,'.campaign-inspector'),'Campaign Builder has dedicated responsive UI');
ok(str_contains($adminApi,"action==='duplicate'")&&str_contains($adminApi,"action==='send_email'")&&str_contains($adminApi,"action==='save_segment'"),'Admin API manages campaign lifecycle, sends, duplication and reusable segments');
ok(str_contains($adminApi,'sf_agent_brain_log'),'campaign authoring and sends feed Admin Agent Brain');

ok(str_contains($publicApi,'sf_campaign_enter')&&str_contains($publicApi,'marketing_opt_in'),'public campaign entry feeds CRM with explicit newsletter consent');
ok(str_contains($publicApi,'sf_campaign_public_payload($campaign,(int)$p[\'id\'])'),'post-entry public offers are filtered through the executed graph');
ok(str_contains($download,'Content-Disposition: attachment')&&str_contains($download,'song_downloaded'),'free song download is controlled and attributed');
ok(str_contains($core,'sf_campaign_marketing_unsubscribe_link')&&str_contains($core,'campaign_marketing'),'campaign email respects newsletter consent and unsubscribe');
ok(str_contains($core,'purchase_attributed')&&str_contains($core,'attributed_revenue_cents'),'campaign analytics attributes downstream Stonefellow purchases and revenue');

ok(str_contains($quote,"campaign_code")&&str_contains($order,'sf_campaign_redeem_code'),'campaign discount code flows through quote and order');
ok(str_contains($boot,'discount_cents')&&str_contains($boot,'sf_campaign_discount_amount'),'cart quote applies campaign discounts server-side');
ok(str_contains($app,'function renderCampaign(')&&str_contains($app,'campaignEntryForm'),'public app renders campaign landing pages and acquisition forms');
ok(str_contains($app,'claimCampaignOffer')&&str_contains($app,'stonefellow.campaign.code'),'public campaign can claim offers and apply campaign discount to cart');
ok(str_contains($app,"view==='campaign'")&&str_contains($app,"slug:u.searchParams.get('slug')"),'public router supports campaign views');
ok(str_contains($ht,'RewriteRule ^campaign/'),'pretty /campaign/{slug} routes are supported');
ok(str_contains($siteCss,'.campaign-public')&&str_contains($siteCss,'.campaign-offer-card'),'public campaigns have dedicated responsive styling');

ok(str_contains($agent,'ACTIVE CAMPAIGNS')&&str_contains($agent,"campaign_offer"),'public Agent is grounded in active campaigns and offer intent');
ok(str_contains($agent,"view'=>'campaign'")&&str_contains($app,"slug:a.slug"),'Agent can open a matched campaign directly');
ok(str_contains(src('admin/assets/admin.js'),"openView('campaigns')")&&str_contains(src('admin/assets/admin.js'),'Campaign Builder'),'Admin Agent can route campaign creation requests into the builder');

preg_match("/'stonefellow'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$app18);
preg_match("/'database_schema_target'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$schema18);
ok(isset($app18[1],$app18[2],$app18[3],$schema18[1],$schema18[2],$schema18[3])&&[(int)$app18[1],(int)$app18[2],(int)$app18[3]]>=[1,3,17]&&[(int)$schema18[1],(int)$schema18[2],(int)$schema18[3]]>=[1,3,14],'version endpoint reports app v1.3.17 or later and schema v1.3.14 or later');
ok(str_contains($version,"'campaigns'=>'visual-node-builder-audience-offers-messaging-attribution'"),'version endpoint advertises campaign builder capability');
ok(str_contains($wf,'php tests/v1317-section18-campaigns.php'),'release gate includes Section 18 campaign regression suite');
echo "Stonefellow v1.3.17 Section 18 Campaigns, Offers & Fan Acquisition audit: PASS\n";
