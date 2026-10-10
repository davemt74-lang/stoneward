<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/press-core.php';

ok(sf_press_slug('New Album — Press Kit')==='new-album-press-kit','EPK slug normalizes safely');
$token='private-token-example';
$private=['status'=>'private','share_token_hash'=>hash('sha256',$token)];
ok(sf_press_access_allowed($private,$token)===true,'private EPK accepts its current bearer token');
ok(sf_press_access_allowed($private,'wrong-token')===false,'private EPK rejects an invalid token');
ok(sf_press_access_allowed(['status'=>'published','share_token_hash'=>''],'')===true,'published EPK needs no token');
ok(sf_press_access_allowed(['status'=>'draft','share_token_hash'=>''],'')===false,'draft EPK is not publicly accessible');
ok(sf_press_access_allowed(['status'=>'archived','share_token_hash'=>''],'')===false,'archived EPK is not publicly accessible');

$core=file_get_contents($root.'/api/press-core.php');
$adminApi=file_get_contents($root.'/admin/api/press.php');
$publicApi=file_get_contents($root.'/api/press.php');
$adminJs=file_get_contents($root.'/admin/assets/press.js');
$adminCss=file_get_contents($root.'/admin/assets/press.css');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$mediaApi=file_get_contents($root.'/admin/api/media.php');
$mediaLinks=file_get_contents($root.'/api/media-links.php');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$ht=file_get_contents($root.'/.htaccess');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

foreach(['press_kits','press_contacts','press_outreach','press_coverage','press_events'] as $table)ok(substr_count($core,'CREATE TABLE IF NOT EXISTS '.$table)===2,'press schema supports SQLite and MySQL for '.$table);
ok(str_contains($core,'share_token_hash')&&str_contains($core,"hash('sha256',$raw)"),'private EPK stores a one-way token hash');
ok(str_contains($core,'hash_equals'),'private token comparison uses constant-time hash comparison');
ok(str_contains($core,'sf_transactional_email'),'press outreach uses the existing Stonefellow email delivery/outbox');
ok(str_contains($core,"status']??'')!=='active'")&&str_contains($core,'cannot receive outreach'),'do-not-contact/archived press contacts cannot receive outreach');
ok(str_contains($core,"($kit['status']??'')==='private'")&&str_contains($core,'valid current private-kit token'),'private EPK outreach requires the current token');
ok(str_contains($core,"'queued','sent'")&&str_contains($core,"status='failed'"),'press summary preserves real queued/sent/failed delivery states');
ok(str_contains($core,"['view','media_click','contact_click','release_play','download_click']"),'EPK analytics are limited to explicit real interaction events');
ok(!str_contains($core,'opened_at')&&!str_contains($core,'email_open'),'press module does not invent email-open tracking');
ok(str_contains($core,"'artist_photo'")&&str_contains($core,"'media_artist_photo'"),'public EPK maps existing flat site-media settings correctly');
ok(!str_contains($core,"'notes'=>(string)$kit['notes']"),'public EPK payload does not expose internal kit notes');

ok(str_contains($adminApi,"action==='send_outreach'")&&str_contains($adminApi,'confirmed'),'press outreach requires explicit Admin confirmation');
ok(str_contains($adminApi,"action==='rotate_token'")&&str_contains($adminApi,'confirmed'),'private-link rotation requires explicit Admin confirmation');
ok(str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,'sf_log_admin_action'),'press mutations feed Agent Brain and Admin audit');
ok(str_contains($publicApi,'sf_press_public_kit')&&str_contains($publicApi,'sf_press_event'),'public EPK API enforces access and logs events');

ok(str_contains($mediaCore,"'press_kit'"),'central Media Library accepts press-kit relationships');
ok(str_contains($mediaLinks,"'press_kit'"),'public media endpoint supports press-kit media');
ok(str_contains($mediaApi,"'press_kit'=>['hero']"),'EPK hero media can be explicitly published through universal media controls');

ok(str_contains($adminShell,'data-view="press"')&&str_contains($adminShell,'assets/press.js'),'EPK + Press is a first-class Admin module');
ok(str_contains($adminMain,"press:'EPK + Press'")&&str_contains($adminMain,"openView('press')"),'Admin shell and Agent route into EPK + Press');
ok(str_contains($adminMain,'press kit|media relations|press contact'),'Admin Agent distinguishes media-relations press work from record pressing');
ok(str_contains($adminMain,'record press|pressing|manufactur'),'POD route remains available for physical record pressing');

ok(str_contains($adminJs,'Electronic press kits')&&str_contains($adminJs,'Press contacts'),'Admin workspace manages EPKs and media contacts');
ok(str_contains($adminJs,'Rotate private link')&&str_contains($adminJs,'privateTokens'),'Admin exposes newly rotated private tokens only in the current browser session');
ok(str_contains($adminJs,'Send approved outreach')&&str_contains($adminJs,"confirm('Send this press outreach"),'Admin UI requires confirmation before press email send');
ok(str_contains($adminJs,'do_not_contact'),'Admin contacts can explicitly opt out of outreach');
ok(str_contains($adminJs,'Record confirmed coverage'),'Admin records confirmed coverage separately from outreach');
ok(str_contains($adminCss,'.press-share-panel')&&str_contains($adminCss,'.press-outreach-list'),'EPK + Press has dedicated responsive Admin styling');

ok(str_contains($app,'async function renderPress(')&&str_contains($app,"view==='press'"),'public app renders routed EPK pages');
ok(str_contains($app,'STONEFELLOW · ELECTRONIC PRESS KIT')&&str_contains($app,'Selected music'),'public EPK includes branded release/listening context');
ok(str_contains($app,"event_type:'release_play'")&&str_contains($app,"event_type:'contact_click'"),'public EPK logs actual play/contact interactions');
ok(str_contains($siteCss,'.press-public')&&str_contains($siteCss,'.press-track-list'),'public EPK has dedicated responsive presentation');
ok(str_contains($ht,'RewriteRule ^press/'),'pretty /press/{slug} route is supported');

ok(str_contains($boot,"require_once __DIR__ . '/press-core.php'"),'press core loads from canonical bootstrap');
ok(str_contains($mig,"'id'=>'2026-10-10-025'")&&str_contains($mig,'sf_press_ensure_schema'),'migration 025 installs press/EPK schema');
ok(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.24'"),'database schema advances to 1.3.24');
foreach(['rights_parties','rights_works','rights_splits','rights_licenses','press_kits','press_contacts','press_outreach','press_coverage','press_events'] as $table)ok(str_contains($mig,"'".$table."'"),'migration integrity requires '.$table);
ok(str_contains($version,"'stonefellow'=>'1.3.28'")&&str_contains($version,"'database_schema_target'=>'1.3.24'"),'version endpoint reports app 1.3.28 and schema 1.3.24');
ok(str_contains($version,"'press_epk'=>'public-private-release-media-contact-share-links'"),'version endpoint advertises EPK capability');
ok(str_contains($version,"'press_outreach'=>'contacts-explicit-send-email-outbox-coverage-events'"),'version endpoint advertises governed press outreach');
ok(str_contains($wf,'node --check admin/assets/press.js')&&str_contains($wf,'php tests/v1328-section29-epk-press.php'),'release gate includes Press Admin JS and Section 29 suite');

echo "Stonefellow v1.3.28 Section 29 Press Kit, EPK & Media Relations audit: PASS\n";
