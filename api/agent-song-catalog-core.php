<?php
declare(strict_types=1);
/** 91B21B4 — temporary, authorized catalog sections -> OpenAI lyric drafts. */
require_once __DIR__.'/agent-song-transcribe-core.php';
require_once __DIR__.'/ai-token-reservations.php';
require_once __DIR__.'/agent-song-audio-excerpt.php';

const SF_SONG_CATALOG_MODEL = 'gpt-transcribe';
const SF_SONG_CATALOG_SOURCE_LIMIT = 67108864; // 64 MiB; the extractor uses the same bound.
const SF_SONG_CATALOG_CLIP_LIMIT = 20971520; // 20 MiB, below the provider's 25 MB limit.

final class SFSongCatalogError extends RuntimeException {
    public function __construct(public readonly string $error,public readonly int $httpStatus,string $message,?Throwable $previous=null){parent::__construct($message,0,$previous);}
}

function sf_song_catalog_error(string $error,int $status,string $message): never {
    throw new SFSongCatalogError($error,$status,$message);
}

function sf_song_catalog_track(string $id,array $published): array {
    if($id===''||strlen($id)>180||preg_match('/[\x00-\x1F\x7F]/',$id))
        sf_song_catalog_error('invalid_song_section',422,'Choose a published playable song.');
    $matches=array_values(array_filter($published,static fn($track)=>is_array($track)
        &&(string)($track['id']??'')===$id&&is_string($track['audio']??null)&&$track['audio']!==''));
    if(count($matches)!==1)sf_song_catalog_error('song_unavailable',404,'This published song is no longer available.');
    return $matches[0];
}

/** The public catalog reference, never a client URL or manufacturing original. */
function sf_song_catalog_source(string $trackId,array $published,array $user,bool $verifyHash=false,?string $expectedReference=null): array {
    $track=sf_song_catalog_track($trackId,$published);
    $reference=$track['audio'];
    if($expectedReference!==null&&$reference!==$expectedReference)
        sf_song_catalog_error('song_source_changed',409,'The song was republished. Reload the player before selecting lyrics.');
    if(!preg_match('/\Aapi\/media\.php\?id=(M[A-F0-9]{20})(?:&variant=original)?\z/',$reference,$match))
        sf_song_catalog_error('song_source_unavailable',422,'This song needs a published MP3 or WAV from the Media Library for lyric transcription.');
    if(!empty($track['private_master_audio'])&&$reference===$track['private_master_audio'])
        sf_song_catalog_error('song_source_unavailable',404,'The published listening copy is unavailable.');
    $asset=sf_media_asset($match[1]);
    if(!$asset||($asset['uuid']??'')!==$match[1]||($asset['category']??'')!=='audio'
        ||sf_media_protected_master_asset((int)($asset['id']??0)))
        sf_song_catalog_error('song_source_unavailable',404,'The published listening copy is unavailable.');
    // Even administrators use the listener-visible preview for this operation.
    $q=sf_db()->prepare('SELECT entity_type,entity_id FROM media_links WHERE asset_id=? AND public_visible=1');
    $q->execute([(int)$asset['id']]);$allowed=false;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $link){
        if(($link['entity_type']??'')==='member_content'){
            $content=function_exists('sf_membership_content_get')?sf_membership_content_get((int)$link['entity_id']):null;
            $access=$content&&function_exists('sf_membership_content_access')
                ?sf_membership_content_access((int)$user['id'],$content):['allowed'=>false];
            if(!empty($access['allowed']))$allowed=true;
        }else $allowed=true;
    }
    if(!$allowed)sf_song_catalog_error('song_source_unavailable',404,'The published listening copy is unavailable.');
    $extension=strtolower((string)($asset['extension']??''));
    if(!in_array($extension,['mp3','wav'],true))
        sf_song_catalog_error('song_format_unavailable',422,'Lyric transcription supports published MP3 and PCM WAV listening copies.');
    $relative=(string)($asset['stored_path']??'');
    if($relative===''||str_contains($relative,'..')||str_contains($relative,'\\')||str_contains($relative,"\0")
        ||!str_starts_with($relative,'storage/media/'))
        sf_song_catalog_error('song_source_unavailable',404,'The published listening copy is unavailable.');
    $root=realpath(SF_ROOT.'/storage/media');$rawPath=SF_ROOT.'/'.$relative;$path=realpath($rawPath);
    if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path)||is_link($rawPath)||!is_readable($path))
        sf_song_catalog_error('song_source_unavailable',404,'The published listening copy is unavailable.');
    clearstatcache(true,$path);$bytes=filesize($path);$sha=(string)($asset['sha256']??'');
    if($bytes===false||$bytes<100||$bytes>SF_SONG_CATALOG_SOURCE_LIMIT||$bytes!==(int)($asset['size_bytes']??0)
        ||!preg_match('/\A[a-f0-9]{64}\z/',$sha))
        sf_song_catalog_error('song_source_unavailable',422,'The published listening copy needs to be verified in the Media Library.');
    if($verifyHash){
        $actual=hash_file('sha256',$path);
        if(!is_string($actual)||!hash_equals($sha,$actual))
            sf_song_catalog_error('song_source_changed',409,'The song file changed. Reload the player before starting another lyric section.');
    }
    if(!sf_song_audio_available($path,$extension))
        sf_song_catalog_error('song_format_unavailable',422,'This listening copy cannot be sliced safely. Publish a supported MP3 or PCM WAV.');
    return ['path'=>$path,'extension'=>$extension,'bytes'=>$bytes,'sha256'=>$sha,'asset_id'=>(int)$asset['id'],
        'uuid'=>$match[1],'reference'=>$reference,'track'=>$track,
        'fingerprint'=>hash('sha256',json_encode([$match[1],$reference,$sha],JSON_THROW_ON_ERROR))];
}

