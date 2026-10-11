<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok31($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/artist-home-core.php';

ok31(sf_artist_home_keys()===['hero','featured_release','featured_track','latest_releases','upcoming_show','merch','artist_story','newsletter','personalized'],'homepage section keys are fixed for one artist');
$defs=sf_artist_home_defaults();ok31(count($defs)===9,'homepage seeds nine Stonefellow sections');
ok31($defs[0][0]==='hero'&&$defs[0][4]==='Music made to be lived with.','homepage default hero is Stonefellow-specific');
ok31(!str_contains(file_get_contents($root.'/api/artist-home-core.php'),'artist_id'),'homepage model does not introduce multi-artist tenancy');

$core=file_get_contents($root.'/api/artist-home-core.php');
$publicApi=file_get_contents($root.'/api/artist-home.php');
$adminApi=file_get_contents($root.'/admin/api/homepage.php');
$adminModule=file_get_contents($root.'/admin/assets/homepage.js');
$adminModuleCss=file_get_contents($root.'/admin/assets/homepage.css');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$adminBaseCss=file_get_contents($root.'/admin/assets/admin.css');
$settings=file_get_contents($root.'/api/site-settings.php');
$settingsApi=file_get_contents($root.'/admin/api/settings.php');
$app=file_get_contents($root.'/assets/js/app.js');
$css=file_get_contents($root.'/assets/css/site.css');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok31(substr_count($core,'CREATE TABLE IF NOT EXISTS artist_home_sections')===2,'homepage section schema supports SQLite and MySQL');
ok31(substr_count($core,'CREATE TABLE IF NOT EXISTS artist_home_events')===2,'homepage event schema supports SQLite and MySQL');
ok31(str_contains($core,'function sf_artist_home_reset')&&str_contains($core,'beginTransaction'),'homepage default reset is transactional');
ok31(str_contains($core,"sf_media_public_links"),'homepage media comes through public Media Library relationships');
ok31(str_contains($core,"sf_media_public_links('track'")||str_contains($core,"sf_artist_home_public_media('track'"),'homepage featured track uses public media lookup');
ok31(str_contains($core,"['primary_audio']"),'featured track requires explicitly public primary audio');
ok31(str_contains($core,'sf_live_public_shows'),'homepage show selection uses public show records');
ok31(str_contains($core,'sf_commerce_products(true)'),'homepage merch selection uses only active public products');
ok31(str_contains($core,'sf_distribution_record'),'homepage featured release can consume Section 29 live DSP destinations');
ok31(str_contains($core,'sf_home_state($userId)'),'logged-in homepage reuses existing personalization rather than creating another profile model');
ok31(str_contains($core,"if(\$key==='personalized'&&\$userId<1)continue"),'anonymous visitors never receive personalized-home data');
ok31(str_contains($core,'function sf_artist_home_readiness'),'homepage has deterministic public-content readiness');
ok31(str_contains($core,'function sf_artist_home_normalize_config')&&!str_contains($core,"\$viewAllowed=['music','releases','shows','store','about','newsletter','archive']"),'homepage API normalizes config through a server-side destination allowlist');
ok31(str_contains($core,'Featured release must be a published public Stonefellow release.')&&str_contains($core,'Featured track was not found in the Stonefellow catalog.')&&str_contains($core,'Featured show must be a public Stonefellow show.')&&str_contains($core,'Featured merch must be an active public Stonefellow product.'),'homepage explicit overrides are validated against authoritative Stonefellow entities');
ok31(str_contains($core,"parse_url(\$url,PHP_URL_SCHEME)")&&str_contains($core,"==='https'"),'homepage defensively revalidates live DSP links as HTTPS');
ok31(str_contains($core,'Hero section has no published site hero image')&&str_contains($core,'explicitly Public primary audio'),'readiness checks homepage visual/audio publishing quality');
ok31(str_contains($core,"['view','hero_primary','hero_secondary','release_open','track_open','track_play','show_open','merch_open','newsletter_open','about_open','personalized_open','agent_open']"),'homepage analytics allow only real journey events');
ok31(!str_contains($core,'email_open')&&!str_contains($core,'impression_score'),'homepage does not invent engagement signals');

ok31(str_contains($settings,"'artist_homepage_enabled'"),'site settings expose Artist Homepage plugin toggle');
ok31(str_contains($settingsApi,"'artist_homepage_enabled'=>!empty"),'Admin settings persist homepage plugin toggle');
ok31(str_contains($publicApi,"error'=>'homepage_disabled'")&&str_contains($publicApi,"sf_artist_home_events")&&str_contains($publicApi,'>=60'),'public homepage event writes are disabled with the plugin and rate-limited when enabled');
ok31(str_contains($adminApi,"action==='save_section'")&&str_contains($adminApi,"action==='reset_defaults'"),'homepage Admin API manages sections and governed reset');
ok31(str_contains($adminApi,'explicit confirmation')&&str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,'sf_log_admin_action'),'homepage reset is confirmed and section changes are audited');
ok31(str_contains($adminApi,'releases')&&str_contains($adminApi,'tracks')&&str_contains($adminApi,'shows')&&str_contains($adminApi,'products'),'homepage editor receives authoritative selection references');

