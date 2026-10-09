<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/api/archive-core.php';
function ok($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
function src($rel){global $root;return (string)file_get_contents($root.'/'.$rel);}

$catalog=[
 ['id'=>'song-master','title'=>'One Song','year'=>2026,'metadata'=>['release_date'=>'2026-05-01'],'archive'=>['canonical_work_id'=>'one-song','version_type'=>'studio','version_label'=>'Studio master','recorded_date'=>'2026-01-20','era'=>'First sessions','session'=>'Studio A','alternate_track_ids'=>['song-demo'],'personnel'=>[['name'=>'A Player','instrument'=>'Guitar','role'=>'Guest']]]],
 ['id'=>'song-demo','title'=>'One Song (Demo)','year'=>2025,'archive'=>['canonical_work_id'=>'one-song','version_type'=>'demo','version_of'=>'song-master','recorded_date'=>'2025-11-03','era'=>'First sessions']],
];
$releases=[['id'=>'album-one','title'=>'Album One','type'=>'album','state'=>'published','release_date'=>'2026-05-01','archive'=>['era'=>'Release era','edition'=>'Original','original_release_date'=>'2026-05-01']]];
$p=sf_archive_payload($catalog,$releases);
ok(isset($p['version_groups']['one-song'])&&count($p['version_groups']['one-song'])===2,'canonical work groups connect multiple recording versions');
ok(count($p['timeline'])===3,'archive timeline combines recordings and releases');
ok($p['timeline'][0]['id']==='song-demo','archive timeline sorts chronologically');
ok(in_array('First sessions',$p['eras'],true)&&in_array('Release era',$p['eras'],true),'archive eras are derived without a parallel database');
ok(sf_archive_track_meta($catalog[0])['personnel'][0]['instrument']==='Guitar','structured personnel survives archive normalization');

$app=src('assets/js/app.js');$admin=src('admin/assets/admin.js');$catalogApi=src('admin/api/catalog.php');$releaseApi=src('admin/api/releases.php');$search=src('api/search-core.php');$agent=src('api/agent-runtime.php');$menu=src('stonefellow-v120.php');$version=src('version.php');$siteData=src('api/site-data.php');
ok(str_contains($app,'function renderArchive(')&&str_contains($app,'archive-timeline'),'public Music Archive workspace exists');
ok(str_contains($app,'function versionTracksFor(')&&str_contains($app,'Other versions'),'track pages connect alternate recordings');
ok(str_contains($app,'archiveMediaHtml')&&str_contains($app,'Personnel'),'track/release archive media and personnel are surfaced');
ok(str_contains($admin,'Archive + version history')&&str_contains($admin,'Attached media'),'Admin exposes structured archive editing');
ok(str_contains($catalogApi,"'alternate_track_ids'")&&str_contains($catalogApi,"'personnel'")&&str_contains($catalogApi,"'media'"),'catalog API validates structured archive relationships');
ok(str_contains($releaseApi,"stonefellow.release.v2")&&str_contains($releaseApi,"'liner_notes'")&&str_contains($releaseApi,"'archive'"),'release API persists liner notes, credits, editions and archive context');
ok(str_contains($search,"'archive'=>sf_search_string_values"),'catalog search indexes archive metadata');
ok(str_contains($agent,"archive_browse")&&str_contains($agent,'other versions'),'Agent understands archive browsing and version questions');
ok(str_contains($menu,'data-view="archive"')&&str_contains($menu,'Music Archive'),'public menu links to the Music Archive');
preg_match("/'stonefellow'=>'([0-9]+)\.([0-9]+)\.([0-9]+)'/",$version,$vm15);
ok(isset($vm15[1],$vm15[2],$vm15[3])&&[(int)$vm15[1],(int)$vm15[2],(int)$vm15[3]]>=[1,3,13]&&str_contains($version,"'music_archive'=>'single-artist-eras-versions-sessions-personnel-media-timeline'"),'version endpoint advertises Section 15 capability at v1.3.13 or later');
ok(str_contains($siteData,"'archive'=>$archive"),'site data publishes derived archive context');
echo "Stonefellow v1.3.13 Section 15 music archive audit: PASS\n";
