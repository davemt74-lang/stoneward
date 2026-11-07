<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-lyric-dataset-'.bin2hex(random_bytes(6));
foreach(['config','data'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return [];');
$asset='M'.str_repeat('A',20);
$lyrics="First verse\n\n".str_repeat('夜',800).' <em>literal</em>';
$catalog=[
    ['id'=>'timed-song','title'=>'Timed song','lyrics'=>$lyrics,'audio'=>'api/media.php?id='.$asset],
    ['id'=>'text-song','title'=>'Text song','lyrics'=>"Verse 1\nPublished plain lyrics"],
    ['id'=>'draft-song','title'=>'Draft song','lyrics'=>'SECRET_DRAFT_BOOK'],
    ['id'=>'hidden-song','title'=>'Hidden song','state'=>'private','lyrics'=>'SECRET_PRIVATE_BOOK'],
    ['id'=>'bulk-song','title'=>'Bulk song','audio'=>'api/media.php?id='.$asset],
];
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json','[]');
file_put_contents($fixture.'/data/shows.json','[]');
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/agent-canvas-data.php';

function check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    echo "PASS $message\n";
}

$db=sf_db();
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT); INSERT INTO users VALUES(1,'active')");
sf_canvas_lyrics_prepare();
$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,duration_seconds,title,caption,technical_json,variants_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$asset,'audio','audio/mpeg','mp3','fixture.mp3','storage/media/fixture.mp3',str_repeat('a',64),4096,240,'Fixture','','{}','{}','now','now']);
$assetId=(int)$db->lastInsertId();
foreach(['timed-song','bulk-song'] as $id)sf_media_attach($assetId,'track',$id,'primary_audio',['public_visible'=>true]);
$book=$db->prepare('INSERT INTO songbooks(track_id,status,sections_json,notes,created_at,updated_at,published_at) VALUES(?,?,?,?,?,?,?)');
foreach(['timed-song'=>3,'text-song'=>2,'draft-song'=>1,'hidden-song'=>1] as $id=>$end){
    $book->execute([$id,$id==='draft-song'?'draft':'published',json_encode([['label'=>'Verse','start_line'=>1,'end_line'=>$end]],JSON_THROW_ON_ERROR),'SECRET_ADMIN_NOTE','now','now','now']);
}
$fingerprint=sf_song_moments_audio_fingerprint($catalog[0]);
check($fingerprint!=='','Fixture uses authorized managed listening audio');
$cue=$db->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,body,status,lyric_state,timing_source,source_fingerprint,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
for($i=0;$i<135;$i++)$cue->execute(['timed-song',$i*1000,$i*1000+900,'lyric',"Line ".($i+1).' ✨','published','approved',$i===0?'catalog_chunk_estimate':'manual',$fingerprint,'now','now']);
for($i=0;$i<700;$i++)$cue->execute(['bulk-song',$i*100,$i*100+90,'lyric',str_repeat('🎵',1000),'published','approved','manual',$fingerprint,'now','now']);
$cue->execute(['timed-song',180000,181000,'lyric','SECRET_UNREVIEWED','published','unverified','manual',$fingerprint,'now','now']);
$cue->execute(['timed-song',182000,183000,'lyric','SECRET_STALE','published','approved','manual',str_repeat('f',64),'now','now']);

$rowMarkup='<p class="sf-data-row"><span class="sf-field-text"></span></p>';
$binding=static fn(string $classes=''):string=>'<section class="sf-dataset-published_lyrics'.$classes.'">'.$rowMarkup.'</section>';
$read=static fn(string $html,?array $ids=null,string $current='',array $viewer=[]):array=>
    sf_agent_canvas_data_for_scene($viewer,$html,$current,null,$ids===null?null:['lyrics_ids'=>$ids]);
$both=['text-song','timed-song','draft-song','hidden-song'];
$all=$read($binding(),$both)['published_lyrics'];
check(count($all['rows'])===137&&$all['rows'][0]['track_id']==='text-song'&&!$all['payload_truncated'],
    'Explicit lyrics selection preserves attachment order and delivers normal cues beyond the DOM mount budget');
check($all===$read($binding(),$both,'',['id'=>99,'role'=>'admin'])['published_lyrics'],
    'Public lyrics projection is the same for guests and signed-in administrators');
$text=$all['rows'][1];$firstCue=$all['rows'][2];
check($text['kind']==='text'&&$text['text']===$lyrics&&$text['start_ms']===null&&$text['end_ms']===null
    &&$firstCue['kind']==='cue'&&$firstCue['start_ms']===0&&$firstCue['end_ms']===900&&$firstCue['approximate']===1
    &&$firstCue['timing_source']==='catalog_chunk_estimate'&&$firstCue['revision_hash']===$text['revision_hash'],
    'Whole text and cues keep exact Unicode, line breaks, timing provenance, and a shared source revision');
