<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-canvas-lyrics-'.bin2hex(random_bytes(6));
foreach(['config','data'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
$rawConfig=getenv('SF_TEST_LYRICS_DB_CONFIG');
$config=$rawConfig?json_decode($rawConfig,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
$first='M'.str_repeat('A',20);$second='M'.str_repeat('B',20);$third='M'.str_repeat('C',20);
$tracks=[
    ['id'=>'untimed','title'=>'Plain song','lyrics'=>"La lumière\r\n\r\nLa mer <3"],
    ['id'=>'mixed','title'=>'Mixed song','lyrics'=>"Songbook line one\nSongbook line two",'audio'=>'api/media.php?id='.$first],
    ['id'=>'cue-only','title'=>'Timed song','audio'=>'api/media.php?id='.$second,'duration'=>1000],
    ['id'=>'catalog-only','title'=>'Private text on a public song','lyrics'=>'CATALOG_DRAFT_SECRET'],
    ['id'=>'draft-book','title'=>'Draft book song','lyrics'=>'BOOK_DRAFT_SECRET'],
    ['id'=>'hidden','title'=>'Hidden song','state'=>'hidden','lyrics'=>'HIDDEN_TRACK_SECRET','audio'=>'api/media.php?id='.$third],
    ['id'=>'draft-album-song','title'=>'Draft album song','release_id'=>'draft-album','lyrics'=>'HIDDEN_RELEASE_SECRET','audio'=>'api/media.php?id='.$third],
    ['id'=>'legacy-draft-album-song','title'=>'Legacy draft album song','release'=>'Draft album','lyrics'=>'HIDDEN_LEGACY_RELEASE_SECRET'],
    ['id'=>'legacy-long','title'=>'Legacy long text','lyrics'=>'a'.str_repeat('é',21001)],
];
file_put_contents($fixture.'/data/catalog.json',json_encode($tracks,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode([['id'=>'draft-album','title'=>'Draft album','state'=>'draft']],JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/canvas-lyrics-core.php';

$checks=0;
function lyric_check(bool $pass,string $label): void {
    global $checks;
    if(!$pass)throw new RuntimeException($label);
    $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
function lyric_reject(callable $operation,string $label): void {
    try{$operation();}
    catch(InvalidArgumentException){lyric_check(true,$label);return;}
    throw new RuntimeException($label);
}

$db=sf_db();$mysql=$config['driver']==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->beginTransaction();
$coldRejected=false;
try{sf_canvas_lyrics_rows(['mixed']);}catch(LogicException){$coldRejected=true;}
lyric_check($coldRejected&&$db->inTransaction(),'Cold lyrics reads leave the caller’s transaction active');
$db->rollBack();
sf_canvas_lyrics_prepare();
$asset=$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,caption,alt_text,copyright_text,technical_json,variants_json,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$now=gmdate('c');
foreach([[$first,'a'],[$second,'b'],[$third,'c']] as [$uuid,$hash]){
    $asset->execute([$uuid,'audio','audio/mpeg','mp3','private-name.mp3','private-path',str_repeat($hash,64),'','','','{}','{}',$now,$now]);
}
$link=$db->prepare('INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,link_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
foreach([[1,'mixed'],[2,'cue-only'],[3,'hidden']] as [$assetId,$trackId])$link->execute([$assetId,'track',$trackId,'primary_audio',1,'{}',$now,$now]);
$link->execute([1,'member_content','private','primary_audio',1,'{}',$now,$now]);

$book=$db->prepare('INSERT INTO songbooks(track_id,status,language,sections_json,annotations_json,notes,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');
foreach([['untimed','published',3],['mixed','published',2],['draft-book','draft',1],['hidden','published',1],['draft-album-song','published',1],
    ['legacy-draft-album-song','published',1],['legacy-long','published',1]] as [$id,$status,$line]){
    $book->execute([$id,$status,'en',json_encode([['label'=>'Verse','start_line'=>1,'end_line'=>$line]],JSON_THROW_ON_ERROR),
        '[]','INTERNAL_BOOK_NOTES',$now,$now,$now]);
}
$fingerprints=[];
foreach($tracks as $track)if(isset($track['audio']))$fingerprints[$track['id']]=sf_song_moments_audio_fingerprint($track);
$seed=$db->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,body,status,lyric_state,timing_source,source_annotation_id,source_fingerprint,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
$cue=static function(string $id,int $start,int $end,string $text,array $change=[])use($seed,$fingerprints,$now):void {
    $seed->execute([$id,$start,$end,$change['kind']??'lyric',$text,$change['status']??'published',
        $change['state']??'approved',$change['timing']??'manual',$change['annotation']??0,
        $change['fingerprint']??$fingerprints[$id],$now,$now]);
};
$cue('mixed',0,3500,"Timeline words\nKeep this block");
$cue('mixed',8000,9600,'Estimated words',['timing'=>'catalog_chunk_estimate']);
$cue('mixed',9600,9700,'DRAFT_TIMELINE_SECRET',['status'=>'draft']);
$cue('mixed',9700,9800,'UNREVIEWED_SECRET',['state'=>'unverified']);
$cue('mixed',9800,9900,'LISTENER_ANNOTATION_SECRET',['annotation'=>1]);
$cue('mixed',9900,10000,'STALE_TIMELINE_SECRET',['fingerprint'=>str_repeat('f',64)]);
$cue('mixed',10000,10000,'INVALID_RANGE_SECRET');
$cue('hidden',0,1000,'HIDDEN_TIMELINE_SECRET');
$cue('draft-album-song',0,1000,'HIDDEN_ALBUM_TIMELINE_SECRET',['fingerprint'=>$fingerprints['hidden']]);
$db->beginTransaction();
for($i=0;$i<701;$i++)$cue('cue-only',$i,$i+1,'UNRELATED_MOMENT_SECRET',['kind'=>'comment']);
for($i=0;$i<701;$i++)$cue('cue-only',1000+$i,1001+$i,'Cue '.$i,['timing'=>'provider']);
$db->commit();

$items=sf_canvas_lyrics_inventory();
lyric_check(array_column($items,'id')===['untimed','mixed','cue-only','legacy-long'],'Inventory includes only currently published lyrics on public songs and releases');
lyric_check(!array_key_exists('rows',$items[0])&&!str_contains(json_encode($items,JSON_THROW_ON_ERROR),'La mer')
    &&$items[0]['timed']===false&&$items[1]['timed']===true,'Picker returns timing availability and provenance without sending lyric text');
lyric_check(array_column(sf_canvas_lyrics_inventory(['cue-only','mixed','cue-only']),'id')===['cue-only','mixed'],
    'Inventory for selected songs preserves order and deduplicates canonical IDs');
lyric_check(sf_canvas_lyrics_rows(null)===[]&&sf_canvas_lyrics_rows([])===[],'Unscoped and explicitly cleared scenes receive no lyric text');
$plain=sf_canvas_lyrics_rows(['untimed']);
lyric_check(count($plain)===1&&$plain[0]['text']==="La lumière\n\nLa mer <3"&&$plain[0]['kind']==='text'&&
    $plain[0]['start_ms']===null&&$plain[0]['end_ms']===null&&$plain[0]['timing_source']===null,
    'Published Songbook retains line breaks and Unicode without invented timing');
$mixed=sf_canvas_lyrics_rows(['mixed']);
lyric_check(array_column($mixed,'kind')===['text','cue','cue']&&$mixed[0]['text']==="Songbook line one\nSongbook line two"&&
    $mixed[1]['text']==="Timeline words\nKeep this block"&&$mixed[1]['start_ms']===0&&$mixed[1]['end_ms']===3500,
    'A published Songbook and approved timeline coexist as separate text and actual timed blocks');
lyric_check(array_column($mixed,'approximate')===[0,0,1]&&$mixed[2]['timing_source']==='catalog_chunk_estimate'&&
    count(array_unique(array_column($mixed,'revision_hash')))===1,'Cues mark estimates and bind to a deterministic publication revision');
lyric_check(!preg_match('/SECRET|INTERNAL|private-path|private-name|audio_url/',json_encode($mixed,JSON_THROW_ON_ERROR)),
    'Returned rows omit unreviewed text, private annotations, audio paths and admin metadata');
$cues=sf_canvas_lyrics_rows(['cue-only']);
lyric_check(count($cues)===700&&$cues[0]['kind']==='cue'&&$cues[0]['text']==='Cue 0'&&$cues[699]['text']==='Cue 699'&&
    $cues[699]['cues_truncated']===1&&$items[2]['cues_truncated']===true,
    'Unrelated timeline rows cannot consume the cue budget and over-limit material is explicitly marked');
$long=sf_canvas_lyrics_rows(['legacy-long'])[0];
lyric_check($long['text_truncated']===1&&strlen($long['text'])<=42000&&preg_match('//u',$long['text'])===1,
    'Legacy over-limit published text is explicitly marked and remains UTF-8');
lyric_reject(static fn()=>sf_canvas_lyrics_validate(['MiXeD']),'Canonical song IDs are exact on both database collations');
lyric_reject(static fn()=>sf_canvas_lyrics_validate(['mixed','draft-book']),'Validation rejects any unavailable song in the explicit selection');
lyric_reject(static fn()=>sf_canvas_lyrics_rows(['mixed','../mixed']),'Invalid track identities never reach the source query');

$db->exec("UPDATE song_timeline_moments SET body='Corrected words',revision=revision+1 WHERE track_id='mixed' AND at_ms=0");
$corrected=sf_canvas_lyrics_rows(['mixed']);
lyric_check($corrected[1]['text']==='Corrected words'&&$corrected[1]['revision_hash']!==$mixed[1]['revision_hash'],
    'Reviewed corrections update live words and version without changing the canonical ID');
$db->exec("UPDATE media_links SET public_visible=0 WHERE asset_id=1 AND entity_type='track'");
lyric_check(array_column(sf_canvas_lyrics_rows(['mixed']),'kind')===['text'],
    'A members-only audio attachment cannot authorize public cues while published standalone text still works');
$db->exec("UPDATE media_links SET public_visible=1 WHERE asset_id=1 AND entity_type='track'");
$db->exec("UPDATE media_assets SET sha256='".str_repeat('d',64)."' WHERE id=1");
lyric_check(array_column(sf_canvas_lyrics_rows(['mixed']),'kind')===['text'],
    'Replacing the audio suppresses every prior-revision timeline cue');
$db->exec("UPDATE songbooks SET status='archived' WHERE track_id='mixed'");
lyric_check(sf_canvas_lyrics_rows(['mixed'])===[],'Archiving the remaining published lyrics withdraws access');

$catalogPath=$fixture.'/data/catalog.json';
$rewriteCatalog=static function(array $rows)use($catalogPath):void {
    file_put_contents($catalogPath.'.next',json_encode($rows,JSON_THROW_ON_ERROR));
    rename($catalogPath.'.next',$catalogPath);
};
$staleCatalog=$tracks;
$staleCatalog[0]['state']='hidden';
$staleCatalog[2]['audio']='api/media.php?id='.$third;
$rewriteCatalog($staleCatalog);
lyric_check(sf_canvas_lyrics_rows(['untimed','cue-only'])===[],
    'A later read within the same request sees hidden songs and replaced listening sources');
unlink($catalogPath);
$catalogRejected=false;
try{sf_canvas_lyrics_rows(['untimed']);}catch(RuntimeException){$catalogRejected=true;}
lyric_check($catalogRejected,'A missing catalog fails closed instead of replaying cached lyric authorization');
$rewriteCatalog($tracks);

if($mysql){
    $dsn='mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
    $writer=new PDO($dsn,$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->beginTransaction();
    $db->query("SELECT status FROM songbooks WHERE track_id='untimed'")->closeCursor();
    $writer->exec("UPDATE songbooks SET status='draft' WHERE track_id='untimed'");
    lyric_reject(static fn()=>sf_canvas_lyrics_validate(['untimed']),'MariaDB approval ignores an older snapshot of a withdrawn Songbook');
    lyric_check($db->inTransaction(),'Current lyric reads preserve the surrounding approval transaction');
    $db->rollBack();
    $db->beginTransaction();
    $db->query("SELECT public_visible FROM media_links WHERE asset_id=2")->closeCursor();
    $writer->exec("UPDATE media_links SET public_visible=0 WHERE asset_id=2");
    lyric_reject(static fn()=>sf_canvas_lyrics_validate(['cue-only']),'MariaDB approval sees a listening link revoked after its read snapshot');
    lyric_check($db->inTransaction(),'Current audio fingerprint reads preserve the surrounding approval transaction');
    $db->rollBack();
}
echo "Canvas lyrics: $checks checks passed (".$config['driver'].')'.PHP_EOL;
