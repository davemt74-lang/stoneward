<?php
declare(strict_types=1);
$root=dirname(__DIR__);define('SF_ROOT',$root);require $root.'/api/live-core.php';
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}

$sample=['id'=>'phoenix-2026','date'=>'2026-11-14','venue'=>'Example Hall','city'=>'Phoenix','region'=>'AZ','country'=>'US','tour'=>'Stonefellow Live','era'=>'2026','archive_notes'=>'First headline show','setlist_track_ids'=>['song-a','song-b']];
$map=['song-a'=>['title'=>'Song A'],'song-b'=>['title'=>'Song B']];
ok(sf_live_location($sample)==='Phoenix, AZ, US','show location normalizes city, region and country');
$summary=sf_live_summary($sample,$map);
ok(str_contains($summary,'Example Hall')&&str_contains($summary,'Stonefellow Live'),'Agent-ready show summary includes venue and tour');
ok(str_contains($summary,'Song A')&&str_contains($summary,'Song B'),'Agent-ready show summary resolves canonical setlist titles');

$app=src('assets/js/app.js');$agent=src('api/agent-runtime.php');$api=src('admin/api/shows.php');$admin=src('admin/assets/admin.js');$adminHtml=src('admin/index.php');$site=src('api/site-data.php');$menu=src('stonefellow-v120.php');$version=src('version.php');$css=src('assets/css/site.css');$boot=src('api/bootstrap.php');$migrations=src('api/migrations.php');
ok(str_contains($boot,"require_once __DIR__ . '/live-core.php'"),'live archive core loads from bootstrap');
ok(str_contains($site,"'shows'=>$shows")&&str_contains($site,'sf_live_public_shows()'),'site-data exposes canonical public shows');
ok(str_contains($api,"stonefellow.show.v1")&&str_contains($api,"'setlist_track_ids'")&&str_contains($api,"'live_recording_track_ids'"),'Admin API persists canonical show/setlist/live-recording records');
ok(str_contains($api,"'message'=>'Unknown '.$label.' track: '")&&str_contains($api,"$normalizeTrackIds($raw['setlist_track_ids']??[],'setlist')")&&str_contains($api,"$normalizeTrackIds($raw['live_recording_track_ids']??[],'live recording')"),'show writes reject unknown catalog track references');
ok(str_contains($api,"['scheduled','completed','cancelled','postponed','archived']"),'show lifecycle states are governed server-side');
ok(str_contains($adminHtml,'data-view="shows"')&&str_contains($adminHtml,'Shows + Live'),'Admin has a dedicated Shows + Live workspace');
ok(str_contains($admin,'function renderShows()')&&str_contains($admin,'function renderShowForm('),'Admin can browse and author show records');
ok(str_contains($admin,'showSetlist')&&str_contains($admin,'showLiveRecordings')&&str_contains($admin,'showMedia'),'Admin edits setlists, live recordings and show archive media');
ok(str_contains($app,'function renderShows()')&&str_contains($app,'function renderShow('),'public app has show browser and show detail views');
ok(str_contains($app,'Upcoming')&&str_contains($app,'Past shows'),'public live archive separates upcoming and historical performances');
ok(str_contains($app,'SETLIST')&&str_contains($app,'LIVE RECORDINGS'),'show pages expose setlists and playable live recordings');
ok(str_contains($app,'data-archive-show'),'Section 15 Music Archive timeline also includes live shows');
ok(str_contains($agent,"shows_browse")&&str_contains($agent,"show_info"),'Agent routes live browsing and show questions separately');
ok(str_contains($agent,'SHOWS + LIVE ARCHIVE')&&str_contains($agent,'sf_live_summary'),'Agent context is grounded in canonical show records');
ok(str_contains($menu,'data-view="shows"')&&str_contains($menu,'Shows &amp; Live'),'public navigation exposes Shows & Live');
ok(str_contains($css,'.live-show-card')&&str_contains($css,'.show-detail-hero'),'live archive UI has dedicated responsive styling');
ok(str_contains($version,"'stonefellow'=>'1.3.14'")&&str_contains($version,"'shows_live_archive'=>'single-artist-shows-tours-setlists-live-recordings-media'"),'version endpoint advertises Section 16 capability');
ok(str_contains($migrations,"SF_DB_SCHEMA_TARGET = '1.3.12'"),'Section 16 remains file-backed and does not advance the database schema');
echo "Stonefellow v1.3.14 Section 16 Shows, Tours & Live Archive audit: PASS\n";
