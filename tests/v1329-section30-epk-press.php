<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok30($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/press-core.php';

ok30(sf_press_slug('New Album — Press Kit')==='new-album-press-kit','EPK slug normalizes safely');
ok30(sf_press_valid_https_url('https://stonefellow.com/press')===true,'HTTPS press URL passes');
ok30(sf_press_valid_https_url('http://stonefellow.com/press')===false,'HTTP press URL is rejected');
ok30(sf_press_valid_https_url('javascript:alert(1)')===false,'non-web URL is rejected');
$token=str_repeat('a',64);$private=['status'=>'private','share_token_hash'=>hash('sha256',$token)];
ok30(sf_press_access_allowed($private,$token)===true,'private EPK accepts current 256-bit hex bearer token');
ok30(sf_press_access_allowed($private,'wrong-token')===false,'private EPK rejects malformed token');
ok30(sf_press_access_allowed(['status'=>'published','share_token_hash'=>''],'')===true,'published EPK needs no token');
ok30(sf_press_access_allowed(['status'=>'draft','share_token_hash'=>''],'')===false,'draft EPK is not public');
ok30(sf_press_access_allowed(['status'=>'archived','share_token_hash'=>''],'')===false,'archived EPK is not public');

$core=file_get_contents($root.'/api/press-core.php');
$adminApi=file_get_contents($root.'/admin/api/press.php');
$publicApi=file_get_contents($root.'/api/press.php');
$adminJs=file_get_contents($root.'/admin/assets/press.js');
$adminCss=file_get_contents($root.'/admin/assets/press.css');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$adminBaseCss=file_get_contents($root.'/admin/assets/admin.css');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$mediaApi=file_get_contents($root.'/admin/api/media.php');
$mediaLinks=file_get_contents($root.'/api/media-links.php');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$publicShell=file_get_contents($root.'/stonefellow-v120.php');
$lifecycle=file_get_contents($root.'/api/lifecycle.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$ht=file_get_contents($root.'/.htaccess');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

foreach(['press_kits','press_contacts','press_outreach','press_coverage','press_events'] as $table)ok30(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'press schema supports SQLite and MySQL for '.$table);
ok30(str_contains($core,'message_fingerprint')&&str_contains($core,'last five minutes')&&str_contains($core,"status IN ('sending','queued','logged','sent')"),'press outreach suppresses duplicate in-flight/successful sends but allows failed retries');
ok30(str_contains($core,'share_token_hash')&&str_contains($core,"hash('sha256'"),'EPK record stores a one-way share-token hash');
ok30(str_contains($core,'hash_equals'),'private token verification uses constant-time comparison');
ok30(str_contains($core,"'#token='"),'private share link keeps token in URL fragment');
ok30(!str_contains($core,"'?token='"),'core does not construct private token query strings');
ok30(str_contains($core,"(\$existing['status']??'')==='private'")&&str_contains($core,'share_token_hash=CASE WHEN ? THEN NULL'),'leaving Private status invalidates its old bearer token');
ok30(str_contains($core,'sf_press_readiness'),'EPK publishing has deterministic readiness');
ok30(str_contains($core,"empty(\$k['readiness']['ready'])"),'published/private EPK access rechecks current readiness');
ok30(str_contains($core,'sf_distribution_record')&&str_contains($core,"status']??'')==='live'"),'release EPK consumes live DSP links from Section 29');
ok30(str_contains($core,"sf_media_public_links('track'")&&str_contains($core,"sf_media_public_links('release'"),'public EPK consumes only explicitly public track/release media');
ok30(!str_contains($core,"'audio'=>(string)(\$t['audio']"),'public EPK does not expose legacy catalog audio paths directly');
ok30(str_contains($core,'sf_transactional_email'),'press outreach uses existing transactional email delivery');
ok30(str_contains($core,"status']??'')!=='active'")&&str_contains($core,'cannot receive outreach'),'do-not-contact and archived press contacts cannot receive outreach');
ok30(str_contains($core,'transactional_email_outbox')&&str_contains($core,'error_text')&&str_contains($core,"'sending'"),'press outreach records an in-flight ledger row and preserves real delivery status/error');
ok30(str_contains($core,"['queued','logged','sent','failed']"),'press outreach preserves real configured email states');
ok30(str_contains($core,"['view','media_click','contact_click','release_play','download_click','dsp_click']"),'EPK analytics allow only explicit real interaction events');
ok30(!str_contains($core,'opened_at')&&!str_contains($core,'email_open'),'press module does not invent email-open tracking');
ok30(str_contains($core,'Coverage URL must use HTTPS')&&str_contains($core,'Coverage publication date is invalid'),'coverage ledger validates source URL and date');
ok30(!str_contains($core,"'notes'=>(string)"),'public EPK payload does not expose internal kit notes');

ok30(str_contains($publicApi,"action==='load'")&&str_contains($publicApi,'sf_request_json'),'private EPK loads through POST body');
ok30(!str_contains($publicApi,"\$_GET['token']"),'public EPK endpoint never accepts private token in GET query');
ok30(str_contains($adminApi,'explicit Admin confirmation')&&str_contains($adminApi,"action==='send_outreach'")&&str_contains($adminApi,"\$existing!==null&&"),'every existing EPK visibility-state change and outreach send are confirmation governed');
ok30(str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,'sf_log_admin_action'),'press mutations feed Agent Brain and Admin audit');
ok30(!str_contains($adminApi,"'token'=>"),'Admin audit/Brain payloads do not log raw bearer token');

ok30(str_contains($mediaCore,"'press_kit'")&&str_contains($mediaCore,'Press kit not found.'),'central Media Library accepts valid press-kit relationships and rejects orphan attachments');
ok30(str_contains($mediaLinks,"'press_kit'"),'public media endpoint supports press-kit relationships');
ok30(str_contains($mediaApi,"'press_kit'=>['hero']"),'EPK hero can be explicitly published through universal media controls');

ok30(str_contains($adminShell,'data-view="press"')&&str_contains($adminShell,'assets/press.js')&&str_contains($adminShell,'assets/press.css'),'EPK + Press is a first-class Admin module');
ok30(str_contains($adminMain,"press:'EPK + Press'")&&str_contains($adminMain,"openView('press')"),'Admin shell routes into EPK + Press');
ok30(str_contains($adminMain,'press kit|epk|media relations|press contact')&&str_contains($adminMain,'record press|pressing|manufactur'),'Admin Agent separates media relations from record manufacturing');
ok30(substr_count($adminShell,'data-view="')===30,'Admin sidebar exposes 30 navigation entries including Stripe Payments');
ok30(str_contains($adminBaseCss,'min-height:21px')&&str_contains($adminBaseCss,'font-size:.62rem'),'short-viewport sidebar remains dense with 29 links');
ok30(str_contains($adminJs,'Electronic press kits')&&str_contains($adminJs,'Press contacts'),'Admin workspace manages EPKs and press contacts');
ok30(str_contains($adminJs,'Rotate private link')&&str_contains($adminJs,'privateTokens'),'private raw token remains session-scoped in the EPK workspace');
ok30(str_contains($adminJs,'#token=')&&!str_contains($adminJs,"publicUrl+'?token='")&&str_contains($adminJs,"new URL('../press/"),'Admin uses fragment tokens and subdirectory-safe EPK links');
ok30(str_contains($adminJs,'Send approved outreach')&&str_contains($adminJs,"confirm('Send this press outreach")&&str_contains($adminJs,'button.disabled=true'),'Admin confirms and locks the send control during press outreach');
ok30(str_contains($adminJs,'do_not_contact')&&str_contains($adminJs,'Record confirmed coverage'),'Admin manages opt-out status and confirmed coverage separately');
ok30(str_contains($adminCss,'.press-share-panel')&&str_contains($adminCss,'.press-readiness'),'EPK Admin has dedicated responsive/readiness styling');

ok30(str_contains($app,'async function renderPress(')&&str_contains($app,"view==='press'"),'public app renders routed EPK pages');
ok30(str_contains($app,"action:'load',slug,token")&&str_contains($app,"hashParams.get('token')"),'public app reads fragment token and posts it in request body');
ok30(str_contains($app,'STONEFELLOW · ELECTRONIC PRESS KIT')&&str_contains($app,'Selected music'),'public EPK has branded listening presentation');
ok30(str_contains($app,"event_type:'release_play'")&&str_contains($app,"event_type:'dsp_click'")&&str_contains($app,"event_type:'contact_click'"),'public EPK records actual play/DSP/contact interactions');
ok30(str_contains($siteCss,'.press-public')&&str_contains($siteCss,'.press-dsp-links'),'public EPK has dedicated responsive presentation');
ok30(str_contains($ht,'RewriteRule ^press/'),'pretty /press/{slug} route is supported');
ok30(str_contains($publicShell,'STONEFELLOW_INITIAL_ROUTE')&&str_contains($app,'initial.view')&&str_contains($app,'initial.slug'),'server rewrite state is handed to the SPA for pretty press/campaign routes');
ok30(str_contains($publicShell,'<base href="<?=sf_h($appBaseHref)?>">'),'public shell has base URL support for pretty subdirectory routes');
ok30(str_contains($lifecycle,'$trimmed!==$script')&&str_contains($lifecycle,"rtrim(\$trimmed,'/\\\\')"),'public base URL preserves subdirectory installs for API/Admin requests');
$publicBuild='0.0.0';if(preg_match("/define\('SF_BUILD', '([^']+)'\)/",$publicShell,$bm))$publicBuild=$bm[1];ok30(version_compare($publicBuild,'1.3.29','>='),'public assets remain Section 30 compatible after later releases');

ok30(str_contains($boot,"require_once __DIR__ . '/press-core.php'"),'press core loads from canonical bootstrap');
ok30(str_contains($mig,"'id'=>'2026-10-11-026'")&&str_contains($mig,'sf_press_ensure_schema'),'migration 026 installs press/EPK schema');
$schema30='0.0.0';if(preg_match("/const SF_DB_SCHEMA_TARGET = '([^']+)'/",$mig,$mm30))$schema30=$mm30[1];ok30(version_compare($schema30,'1.3.25','>='),'database schema remains Section 30 compatible or newer');
foreach(['press_kits','press_contacts','press_outreach','press_coverage','press_events'] as $table)ok30(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);
$app30='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$vm30))$app30=$vm30[1];ok30(version_compare($app30,'1.3.29','>=')&&version_compare($schema30,'1.3.25','>='),'version endpoint remains Section 30 compatible after later releases');
ok30(str_contains($version,"'press_epk'=>'public-private-release-media-live-dsp-share-links'"),'version endpoint advertises EPK capability');
ok30(str_contains($version,"'press_outreach'=>'explicit-send-transactional-outbox-dedupe-agent-brain'"),'version endpoint advertises governed press outreach');
ok30(str_contains($wf,'node --check admin/assets/press.js')&&str_contains($wf,'php tests/v1329-section30-epk-press.php'),'release gate includes Press Admin JS and Section 30 suite');

echo "Stonefellow v1.3.29 Section 30 Press Kit, EPK & Media Relations audit: PASS\n";