function sf_song_catalog_provider_ready(): bool {
    $row=sf_ai_provider_row('openai');
    return !empty($row['enabled'])&&!empty($row['secret_payload'])&&empty($row['endpoint_url'])
        &&function_exists('curl_init')&&class_exists('CURLFile');
}

/** Read-only readiness: no decrypted credential, extraction, escrow or provider call. */
function sf_song_catalog_readiness(string $trackId,array $published,array $user,string $sourceReference): array {
    $result=['ok'=>true,'available'=>false,'provider'=>'openai','model'=>SF_SONG_CATALOG_MODEL,'max_seconds'=>90];
    if(!sf_song_catalog_provider_ready())return $result+['message'=>'Enable OpenAI with an API key in Admin → AI Providers to convert song audio to lyrics.'];
    try{$source=sf_song_catalog_source($trackId,$published,$user,false,$sourceReference);}
    catch(SFSongCatalogError $e){return $result+['message'=>$e->getMessage(),'error'=>$e->error];}
    return array_replace($result,['available'=>true,'source_fingerprint'=>$source['fingerprint'],
        'message'=>'Select a song section, then Stop to convert its temporary audio to text with OpenAI.']);
}

function sf_song_catalog_request(array $raw,array $published,?int $now=null): array {
    $now??=time();
    if(array_diff(array_keys($raw),['track_id','start_ms','end_ms','captured_at','request_id','source_ref','source_fingerprint'])
        ||!is_string($raw['track_id']??null)||!is_string($raw['captured_at']??null)
        ||!is_string($raw['request_id']??null)||!preg_match('/\A[a-f0-9]{32}\z/',$raw['request_id'])
        ||!is_string($raw['source_ref']??null)||strlen($raw['source_ref'])>512
        ||!is_string($raw['source_fingerprint']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$raw['source_fingerprint']))
        sf_song_catalog_error('invalid_song_section',422,'The lyric section request is invalid. Start a new listening session.');
    foreach(['start_ms','end_ms'] as $field){
        if(!is_int($raw[$field]??null)&&!(is_string($raw[$field]??null)&&preg_match('/\A[0-9]{1,8}\z/',$raw[$field])))
            sf_song_catalog_error('invalid_song_section',422,'The lyric section needs valid playback positions.');
    }
    if(!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/',$raw['captured_at'])
        ||($at=strtotime($raw['captured_at']))===false||$at<$now-900||$at>$now+30)
        sf_song_catalog_error('song_section_expired',422,'This lyric selection expired. Start a new listening session.');
    try{$meta=sf_song_transcribe_request($raw,$published);}
    catch(InvalidArgumentException $e){sf_song_catalog_error('invalid_song_section',422,'Select a continuous song section between 0.5 and 90 seconds.');}
    $meta['request_id']=$raw['request_id'];
    $meta['source_ref']=$raw['source_ref'];$meta['source_fingerprint']=$raw['source_fingerprint'];
    return $meta;
}

