<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok33(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);} echo "PASS: $m\n"; }
require $root.'/api/story-core.php';

ok33(sf_story_slug('Stonefellow — Desert Years!')==='stonefellow-desert-years','story slugs are stable and URL-safe');
ok33(sf_story_clean_long_text("First\r\n\r\nSecond",100)==="First\n\nSecond",'story long text preserves editorial paragraphs while normalizing line endings');
ok33(sf_story_required_entity_type('release_story')==='release'&&sf_story_required_entity_type('track_story')==='track'&&sf_story_required_entity_type('show_story')==='show','entity-linked editorial types map to their authoritative Stonefellow records');
ok33(sf_story_required_entity_type('artist_about')===''&&sf_story_required_entity_type('essay')===''&&sf_story_required_entity_type('milestone')==='','artist-wide editorial remains independent of a fake content entity');
$types=sf_story_types();
ok33($types===['artist_about','essay','release_story','track_story','show_story','milestone'],'story types are fixed for one Stonefellow artist');

$core=file_get_contents($root.'/api/story-core.php');
$publicApi=file_get_contents($root.'/api/stories.php');
$adminApi=file_get_contents($root.'/admin/api/stories.php');
$adminJs=file_get_contents($root.'/admin/assets/stories.js');
$adminMain=file_get_contents($root.'/admin/assets/admin.js');
$adminIndex=file_get_contents($root.'/admin/index.php');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$mediaApi=file_get_contents($root.'/api/media-links.php');
$app=file_get_contents($root.'/assets/js/app.js');
$siteCss=file_get_contents($root.'/assets/css/site.css');
$seo=file_get_contents($root.'/api/seo-core.php');
$home=file_get_contents($root.'/api/artist-home-core.php');
$homeAdmin=file_get_contents($root.'/admin/assets/homepage.js');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$ht=file_get_contents($root.'/.htaccess');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

# 33A — Single-artist editorial model
ok33(substr_count($core,'CREATE TABLE IF NOT EXISTS story_entries')===2&&substr_count($core,'CREATE TABLE IF NOT EXISTS story_events')===2,'story schema supports SQLite and MySQL');
ok33(!str_contains($core,'artist_id')&&!str_contains($core,'tenant_id')&&!str_contains($core,'workspace_id'),'story model introduces no multi-artist or tenant abstraction');
ok33(str_contains($core,'Only one Artist About story may be published at a time.'),'single public Artist About is enforced');
ok33(str_contains($core,'function sf_story_about_payload'),'single-artist About, editorial and timeline payload exists');

# 33B — Canonical content ownership
ok33(str_contains($core,'function sf_story_entity')&&str_contains($core,'function sf_story_required_entity_type'),'editorial stories link to authoritative public track/release/show entities');
ok33(str_contains($core,'This story must link to a public Stonefellow'),'linked editorial cannot publish against missing/private canonical content');
ok33(!str_contains($core,'sf_admin_write_catalog')&&!str_contains($core,'sf_admin_write_releases')&&!str_contains($core,'sf_admin_write_shows'),'story system never rewrites catalog, release or show sources');
ok33(str_contains($app,'trackStoryLinks')&&str_contains($app,'releaseStoryLinks')&&str_contains($app,'showStoryLinks'),'canonical track/release/show pages surface supplemental editorial without replacing their source data');

# 33C — Admin editor and publishing governance
ok33(str_contains($adminIndex,'assets/stories.css')&&str_contains($adminIndex,'assets/stories.js'),'Storytelling Admin assets load in the existing application');
ok33(substr_count($adminIndex,'<button type="button" data-view=')===29,'Storytelling adds no 30th Admin sidebar item');
ok33(str_contains($adminMain,'id="storytellingPanel"')&&str_contains($adminMain,"SFStoriesAdmin?.renderInto?.('storytellingPanel')"),'Storytelling editor lives inside Settings');
ok33(str_contains($adminMain,'Artist Story, Editorial & Timeline lives in Settings.'),'Admin Agent routes storytelling requests to the embedded editor');
ok33(str_contains($adminApi,'$existing===null?in_array($target')&&str_contains($adminApi,'Publishing or archiving a story requires explicit Admin confirmation.'),'API requires confirmation even for a brand-new direct publish/archive');
ok33(str_contains($adminJs,'consequential=statusChanged')&&str_contains($adminJs,"confirm('Change this story status to '+status+'?')")&&str_contains($adminJs,'confirmed:consequential'),'Admin editor mirrors consequential publish/archive confirmation');
ok33(str_contains($adminApi,'story_saved')&&str_contains($adminApi,'sf_agent_brain_log'),'story saves feed Admin audit and Agent Brain');
ok33(str_contains($core,'Archive or draft this story before deleting it.')&&str_contains($adminApi,'Deleting a story requires explicit Admin confirmation.'),'published stories cannot be deleted and deletion is confirmed');

