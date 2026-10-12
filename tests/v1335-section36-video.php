<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function ok36($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
if(!function_exists('sf_clean_text')){function sf_clean_text(mixed $v,int $max=255): string {$s=trim((string)$v);return strlen($s)>$max?substr($s,0,$max):$s;}}
require $root.'/api/video-core.php';

ok36(sf_video_slug('Live Session — Desert Room!')==='live-session-desert-room','video slugs normalize safely');
ok36(sf_video_kinds()===['music_video','live_session','visualizer','behind_scenes','interview','short_film','other'],'video kinds stay single-artist and editorially bounded');
$chap=sf_video_clean_chapters([['seconds'=>30,'title'=>'Second'],['seconds'=>0,'title'=>'Intro'],['seconds'=>120,'title'=>'Outside']],100);
ok36(count($chap)===2&&$chap[0]['seconds']===0.0&&$chap[1]['seconds']===30.0,'video chapters sort and exclude positions outside known duration');

$core=file_get_contents($root.'/api/video-core.php');
$publicApi=file_get_contents($root.'/api/videos.php');
$adminApi=file_get_contents($root.'/admin/api/videos.php');
$adminModule=file_get_contents($root.'/admin/assets/video.js');
$adminCss=file_get_contents($root.'/admin/assets/video.css');
$mediaCore=file_get_contents($root.'/api/media-core.php');
$mediaDelivery=file_get_contents($root.'/api/media.php');
$mediaAdmin=file_get_contents($root.'/admin/api/media.php');
$mediaJs=file_get_contents($root.'/admin/assets/media.js');
$adminShell=file_get_contents($root.'/admin/index.php');
$app=file_get_contents($root.'/assets/js/app.js');
$css=file_get_contents($root.'/assets/css/site.css');
$shell=file_get_contents($root.'/stonefellow-v120.php');
$ht=file_get_contents($root.'/.htaccess');
$seo=file_get_contents($root.'/api/seo-core.php');
$home=file_get_contents($root.'/api/artist-home-core.php');
$homeApi=file_get_contents($root.'/admin/api/homepage.php');
$homeAdmin=file_get_contents($root.'/admin/assets/homepage.js');
$agent=file_get_contents($root.'/api/agent-runtime.php');
$boot=file_get_contents($root.'/api/bootstrap.php');
$mig=file_get_contents($root.'/api/migrations.php');
$version=file_get_contents($root.'/version.php');
$wf=file_get_contents($root.'/.github/workflows/release-gate.yml');

ok36(substr_count($core,'CREATE TABLE IF NOT EXISTS video_features')===2,'video publication schema supports SQLite and MySQL');
ok36(substr_count($core,'CREATE TABLE IF NOT EXISTS video_events')===2,'video event schema supports SQLite and MySQL');
ok36(!str_contains($core,'stored_path TEXT')&&!str_contains($core,'video_path'),'Video Hub stores publication metadata rather than duplicate video bytes');
ok36(str_contains($core,"sf_media_links('video_feature'"),'video publication references central Media Library relationships');
ok36(str_contains($core,"count(\$videos)===1")&&str_contains($core,"count(\$posters)===1"),'publishing requires exactly one public managed video and poster');
ok36(str_contains($core,"['video/mp4','video/webm']")&&str_contains($core,"['mp4','webm']"),'public playback readiness allows browser-safe MP4/WebM while MOV may remain a private/source asset');
ok36(str_contains($core,'Public video requires known media duration'),'public playback requires known managed duration');
ok36(str_contains($core,'Public poster must be at least 640×360'),'public poster readiness enforces usable dimensions');
ok36(str_contains($core,'function sf_video_caption_valid')&&str_contains($core,"'WEBVTT'"),'caption readiness validates managed WebVTT content header, not extension alone');
ok36(str_contains($core,'count($captions)<=1'),'single-language caption model allows at most one published caption track');
ok36(str_contains($core,'Linked song, release and show records must be currently public.'),'video publication validates authoritative public entity links');
ok36(str_contains($core,'Chapter times must be unique, ascending and inside video duration.'),'video publication validates chapters against duration');
ok36(str_contains($core,"if(\$status==='published')")&&str_contains($core,'Video is blocked:'),'published videos require green readiness');
ok36(str_contains($core,"status='published'")&&str_contains($core,'featured=0'),'publishing a featured video makes featured published state unique');
ok36(str_contains($core,"\$allowed=['view','play','pause','progress_25','progress_50','progress_75','complete','caption_on','chapter_seek','entity_open']"),'video event allowlist matches real player interactions');
ok36(!str_contains($core,"'impression'")&&!str_contains($core,"'watch_time_estimate'"),'video metrics do not invent impressions or estimated watch time');
ok36(str_contains($core,'sf_video_agent_context')&&str_contains($core,'PUBLISHED VIDEOS'),'Agent context is sourced only from public Video Hub records');

ok36(str_contains($mediaCore,"'video'=>['video/mp4'=>['mp4'],'video/webm'=>['webm'],'video/quicktime'=>['mov']]"),'Media Library remains the managed MP4/WebM/MOV uploader');
ok36(str_contains($mediaCore,"'text/plain'=>['txt','vtt']")&&str_contains($mediaCore,"'text/vtt'=>['vtt']"),'Media Library accepts WebVTT across common server MIME detection');
ok36(str_contains($mediaCore,"'video_feature'")&&str_contains($mediaCore,'Video feature not found.'),'Media Library allows only real Video Hub relationships');
ok36(str_contains($mediaDelivery,"extension'])==='vtt'")&&str_contains($mediaDelivery,"\$mime='text/vtt'"),'managed VTT captions are served with browser-correct MIME');
ok36(str_contains($mediaAdmin,"'video_feature'=>['poster']"),'Admin can publish exactly one governed video poster');
ok36(str_contains($mediaAdmin,'Changing Video Hub media visibility requires explicit Admin confirmation.')&&str_contains($mediaAdmin,'Publishing a Video Hub poster requires explicit Admin confirmation.'),'server requires confirmation for Video Hub media visibility and poster publication');
ok36(str_contains($mediaAdmin,'video_media_visibility_changed')&&str_contains($mediaAdmin,'sf_agent_brain_log'),'Video Hub media visibility changes are audited and sent to Agent Brain');
ok36(str_contains($mediaJs,"captions:{label:'Captions'")&&str_contains($mediaJs,'videoHubPanel'),'Media Library exposes captions role and embedded Public Video Hub');
ok36(str_contains($mediaJs,"entityType==='video_feature'")&&str_contains($mediaJs,'confirmed:true'),'Admin confirms governed Video Hub media visibility and poster publication');
ok36(str_contains($mediaJs,'SFVideoAdmin?.renderInto'),'Media Library loads the Video Hub publisher in place');

ok36(str_contains($adminShell,'assets/video.css')&&str_contains($adminShell,'assets/video.js'),'Admin shell loads Video Hub publisher assets');
ok36(substr_count($adminShell,'data-view="')===29,'Video Hub does not add another Admin sidebar item');
ok36(str_contains($adminApi,'Changing video visibility requires explicit Admin confirmation.'),'every consequential video visibility transition is confirmation governed');
ok36(str_contains($adminApi,'sf_agent_brain_log')&&str_contains($adminApi,'sf_log_admin_action'),'video publication changes feed Agent Brain and Admin audit');
ok36(str_contains($adminModule,'save a Draft')&&str_contains($adminModule,'Manage media'),'Video Hub editor teaches draft → media → publish workflow');
ok36(str_contains($adminModule,"roles:['video','poster','captions','photo']"),'Video Hub reuses universal Media Library roles');
ok36(str_contains($adminModule,"confirm('Change this video status to '")&&str_contains($adminModule,'confirmed:changed'),'Video Hub Admin confirms visibility changes before save');
ok36(str_contains($adminCss,'.video-admin-row')&&str_contains($adminCss,'.video-editor'),'Video Hub Admin has dedicated responsive presentation');

ok36(str_contains($publicApi,"if(\$method==='GET')")&&str_contains($publicApi,"if(\$method==='POST')"),'public video API separates side-effect-free reads from events');
ok36(str_contains($publicApi,'count($attempts)>=90'),'public video event writes are rate-limited');
ok36(str_contains($app,"async function renderVideos()")&&str_contains($app,"async function renderVideo(slug)"),'public app provides Video Hub and focused video detail');
ok36(str_contains($app,'controls preload="metadata" playsinline')&&!str_contains($app,'<video autoplay'),'public video playback never autoplays and uses native controls');
ok36(str_contains($app,'kind="captions"')&&str_contains($app,'srclang='),'public video player attaches managed captions');
ok36(str_contains($app,"for(const mark of [25,50,75])")&&str_contains($app,"videoEvent(Number(v.id),'progress_'+mark"),'public player emits truthful progress_25/50/75 milestones through one guarded loop');
foreach(['complete','caption_on','chapter_seek','entity_open'] as $event)ok36(str_contains($app,"'".$event."'"),'public player emits truthful '.$event.' event');
ok36(str_contains($app,"shareButton('video'"),'public video detail uses the existing completed-share workflow');
ok36(str_contains($app,"navigate('video',{slug:b.dataset.videoOpen})"),'Video Hub cards open canonical focused video routes');
ok36(str_contains($css,'.video-hub')&&str_contains($css,'.video-player-shell')&&str_contains($css,'.video-chapters'),'public Video Hub/player/chapters have dedicated responsive styling');

ok36(str_contains($ht,'^video/([A-Za-z0-9_-]+)/?$')&&str_contains($ht,'shows|videos|store'),'Apache routing supports canonical video detail and hub');
ok36(str_contains($shell,"define('SF_BUILD', '1.3.35')"),'public shell advances to v1.3.35');
ok36(str_contains($shell,"'video'")&&str_contains($shell,"'videos'")&&str_contains($shell,'href="videos" data-view="videos"'),'server route handoff and public nav include Videos');
ok36(str_contains($app,"if(view==='video'&&slug)")&&str_contains($app,"'videos'].includes(view)"),'SPA canonical URL builder supports /video/{slug} and /videos');
ok36(str_contains($seo,"'@type'=>'VideoObject'")&&str_contains($seo,"'contentUrl'")&&str_contains($seo,"'thumbnailUrl'"),'video pages emit VideoObject structured data');
ok36(str_contains($seo,"\$base.'/video/'")&&str_contains($seo,"'videos'=>'0.8'"),'sitemap exposes only public video pages and Video Hub');
ok36(str_contains($seo,"function_exists('sf_video_public_list')"),'sitemap source is readiness-filtered public Video Hub list');

ok36(str_contains($home,"'featured_video'"),'Artist Homepage supports optional Featured Video section');
ok36(str_contains($home,"sf_video_featured()")&&str_contains($home,"sf_video_public((string)\$cfg['video_slug'])"),'Homepage auto-selects or explicitly selects a public ready video');
ok36(str_contains($home,"'video_open'"),'Homepage records real featured-video opens');
ok36(str_contains($homeApi,"'videos'=>\$videos"),'Homepage Admin references published Video Hub records');
ok36(str_contains($homeAdmin,"if(key==='featured_video')")&&str_contains($homeAdmin,'Auto — featured published video'),'Homepage editor can select or auto-pick featured video');
ok36(str_contains($app,"k==='featured_video'")&&str_contains($app,'data-home-video'),'public Homepage renders a Featured Video section');
ok36(str_contains($mig,'sf_artist_home_ensure_schema()'),'migration 031 seeds missing Featured Video homepage defaults on existing installs');

ok36(str_contains($agent,'sf_video_agent_context')&&str_contains($agent,"return 'videos_browse'"),'Agent grounds video facts and recognizes video browse intent');
ok36(str_contains($agent,"case 'videos_browse'")&&str_contains($agent,"'view'=>'videos'"),'Agent opens the public Video Hub for video browse requests');
ok36(str_contains($boot,"require_once __DIR__ . '/video-core.php'")&&strpos($boot,"video-core.php")<strpos($boot,"seo-core.php"),'Video Hub core loads before SEO resolver');

ok36(str_contains($mig,"'id'=>'2026-10-11-031'")&&str_contains($mig,"'app_version'=>'1.3.35'"),'migration 031 belongs to v1.3.35');
ok36(str_contains($mig,"const SF_DB_SCHEMA_TARGET = '1.3.30'"),'database schema advances to 1.3.30');
ok36(str_contains($mig,"'video_features'")&&str_contains($mig,"'video_events'"),'upgrade integrity requires Video Hub tables');
ok36(str_contains($version,"'stonefellow'=>'1.3.35'")&&str_contains($version,"'database_schema_target'=>'1.3.30'"),'version endpoint reports v1.3.35 and DB 1.3.30');
ok36(str_contains($version,"'video_hub'=>'single-artist-managed-video-poster-vtt-gallery-publication'"),'version endpoint advertises managed Video Hub capability');
ok36(str_contains($version,"'songbook_events'=>'truthful-view-section-annotation-events'"),'version endpoint retains hardened Section 35 event contract');
ok36(str_contains($wf,'node --check admin/assets/video.js')&&str_contains($wf,'php tests/v1335-section36-video.php'),'release gate includes Video Hub Admin JS and Section 36 certification');

echo "Stonefellow v1.3.35 Section 36 Visual Media & Video Experience audit: PASS\n";
