<?php
declare(strict_types=1);
/** Real disposable PDO, published media, extraction, timeline, and token ledger; provider replies are synthetic. */
$source=dirname(__DIR__);$worker=($argv[1]??'')==='worker';$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true)||!extension_loaded('pdo_'.$driver))throw new RuntimeException('Required fixture PDO driver unavailable.');
$tmp=$worker?(string)$argv[2]:sys_get_temp_dir().'/sf-song-import-'.bin2hex(random_bytes(8));
if(!$worker){
    foreach(['config','data','storage/media','sessions'] as $dir)mkdir($tmp.'/'.$dir,0700,true);
    $cfg=$driver==='sqlite'?['driver'=>'sqlite','path'=>$tmp.'/fixture.sqlite']:
      ['driver'=>'mysql','host'=>'127.0.0.1','port'=>33306,'database'=>'stonefellow_lyrics_test','username'=>'root','password'=>(string)getenv('SF_TEST_MYSQL_PASSWORD')];
    if($driver==='mysql'&&$cfg['password']==='')throw new RuntimeException('Isolated MySQL password required.');
    file_put_contents($tmp.'/config/database.php','<?php return '.var_export($cfg,true).';');
    file_put_contents($tmp.'/config/store.php',"<?php return ['mode'=>'test'];");
}
define('SF_ROOT',$tmp);session_save_path($tmp.'/sessions');
require $source.'/api/bootstrap.php';require $source.'/api/agent-runtime.php';require $source.'/api/song-lyrics-import.php';
$db=sf_db();if($driver==='sqlite')$db->exec('PRAGMA busy_timeout=15000');
if($worker){
    $index=(int)$argv[3];$id=(int)$argv[4];touch($tmp.'/ready-'.$index);$until=microtime(true)+15;
    while(!is_file($tmp.'/go')&&microtime(true)<$until)usleep(10000);
    if(!is_file($tmp.'/go'))throw new RuntimeException('Worker barrier expired.');
    $result=sf_song_lyrics_import_advance(2,$id,static function(array $provider,array $clip)use($tmp):array{
        if($provider['model']!==SF_SONG_CATALOG_MODEL||!is_file($clip['path']))throw new RuntimeException('Incorrect fixture clip.');
        file_put_contents($tmp.'/clip-paths',$clip['path']."\n",FILE_APPEND|LOCK_EX);
        file_put_contents($tmp.'/provider-calls',"call\n",FILE_APPEND|LOCK_EX);usleep(500000);
        return ['text'=>"First verse\nSee the midnight sky.",'usage'=>['input_tokens'=>17,'output_tokens'=>6]];
    });
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}