function sf_song_catalog_request_schema(): void {
    $pdo=sf_db();
    if((sf_db_config()['driver']??'')==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS song_lyric_requests(
            user_id INTEGER NOT NULL,request_id TEXT NOT NULL,payload_hash TEXT NOT NULL,
            status TEXT NOT NULL,reservation_key TEXT NULL,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,
            PRIMARY KEY(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lyric_request_user_time ON song_lyric_requests(user_id,created_at)');
    }else $pdo->exec("CREATE TABLE IF NOT EXISTS song_lyric_requests(
        user_id BIGINT UNSIGNED NOT NULL,request_id CHAR(32) NOT NULL,payload_hash CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL,reservation_key CHAR(32) NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
        PRIMARY KEY(user_id,request_id),INDEX idx_lyric_request_user_time(user_id,created_at),
        CONSTRAINT fk_song_lyric_request_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Metadata-only claim. Never replay a provider request, even after a lost response. */
function sf_song_catalog_claim(array $user,array $meta,array $source,?int $now=null): void {
    $now??=time();sf_song_catalog_request_schema();$pdo=sf_db();$userId=(int)$user['id'];
    $hash=hash('sha256',json_encode([$meta['track_id'],$meta['start_ms'],$meta['end_ms'],$meta['captured_at'],
        $source['uuid'],$source['sha256']],JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try{
        if((sf_db_config()['driver']??'')==='sqlite'){
            // A write before any read acquires SQLite's writer lock.
            $q=$pdo->prepare('UPDATE users SET id=id WHERE id=?');$q->execute([$userId]);
            $q=$pdo->prepare('SELECT id,status,role FROM users WHERE id=?');
        }else $q=$pdo->prepare('SELECT id,status,role FROM users WHERE id=? FOR UPDATE');
        $q->execute([$userId]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if(!$current||($current['status']??'')!=='active'||($current['role']??'')!==($user['role']??''))
            sf_song_catalog_error('authentication_required',401,'Your account session changed. Sign in again to transcribe lyrics.');
        // Requests expire after 15 minutes, so removing >24h claim metadata cannot enable a valid replay.
        $q=$pdo->prepare("DELETE FROM song_lyric_requests WHERE user_id=? AND created_at<? AND status IN ('completed','failed')");$q->execute([$userId,$now-86400]);
        $q=$pdo->prepare('SELECT payload_hash,status FROM song_lyric_requests WHERE user_id=? AND request_id=?');
        $q->execute([$userId,$meta['request_id']]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            $same=hash_equals((string)$prior['payload_hash'],$hash);
            sf_song_catalog_error($same?'lyric_request_duplicate':'lyric_request_conflict',409,$same
                ?'This lyric request was already submitted. It will not be sent or charged again. Start a new selection if its result was lost.'
                :'This request identity belongs to a different lyric section. Start a new listening session.');
        }
        $q=$pdo->prepare("SELECT COUNT(*) FROM song_lyric_requests WHERE user_id=? AND status IN ('claimed','processing') AND created_at>=?");
        $q->execute([$userId,$now-120]);
        if((int)$q->fetchColumn()>0)sf_song_catalog_error('lyric_request_busy',429,'A lyric request is still finishing. Wait before starting another.');
        $q=$pdo->prepare('SELECT COUNT(*) FROM song_lyric_requests WHERE user_id=? AND created_at>=?');
        $q->execute([$userId,$now-300]);
        if((int)$q->fetchColumn()>=8)sf_song_catalog_error('lyric_rate_limited',429,'Several lyric sections were submitted recently. Wait a few minutes before trying again.');
        $q->execute([$userId,$now-3600]);
        if((int)$q->fetchColumn()>=40)sf_song_catalog_error('lyric_rate_limited',429,'The hourly lyric transcription limit was reached. Try again later.');
        $q=$pdo->prepare("INSERT INTO song_lyric_requests(user_id,request_id,payload_hash,status,created_at,updated_at) VALUES(?,?,?,'claimed',?,?)");
        $q->execute([$userId,$meta['request_id'],$hash,$now,$now]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function sf_song_catalog_request_state(array $user,string $id,string $status,?string $reservation=null): void {
    if(!in_array($status,['processing','completed','failed','unknown'],true))throw new LogicException('Invalid lyric request state.');
    $q=sf_db()->prepare('UPDATE song_lyric_requests SET status=?,reservation_key=?,updated_at=? WHERE user_id=? AND request_id=?');
    $q->execute([$status,$reservation,time(),(int)$user['id'],$id]);
}

/** Fixed HTTPS destination, bounded response, no redirects and no automatic retries. */
function sf_song_catalog_transport(array $provider,array $clip): array {
    $body=['file'=>new CURLFile($clip['path'],$clip['mime'],'song-section.'.$clip['extension']),
        'model'=>SF_SONG_CATALOG_MODEL,'response_format'=>'json'];
    $handle=curl_init('https://api.openai.com/v1/audio/transcriptions');$response='';
    if($handle===false)throw new RuntimeException('Transcription transport unavailable.');
    try{
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$provider['api_key']],
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($curl,string $bytes)use(&$response):int{
                if(strlen($response)+strlen($bytes)>150000)return 0;
                $response.=$bytes;return strlen($bytes);
            }]);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
    }finally{curl_close($handle);}
    if($ok===false||$status<200||$status>=300)throw new RuntimeException('Transcription provider did not complete.');
    $parsed=json_decode($response,true);
    if(!is_array($parsed)||!is_string($parsed['text']??null))throw new RuntimeException('Transcription response was invalid.');
    return $parsed;
}

function sf_song_catalog_check_excerpt(array $clip,array $meta): void {
    foreach(['path','mime','extension'] as $field)if(!is_string($clip[$field]??null))throw new RuntimeException('Invalid audio excerpt.');
    foreach(['start_ms','end_ms','duration_ms'] as $field)if(!is_int($clip[$field]??null))throw new RuntimeException('Invalid excerpt timing.');
    $lead=$clip['silent_lead_ms']??0;$audioDuration=$clip['audio_duration_ms']??$clip['duration_ms'];
    if(!is_int($lead)||!is_int($audioDuration)||$lead<0||$lead>100
        ||abs($audioDuration-($clip['duration_ms']+$lead))>1||$audioDuration>90100
        ||$clip['start_ms']<$meta['start_ms']||$clip['start_ms']>$meta['start_ms']+100
        ||$clip['end_ms']>$meta['end_ms']||$clip['end_ms']<$meta['end_ms']-100
        ||$clip['duration_ms']!==$clip['end_ms']-$clip['start_ms']||$clip['duration_ms']>90000)
        throw new RuntimeException('Excerpt is outside the selected section.');
    if($clip['duration_ms']<500)sf_song_catalog_error('invalid_song_section',422,'Select at least one second of the song before pressing Stop.');
    $tmpRoot=realpath(sys_get_temp_dir());$path=realpath($clip['path']);
    if(!$tmpRoot||!$path||dirname($path)!==$tmpRoot||!str_starts_with(basename($path),'sf-lyric-')||!is_file($path)||is_link($clip['path'])
        ||!in_array($clip['extension'],['mp3','wav'],true)
        ||$clip['mime']!==($clip['extension']==='mp3'?'audio/mpeg':'audio/wav'))throw new RuntimeException('Invalid private excerpt.');
    $size=filesize($path);
    if($size===false||$size<100||$size>SF_SONG_CATALOG_CLIP_LIMIT)throw new RuntimeException('Excerpt exceeds the permitted upload size.');
}

/** No JSON exit here: cleanup must finish before the endpoint writes its response. */
function sf_song_catalog_transcribe(array $user,array $raw,array $published,?callable $extract=null,?callable $transport=null): array {
    $meta=sf_song_catalog_request($raw,$published);
    if(!sf_song_catalog_provider_ready())sf_song_catalog_error('transcription_provider_unavailable',503,
        'OpenAI audio transcription is unavailable. Check the enabled OpenAI API key in Admin → AI Providers.');
    $source=sf_song_catalog_source($meta['track_id'],$published,$user,true,$meta['source_ref']);
    if(!hash_equals($source['fingerprint'],$meta['source_fingerprint']))
        sf_song_catalog_error('song_source_changed',409,'The song changed after lyric listening began. Reload the player and select a new section.');
    sf_song_catalog_claim($user,$meta,$source);
    $clip=null;$reservation=null;$reserved=false;$settled=false;$providerStarted=false;
    $tokenSource='song-catalog:'.$meta['request_id'];
    try{
        $extract??='sf_song_audio_extract';$transport??='sf_song_catalog_transport';
        $clip=$extract($source['path'],$source['extension'],$meta['start_ms'],$meta['end_ms']);
        sf_song_catalog_check_excerpt($clip,$meta);
        // Recheck authorization and immutable-file hash before any audio leaves this host.
        $fresh=sf_song_catalog_source($meta['track_id'],$published,$user,true,$meta['source_ref']);
        if($fresh['uuid']!==$source['uuid']||$fresh['reference']!==$source['reference']||!hash_equals($fresh['sha256'],$source['sha256']))
            sf_song_catalog_error('song_source_changed',409,'The song changed while selecting this section. Reload the player.');
        $duration=(int)($clip['audio_duration_ms']??$clip['duration_ms']);
        $amount=max(12,(int)ceil($duration/1000)*4);
        // sf_can_use_ai performs the existing enrollment policy; escrow makes concurrent spending atomic.
        $allow=sf_can_use_ai($user,$amount);
        if(empty($allow['ok']))sf_song_catalog_error('active_tokens_required',402,'More Active Tokens are required to transcribe this song section.');
        try{$reservation=sf_ai_reserve_tokens($user,$amount,$tokenSource);$reserved=true;}
        catch(RuntimeException $e){if($e->getMessage()==='ACTIVE_TOKENS_REQUIRED')sf_song_catalog_error('active_tokens_required',402,'More Active Tokens are required to transcribe this song section.');throw $e;}
        sf_song_catalog_request_state($user,$meta['request_id'],'processing',$reservation);
        // The caller authenticated and released its session before entering this function.
        // Credentials and provider details never appear in a readiness response or public error.
        if(!sf_song_catalog_provider_ready())throw new RuntimeException('Transcription provider was disabled.');
        $key=sf_ai_provider_secret('openai');
        if($key==='')throw new RuntimeException('Transcription credential unavailable.');
        $provider=['provider'=>'openai','model'=>SF_SONG_CATALOG_MODEL,'api_key'=>$key];
        $providerStarted=true;$response=$transport($provider,$clip);
        if(!is_array($response)||!is_string($response['text']??null))throw new RuntimeException('Invalid transcription response.');
        $actualMeta=array_replace($meta,['start_ms'=>$clip['start_ms'],'end_ms'=>$clip['end_ms'],'duration_ms'=>$clip['duration_ms']]);
        $draft=sf_song_transcribe_draft($actualMeta,$provider,$response);
        $draft['source']='catalog-audio';$draft['source_fingerprint']=$source['fingerprint'];
        $draft['silent_lead_ms']=(int)($clip['silent_lead_ms']??0);$draft['audio_duration_ms']=$duration;
        $usage=['provider'=>'openai','model'=>SF_SONG_CATALOG_MODEL];
        foreach(['input_tokens','output_tokens'] as $field){$value=$response['usage'][$field]??0;$usage[$field]=is_int($value)&&$value>=0&&$value<=1000000?$value:0;}
        $balance=sf_ai_finish_reservation($user,$reservation,$amount,$usage,$tokenSource,true);$settled=true;
        sf_song_catalog_request_state($user,$meta['request_id'],'completed',$reservation);
        return ['ok'=>true,'transcript'=>$draft,'active_tokens_remaining'=>($user['role']??'')==='admin'?null:$balance];
    }catch(Throwable $e){
        if($reserved&&!$settled){
            // Never refund an already-settled reservation if a later status write failed.
            $held=($user['role']??'')==='admin';
            if($reservation){$q=sf_db()->prepare('SELECT status FROM ai_token_reservations WHERE request_key=? AND user_id=?');$q->execute([$reservation,(int)$user['id']]);$held=$q->fetchColumn()==='held';$q->closeCursor();}
            if($held)sf_ai_finish_reservation($user,$reservation,0,[], $tokenSource,false);
        }
        try{sf_song_catalog_request_state($user,$meta['request_id'],$providerStarted?'unknown':'failed',$reservation);}catch(Throwable $ignored){}
        if($e instanceof SFSongCatalogError)throw $e;
        throw new SFSongCatalogError('transcription_failed',503,$providerStarted
            ?'The lyric request could not finish. It will not be retried automatically. Start a new selection to try again.'
            :'This song section could not be prepared for transcription. Try a longer section or check its published audio.',$e);
    }finally{
        if(is_array($clip)&&is_string($clip['path']??null)){
            $tmpRoot=realpath(sys_get_temp_dir());$path=realpath($clip['path']);
            if($tmpRoot&&$path&&dirname($path)===$tmpRoot&&str_starts_with(basename($path),'sf-lyric-')
                &&!is_link($clip['path'])&&is_file($path)&&$path!==$source['path'])@unlink($path);
        }
    }
}
