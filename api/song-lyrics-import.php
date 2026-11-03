<?php
declare(strict_types=1);
/** 91B22A — import each published listening source once; playback only reads saved lyrics. */
require_once __DIR__.'/agent-song-catalog-core.php';
require_once __DIR__.'/song-moments-core.php';

const SF_LYRICS_IMPORT_PROFILE='catalog-60s-overlap2-v1';
const SF_LYRICS_IMPORT_MAX_MS=900000;

function sf_song_lyrics_import_schema(): void {
    $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS song_lyrics_import_jobs (
          id INTEGER PRIMARY KEY AUTOINCREMENT,track_id TEXT NOT NULL,source_fingerprint TEXT NOT NULL,
          source_ref TEXT NOT NULL,model TEXT NOT NULL,profile TEXT NOT NULL,duration_ms INTEGER NOT NULL,
          status TEXT NOT NULL,created_by INTEGER NULL,pause_requested INTEGER NOT NULL DEFAULT 0,
          error_code TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
          UNIQUE(track_id,source_fingerprint,model,profile),
          FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $db->exec("CREATE TABLE IF NOT EXISTS song_lyrics_import_chunks (
          job_id INTEGER NOT NULL,chunk_index INTEGER NOT NULL,start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,
          actual_start_ms INTEGER NOT NULL DEFAULT 0,actual_end_ms INTEGER NOT NULL DEFAULT 0,
          status TEXT NOT NULL,operation_key TEXT NOT NULL DEFAULT '',processing_by INTEGER NULL,
          started_at INTEGER NOT NULL DEFAULT 0,raw_text TEXT NOT NULL DEFAULT '',transcript_text TEXT NOT NULL DEFAULT '',
          usage_json TEXT NOT NULL DEFAULT '{}',token_amount INTEGER NOT NULL DEFAULT 0,error_code TEXT NOT NULL DEFAULT '',
          PRIMARY KEY(job_id,chunk_index),FOREIGN KEY(job_id) REFERENCES song_lyrics_import_jobs(id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_lyrics_import_queue ON song_lyrics_import_jobs(status,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_lyrics_import_actor_time ON song_lyrics_import_chunks(processing_by,started_at)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS song_lyrics_import_jobs (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,track_id VARCHAR(180) NOT NULL,source_fingerprint CHAR(64) NOT NULL,
          source_ref VARCHAR(512) NOT NULL,model VARCHAR(80) NOT NULL,profile VARCHAR(80) NOT NULL,duration_ms INT UNSIGNED NOT NULL,
          status VARCHAR(24) NOT NULL,created_by BIGINT UNSIGNED NULL,pause_requested TINYINT NOT NULL DEFAULT 0,
          error_code VARCHAR(80) NOT NULL DEFAULT '',created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
          UNIQUE KEY uq_lyrics_import_source(track_id,source_fingerprint,model,profile),
          INDEX idx_lyrics_import_queue(status,id),
          CONSTRAINT fk_lyrics_import_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS song_lyrics_import_chunks (
          job_id BIGINT UNSIGNED NOT NULL,chunk_index INT UNSIGNED NOT NULL,start_ms INT UNSIGNED NOT NULL,end_ms INT UNSIGNED NOT NULL,
          actual_start_ms INT UNSIGNED NOT NULL DEFAULT 0,actual_end_ms INT UNSIGNED NOT NULL DEFAULT 0,
          status VARCHAR(24) NOT NULL,operation_key CHAR(32) NOT NULL DEFAULT '',processing_by BIGINT UNSIGNED NULL,
          started_at BIGINT NOT NULL DEFAULT 0,raw_text TEXT NOT NULL,transcript_text TEXT NOT NULL,
          usage_json TEXT NOT NULL,token_amount INT UNSIGNED NOT NULL DEFAULT 0,error_code VARCHAR(80) NOT NULL DEFAULT '',
          PRIMARY KEY(job_id,chunk_index),
          INDEX idx_lyrics_import_actor_time(processing_by,started_at),
          CONSTRAINT fk_lyrics_import_chunk_job FOREIGN KEY(job_id) REFERENCES song_lyrics_import_jobs(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_song_lyrics_import_admin(int $adminId): array {
    $q=sf_db()->prepare("SELECT id,role,status FROM users WHERE id=?");$q->execute([$adminId]);
    $user=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$user||($user['role']??'')!=='admin'||($user['status']??'')!=='active')
        throw new RuntimeException('Administrator authority is required to import song lyrics.');
    return $user;
}
function sf_song_lyrics_import_catalog(): array {
    // sf_catalog() caches within a request; provider work must see concurrent publication changes.
    $raw=@file_get_contents(SF_ROOT.'/data/catalog.json');$rows=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($rows))throw new RuntimeException('The current catalog could not be read.');
    return array_values(array_filter($rows,static function($track):bool{
        if(!is_array($track)||!sf_track_is_public($track))return false;
        $release=(string)($track['release_id']??'');
        return $release===''||(function_exists('sf_seo_public_release')&&sf_seo_public_release($release)!==null);
    }));
}
function sf_song_lyrics_import_source(string $trackId,array $published,array $user,?string $reference=null): array {
    $source=sf_song_catalog_source($trackId,$published,$user,true,$reference);
    $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE asset_id=? AND entity_type='track' AND entity_id=? AND role='primary_audio' AND public_visible=1 LIMIT 1");
    $q->execute([(int)$source['asset_id'],$trackId]);$active=(bool)$q->fetchColumn();$q->closeCursor();
    if(!$active)sf_song_catalog_error('song_source_changed',409,'The track has no active published listening copy.');
    return $source;
}
/** Whole-clip estimates, never invented word/line alignment. */
function sf_song_lyrics_import_plan(int $durationMs,int $maximumSpanMs=65000): array {
    if($durationMs<1000||$durationMs>SF_LYRICS_IMPORT_MAX_MS)
        throw new InvalidArgumentException('Song lyric import supports published songs from one second to fifteen minutes.');
    if($maximumSpanMs<4000||$maximumSpanMs>65000)throw new InvalidArgumentException('This listening copy needs an MP3 or a smaller PCM WAV.');
    $segments=[];$start=0;$window=min(60000,$maximumSpanMs);
    while($start<$durationMs){
        $end=min($durationMs,$start+$window);
        if($durationMs>$end&&$durationMs-$end<5000&&$durationMs-$start<=$maximumSpanMs)$end=$durationMs;
        $segments[]=['start_ms'=>$start,'end_ms'=>$end];
        if($end===$durationMs)break;
        $start=$end-2000;
    }
    if(count($segments)>16)throw new LogicException('Song import exceeded its bounded request plan.');
    return $segments;
}
/** Verify the actual playable duration before any paid work; MP3 tags/metadata can include encoder padding. */
function sf_song_lyrics_import_media_plan(array $source): array {
    $input=sf_song_audio_open($source['path'],$source['extension']);$span=65000;
    try{
        if($source['extension']==='wav'){
            $wav=sf_song_audio_wav_info($input);$duration=intdiv($wav['samples']*1000,$wav['rate']);
            // Reserve room for the RIFF header and an odd-byte PCM pad.
            $span=min($span,intdiv((SF_SONG_CATALOG_CLIP_LIMIT-45)*1000,$wav['byte_rate']));
        }else{
            $bounds=sf_song_audio_mp3_bounds($input);$first=sf_song_audio_mp3_frame($input,$bounds['end'],null);
            $format=$first['info'];$timing=sf_song_audio_mp3_timing($first);
            if(!$timing['tag'])fseek($input,$bounds['start']);
            $frames=0;$mainBytes=0;$previousEndBits=0;
            while(ftell($input)<$bounds['end']){
                if($frames>=250000)throw new InvalidArgumentException('This MP3 exceeds the song import frame limit.');
                $frame=sf_song_audio_mp3_frame($input,$bounds['end'],$format);$back=$frame['side']['back'];
                $beginBits=($mainBytes-$back)*8;
                if($back>min($mainBytes,$format['version']===3?511:255)||$beginBits<$previousEndBits)
                    throw new InvalidArgumentException('This MP3 has an unsupported bit reservoir.');
                $mainBytes+=strlen($frame['frame'])-4-$frame['info']['side'];
                $previousEndBits=$beginBits+$frame['side']['bits'];$frames++;
                if($frames*$format['samples']>($format['rate']*(SF_LYRICS_IMPORT_MAX_MS+1000))/1000)
                    throw new InvalidArgumentException('Song lyric import is limited to fifteen minutes.');
            }
            if($timing['frames']!==null&&$timing['frames']!==$frames)throw new InvalidArgumentException('The MP3 frame count does not match its timing header.');
            $duration=intdiv(($frames*$format['samples']-$timing['lead']-$timing['padding'])*1000,$format['rate']);
        }
    }finally{fclose($input);}
    if($duration>SF_LYRICS_IMPORT_MAX_MS)throw new InvalidArgumentException('Song lyric import is limited to fifteen minutes.');
    return ['duration_ms'=>$duration,'segments'=>sf_song_lyrics_import_plan($duration,$span)];
}
function sf_song_lyrics_import_job(int $id): ?array {
    $q=sf_db()->prepare('SELECT * FROM song_lyrics_import_jobs WHERE id=?');$q->execute([$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}
/** No source paths, credentials, raw provider data, or private masters enter this response. */
function sf_song_lyrics_import_job_view(array $job): array {
    $q=sf_db()->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
      MIN(CASE WHEN status<>'completed' THEN chunk_index ELSE NULL END) next_chunk
      FROM song_lyrics_import_chunks WHERE job_id=?");$q->execute([(int)$job['id']]);
    $counts=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    return ['id'=>(int)$job['id'],'track_id'=>(string)$job['track_id'],'status'=>(string)$job['status'],
      'source_fingerprint'=>(string)$job['source_fingerprint'],'model'=>(string)$job['model'],
      'timing_source'=>'catalog_chunk_estimate','duration_ms'=>(int)$job['duration_ms'],
      'total_chunks'=>(int)$counts['total'],'completed_chunks'=>(int)$counts['completed'],
      'next_chunk'=>$counts['next_chunk']===null?null:(int)$counts['next_chunk']+1,
      'can_advance'=>$job['status']==='queued','pause_requested'=>!empty($job['pause_requested']),
      'error_code'=>(string)$job['error_code'],'updated_at'=>(string)$job['updated_at']];
}
/** Provider work may finish after a browser closes. An expired operation is never replayed. */
function sf_song_lyrics_import_recover(): void {
    $db=sf_db();$now=gmdate('c');
    $q=$db->prepare("UPDATE song_lyrics_import_chunks SET status='unknown',error_code='provider_outcome_unknown'
      WHERE status='processing' AND started_at<?");$q->execute([time()-180]);
    $db->prepare("UPDATE song_lyrics_import_jobs SET status='unknown',error_code='provider_outcome_unknown',updated_at=?
      WHERE status='processing' AND EXISTS (SELECT 1 FROM song_lyrics_import_chunks c WHERE c.job_id=song_lyrics_import_jobs.id AND c.status='unknown')")->execute([$now]);
    // Cached text and its metering were committed together. Retrying this local import cannot repeat a provider call.
    $db->prepare("UPDATE song_lyrics_import_jobs SET status=CASE WHEN pause_requested=1 THEN 'paused' ELSE 'queued' END,updated_at=?
      WHERE status='processing' AND updated_at<? AND EXISTS (SELECT 1 FROM song_lyrics_import_chunks c WHERE c.job_id=song_lyrics_import_jobs.id AND c.status='cached')")->execute([$now,gmdate('c',time()-180)]);
}
function sf_song_lyrics_import_jobs(int $adminId,string $trackId=''): array {
    sf_song_lyrics_import_admin($adminId);sf_song_lyrics_import_schema();sf_song_lyrics_import_recover();
    $sql='SELECT * FROM song_lyrics_import_jobs';$args=[];
    if($trackId!==''){$sql.=' WHERE track_id=?';$args[]=$trackId;}
    $q=sf_db()->prepare($sql.' ORDER BY id DESC LIMIT 100');$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    return array_map('sf_song_lyrics_import_job_view',$rows);
}
/** Queue only once the uploaded asset is the authorized public listening copy. No provider call here. */
function sf_song_lyrics_import_enqueue(int $adminId,string $trackId,?array $published=null): array {
    $user=sf_song_lyrics_import_admin($adminId);$published??=sf_song_lyrics_import_catalog();
    $source=sf_song_lyrics_import_source($trackId,$published,$user);
    sf_song_lyrics_import_schema();$db=sf_db();
    $find=$db->prepare('SELECT * FROM song_lyrics_import_jobs WHERE track_id=? AND source_fingerprint=? AND model=? AND profile=?');
    $args=[$trackId,$source['fingerprint'],SF_SONG_CATALOG_MODEL,SF_LYRICS_IMPORT_PROFILE];$find->execute($args);
    $existing=$find->fetch(PDO::FETCH_ASSOC);$find->closeCursor();
    if($existing)return sf_song_lyrics_import_job_view($existing);
    $mediaPlan=sf_song_lyrics_import_media_plan($source);$duration=$mediaPlan['duration_ms'];$plan=$mediaPlan['segments'];
    $now=gmdate('c');
    try{
        $db->beginTransaction();
        $q=$db->prepare('INSERT INTO song_lyrics_import_jobs(track_id,source_fingerprint,source_ref,model,profile,duration_ms,status,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$trackId,$source['fingerprint'],$source['reference'],SF_SONG_CATALOG_MODEL,SF_LYRICS_IMPORT_PROFILE,$duration,'queued',$adminId,$now,$now]);
        $id=(int)$db->lastInsertId();
        $insert=$db->prepare("INSERT INTO song_lyrics_import_chunks(job_id,chunk_index,start_ms,end_ms,status,raw_text,transcript_text,usage_json) VALUES(?,?,?,?,'queued','','','{}')");
        foreach($plan as $index=>$segment)$insert->execute([$id,$index,$segment['start_ms'],$segment['end_ms']]);
        $db->commit();return sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id));
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();$find->execute($args);$existing=$find->fetch(PDO::FETCH_ASSOC);$find->closeCursor();
        if($existing)return sf_song_lyrics_import_job_view($existing);throw $e;
    }
}
/** Best-effort publication hook: a queue problem never rolls back an already published song. */
function sf_song_lyrics_import_on_publish(int $adminId,string $trackId,array $published): void {
    if($adminId<1||!function_exists('sf_track_is_public'))return;
    try{
        $published=array_values(array_filter($published,static fn($row):bool=>is_array($row)&&sf_track_is_public($row)
            &&(empty($row['release_id'])||(function_exists('sf_seo_public_release')&&sf_seo_public_release((string)$row['release_id'])!==null))));
        foreach($published as $track)if(($track['id']??'')===$trackId){sf_song_lyrics_import_enqueue($adminId,$trackId,$published);return;}
    }
    catch(Throwable $e){error_log('[Song lyric import queue] '.get_class($e));}
}
/** Trim only adjacent overlap text; keep raw text and natural repeated choruses elsewhere. */
function sf_song_lyrics_import_dedupe(string $previous,string $current): string {
    $before=preg_split('/\s+/u',trim($previous),-1,PREG_SPLIT_NO_EMPTY)?:[];
    preg_match_all('/\S+/u',$current,$matches,PREG_OFFSET_CAPTURE);$offsets=$matches[0]??[];$after=array_column($offsets,0);
    $normal=static function(string $text):string{
        $word=preg_replace('/[^\p{L}\p{N}]/u','',$text)??'';
        return function_exists('mb_strtolower')?mb_strtolower($word,'UTF-8'):strtolower($word);
    };
    for($count=min(12,count($before),count($after));$count>=4;$count--){
        $prefix=array_map($normal,array_slice($after,0,$count));
        if(!in_array('',$prefix,true)&&array_map($normal,array_slice($before,-$count))===$prefix)
            return isset($offsets[$count])?trim(substr($current,$offsets[$count][1])):'';
    }
    return trim($current);
}
/** Retry only a proven pre-provider failure. Unknown/paid operations remain non-replayable. */
function sf_song_lyrics_import_retry(int $adminId,int $id): array {
    sf_song_lyrics_import_admin($adminId);sf_song_lyrics_import_schema();$db=sf_db();$db->beginTransaction();
    try{
        $q=$db->prepare("UPDATE song_lyrics_import_jobs SET status='queued',pause_requested=0,error_code='',updated_at=? WHERE id=? AND status='failed'");$q->execute([gmdate('c'),$id]);
        if($q->rowCount()===1){
            $q=$db->prepare("UPDATE song_lyrics_import_chunks SET status='queued',operation_key='',processing_by=NULL,started_at=0,error_code='' WHERE job_id=? AND status='failed'");$q->execute([$id]);
            if($q->rowCount()!==1)throw new RuntimeException('A failed import chunk could not be restarted safely.');
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $job=sf_song_lyrics_import_job($id);if(!$job)throw new InvalidArgumentException('Song lyric import not found.');
    return sf_song_lyrics_import_job_view($job);
}
function sf_song_lyrics_import_pause(int $adminId,int $id,bool $pause): array {
    sf_song_lyrics_import_admin($adminId);sf_song_lyrics_import_schema();$db=sf_db();$now=gmdate('c');
    if($pause)$db->prepare("UPDATE song_lyrics_import_jobs SET pause_requested=1,status=CASE WHEN status='queued' THEN 'paused' ELSE status END,updated_at=? WHERE id=? AND status IN ('queued','processing')")->execute([$now,$id]);
    else $db->prepare("UPDATE song_lyrics_import_jobs SET pause_requested=0,status='queued',updated_at=? WHERE id=? AND status='paused'")->execute([$now,$id]);
    $job=sf_song_lyrics_import_job($id);if(!$job)throw new InvalidArgumentException('Song lyric import not found.');
    return sf_song_lyrics_import_job_view($job);
}
function sf_song_lyrics_import_set_state(int $id,string $status,string $error=''): void {
    sf_db()->prepare('UPDATE song_lyrics_import_jobs SET status=?,error_code=?,updated_at=? WHERE id=?')->execute([$status,$error,gmdate('c'),$id]);
}
/** Persist one cached chunk into the established timeline; helper receipts preserve editorial corrections. */
function sf_song_lyrics_import_finish_cached(array $user,array $job,array $chunk): array {
    $id=(int)$job['id'];
    try{
        $source=sf_song_lyrics_import_source((string)$job['track_id'],sf_song_lyrics_import_catalog(),$user,(string)$job['source_ref']);
        if(!hash_equals((string)$job['source_fingerprint'],$source['fingerprint']))sf_song_catalog_error('song_source_changed',409,'The song source changed.');
        $text=(string)$chunk['transcript_text'];
        // An entirely duplicated overlap still has a durable cached receipt but adds no misleading empty segment.
        if($text!=='')sf_song_moments_ingest_lyrics((string)$job['track_id'],(string)$job['source_fingerprint'],[[
          'source_key'=>'job:'.$id.':chunk:'.(int)$chunk['chunk_index'],
          'start_ms'=>(int)$chunk['actual_start_ms'],'end_ms'=>(int)$chunk['actual_end_ms'],
          'text'=>$text,'timing_source'=>'catalog_chunk_estimate','confidence'=>null]],(int)$job['duration_ms']);
        $db=sf_db();$db->beginTransaction();
        $q=$db->prepare("UPDATE song_lyrics_import_chunks SET status='completed',error_code='' WHERE job_id=? AND chunk_index=? AND status='cached'");$q->execute([$id,(int)$chunk['chunk_index']]);
        if($q->rowCount()!==1){$db->rollBack();return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];}
        $q=$db->prepare("SELECT COUNT(*) FROM song_lyrics_import_chunks WHERE job_id=? AND status<>'completed'");$q->execute([$id]);$remaining=(int)$q->fetchColumn();$q->closeCursor();
        $next=$remaining===0?"'ready'":"CASE WHEN pause_requested=1 THEN 'paused' ELSE 'queued' END";
        $db->prepare("UPDATE song_lyrics_import_jobs SET status=$next,error_code='',updated_at=? WHERE id=? AND status='processing'")->execute([gmdate('c'),$id]);
        $db->commit();
    }catch(Throwable $e){
        if(sf_db()->inTransaction())sf_db()->rollBack();
        $stale=$e instanceof SFSongCatalogError;
        $next=$stale?"'stale'":"CASE WHEN pause_requested=1 THEN 'paused' ELSE 'queued' END";
        $q=sf_db()->prepare("UPDATE song_lyrics_import_jobs SET status=$next,error_code=?,updated_at=? WHERE id=? AND status='processing' AND EXISTS (SELECT 1 FROM song_lyrics_import_chunks c WHERE c.job_id=song_lyrics_import_jobs.id AND c.chunk_index=? AND c.status='cached')");
        $q->execute([$stale?'song_source_changed':'local_timeline_import_pending',gmdate('c'),$id,(int)$chunk['chunk_index']]);
        return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];
    }
    return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>true];
}
/** At most one bounded provider operation. Lost/uncertain calls are never automatically retried. */
function sf_song_lyrics_import_advance(int $adminId,int $id,?callable $transport=null): array {
    $user=sf_song_lyrics_import_admin($adminId);sf_song_lyrics_import_schema();sf_song_lyrics_import_recover();$db=sf_db();
    $job=sf_song_lyrics_import_job($id);if(!$job)throw new InvalidArgumentException('Song lyric import not found.');
    if($job['status']!=='queued')return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view($job),'advanced'=>false];
    $q=$db->prepare("SELECT * FROM song_lyrics_import_chunks WHERE job_id=? AND status<>'completed' ORDER BY chunk_index LIMIT 1");$q->execute([$id]);$chunk=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    if(!$chunk){sf_song_lyrics_import_set_state($id,'ready');return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];}
    if($chunk['status']==='cached'){
        $claim=$db->prepare("UPDATE song_lyrics_import_jobs SET status='processing',updated_at=? WHERE id=? AND status='queued' AND pause_requested=0 AND EXISTS (SELECT 1 FROM song_lyrics_import_chunks c WHERE c.job_id=song_lyrics_import_jobs.id AND c.chunk_index=? AND c.status='cached')");
        $claim->execute([gmdate('c'),$id,(int)$chunk['chunk_index']]);
        return $claim->rowCount()===1?sf_song_lyrics_import_finish_cached($user,$job,$chunk):['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];
    }
    if($chunk['status']!=='queued')return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view($job),'advanced'=>false];
    if(!sf_song_catalog_provider_ready()||sf_ai_provider_secret('openai')==='')throw new RuntimeException('Enable OpenAI in AI Providers before starting the queued lyric import.');
    $operation=bin2hex(random_bytes(16));$now=time();
    $db->beginTransaction();
    try{
        // Serialize provider budgets per acting admin. SQLite acquires its writer before any read.
        if((sf_db_config()['driver']??'')==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$adminId]);
        else{$lock=$db->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$adminId]);$lock->fetchColumn();$lock->closeCursor();}
        $q=$db->prepare("SELECT COUNT(*) FROM song_lyrics_import_chunks WHERE processing_by=? AND status='processing'");$q->execute([$adminId]);$busy=(int)$q->fetchColumn();$q->closeCursor();
        if($busy>0){$db->commit();return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];}
        $q=$db->prepare('SELECT COALESCE(SUM(end_ms-start_ms),0) FROM song_lyrics_import_chunks WHERE processing_by=? AND started_at>=?');$q->execute([$adminId,$now-86400]);$used=(int)$q->fetchColumn();$q->closeCursor();
        if($used+(int)$chunk['end_ms']-(int)$chunk['start_ms']>3600000){sf_song_lyrics_import_set_state($id,'paused','daily_import_budget');$db->commit();return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];}
        $q=$db->prepare("UPDATE song_lyrics_import_jobs SET status='processing',error_code='',updated_at=? WHERE id=? AND status='queued' AND pause_requested=0");$q->execute([gmdate('c'),$id]);
        if($q->rowCount()!==1){$db->commit();return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];}
        $q=$db->prepare("UPDATE song_lyrics_import_chunks SET status='processing',operation_key=?,processing_by=?,started_at=? WHERE job_id=? AND chunk_index=? AND status='queued'");$q->execute([$operation,$adminId,$now,$id,(int)$chunk['chunk_index']]);
        if($q->rowCount()!==1)throw new RuntimeException('Chunk state changed before provider claim.');
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    $clip=null;$providerStarted=false;$reservation=null;$settled=false;$tokenSource='song-import:'.$id.':'.(int)$chunk['chunk_index'];
    try{
        $source=sf_song_lyrics_import_source((string)$job['track_id'],sf_song_lyrics_import_catalog(),$user,(string)$job['source_ref']);
        if(!hash_equals((string)$job['source_fingerprint'],$source['fingerprint']))sf_song_catalog_error('song_source_changed',409,'The song source changed.');
        $clip=sf_song_audio_extract($source['path'],$source['extension'],(int)$chunk['start_ms'],(int)$chunk['end_ms']);
        sf_song_catalog_check_excerpt($clip,$chunk);
        $amount=max(12,(int)ceil((int)($clip['audio_duration_ms']??$clip['duration_ms'])/1000)*4);
        $allowed=sf_can_use_ai($user,$amount);if(empty($allowed['ok']))throw new RuntimeException('Active Tokens are required.');
        $reservation=sf_ai_reserve_tokens($user,$amount,$tokenSource);
        // Metering schema is prepared before the completion transaction; MySQL DDL must never commit that transaction.
        sf_entitlements_ensure_schema();
        $fresh=sf_song_lyrics_import_source((string)$job['track_id'],sf_song_lyrics_import_catalog(),$user,(string)$job['source_ref']);
        if(!hash_equals((string)$job['source_fingerprint'],$fresh['fingerprint']))sf_song_catalog_error('song_source_changed',409,'The song source changed.');
        sf_song_lyrics_import_admin($adminId);
        if(!sf_song_catalog_provider_ready())throw new RuntimeException('Song processing authorization changed.');
        $key=sf_ai_provider_secret('openai');if($key==='')throw new RuntimeException('Transcription credential is unavailable.');
        $provider=['provider'=>'openai','model'=>SF_SONG_CATALOG_MODEL,'api_key'=>$key];
        $providerStarted=true;$response=($transport??'sf_song_catalog_transport')($provider,$clip);
        if(!is_array($response)||!is_string($response['text']??null))throw new RuntimeException('Invalid transcription response.');
        $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$response['text']);
        if($clean===null)throw new RuntimeException('Transcription text is not valid UTF-8.');
        // Empty, valid provider text may be an instrumental passage. Meter it once and continue.
        $text=trim(substr($clean,0,4200));while($text!==''&&!preg_match('//u',$text))$text=substr($text,0,-1);
        $prior=$db->prepare("SELECT raw_text FROM song_lyrics_import_chunks WHERE job_id=? AND chunk_index=? AND status='completed'");$prior->execute([$id,(int)$chunk['chunk_index']-1]);$previous=(string)($prior->fetchColumn()?:'');$prior->closeCursor();
        $deduped=sf_song_lyrics_import_dedupe($previous,$text);
        $usage=['provider'=>'openai','model'=>SF_SONG_CATALOG_MODEL];
        foreach(['input_tokens','output_tokens'] as $field){$value=$response['usage'][$field]??0;$usage[$field]=is_int($value)&&$value>=0&&$value<=1000000?$value:0;}
        $db->beginTransaction();
        $q=$db->prepare("UPDATE song_lyrics_import_chunks SET status='cached',raw_text=?,transcript_text=?,actual_start_ms=?,actual_end_ms=?,usage_json=?,token_amount=? WHERE job_id=? AND chunk_index=? AND status='processing' AND operation_key=?");
        $q->execute([$text,$deduped,$clip['start_ms'],$clip['end_ms'],json_encode($usage,JSON_THROW_ON_ERROR),$amount,$id,(int)$chunk['chunk_index'],$operation]);
        if($q->rowCount()!==1)throw new RuntimeException('The provider operation is no longer current.');
        sf_ai_finish_reservation($user,$reservation,$amount,$usage,$tokenSource,true);
        $db->prepare("UPDATE song_lyrics_import_jobs SET updated_at=? WHERE id=? AND status='processing'")->execute([gmdate('c'),$id]);
        $db->commit();$settled=true;
        $chunk=array_replace($chunk,['status'=>'cached','raw_text'=>$text,'transcript_text'=>$deduped,
          'actual_start_ms'=>$clip['start_ms'],'actual_end_ms'=>$clip['end_ms']]);
        return sf_song_lyrics_import_finish_cached($user,$job,$chunk);
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        if(!$settled)try{sf_ai_finish_reservation($user,$reservation,0,[],$tokenSource,false);}catch(Throwable $ignored){}
        $stale=!$providerStarted&&$e instanceof SFSongCatalogError&&in_array($e->error,['song_source_changed','song_unavailable','song_source_unavailable'],true);
        $state=$providerStarted?'unknown':($stale?'stale':'failed');$error=$providerStarted?'provider_outcome_unknown':($stale?'song_source_changed':'source_or_provider_unavailable');
        $q=$db->prepare('UPDATE song_lyrics_import_chunks SET status=?,error_code=? WHERE job_id=? AND chunk_index=? AND status=\'processing\' AND operation_key=?');
        $q->execute([$state,$error,$id,(int)$chunk['chunk_index'],$operation]);
        if($q->rowCount()===1)sf_song_lyrics_import_set_state($id,$state,$error);
        return ['ok'=>true,'job'=>sf_song_lyrics_import_job_view(sf_song_lyrics_import_job($id)),'advanced'=>false];
    }finally{
        if(is_array($clip)&&is_string($clip['path']??null)){
            $root=realpath(sys_get_temp_dir());$path=realpath($clip['path']);
            if($root&&$path&&dirname($path)===$root&&str_starts_with(basename($path),'sf-lyric-')&&!is_link($clip['path']))@unlink($path);
        }
    }
}