$checks=0;$workers=[];
function verify(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;echo "PASS $why\n";}
function fixture_cleanup(string $path): void {
    foreach(new DirectoryIterator($path) as $node){if($node->isDot())continue;$entry=$node->getPathname();if($node->isDir()&&!$node->isLink())fixture_cleanup($entry);else unlink($entry);}rmdir($path);
}
function fixture_audio(string $name,string $uuid,int $sample,int $seconds): int {
    $pcm=str_repeat(pack('v',$sample),8000*$seconds);
    $path=SF_ROOT.'/storage/media/'.$name.'.wav';file_put_contents($path,'RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($pcm)).$pcm);
    $q=sf_db()->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,duration_seconds,title,caption,technical_json,variants_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$uuid,'audio','audio/wav','wav',$name.'.wav','storage/media/'.$name.'.wav',hash_file('sha256',$path),filesize($path),$seconds,$name,'','{}','{}',gmdate('c'),gmdate('c')]);
    return (int)sf_db()->lastInsertId();
}
try{
    if($driver==='mysql'){
        if($db->query('SELECT DATABASE()')->fetchColumn()!=='stonefellow_lyrics_test')throw new RuntimeException('Refusing a non-fixture database.');
        $db->exec('SET FOREIGN_KEY_CHECKS=0');foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');$db->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    $db->exec('CREATE TABLE users(id '.($driver==='sqlite'?'INTEGER':'BIGINT UNSIGNED').' PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(100),role VARCHAR(30),status VARCHAR(30),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40),password_hash VARCHAR(255))'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $db->exec('CREATE TABLE app_meta(meta_key VARCHAR(190) PRIMARY KEY,meta_value TEXT NOT NULL,updated_at VARCHAR(40) NOT NULL)'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $q=$db->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at,password_hash) VALUES(?,?,?,?,?,?,?,?)');
    foreach([1=>'user',2=>'admin'] as $id=>$role)$q->execute([$id,'import'.$id.'@example.invalid','Fixture '.$id,$role,'active',gmdate('c'),gmdate('c'),'unused']);
    sf_media_ensure_schema();sf_entitlements_ensure_schema();sf_song_moments_schema();
    $uuid='M'.str_repeat('A',20);$masterUuid='M'.str_repeat('B',20);
    $asset=fixture_audio('preview',$uuid,900,125);$master=fixture_audio('manufacturing-master',$masterUuid,1200,125);
    $catalog=[];
    foreach(['fixture-song','unknown-song','stale-song','retry-song','cache-song','expired-song','rounding-song'] as $trackId){
        sf_media_attach($asset,'track',$trackId,'primary_audio',['public_visible'=>true]);
        $catalog[]=['id'=>$trackId,'title'=>$trackId,'audio'=>'api/media.php?id='.$uuid,'duration'=>$trackId==='rounding-song'?124:125,'state'=>'published','public_visible'=>true];
    }
    sf_media_attach($master,'track','protected-song','master_audio',['public_visible'=>false]);
    $catalog[]=['id'=>'protected-song','title'=>'Protected','audio'=>'api/media.php?id='.$masterUuid,'duration'=>125,'state'=>'published'];
    $catalog[]=['id'=>'private-song','title'=>'Private','audio'=>'api/media.php?id='.$uuid,'duration'=>125,'state'=>'private'];
    file_put_contents($tmp.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));file_put_contents($tmp.'/data/releases.json','[]');
    verify(sf_song_lyrics_import_plan(125000)===[['start_ms'=>0,'end_ms'=>60000],['start_ms'=>58000,'end_ms'=>118000],['start_ms'=>116000,'end_ms'=>125000]],'The full song is covered by bounded windows with a controlled overlap');
    verify(count(sf_song_lyrics_import_plan(900000))===16&&sf_song_lyrics_import_plan(61000)[0]['end_ms']===61000,'Maximum song duration caps provider requests and a tiny final tail is folded into its window');
    $wide=$tmp.'/storage/media/wide.wav';$wideBytes=192000*8*15;$file=fopen($wide,'wb');
    fwrite($file,'RIFF'.pack('V',36+$wideBytes).'WAVEfmt '.pack('VvvVVvv',16,1,2,192000,192000*8,8,32).'data'.pack('V',$wideBytes));ftruncate($file,44+$wideBytes);fclose($file);
    $plan=sf_song_lyrics_import_media_plan(['path'=>$wide,'extension'=>'wav']);
    verify($plan['duration_ms']===15000&&count($plan['segments'])===2&&$plan['segments'][1]['end_ms']===15000,'Wide PCM audio uses smaller windows while covering its verified duration');
    foreach($plan['segments'] as $window){$clip=sf_song_audio_extract($wide,'wav',$window['start_ms'],$window['end_ms']);try{verify(filesize($clip['path'])<=SF_SONG_CATALOG_CLIP_LIMIT,'Wide PCM import window fits the provider upload ceiling');}finally{unlink($clip['path']);}}
    if(is_executable('/usr/bin/ffmpeg')){
        $mp3=$tmp.'/storage/media/gapless.mp3';$pipes=[];$generator=proc_open(['/usr/bin/ffmpeg','-nostdin','-loglevel','error','-f','lavfi','-i','sine=frequency=440:sample_rate=44100:duration=4.425','-c:a','libmp3lame','-q:a','2','-y',$mp3],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($generator))throw new RuntimeException('Could not generate synthetic MP3.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($generator)!==0)throw new RuntimeException('MP3 fixture failed: '.$out.$err);
        $plan=sf_song_lyrics_import_media_plan(['path'=>$mp3,'extension'=>'mp3']);$end=$plan['duration_ms'];
        $clip=sf_song_audio_extract($mp3,'mp3',0,$end);
        try{verify(abs($end-4425)<15&&$clip['end_ms']<=$end&&$clip['end_ms']>=$end-100,'Gapless MP3 import ends at playable frames rather than padded catalog duration');}finally{unlink($clip['path']);}
    }
    $denied=false;try{sf_song_lyrics_import_enqueue(1,'fixture-song');}catch(RuntimeException $e){$denied=true;}
    verify($denied,'Listeners cannot enqueue a paid upload import');
    foreach(['protected-song','private-song'] as $blocked){$denied=false;try{sf_song_lyrics_import_enqueue(2,$blocked);}catch(SFSongCatalogError $e){$denied=true;}verify($denied,'Import rejects '.$blocked.' before provider access');}
    sf_song_lyrics_import_on_publish(2,'fixture-song',array_values(array_filter($catalog,'sf_track_is_public')));
    $job=sf_song_lyrics_import_enqueue(2,'fixture-song');$jobId=$job['id'];
    verify($job['status']==='queued'&&$job['total_chunks']===3&&count(sf_song_lyrics_import_jobs(2,'fixture-song'))===1,'Publication and repeated enqueue share a durable source-bound job without a provider call');
    $disabled=false;try{sf_song_lyrics_import_advance(2,$jobId);}catch(RuntimeException $e){$disabled=true;}
    verify($disabled&&sf_song_lyrics_import_job($jobId)['status']==='queued','Disabled OpenAI leaves the import queued without consuming an attempt');
    sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'sk-fixture-no-network','model'=>'separate-chat-model']);
    foreach([1,2] as $index){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'worker',$tmp,(string)$index,(string)$jobId],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch fixture workers.');fclose($pipes[0]);$workers[]=[$process,$pipes];}
    $until=microtime(true)+15;while((!is_file($tmp.'/ready-1')||!is_file($tmp.'/ready-2'))&&microtime(true)<$until)usleep(10000);
    verify(is_file($tmp.'/ready-1')&&is_file($tmp.'/ready-2'),'Two independent import workers reached the race barrier');touch($tmp.'/go');$advanced=[];
    foreach($workers as [$process,$pipes]){$raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Worker failed: '.$err);$advanced[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR)['advanced'];}
    $workers=[];sort($advanced);
    verify($advanced===[false,true]&&substr_count((string)file_get_contents($tmp.'/provider-calls'),'call')===1,'Concurrent advance claims make exactly one provider operation for the first chunk');
    $clipPaths=file($tmp.'/clip-paths',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
    verify(count($clipPaths)===1&&!file_exists($clipPaths[0]),'The winning provider excerpt is removed before response');
    $q=$db->prepare("SELECT COUNT(*) FROM token_ledger WHERE source=? AND provider='openai' AND model=? AND input_tokens=17");$q->execute(['song-import:'.$jobId.':0',SF_SONG_CATALOG_MODEL]);$metered=(int)$q->fetchColumn();$q->closeCursor();
    verify($metered===1,'Successful import metering is committed exactly once for the claimed chunk');
    $second=sf_song_lyrics_import_advance(2,$jobId,static function(array $provider,array $clip)use($jobId):array{
        verify($clip['start_ms']===58000&&$clip['end_ms']===118000,'Worker extracts the next real playback window');
        $pause=sf_song_lyrics_import_pause(2,$jobId,true);verify($pause['status']==='processing'&&$pause['pause_requested'],'Pause waits for the in-flight provider operation');
        return ['text'=>"See the midnight sky!\nDifferent line.\nKeep this line."];
    });
    verify($second['job']['status']==='paused'&&$second['job']['completed_chunks']===2,'Pausing after an in-flight response keeps its cached result');
    verify(sf_song_lyrics_import_advance(2,$jobId,static fn()=>throw new LogicException('Must not call provider'))['advanced']===false,'A paused job cannot charge another chunk');
    verify(sf_song_lyrics_import_pause(2,$jobId,false)['status']==='queued','Resume makes the next unprocessed chunk available');
    $final=sf_song_lyrics_import_advance(2,$jobId,static fn()=>['text'=>'']);
    verify($final['job']['status']==='ready'&&$final['job']['completed_chunks']===3&&$final['job']['next_chunk']===null,'Instrumental tail completes and the full source is processed once');
    $rows=array_values(array_filter(sf_song_moments_admin_list('fixture-song'),static fn($row)=>$row['kind']==='lyric'));
    verify(count($rows)===2&&sf_song_moments_public('fixture-song')===[],'Only usable lyrics become private editorial drafts; silence is not a public or fake segment');
    $secondRow=array_values(array_filter($rows,static fn($row)=>(int)$row['at_ms']===58000))[0];
    verify($secondRow['body']==="Different line.\nKeep this line."&&$secondRow['lyric_state']==='unverified'&&$secondRow['timing_source']==='catalog_chunk_estimate'&&$secondRow['confidence']===null,'Adjacent overlap is deduplicated without deleting newlines or inventing confidence');
    $q=$db->prepare('SELECT raw_text FROM song_lyrics_import_chunks WHERE job_id=? AND chunk_index=1');$q->execute([$jobId]);$raw=(string)$q->fetchColumn();$q->closeCursor();
    verify(str_starts_with($raw,'See the midnight sky!'),'Original provider words are preserved separately for editorial provenance');
    verify(sf_song_lyrics_import_enqueue(2,'fixture-song')['status']==='ready'&&!sf_song_lyrics_import_advance(2,$jobId,static fn()=>throw new LogicException('Must not replay'))['advanced'],'Reopening or re-enqueueing a completed source never calls the provider again');
    $rounded=sf_song_lyrics_import_enqueue(2,'rounding-song');
    $imported=sf_song_moments_ingest_lyrics('rounding-song',$rounded['source_fingerprint'],[['source_key'=>'verified-tail','start_ms'=>124500,'end_ms'=>125000,'text'=>'Playable final lyric']],$rounded['duration_ms']);
    $tail=$imported['segments'][0];sf_song_moments_admin_save(2,['id'=>$tail['id'],'revision'=>$tail['revision'],'track_id'=>'rounding-song','kind'=>'lyric','at_ms'=>124500,'end_ms'=>125000,'body'=>'Reviewed final lyric','timing_source'=>'catalog_chunk_estimate','lyric_state'=>'approved','status'=>'published','confirmed'=>true]);
    verify($rounded['duration_ms']===125000&&sf_song_moments_lyric_section('rounding-song',124500,125000)['text']==='Reviewed final lyric','Verified source duration survives rounded catalog metadata in ingestion, review, and playback lookup');
    $unknown=sf_song_lyrics_import_enqueue(2,'unknown-song');
    $outcome=sf_song_lyrics_import_advance(2,$unknown['id'],static fn()=>throw new RuntimeException('Synthetic lost response'));
    verify($outcome['job']['status']==='unknown'&&sf_song_lyrics_import_retry(2,$unknown['id'])['status']==='unknown','An uncertain provider response is terminal and cannot be explicitly replayed');
    $stale=sf_song_lyrics_import_enqueue(2,'stale-song');
    $outcome=sf_song_lyrics_import_advance(2,$stale['id'],static function()use($tmp,$catalog,$masterUuid):array{
        foreach($catalog as &$track)if($track['id']==='stale-song')$track['audio']='api/media.php?id='.$masterUuid;unset($track);
        file_put_contents($tmp.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));return ['text'=>'An old source lyric that must never be attached'];
    });
    verify($outcome['job']['status']==='stale'&&sf_song_moments_admin_list('stale-song')===[],'A changed publication rejects late cached words before timeline ingestion');
    $failed=sf_song_lyrics_import_enqueue(2,'retry-song');
    $db->prepare('UPDATE song_lyrics_import_chunks SET end_ms=100000 WHERE job_id=? AND chunk_index=0')->execute([$failed['id']]);
    $outcome=sf_song_lyrics_import_advance(2,$failed['id'],static fn()=>throw new LogicException('Invalid clip must not reach the provider'));
    verify($outcome['job']['status']==='failed'&&sf_song_lyrics_import_retry(2,$failed['id'])['status']==='queued','Only an established pre-provider failure can be retried explicitly');
    $cached=sf_song_lyrics_import_enqueue(2,'cache-song');
    $db->prepare("UPDATE song_lyrics_import_chunks SET status='cached',raw_text='Already cached text',transcript_text='Already cached text',actual_start_ms=0,actual_end_ms=60000 WHERE job_id=? AND chunk_index=0")->execute([$cached['id']]);
    $db->prepare("UPDATE song_lyrics_import_jobs SET status='processing',updated_at=? WHERE id=?")->execute([gmdate('c',time()-181),$cached['id']]);
    $outcome=sf_song_lyrics_import_advance(2,$cached['id'],static fn()=>throw new LogicException('Must finalize cache without a provider call'));
    verify($outcome['job']['completed_chunks']===1&&count(sf_song_moments_admin_list('cache-song'))===1,'A crash after caching resumes local timeline ingestion without resending audio');
    $expired=sf_song_lyrics_import_enqueue(2,'expired-song');
    $db->prepare("UPDATE song_lyrics_import_chunks SET status='processing',started_at=?,processing_by=2 WHERE job_id=? AND chunk_index=0")->execute([time()-181,$expired['id']]);
    sf_song_lyrics_import_set_state($expired['id'],'processing');
    $outcome=sf_song_lyrics_import_advance(2,$expired['id'],static fn()=>throw new LogicException('Expired provider operation must not be replayed'));
    verify($outcome['job']['status']==='unknown'&&!$outcome['advanced'],'An expired in-flight operation is never reclaimed for paid work');
    verify(glob($tmp.'/storage/media/*.wav')!==[],'Provider cleanup preserves published and private source audio');
    echo "$checks song import assertions passed ($driver).\n";
}finally{
    foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process))proc_terminate($process);}
    fixture_cleanup($tmp);
}