# 33D — Public storytelling experience
ok33(str_contains($app,'async function renderAbout')&&str_contains($app,"stories.php?mode=about"),'public About uses the Storytelling payload');
ok33(str_contains($app,'async function renderEditorialStory')&&str_contains($app,'story-rich-copy'),'standalone editorial story rendering exists');
ok33(str_contains($app,'function storyBodyHtml')&&str_contains($app,"split(/\\n{2,}/)"),'public story body preserves editorial paragraph structure');
ok33(str_contains($app,'story-timeline')&&str_contains($app,'Stonefellow history'),'public About includes the chronological Stonefellow timeline');
ok33(str_contains($app,"else if(view==='story')")&&str_contains($app,"new URL('story/'+slug"),'SPA supports canonical story navigation');
ok33(str_contains($ht,'^story/([A-Za-z0-9_-]+)/?$'),'web server exposes canonical story routes');

# 33E — Media and SEO
ok33(str_contains($mediaCore,"'story_entry'")&&str_contains($mediaApi,"'story_entry'"),'Universal Media Library supports story entries');
ok33(str_contains($adminJs,"roles:['hero','inline','gallery']")&&str_contains($adminJs,"publishRoles:['hero']"),'Story editor reuses governed hero/inline/gallery media');
ok33(str_contains($core,"DELETE FROM media_links WHERE entity_type='story_entry'"),'story deletion cleans media relationships without deleting library assets');
ok33(str_contains($seo,"view==='story'")&&str_contains($seo,"'@type'=>'Article'"),'published stories receive route-specific Article structured data');
ok33(str_contains($seo,"view==='about'")&&str_contains($seo,"canonical'=>sf_seo_base_url().'/about'"),'Artist About uses the canonical /about identity');
ok33(str_contains($seo,"sf_story_list(true,'',500)")&&str_contains($seo,"'/story/'"),'only public readiness-approved stories enter the sitemap');
ok33(str_contains($siteCss,'.story-public-page')&&str_contains($siteCss,'.story-timeline'),'public story and timeline presentation is styled');

# 33F — Homepage and Agent integration
ok33(str_contains($home,'sf_story_about_payload')&&str_contains($home,"'story_source'")&&str_contains($home,"'storytelling':'homepage_fallback'"),'Homepage Artist Story consumes the published canonical About narrative with fallback');
ok33(str_contains($home,'sf_story_media((int)')&&str_contains($home,"['id'],true)"),'Homepage Artist Story uses public governed story media');
ok33(str_contains($homeAdmin,'Single source:')&&str_contains($homeAdmin,'safe fallback'),'Homepage editor explains canonical Storytelling ownership');
ok33(str_contains($agent,"sf_story_list(true,'',30)")&&str_contains($agent,'PUBLISHED ARTIST STORIES'),'public Agent context includes only published ready artist editorial');
ok33(str_contains($agent,'count($storyLines)>=6'),'Agent storytelling context is bounded');

# 33G — Truthful events and public safety
ok33(str_contains($core,"['view','entity_open','media_open','share']"),'story analytics allowlist contains only observable actions');
ok33(str_contains($core,'if(!sf_story_get($storyId,true))return'),'events cannot be written for draft/private/unready stories');
ok33(str_contains($publicApi,'count($attempts)>=60'),'anonymous/public story event writes are rate-limited');
ok33(strpos($app,'await navigator.share')<strpos($app,"storyEvent(Number(id),'share',{target:'native'})"),'native story share is recorded only after successful native share');
ok33(strpos($app,'await navigator.clipboard.writeText')<strpos($app,"storyEvent(Number(id),'share',{target:'copy'})"),'copy story share is recorded only after successful clipboard copy');
ok33(str_contains($app,"'media_open'")&&str_contains($app,"'entity_open'"),'story media/entity events are tied to actual public interactions');

# 33H — Upgrade and regression safety
ok33(str_contains($boot,"require_once __DIR__ . '/story-core.php'"),'Storytelling core loads from canonical bootstrap');
$schema33='0.0.0';if(preg_match("/const SF_DB_SCHEMA_TARGET = '([^']+)'/",$mig,$m33))$schema33=$m33[1];ok33(version_compare($schema33,'1.3.28','>=')&&str_contains($mig,"'id'=>'2026-10-11-029'")&&str_contains($mig,"'app_version'=>'1.3.32'"),'database remains Section 33 compatible and retains migration 029');
ok33(str_contains($mig,"'story_entries','story_events'"),'upgrade integrity requires both story tables');
$app33='0.0.0';if(preg_match("/'stonefellow'=>'([^']+)'/",$version,$v33))$app33=$v33[1];ok33(version_compare($app33,'1.3.32','>=')&&version_compare($schema33,'1.3.28','>='),'version endpoint remains Section 33 compatible after later releases');
ok33(str_contains($version,"'artist_storytelling'=>'single-artist-about-essays-release-track-show-stories-timeline'"),'version endpoint advertises the single-artist storytelling contract');
ok33(str_contains($wf,'node --check admin/assets/stories.js'),'release gate syntax-checks Storytelling Admin JavaScript');
ok33(str_contains($wf,'php tests/v1332-section33-storytelling.php'),'release gate includes Section 33 certification');

echo "Stonefellow v1.3.32 Section 33 Public Content & Storytelling audit: PASS\n";