check(!str_contains(json_encode($all,JSON_THROW_ON_ERROR),'SECRET_'),'Published lyric dataset excludes draft, private, unreviewed, stale and admin data');
check($read($binding())['published_lyrics']['rows']===[]&&$read($binding(),[])['published_lyrics']['rows']===[],
    'Unattached unfiltered bindings and cleared selections stay empty');
$fixed=$binding(' sf-filter-track_id--timed-song sf-filter-kind--text');
$current=$binding(' sf-current-track sf-filter-kind--text');
$mixed=$read($binding().$fixed.$current,null,'text-song')['published_lyrics'];
check($mixed['requires_track_filter']&&array_column($mixed['rows'],'track_id')===['timed-song']
    &&array_column($mixed['current_rows'],'track_id')===['text-song'],
    'An exact static binding and current-track binding resolve independently without opening an unattached sibling');
$selected=$read($fixed.$current,['timed-song'],'text-song')['published_lyrics'];
check(array_column($selected['rows'],'track_id')===['timed-song']&&$selected['current_rows']===[],
    'Current-track and exact-filter requests stay within the chosen attachment scope');
check($read($fixed.$current,[],'timed-song')['published_lyrics']['current_rows']===[]&&
    $read($fixed,['text-song'])['published_lyrics']['rows']===[],'An explicit empty or different selection cannot widen through a filter');
check($read($fixed.$current,null,'invalid track')['published_lyrics']['rows'][0]['track_id']==='timed-song'&&
    $read($binding(' 1 sf-filter-track_id--timed-song sf-filter-other--invalid'))['published_lyrics']['rows']===[],
    'Malformed current track input and undeclared filters cannot affect a separate authorized view');

$large=$read($binding(),['bulk-song','timed-song'])['published_lyrics'];
check($large['payload_truncated']&&count($large['rows'])>0&&count($large['rows'])<700&&
    strlen(json_encode($large,JSON_THROW_ON_ERROR))<400*1024,
    'A worst-case approved lyric set delivers whole rows and flags its bounded response');
$late=$read($binding(' sf-filter-track_id--bulk-song sf-filter-kind--cue sf-filter-position--700'))['published_lyrics'];
check(!$late['payload_truncated']&&array_column($late['rows'],'position')===[700],
    'An exact cue filter reaches the last source cue before the delivery budget applies');
$priority=$read($binding().$current,['bulk-song','text-song'],'text-song')['published_lyrics'];
check($priority['payload_truncated']&&array_column($priority['current_rows'],'track_id')===['text-song'],
    'Current-track data keeps its delivery slot when another attached song exhausts the budget');

sf_canvas_story_prepare();
$story=sf_story_save(['entry_type'=>'essay','status'=>'published','title'=>'Public story','body_text'=>'A story.'],0);
$combined=sf_agent_canvas_data_for_scene([],$fixed.'<section class="sf-dataset-published_stories">'.$rowMarkup.'</section>','',null,[
    'lyrics_ids'=>['timed-song'],'stories'=>['mode'=>'selected','story_ids'=>[(string)$story['id']]],
]);
check(count($combined['published_lyrics']['rows'])===1&&count($combined['published_stories']['rows'])===1,
    'Lyric and Story bindings mount together from independent scopes');

require_once $root.'/api/agent-scene-history.php';
sf_privacy_ensure_schema();
$stored=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Saved lyric canvas',
    'html'=>$binding(),'css'=>'','artist_inputs'=>['lyrics_ids'=>['text-song']]]);
$storedData=sf_agent_canvas_data_for_scene(['id'=>1],$stored['html'],'',null,['lyrics_ids'=>['timed-song']],['scene_id'=>$stored['id']]);
check(array_column($storedData['published_lyrics']['rows'],'track_id')===['text-song'],
    'Saved canvas access resolves lyric scope from authoritative storage');

sf_canvas_permission_save(['id'=>7,'role'=>'admin'],1,['canvas.data.published_lyrics'=>false]);
check(!isset($read($binding(),$both)['published_lyrics'])&&!in_array('published_lyrics',array_column(sf_agent_canvas_data_tools(),'id'),true),
    'Disabling the lyrics permission removes both data and tool discovery');
sf_canvas_permission_save(['id'=>7,'role'=>'admin'],2,['canvas.data.published_lyrics'=>true]);
$db->exec("UPDATE songbooks SET status='archived' WHERE track_id='timed-song'");
$db->prepare('UPDATE media_assets SET sha256=? WHERE id=?')->execute([str_repeat('b',64),$assetId]);
check($read($binding(),['timed-song'])['published_lyrics']['rows']===[],
    'Fresh rendering omits withdrawn whole text and stale audio cues');
