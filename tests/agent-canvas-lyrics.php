<?php
declare(strict_types=1);

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE media_links(asset_id INTEGER NOT NULL,public_visible INTEGER NOT NULL,entity_type TEXT NOT NULL)');
$db->exec("INSERT INTO media_links VALUES(1,1,'track'),(2,1,'track')");
$firstAsset='M'.str_repeat('A',20);$secondAsset='M'.str_repeat('B',20);
$assets=[$firstAsset=>['id'=>1,'category'=>'audio','sha256'=>str_repeat('a',64)],
    $secondAsset=>['id'=>2,'category'=>'audio','sha256'=>str_repeat('b',64)]];
$catalog=[
    ['id'=>'one','title'=>'Fixture one','duration'=>120,'audio'=>'api/media.php?id='.$firstAsset,
        'lyrics'=>"Low lights\nSilver lines\nThe sky opens\nOrbit draws close\nWait for dawn"],
    ['id'=>'two','title'=>'Fixture two','duration'=>120,'audio'=>'api/media.php?id='.$secondAsset],
    ['id'=>'draft','title'=>'Public song with draft lyrics','lyrics'=>'Draft book secret'],
    ['id'=>'hidden','title'=>'Private song','public_visible'=>false,'lyrics'=>'Hidden book secret'],
];
function sf_db():PDO {global $db;return $db;}
function sf_db_config():array {return ['driver'=>'sqlite'];}
function sf_catalog():array {global $catalog;return $catalog;}
function sf_public_catalog():array {return array_values(array_filter(sf_catalog(),static fn(array $t):bool=>($t['public_visible']??true)!==false));}
function sf_media_asset(string $uuid):?array {global $assets;return $assets[$uuid]??null;}

require dirname(__DIR__).'/api/agent-canvas-lyrics.php';

function check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}

function fixtureBook(string $id,array $sections,string $status='published'): void {
    sf_db()->prepare('INSERT INTO songbooks(track_id,status,sections_json,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?)')
        ->execute([$id,$status,json_encode($sections,JSON_THROW_ON_ERROR),'now','now','now']);
}

function fixtureLyric(string $id,string $fingerprint,int $start,int $end,string $body,array $options=[]): void {
    sf_db()->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,body,status,lyric_state,timing_source,source_annotation_id,source_fingerprint,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$start,$end,'lyric',$body,$options['status']??'published',$options['state']??'approved',
            $options['timing']??'manual',$options['annotation']??0,$fingerprint,'now','now']);
}

sf_songbook_ensure_schema();
sf_song_moments_schema();
fixtureBook('one',[
    ['label'=>'Verse 1','start_line'=>1,'end_line'=>2],
    ['label'=>'Chorus','start_line'=>3,'end_line'=>4],
    ['label'=>'Bridge','start_line'=>5,'end_line'=>5],
]);
fixtureBook('draft',[['label'=>'Chorus','start_line'=>1,'end_line'=>1]],'draft');
fixtureBook('hidden',[['label'=>'Chorus','start_line'=>1,'end_line'=>1]]);
$first=sf_song_moments_audio_fingerprint($catalog[0]);
$second=sf_song_moments_audio_fingerprint($catalog[1]);
fixtureLyric('one',$first,0,3500,"Low lights\nSilver lines");
fixtureLyric('one',$first,10000,12000,'The sky opens.');
fixtureLyric('one',$first,12000,14000,'Orbit draws close',['timing'=>'catalog_chunk_estimate']);
fixtureLyric('one',$first,16000,19000,'Wait for dawn and greet the moon');
fixtureLyric('one',$first,20000,22000,'Private listener transcript',['annotation'=>5]);
fixtureLyric('one',$first,23000,24000,'Unreviewed text',['state'=>'unverified']);
fixtureLyric('one',$first,25000,27000,'Unpublished text',['status'=>'draft']);
fixtureLyric('one',str_repeat('f',64),28000,29000,'Stale audio transcript');
fixtureLyric('two',$second,5000,8000,"[Chorus 2]\n".str_repeat('é',500));
fixtureLyric('two',$second,8000,9000,"[Bridge]\nPrivate annotation",['annotation'=>5]);
fixtureLyric('two',$second,9000,10000,"[Bridge]\nUnreviewed text",['state'=>'unverified']);
fixtureLyric('two',$second,10000,11000,"[Bridge]\nUnpublished text",['status'=>'draft']);
fixtureLyric('two',str_repeat('f',64),11000,12000,"[Bridge]\nStale audio transcript");

$verse=sf_agent_canvas_lyrics('one','verse');
check($verse['text']==='Low lights Silver lines'&&$verse['label']==='Verse 1'&&$verse['start_ms']===0&&$verse['end_ms']===3500&&!$verse['approximate'],
    'A named published verse receives its actual saved block timing');
$chorus=sf_agent_canvas_lyrics('one','chorus');
check($chorus['text']==='The sky opens Orbit draws close'&&$chorus['source']==='published_songbook'&&$chorus['start_ms']===10000&&$chorus['end_ms']===14000&&$chorus['approximate'],
    'A published chorus can span consecutive approved blocks and flags estimated timing');
$bridge=sf_agent_canvas_lyrics('one','bridge');
check($bridge['text']==='Wait for dawn'&&$bridge['start_ms']===null&&$bridge['end_ms']===null&&$bridge['timing']===null,
    'A published bridge without exact block boundaries remains untimed');
$whole=sf_agent_canvas_lyrics('one');
check($whole['section']==='whole'&&$whole['text']==='Low lights Silver lines The sky opens Orbit draws close Wait for dawn',
    'Whole-song context comes from the published Songbook alone');

fixtureLyric('one',$first,60000,62000,'The sky opens');
fixtureLyric('one',$first,62000,64000,'Orbit draws close');
$ambiguous=sf_agent_canvas_lyrics('one','chorus');
check($ambiguous['start_ms']===null&&$ambiguous['end_ms']===null,'A repeated section stays untimed when multiple exact ranges match');
$timeline=sf_agent_canvas_lyrics('two','chorus');
check($timeline['source']==='published_timeline'&&$timeline['label']==='Chorus 2'&&$timeline['text']===str_repeat('é',420)
    &&$timeline['start_ms']===5000&&$timeline['end_ms']===8000,'A labeled approved timeline block supplies a UTF-8-safe bounded excerpt');
check(sf_agent_canvas_lyrics('two','bridge')===null,'Private, unreviewed, unpublished and stale labels cannot supply a section');
check(sf_agent_canvas_lyrics('draft','chorus')===null&&sf_agent_canvas_lyrics('hidden','chorus')===null,
    'Draft Songbooks and private songs are excluded');

$db->exec("UPDATE songbooks SET status='draft' WHERE track_id='one'");
$timelineOnly=sf_agent_canvas_lyrics('one');
check($timelineOnly['text']==='Low lights Silver lines'&&$timelineOnly['source']==='published_timeline',
    'Timeline fallback uses approved current-audio words');
$db->exec("UPDATE media_links SET public_visible=0 WHERE asset_id=1");
check(sf_agent_canvas_lyrics('one')===null,'A revoked public listening link removes timeline excerpts');
$assets[$secondAsset]['sha256']=str_repeat('c',64);
check(sf_agent_canvas_lyrics('two','chorus')===null,'Republishing the audio removes cues from its prior fingerprint');
