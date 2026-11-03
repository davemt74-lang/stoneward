<?php
declare(strict_types=1);
/** Real PDO persistence tests, using disposable SQLite or the isolated CI MySQL database. */
$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true)||!extension_loaded('pdo_'.$driver))throw new RuntimeException('Required PDO driver unavailable.');
$worker=($argv[1]??'')==='worker';
$temporary=$worker?(string)$argv[2]:sys_get_temp_dir().'/sf-lyric-timeline-'.bin2hex(random_bytes(6)).'.sqlite';
$fixture=$temporary.'.files';if(!$worker)mkdir($fixture.'/data',0700,true);define('SF_ROOT',$fixture);
if($driver==='sqlite')$db=new PDO('sqlite:'.$temporary);
else{
    $password=(string)getenv('SF_TEST_MYSQL_PASSWORD');if($password==='')throw new RuntimeException('Isolated MySQL test password required.');
    $db=new PDO('mysql:host=127.0.0.1;port=33306;dbname=stonefellow_lyrics_test;charset=utf8mb4','root',$password);
    if($db->query('SELECT DATABASE()')->fetchColumn()!=='stonefellow_lyrics_test')throw new RuntimeException('Refusing a non-fixture database.');
    if(!$worker){$db->exec('SET FOREIGN_KEY_CHECKS=0');foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');$db->exec('SET FOREIGN_KEY_CHECKS=1');}
}
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if($driver==='sqlite'){$db->exec('PRAGMA foreign_keys=ON');$db->exec('PRAGMA busy_timeout=15000');}
$catalog=[['id'=>'song-one','title'=>'Synthetic one','duration'=>120,'audio'=>'api/media.php?id=M'.str_repeat('A',20)],
  ['id'=>'song-two','title'=>'Synthetic two','duration'=>120,'audio'=>'api/media.php?id=M'.str_repeat('B',20)]];
$assets=['M'.str_repeat('A',20)=>['id'=>1,'category'=>'audio','sha256'=>str_repeat('a',64)],
  'M'.str_repeat('B',20)=>['id'=>2,'category'=>'audio','sha256'=>str_repeat('b',64)]];
function sf_db():PDO {global $db;return $db;}
function sf_db_config():array {global $driver;return ['driver'=>$driver];}
function sf_public_catalog():array {global $catalog;return $catalog;}
function sf_media_asset(string $uuid):?array {global $assets;return $assets[$uuid]??null;}
function sf_media_protected_master_asset(int $id):bool {return $id===99;}
function sf_admin_write_catalog(array $rows,?string $expectedHash=null):void {
    global $catalog;$path=SF_ROOT.'/data/catalog.json';
    if($expectedHash===null||!hash_equals($expectedHash,(string)hash_file('sha256',$path)))throw new RuntimeException('Catalog changed during review.');
    file_put_contents($path,json_encode($rows,JSON_THROW_ON_ERROR));$catalog=$rows;
}
require dirname(__DIR__).'/api/song-moments-core.php';
require dirname(__DIR__).'/api/song-lyrics-catalog.php';
require dirname(__DIR__).'/api/agent-song-analyze-core.php';
if($worker){
    sf_song_moments_schema();file_put_contents(SF_ROOT.'/ready-'.$argv[3],'ready');$deadline=microtime(true)+10;
    while(!is_file(SF_ROOT.'/go')&&microtime(true)<$deadline)usleep(10000);
    if(!is_file(SF_ROOT.'/go'))throw new RuntimeException('Concurrent import test barrier timed out.');
    $result=sf_song_moments_ingest_lyrics('song-one',sf_song_moments_audio_fingerprint($catalog[0]),[
        ['start_ms'=>71000,'end_ms'=>74000,'text'=>'Concurrent synthetic lyrics','source_key'=>'job:concurrent:chunk:0']]);
    echo json_encode(['created'=>$result['created'],'id'=>$result['segments'][0]['id']],JSON_THROW_ON_ERROR);exit;
}
$checks=0;
function verify(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label."\n";}
function refuses(callable $call,string $label):void {try{$call();}catch(InvalidArgumentException|RuntimeException $e){verify(true,$label);return;}throw new RuntimeException('FAIL expected rejection: '.$label);}
function canonical(array $changes=[]):array {return array_replace(['track_id'=>'song-one','kind'=>'lyric','at_ms'=>1000,'end_ms'=>5000,'body'=>'Synthetic lyric phrase','lyric_state'=>'unverified','timing_source'=>'manual','confidence'=>null,'status'=>'draft'],$changes);}
function row(int $id):array {$q=sf_db()->prepare('SELECT * FROM song_timeline_moments WHERE id=?');$q->execute([$id]);$r=$q->fetch();$q->closeCursor();return $r;}
try{
    file_put_contents(SF_ROOT.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
    $db->exec('CREATE TABLE users(id '.($driver==='sqlite'?'INTEGER':'BIGINT UNSIGNED').' PRIMARY KEY)'.($driver==='mysql'?' ENGINE=InnoDB':''));$db->exec('INSERT INTO users(id) VALUES(1),(2)');
    $db->exec('CREATE TABLE media_links(asset_id INTEGER NOT NULL,public_visible INTEGER NOT NULL,entity_type VARCHAR(30) NOT NULL)');
    $db->exec("INSERT INTO media_links(asset_id,public_visible,entity_type) VALUES(1,1,'track'),(2,1,'track')");
    // Deliberately start with the exact legacy shape to exercise additive upgrade.
    if($driver==='sqlite')$db->exec("CREATE TABLE song_timeline_moments(id INTEGER PRIMARY KEY AUTOINCREMENT,track_id TEXT NOT NULL,at_ms INTEGER NOT NULL,kind TEXT NOT NULL,title TEXT NOT NULL DEFAULT '',body TEXT NOT NULL DEFAULT '',media_uuid TEXT NOT NULL DEFAULT '',story_id INTEGER NOT NULL DEFAULT 0,user_id INTEGER NULL,display_name TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,moderated_by INTEGER NULL,moderated_at TEXT NOT NULL DEFAULT '',FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
    else $db->exec("CREATE TABLE song_timeline_moments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,track_id VARCHAR(180) NOT NULL,at_ms INT UNSIGNED NOT NULL,kind VARCHAR(20) NOT NULL,title VARCHAR(180) NOT NULL DEFAULT '',body TEXT NOT NULL,media_uuid VARCHAR(80) NOT NULL DEFAULT '',story_id BIGINT UNSIGNED NOT NULL DEFAULT 0,user_id BIGINT UNSIGNED NULL,display_name VARCHAR(100) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,moderated_by BIGINT UNSIGNED NULL,moderated_at VARCHAR(40) NOT NULL DEFAULT '') ENGINE=InnoDB");
    $db->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,kind,title,body,media_uuid,story_id,display_name,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(['song-one',500,'note','Existing note','Preserved legacy content','',0,'Stonefellow','published','before','before']);
    sf_song_moments_schema();sf_song_moments_schema();
    verify(row(1)['body']==='Preserved legacy content'&&(int)row(1)['revision']===1,'Additive migration preserves existing timeline entries and is repeatable');
    $fingerprint=sf_song_moments_audio_fingerprint($catalog[0]);verify(strlen($fingerprint)===64,'Managed audio version has a stable fingerprint');
    $draft=sf_song_moments_admin_save(1,canonical());$id=$draft['id'];
    verify((int)row($id)['revision']===1&&row($id)['confidence']===null,'Draft stores an explicit range and unknown confidence');
    verify(count(sf_song_moments_public('song-one'))===1&&sf_song_moments_lyric_available('song-one')===0,'Unverified drafts do not enter the public timeline');
    refuses(fn()=>sf_song_lyrics_apply_catalog('song-one',1,true),'Unreviewed text cannot replace existing Songbook lyrics');
    verify(sf_song_moments_lyric_available('song-one',true)===1,'Administrator preview can find draft lyrics');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['status'=>'published','confirmed'=>true])),'Unreviewed lyrics cannot be published');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['lyric_state'=>'approved','status'=>'published'])),'Publishing requires explicit confirmation');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['at_ms'=>5000,'end_ms'=>5000])),'Empty time ranges are rejected');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['end_ms'=>120001])),'Lyric range cannot exceed catalog duration');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['at_ms'=>1.1])),'Fractional millisecond input is rejected');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['confidence'=>1.01])),'Invalid provider confidence is rejected');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['confidence'=>'0.9'])),'String confidence cannot bypass numeric validation');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['timing_source'=>'provider'])),'Manual admin creation cannot claim provider timing');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['body'=>str_repeat('x',4201)])),'Lyric text is bounded');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['track_id'=>'unpublished-song'])),'Unpublished song IDs cannot receive canonical lyrics');
    $approved=sf_song_moments_admin_save(1,canonical(['id'=>$id,'revision'=>1,'lyric_state'=>'approved','status'=>'published','confirmed'=>true,'confidence'=>0.8]));
    verify($approved['revision']===2&&count(sf_song_moments_public('song-one'))===2,'Explicit approval and publication expose a timed lyric');
    $public=sf_song_moments_public('song-one')[1];verify($public['end_ms']===5000&&$public['lyric_state']==='approved'&&$public['confidence']===0.8,'Public lyric contains accurate range and review provenance');
    refuses(fn()=>sf_song_lyrics_apply_catalog('song-one',0,true),'Songbook promotion requires an administrator');
    refuses(fn()=>sf_song_lyrics_apply_catalog('song-one',1,false),'Songbook promotion requires explicit confirmation');
    $applied=sf_song_lyrics_apply_catalog('song-one',1,true);$saved=json_decode((string)file_get_contents(SF_ROOT.'/data/catalog.json'),true);
    verify($applied['segments']===1&&$saved[0]['lyrics']==='Synthetic lyric phrase'&&$saved[0]['metadata']['lyrics_source']['fingerprint']===$fingerprint&&!isset($saved[1]['lyrics']),'Approved text updates only the selected song with source and revision provenance');
    $section=sf_song_moments_lyric_section('song-one',3000,6000);verify($section['text']==='Synthetic lyric phrase'&&$section['segments'][0]['start_ms']===1000,'Saved section returns overlapping text and its original range');
    $analysisRequest=['track_id'=>'song-one','title'=>'Synthetic one','release'=>'Fixture','start_ms'=>3000,'end_ms'=>6000,
      'captured_at'=>gmdate('c'),'transcript'=>$section['text'],'question'=>'What does the phrase suggest?',
      'listener_edited'=>false,'draft_status'=>'unverified_user_supplied'];
    $reference=['saved_lyric_ids'=>[$id]];
    $analysis=sf_song_analysis_saved_lyrics($analysisRequest,$reference);
    verify($analysis['draft_status']==='approved_catalog_lyrics'&&$analysis['lyric_ranges'][0]['start_ms']===1000
      &&str_contains(sf_song_analysis_system($analysis,'','',''),'ADMIN-APPROVED SAVED LYRICS'),'Agent verifies reviewed words and includes original block timing');
    refuses(fn()=>sf_song_analysis_saved_lyrics(array_replace($analysisRequest,['transcript'=>'Forged lyrics']),$reference),'Listener cannot label changed words as approved lyrics');
    verify(sf_song_analysis_saved_lyrics(array_replace($analysisRequest,['listener_edited'=>true]),$reference)['draft_status']==='unverified_user_supplied','Listener edits remain unverified evidence');
    verify(sf_song_moments_lyric_section('song-one',5000,6000)['text']==='','Range overlap is end-exclusive');
    verify(sf_song_moments_lyric_section('song-two',1000,5000)['text']==='','Saved lyrics never cross song identities');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['id'=>$id,'revision'=>1,'lyric_state'=>'approved'])),'Stale lyric revisions cannot overwrite reviewed content');
    sf_song_moments_admin_save(1,canonical(['id'=>$id,'revision'=>2,'body'=>'Corrected private draft','lyric_state'=>'corrected']));
    refuses(fn()=>sf_song_analysis_saved_lyrics($analysisRequest,$reference),'Retired approval cannot be reused by a listener');
    verify(sf_song_moments_lyric_available('song-one')===0&&row($id)['lyric_state']==='corrected','Editing into a draft removes public visibility and retains correction state');
    // Defensively exclude personal annotation-linked rows, even if a malformed legacy row says published.
    $db->prepare("INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at,lyric_state,source_annotation_id,source_fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute(['song-one',0,1000,'lyric','','Private owner text','',0,2,'Listener','published','now','now','approved',99,$fingerprint]);$privateId=(int)$db->lastInsertId();
    verify(!str_contains(json_encode(sf_song_moments_public('song-one')),'Private owner text')&&!str_contains(json_encode(sf_song_moments_admin_list()),'Private owner text'),'Personal annotation text is excluded from public and admin timeline lists');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['id'=>$privateId,'revision'=>1])),'Admin canonical editor cannot modify personal annotation rows');
    refuses(fn()=>sf_song_moments_admin_moderate(1,$privateId,'remove'),'Canonical moderation cannot alter personal annotation rows');
    $segment=['start_ms'=>10000,'end_ms'=>70000,'text'=>str_repeat('Synthetic phrase. ',110),'source_key'=>'job:1:chunk:0','timing_source'=>'catalog_chunk_estimate'];
    $import=sf_song_moments_ingest_lyrics('song-one',$fingerprint,[$segment]);verify($import['created']===1&&$import['segments'][0]['lyric_state']==='unverified'&&$import['segments'][0]['confidence']===null,'Worker saves bounded estimated chunks as unverified canonical drafts');
    $importId=$import['segments'][0]['id'];$retry=sf_song_moments_ingest_lyrics('song-one',$fingerprint,[array_replace($segment,['text'=>'Changed retry text'])]);verify($retry['created']===0&&row($importId)['body']===trim($segment['text']),'Retry receipt prevents overwriting the first stored chunk');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['id'=>$importId,'revision'=>1,'at_ms'=>12000,'end_ms'=>70000,'timing_source'=>'catalog_chunk_estimate'])),'An admin cannot retain provider timing provenance after changing timestamps');
    $manual=sf_song_moments_admin_save(1,canonical(['id'=>$importId,'revision'=>1,'at_ms'=>10000,'end_ms'=>70000,'body'=>'Admin reviewed correction','lyric_state'=>'approved','status'=>'published','confirmed'=>true]));
    $db->exec("UPDATE media_links SET entity_type='member_content' WHERE asset_id=1");
    verify(sf_song_moments_lyric_available('song-one')===0&&sf_song_moments_lyric_section('song-one',10000,15000)['segments']===[]&&count(sf_song_moments_public('song-one'))===1,'A revoked public listening link cannot expose saved lyric text');
    $db->exec("UPDATE media_links SET entity_type='track' WHERE asset_id=1");
    sf_song_moments_ingest_lyrics('song-one',$fingerprint,[$segment]);verify(row($importId)['body']==='Admin reviewed correction'&&(int)row($importId)['revision']===$manual['revision'],'Import retry preserves administrator corrections and approval');
    sf_song_moments_admin_moderate(1,$importId,'remove');$retry=sf_song_moments_ingest_lyrics('song-one',$fingerprint,[$segment]);verify($retry['created']===0&&$retry['segments']===[],'Import receipt preserves removal without resurrecting deleted lyrics');
    refuses(fn()=>sf_song_moments_ingest_lyrics('song-one',$fingerprint,[$segment,$segment]),'Duplicate segment keys reject the batch');
    $before=(int)$db->query('SELECT COUNT(*) FROM song_timeline_moments')->fetchColumn();
    refuses(fn()=>sf_song_moments_ingest_lyrics('song-one',$fingerprint,[array_replace($segment,['source_key'=>'valid-new']),array_replace($segment,['source_key'=>'bad-new','end_ms'=>130000])]),'Invalid batch ranges reject ingestion');
    verify((int)$db->query('SELECT COUNT(*) FROM song_timeline_moments')->fetchColumn()===$before,'Invalid batch validation creates no partial lyric rows');
    $workers=[];
    try{
        for($i=1;$i<=2;$i++){
            $process=proc_open([PHP_BINARY,__FILE__,'worker',$temporary,(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Could not start concurrent import fixture.');
            fclose($pipes[0]);$workers[]=[$process,$pipes[1],$pipes[2]];
        }
        $deadline=microtime(true)+10;while((!is_file(SF_ROOT.'/ready-1')||!is_file(SF_ROOT.'/ready-2'))&&microtime(true)<$deadline)usleep(10000);
        if(!is_file(SF_ROOT.'/ready-1')||!is_file(SF_ROOT.'/ready-2'))throw new RuntimeException('Concurrent import fixture did not become ready.');
        file_put_contents(SF_ROOT.'/go','go');$results=[];
        foreach($workers as [$process,$stdout,$stderr]){
            $body=stream_get_contents($stdout);$error=stream_get_contents($stderr);fclose($stdout);fclose($stderr);
            if(proc_close($process)!==0)throw new RuntimeException('Concurrent import failed: '.$error);
            $results[]=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        }
        verify(array_sum(array_column($results,'created'))===1&&$results[0]['id']===$results[1]['id'],'Concurrent same-source chunk imports create exactly one durable lyric row');
    }finally{
        foreach($workers as [$process,$stdout,$stderr]){if(is_resource($stdout))fclose($stdout);if(is_resource($stderr))fclose($stderr);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
        foreach(['ready-1','ready-2','go'] as $name)@unlink(SF_ROOT.'/'.$name);
    }
    $assets['M'.str_repeat('A',20)]['sha256']=str_repeat('c',64);
    verify(sf_song_moments_lyric_available('song-one',true)===0&&sf_song_moments_lyric_section('song-one',0,90000,true)['segments']===[],'Replacing listening audio hides old saved text from the new song version');
    refuses(fn()=>sf_song_moments_ingest_lyrics('song-one',$fingerprint,[$segment]),'Stale audio fingerprint cannot ingest lyrics');
    refuses(fn()=>sf_song_moments_admin_save(1,canonical(['id'=>$id,'revision'=>3])),'A stale admin form cannot silently rebind old text to new audio');
    verify(count(sf_song_moments_public('song-one'))===1,'Version changes preserve unrelated legacy moments');
    echo 'PASS '.$checks.' lyric timeline checks ('.$driver.")\n";
}finally{if($driver==='sqlite'){unset($db);@unlink($temporary);}@unlink(SF_ROOT.'/data/catalog.json');@rmdir(SF_ROOT.'/data');@rmdir(SF_ROOT);}
