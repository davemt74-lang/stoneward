<?php
declare(strict_types=1);
/** Legacy transcription core regressions: bootstrap, PDO, media authorization,
 * duplicate claims and token escrow with injected provider responses. The old
 * HTTP route now resolves stored lyrics only. Disposable DBs only.
 */
$source=dirname(__DIR__);$worker=($argv[1]??'')==='worker';
$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true)||!extension_loaded('pdo_'.$driver))throw new RuntimeException('Required PDO driver unavailable.');
$tmp=$worker?(string)$argv[2]:sys_get_temp_dir().'/sf-catalog-lyrics-'.bin2hex(random_bytes(6));
if(!$worker){
    foreach(['config','storage/media','sessions','data'] as $dir)mkdir($tmp.'/'.$dir,0700,true);
    $cfg=$driver==='sqlite'?['driver'=>'sqlite','path'=>$tmp.'/test.sqlite']:
        ['driver'=>'mysql','host'=>'127.0.0.1','port'=>33306,'database'=>'stonefellow_lyrics_test','username'=>'root','password'=>(string)getenv('SF_TEST_MYSQL_PASSWORD')];
    if($driver==='mysql'&&$cfg['password']==='')throw new RuntimeException('Isolated MySQL password required.');
    file_put_contents($tmp.'/config/database.php','<?php return '.var_export($cfg,true).';');
    file_put_contents($tmp.'/config/store.php',"<?php return ['mode'=>'test'];");
}
define('SF_ROOT',$tmp);session_save_path($tmp.'/sessions');
require $source.'/api/bootstrap.php';require $source.'/api/agent-runtime.php';require $source.'/api/agent-song-catalog-core.php';
$db=sf_db();if($driver==='sqlite')$db->exec('PRAGMA busy_timeout=15000');
if($worker){
    ini_set('log_errors','1');ini_set('error_log',$tmp.'/worker-'.$argv[3].'.error.log');
    file_put_contents($tmp.'/ready-'.$argv[3],'ready');$until=microtime(true)+15;
    while(!is_file($tmp.'/go')&&microtime(true)<$until)usleep(10000);
    if(!is_file($tmp.'/go'))throw new RuntimeException('Concurrent worker barrier timed out.');
    $payload=json_decode((string)file_get_contents($tmp.'/race.json'),true,512,JSON_THROW_ON_ERROR);
    try{
        $result=sf_song_catalog_transcribe(['id'=>4,'role'=>'user','status'=>'active'],$payload,sf_agent_public_catalog(),null,
            static function(array $provider,array $clip)use($tmp):array{
                file_put_contents($tmp.'/provider-calls','call\n',FILE_APPEND|LOCK_EX);usleep(200000);
                return ['text'=>'A synthetic lyric fixture.'];
            });
        echo json_encode(['status'=>200,'ok'=>$result['ok']]);
    }catch(SFSongCatalogError $e){
        $causes=[];for($cause=$e->getPrevious();$cause;$cause=$cause->getPrevious())$causes[]=['type'=>get_class($cause),'message'=>$cause->getMessage(),'file'=>$cause->getFile(),'line'=>$cause->getLine(),'trace'=>$cause->getTraceAsString()];
        echo json_encode(['status'=>$e->httpStatus,'error'=>$e->error,'causes'=>$causes]);
    }
    exit;
}
ob_start();$checks=0;$server=null;$serverPipes=[];
function check(bool $ok,string $why):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;echo "PASS $why\n";}
function rejected(callable $fn,string $error,string $why):SFSongCatalogError {
    try{$fn();}catch(SFSongCatalogError $e){check($e->error===$error,$why.' ('.$e->error.')');return $e;}
    throw new RuntimeException('Expected rejection: '.$why);
}
function sqlCount(string $table):int{return (int)sf_db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function user(int $id=1):array{return ['id'=>$id,'role'=>$id===2?'admin':'user','status'=>'active'];}
function req(array $changes=[]):array {global $catalog,$fingerprint;return array_replace([
    'track_id'=>'fixture-song','start_ms'=>1000,'end_ms'=>4000,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'request_id'=>bin2hex(random_bytes(16)),'source_ref'=>$catalog[0]['audio'],'source_fingerprint'=>$fingerprint],$changes);}
function clearClaims():void {sf_db()->exec('DELETE FROM song_lyric_requests');}
function fixtureDrop(PDO $db,string $driver):void {
    if($driver==='mysql'){
        if($db->query('SELECT DATABASE()')->fetchColumn()!=='stonefellow_lyrics_test')throw new RuntimeException('Refusing a non-fixture database.');
        $db->exec('SET FOREIGN_KEY_CHECKS=0');foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');$db->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}
function removeFixture(string $path):void {
    if(!is_dir($path))return;foreach(new DirectoryIterator($path) as $item){if($item->isDot())continue;$p=$item->getPathname();if($item->isDir()&&!$item->isLink())removeFixture($p);else unlink($p);}rmdir($path);
}
function httpRequest(string $base,string $path,string $method='GET',?string $body=null,?string $jar=null,?string $csrf=null):array {
    $curl=curl_init($base.$path);$headers=['Content-Type: application/json'];if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;
    $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15];
    if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;if($jar!==null){$opts[CURLOPT_COOKIEFILE]=$jar;$opts[CURLOPT_COOKIEJAR]=$jar;}
    curl_setopt_array($curl,$opts);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if(!is_string($raw))throw new RuntimeException('Local controller request failed.');
    return ['status'=>$status,'body'=>json_decode($raw,true),'raw'=>$raw];
}
try{
    fixtureDrop($db,$driver);
    $db->exec('CREATE TABLE users(id '.($driver==='sqlite'?'INTEGER':'BIGINT UNSIGNED').' PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(100),role VARCHAR(30),status VARCHAR(30),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40),password_hash VARCHAR(255))'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $db->exec('CREATE TABLE app_meta(meta_key VARCHAR(190) PRIMARY KEY,meta_value TEXT NOT NULL,updated_at VARCHAR(40) NOT NULL)'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $now=gmdate('c');$q=$db->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at,password_hash) VALUES(?,?,?,?,?,?,?,?)');
    for($id=1;$id<=5;$id++)$q->execute([$id,'fixture'.$id.'@example.invalid','Fixture '.$id,$id===2?'admin':'user','active',$now,$now,'unused']);
    sf_customer_lifecycle_ensure_schema();sf_billing_ensure_schema();sf_membership_ensure_schema();sf_media_ensure_schema();sf_auth_ensure_schema();
    sf_song_catalog_request_schema();sf_ai_reservation_schema();
    $package=(int)$db->query('SELECT id FROM subscription_packages ORDER BY id LIMIT 1')->fetchColumn();
    $db->prepare("UPDATE subscription_packages SET monthly_price_cents=100,monthly_tokens=0,membership_enabled=1,membership_rank=1,membership_benefits_json=? WHERE id=?")->execute(['{"member_content":true}',$package]);
    $q=$db->prepare('INSERT INTO user_subscriptions(user_id,package_id,status,current_period_start,current_period_end,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
    foreach([1,2,3,4,5] as $id){$q->execute([$id,$package,'active',$now,gmdate('c',time()+3600),$now,$now]);sf_token_adjust($id,1000,'admin_grant','fixture-seed-'.$id);}
    sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'sk-fixture-not-a-real-key','model'=>'fixture-chat-model']);
    // Four seconds of deterministic PCM samples in a 12-second file. No copyrighted audio.
    $samples=str_repeat(pack('v',900),8000*12);$wav='RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($samples)).$samples;
    $audio=$tmp.'/storage/media/fixture.wav';file_put_contents($audio,$wav);$uuid='M'.str_repeat('A',20);
    $q=$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,caption,technical_json,variants_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$uuid,'audio','audio/wav','wav','fixture.wav','storage/media/fixture.wav',hash_file('sha256',$audio),filesize($audio),'Fixture','','{}','{}',$now,$now]);
    $asset=(int)$db->lastInsertId();sf_media_attach($asset,'track','fixture-song','primary_audio',['public_visible'=>true]);
    $catalog=[['id'=>'fixture-song','title'=>'Synthetic fixture','audio'=>'api/media.php?id='.$uuid,'public_visible'=>true,'state'=>'published']];
    file_put_contents($tmp.'/data/catalog.json',json_encode([...$catalog,['id'=>'hidden-song','audio'=>$catalog[0]['audio'],'state'=>'private']]));
    file_put_contents($tmp.'/data/releases.json','[]');
    check(count(sf_agent_public_catalog())===1,'Canonical catalog omits private tracks');
    $fingerprint=sf_song_catalog_source('fixture-song',$catalog,user(),true,$catalog[0]['audio'])['fingerprint'];
    $beforeFiles=glob(sys_get_temp_dir().'/sf-lyric-*')?:[];$balance=sf_token_balance(1);$ledger=sqlCount('token_ledger');
    $ready=sf_song_catalog_readiness('fixture-song',$catalog,user(),$catalog[0]['audio']);
    check($ready['available']&&$ready['model']==='gpt-transcribe'&&preg_match('/^[a-f0-9]{64}$/',$ready['source_fingerprint'])===1,'Readiness returns supported model and immutable source identity');
    check(sf_token_balance(1)===$balance&&sqlCount('token_ledger')===$ledger&&sqlCount('song_lyric_requests')===0,'Readiness creates no claim or token charge');
    check((glob(sys_get_temp_dir().'/sf-lyric-*')?:[])===$beforeFiles,'Readiness creates no temporary audio');
    $encoded=json_encode($ready);check(!str_contains($encoded,$tmp)&&!str_contains($encoded,'sk-fixture')&&!str_contains($encoded,'secret_payload'),'Readiness exposes no file path or provider secret');
    $db->exec("UPDATE ai_provider_settings SET secret_payload='undecryptable-fixture'");
    check(sf_song_catalog_readiness('fixture-song',$catalog,user(),$catalog[0]['audio'])['available'],'Readiness never decrypts the provider secret');
    sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'sk-fixture-not-a-real-key']);
    foreach([['enabled'=>0],['endpoint_url'=>'https://untrusted.example.invalid/transcribe']] as $change){
        foreach($change as $key=>$value)$db->prepare('UPDATE ai_provider_settings SET '.$key.'=? WHERE provider=?')->execute([$value,'openai']);
        check(!sf_song_catalog_readiness('fixture-song',$catalog,user(),$catalog[0]['audio'])['available'],'Disabled or redirected provider is unavailable');
        $db->exec("UPDATE ai_provider_settings SET enabled=1,endpoint_url=''");
    }
    foreach(['https://example.invalid/song.mp3','../storage/private.wav','api/media.php?id='.$uuid.'&download=1','storage/media/fixture.wav'] as $ref){
        $bad=$catalog;$bad[0]['audio']=$ref;rejected(fn()=>sf_song_catalog_source('fixture-song',$bad,user()),'song_source_unavailable','Client paths and arbitrary network sources are denied');
    }
    rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user(),false,'api/media.php?id=M'.str_repeat('B',20)),'song_source_changed','Stale player source cannot silently select a republished file');
    $private=$catalog;$private[0]['private_master_audio']=$private[0]['audio'];
    rejected(fn()=>sf_song_catalog_source('fixture-song',$private,user(2)),'song_source_unavailable','Admin cannot transcribe a catalog manufacturing master');
    $db->exec("UPDATE media_links SET role='master_audio'");rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user(2)),'song_source_unavailable','Master asset linked publicly elsewhere remains protected');
    $db->exec("UPDATE media_links SET role='primary_audio',public_visible=0");rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user(2)),'song_source_unavailable','Admin has no private media bypass');
    $db->exec('UPDATE media_links SET public_visible=1');
    $member=sf_membership_content_save(['title'=>'Member fixture','status'=>'published','content_type'=>'audio','minimum_rank'=>1],2);
    $db->prepare("UPDATE media_links SET entity_type='member_content',entity_id=?")->execute([(string)$member['id']]);
    check(sf_song_catalog_source('fixture-song',$catalog,user())['uuid']===$uuid,'Current listener membership authorizes protected listening audio');
    $db->exec("UPDATE user_subscriptions SET status='canceled' WHERE user_id=1");
    rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user()),'song_source_unavailable','Expired membership cannot transcribe member audio');
    $db->exec("UPDATE user_subscriptions SET status='active' WHERE user_id=1");$db->exec("UPDATE media_links SET entity_type='track',entity_id='fixture-song'");
    $db->exec("UPDATE media_assets SET stored_path='../private.wav'");rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user()),'song_source_unavailable','Managed path traversal is denied');
    $db->exec("UPDATE media_assets SET stored_path='storage/media/fixture.wav'");
    $h=fopen($audio,'r+b');fseek($h,100);fwrite($h,"\x12\x34");fclose($h);
    rejected(fn()=>sf_song_catalog_source('fixture-song',$catalog,user(),true),'song_source_changed','Actual source hash is revalidated before submission');file_put_contents($audio,$wav);
    rejected(fn()=>sf_song_catalog_request(req(['source_fingerprint'=>[]]),$catalog),'invalid_song_section','Array fingerprint rejected without type coercion');
    foreach([['start_ms'=>-1],['start_ms'=>true],['end_ms'=>[]],['end_ms'=>1001],['end_ms'=>91001],['end_ms'=>86400001],['request_id'=>str_repeat('A',32)],['source_ref'=>[]],['track_id'=>[]],['extra'=>'ignored']] as $change)
        rejected(fn()=>sf_song_catalog_request(req($change),$catalog),'invalid_song_section','Malformed or out-of-range request is rejected');
    foreach([gmdate('c',time()-901),gmdate('c',time()+31),'yesterday'] as $at)
        rejected(fn()=>sf_song_catalog_request(req(['captured_at'=>$at]),$catalog),'song_section_expired','Expired or non-explicit capture time is rejected');
    $p=req(['source_fingerprint'=>str_repeat('0',64)]);
    rejected(fn()=>sf_song_catalog_transcribe(user(),$p,$catalog),'song_source_changed','Republished source fingerprint cannot be submitted');
    check(sqlCount('song_lyric_requests')===0,'Invalid requests never claim or reserve provider work');
    $calls=0;$clipPath='';$p=req();$cost=12;$starting=sf_token_balance(1);
    $result=sf_song_catalog_transcribe(user(),$p,$catalog,null,static function(array $provider,array $clip)use(&$calls,&$clipPath,$starting,$cost):array{
        $calls++;$clipPath=$clip['path'];check(is_file($clipPath)&&((fileperms($clipPath)&0777)===0600),'Only a private temporary excerpt reaches the provider');
        check(sf_token_balance(1)===$starting-$cost,'Tokens are held before provider execution');
        check($provider['model']==='gpt-transcribe'&&$provider['api_key']==='sk-fixture-not-a-real-key','Existing encrypted OpenAI key is resolved only for explicit submission');
        try{sf_ai_reserve_tokens(user(),$starting,'competing-spend');throw new RuntimeException('Competing reservation unexpectedly succeeded.');}
        catch(RuntimeException $e){check($e->getMessage()==='ACTIVE_TOKENS_REQUIRED','Concurrent spending cannot reuse escrowed tokens');}
        return ['text'=>"A synthetic lyric fixture.\x00",'usage'=>['input_tokens'=>17,'output_tokens'=>3]];
    });
    check($result['ok']&&$result['transcript']['source']==='catalog-audio'&&$result['transcript']['captured_at']===$p['captured_at'],'Successful transcript preserves source and capture identity');
    check($result['transcript']['start_ms']===1000&&$result['transcript']['end_ms']===4000&&$result['transcript']['source_fingerprint']===$fingerprint,'Transcript preserves the selected playback range and fingerprint');
    check($result['transcript']['review_status']==='unverified'&&$result['transcript']['text']==='A synthetic lyric fixture.','Returned text is an unverified machine draft with control characters removed');
    check(!is_file($clipPath)&&is_file($audio),'Successful transcription deletes only its temporary section');
    check(sf_token_balance(1)===$starting-$cost&&$result['active_tokens_remaining']===$starting-$cost,'Successful reservation settles exactly once');
    check($db->query('SELECT status FROM ai_token_reservations ORDER BY created_at LIMIT 1')->fetchColumn()==='settled','Reservation persists a terminal settled state');
    $again=static fn()=>sf_song_catalog_transcribe(user(),$p,$catalog,null,static function()use(&$calls){$calls++;return ['text'=>'Unexpected'];});
    rejected($again,'lyric_request_duplicate','Lost-response retry cannot cause a second provider charge');check($calls===1,'Duplicate request never reaches provider');
    rejected(fn()=>sf_song_catalog_transcribe(user(),array_replace($p,['end_ms'=>5000]),$catalog),'lyric_request_conflict','Reused identity for another interval is rejected');
    $dump=json_encode($db->query('SELECT * FROM song_lyric_requests')->fetchAll());
    check(!str_contains($dump,'synthetic')&&!str_contains($dump,$audio)&&!str_contains($dump,'sk-fixture'),'Idempotency metadata never stores transcript, audio path, or credentials');
    clearClaims();$before=sf_token_balance(1);$clipPath='';
    $error=rejected(fn()=>sf_song_catalog_transcribe(user(),req(),$catalog,null,static function(array $provider,array $clip)use(&$clipPath):array{
        $clipPath=$clip['path'];throw new RuntimeException('SECRET sk-fixture internal path '.$clipPath);
    }),'transcription_failed','Provider transport failure becomes a safe retry-free error');
    check(!str_contains($error->getMessage(),'SECRET')&&!str_contains($error->getMessage(),$tmp),'Public provider error is sanitized');
    check(sf_token_balance(1)===$before&&sqlCount('ai_token_reservations')===2,'Provider failure releases its reservation');
    check(!is_file($clipPath)&&(int)$db->query("SELECT COUNT(*) FROM ai_token_reservations WHERE status='held'")->fetchColumn()===0,'Provider failure removes temporary audio and leaves no held credit');
    clearClaims();$clipPath='';
    rejected(fn()=>sf_song_catalog_transcribe(user(),req(),$catalog,static function(string $path,string $ext,int $start,int $end)use(&$clipPath):array{
        $clip=sf_song_audio_extract($path,$ext,$start,$end);$clipPath=$clip['path'];$clip['start_ms']=0;return $clip;
    },static function()use(&$calls){$calls++;return ['text'=>'Unexpected'];}),'transcription_failed','An excerpt outside the selected interval fails closed');
    check(!is_file($clipPath)&&$calls===1,'Rejected excerpt is removed before any provider work');
    clearClaims();$clipPath='';
    rejected(fn()=>sf_song_catalog_transcribe(user(),req(),$catalog,static function(string $path,string $ext,int $start,int $end)use(&$clipPath):array{
        $clip=sf_song_audio_extract($path,$ext,$start,$end);$clipPath=$clip['path'];sf_db()->exec('UPDATE media_links SET public_visible=0');return $clip;
    },static fn()=>['text'=>'Unexpected']),'song_source_unavailable','Access revoked during extraction is rechecked before upload');
    check(!is_file($clipPath),'Access revocation deletes the unused temporary section');$db->exec('UPDATE media_links SET public_visible=1');
    clearClaims();sf_token_adjust(3,-1000,'fixture-debit','fixture-zero');
    rejected(fn()=>sf_song_catalog_transcribe(user(3),req(),$catalog,null,static fn()=>['text'=>'Unexpected']),'active_tokens_required','Insufficient credit fails before paid provider execution');
    clearClaims();$adminBalance=sf_token_balance(2);
    $admin=sf_song_catalog_transcribe(user(2),req(),$catalog,null,static fn()=>['text'=>'Admin synthetic fixture']);
    check($admin['active_tokens_remaining']===null&&sf_token_balance(2)===$adminBalance,'Unlimited admin use remains metered without debiting credit');
    check((int)$db->query("SELECT COUNT(*) FROM token_ledger WHERE user_id=2 AND entry_type='usage'")->fetchColumn()===1,'Admin provider activity is recorded once');
    clearClaims();$meta=sf_song_catalog_request(req(),$catalog);$sourceInfo=sf_song_catalog_source('fixture-song',$catalog,user());
    sf_song_catalog_claim(user(),$meta,$sourceInfo);
    rejected(fn()=>sf_song_catalog_claim(user(),sf_song_catalog_request(req(),$catalog),$sourceInfo),'lyric_request_busy','Second active section for the same user is bounded');
    clearClaims();for($i=0;$i<8;$i++){$m=sf_song_catalog_request(req(),$catalog);sf_song_catalog_claim(user(),$m,$sourceInfo);sf_song_catalog_request_state(user(),$m['request_id'],'completed');}
    rejected(fn()=>sf_song_catalog_claim(user(),sf_song_catalog_request(req(),$catalog),$sourceInfo),'lyric_rate_limited','Eight requests in five minutes is a server-enforced limit');
    clearClaims();$old=sf_song_catalog_request(req(),$catalog);sf_song_catalog_claim(user(),$old,$sourceInfo,time()-90000);$done=sf_song_catalog_request(req(),$catalog);sf_song_catalog_claim(user(),$done,$sourceInfo,time()-89800);sf_song_catalog_request_state(user(),$done['request_id'],'completed');
    sf_song_catalog_claim(user(),sf_song_catalog_request(req(),$catalog),$sourceInfo);
    check(sqlCount('song_lyric_requests')===2,'Pruning removes terminal history but preserves abandoned nonterminal escrow references');
    clearClaims();$db->exec("UPDATE users SET status='disabled' WHERE id=1");
    rejected(fn()=>sf_song_catalog_claim(user(),sf_song_catalog_request(req(),$catalog),$sourceInfo),'authentication_required','Disabled account is rechecked atomically with admission');$db->exec("UPDATE users SET status='active' WHERE id=1");
    $m=sf_song_catalog_request(req(),$catalog);sf_song_catalog_claim(user(5),$m,$sourceInfo);$db->exec('DELETE FROM users WHERE id=5');
    check(sqlCount('song_lyric_requests')===0,'Deleting an account cascades its lyric request metadata');
    // Two independent PHP workers compete for the same paid operation.
    file_put_contents($tmp.'/race.json',json_encode(req()));$jobs=[];
    foreach([1,2] as $n){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker',$tmp,(string)$n],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker launch failed.');fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
    $until=microtime(true)+15;while((!is_file($tmp.'/ready-1')||!is_file($tmp.'/ready-2'))&&microtime(true)<$until)usleep(10000);
    check(is_file($tmp.'/ready-1')&&is_file($tmp.'/ready-2'),'Independent PHP workers reached the duplicate-request barrier');touch($tmp.'/go');$statuses=[];$diagnostics=[];
    foreach($jobs as $i=>[$proc,$pipes]){
        $raw=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
        $errorLog=$tmp.'/worker-'.($i+1).'.error.log';$diagnostics[]=['worker'=>$i+1,'exit'=>$exit,'stdout'=>$raw,'stderr'=>$err,'error_log'=>is_file($errorLog)?file_get_contents($errorLog):''];
        if($exit!==0)continue;
        $decoded=json_decode($raw,true);$statuses[]=$decoded['status']??null;
    }
    sort($statuses);check($statuses===[200,409],'Concurrent duplicate submissions admit exactly one provider operation'.($statuses===[200,409]?'':': '.json_encode($diagnostics,JSON_UNESCAPED_SLASHES)));
    check(substr_count((string)file_get_contents($tmp.'/provider-calls'),'call')===1&&sf_token_balance(4)===988,'Concurrent duplicate submissions debit once across workers');
    clearClaims();
    // The retired HTTP URL still enforces auth/CSRF, but cannot trigger paid work.
    $router='<?php define("SF_ROOT",'.var_export($tmp,true).'); session_save_path(SF_ROOT."/sessions");'.
        '$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if($path==="/ping"){echo "ready";return;}'.
        'if($path==="/login"){require '.var_export($source.'/api/bootstrap.php',true).';sf_login_user(sf_user_by_email("fixture1@example.invalid"));sf_json_response(["csrf"=>sf_user_csrf()]);}'.
        'require '.var_export($source.'/api/agent-song-catalog-transcribe.php',true).';';
    file_put_contents($tmp.'/router.php',$router);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Cannot allocate local HTTP port.');$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-S',$address,$tmp.'/router.php'],[0=>['pipe','r'],1=>['file',$tmp.'/http.log','w'],2=>['file',$tmp.'/http.err','w']],$serverPipes,$source);
    if(!is_resource($server))throw new RuntimeException('Cannot launch local controller fixture.');fclose($serverPipes[0]);$base='http://'.$address;
    $until=microtime(true)+10;do{$c=curl_init($base.'/ping');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>1]);$alive=curl_exec($c);curl_close($c);if($alive==='ready')break;usleep(20000);}while(microtime(true)<$until);
    check($alive==='ready','Actual PHP controller fixture is reachable');
    $query='/transcribe?'.http_build_query(['track_id'=>'fixture-song','source_ref'=>$catalog[0]['audio']]);
    check(httpRequest($base,$query)['status']===401,'HTTP readiness requires an authenticated account');
    check(httpRequest($base,'/transcribe','PUT')['status']===405,'HTTP controller rejects unsupported methods');
    $jar=$tmp.'/cookies';$login=httpRequest($base,'/login','GET',null,$jar);$csrf=$login['body']['csrf']??'';check(strlen($csrf)===64,'Actual account login provides a CSRF token');
    $r=httpRequest($base,$query,'GET',null,$jar);check($r['status']===200&&($r['body']['available']??null)===false&&$r['body']['source_fingerprint']===$fingerprint,'Old URL checks the canonical source and cannot start a fresh transcript');
    check(httpRequest($base,'/transcribe','POST',json_encode(req()),$jar)['status']===403,'HTTP submission without CSRF is rejected');
    check(httpRequest($base,'/transcribe','POST',json_encode(req()),$jar,str_repeat('0',64))['status']===403,'HTTP submission with a forged CSRF token is rejected');
    check(httpRequest($base,'/transcribe','POST',str_repeat('x',8193),$jar,$csrf)['status']===413,'HTTP payload cap rejects oversized JSON');
    check(httpRequest($base,'/transcribe','POST','[]',$jar,$csrf)['status']===422,'HTTP JSON list cannot masquerade as a section object');
    check(httpRequest($base,'/transcribe?track_id[]=fixture-song&source_ref=x','GET',null,$jar)['status']===422,'HTTP query arrays cannot bypass strict identity parsing');
    $r=httpRequest($base,'/transcribe','POST',json_encode(req(['end_ms'=>1001])),$jar,$csrf);
    check($r['status']===422,'Old URL rejects invalid playback ranges before lookup');
    $db->exec("UPDATE ai_provider_settings SET secret_payload='undecryptable-fixture'");$before=sf_token_balance(1);$files=glob(sys_get_temp_dir().'/sf-lyric-*')?:[];$reservations=sqlCount('ai_token_reservations');
    $r=httpRequest($base,'/transcribe','POST',json_encode(req()),$jar,$csrf);
    check($r['status']===404&&($r['body']['error']??'')==='saved_lyrics_unavailable','Cached clients get missing stored lyrics rather than a new transcription');
    check(sf_token_balance(1)===$before&&sqlCount('ai_token_reservations')===$reservations&&sqlCount('song_lyric_requests')===0&&(glob(sys_get_temp_dir().'/sf-lyric-*')?:[])===$files,'Retired HTTP route creates no provider claim, reservation, debit or temporary audio');
    check(!str_contains($r['raw'],$tmp)&&!str_contains($r['raw'],'undecryptable-fixture'),'HTTP error response does not reveal file paths or key material');
    echo "91B21B4 catalog transcription $driver: $checks assertions passed\n";
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    fixtureDrop($db,$driver);removeFixture($tmp);
}
ob_end_flush();