ok31(str_contains($adminShell,'assets/homepage.css')&&str_contains($adminShell,'assets/homepage.js'),'homepage plugin Admin assets load');
ok31(substr_count($adminShell,'data-view="')===29,'homepage plugin does not add another Admin sidebar item');
ok31(str_contains($adminMain,'Enable the single-artist Homepage plugin'),'Settings includes explicit homepage on/off control');
ok31(str_contains($adminMain,'artistHomepagePanel')&&str_contains($adminMain,'SFArtistHomeAdmin'),'Settings embeds the homepage editor');
ok31(str_contains($adminMain,'homepage|home page|landing page|splash page|artist homepage'),'Admin Agent routes homepage requests into Settings');
ok31(str_contains($adminModule,'Auto — newest published release')&&str_contains($adminModule,'Auto — first track from featured release')&&str_contains($adminModule,'Auto — next upcoming show'),'homepage editor supports smart single-artist auto selections');
ok31(str_contains($adminModule,'Reset defaults')&&str_contains($adminModule,'Preview public home'),'homepage editor supports preview and guarded reset');
ok31(str_contains($adminModule,'CORE READY')&&str_contains($adminModule,'NEEDS ATTENTION'),'homepage editor surfaces readiness');
ok31(str_contains($adminModuleCss,'.homepage-section-card')&&str_contains($adminModuleCss,'.homepage-readiness'),'homepage editor has dedicated responsive styling');
ok31(str_contains($adminBaseCss,'min-height:21px')&&str_contains($adminBaseCss,'font-size:.62rem'),'existing 29-link short-viewport sidebar density remains unchanged');

ok31(str_contains($shell,"define('SF_BUILD', '1.3.30')"),'public shell advances cache-busting build to 1.3.30');
ok31(str_contains($shell,"'artistHomepageEnabled'"),'public shell exposes homepage plugin state');
ok31(str_contains($shell,"?'Home':'Agent'"),'public navigation labels Home when the Artist Homepage plugin is active');
ok31(str_contains($shell,"siteSettings['splash_enabled']"),'existing splash toggle remains independently supported');
ok31(str_contains($app,'artistHomepageEnabled')&&str_contains($app,'renderArtistHomepage'),'public app can render the Artist Homepage plugin');
ok31(str_contains($app,'if(artistHomepageEnabled&&await renderArtistHomepage())return'),'homepage plugin cleanly falls back to the existing Home when disabled/unavailable');
ok31(str_contains($app,"document.body.classList.add('artist-home-active')")&&str_contains($css,'body.artist-home-active .agent-stage{display:none}'),'artist homepage becomes the visual landing surface rather than stacking under the Agent stage');
ok31(str_contains($app,'artist-home-hero')&&str_contains($app,'featured_release')&&str_contains($app,'featured_track')&&str_contains($app,'upcoming_show')&&str_contains($app,'artist_story'),'public homepage renders the core single-artist journey');
ok31(str_contains($app,'newsletterSignupHtml(true)'),'homepage reuses existing governed newsletter signup');
ok31(str_contains($app,'homepageTrackObject')&&str_contains($app,"audio:t.audio?.url||''")&&!str_contains($app,"audio:t.audio?.url||base.audio"),'homepage playback has no legacy catalog-audio fallback');
ok31(str_contains($app,"homepageEvent('track_play'")&&str_contains($app,"homepageEvent('release_open'")&&str_contains($app,"homepageEvent('newsletter_open'"),'public homepage records truthful fan-journey interactions');
ok31(str_contains($css,'.artist-homepage')&&str_contains($css,'.artist-home-release-grid')&&str_contains($css,'.artist-home-personalized'),'public homepage has dedicated responsive artist-site presentation');

ok31(str_contains($boot,"require_once __DIR__ . '/artist-home-core.php'"),'homepage core loads from canonical bootstrap');
ok31(str_contains($mig,"'id'=>'2026-10-11-027'")&&str_contains($mig,'sf_artist_home_ensure_schema'),'migration 027 installs homepage section/event schema');
ok31(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.26'"),'database schema advances to 1.3.26');
ok31(str_contains($mig,"'artist_home_sections'")&&str_contains($mig,"'artist_home_events'"),'upgrade integrity requires homepage tables');
ok31(str_contains($version,"'stonefellow'=>'1.3.30'")&&str_contains($version,"'database_schema_target'=>'1.3.26'"),'version endpoint reports app 1.3.30 and schema 1.3.26');
ok31(str_contains($version,"'artist_homepage'=>'single-artist-plugin-toggle-configurable-sections-public-media'"),'version endpoint advertises single-artist homepage capability');
ok31(str_contains($wf,'node --check admin/assets/homepage.js')&&str_contains($wf,'php tests/v1330-section31-artist-home.php'),'release gate includes Homepage Admin JS and Section 31 suite');

echo "Stonefellow v1.3.30 Section 31 Public Artist Experience & Fan Journey audit: PASS\n";
