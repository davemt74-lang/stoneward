<?php
declare(strict_types=1);
/** Actual saved-lyrics HTTP endpoint, bootstrap, authentication and PDO.
 * The disposable HTTP server has curl_exec disabled: saved lookup must work
 * without any ability to transfer audio to a provider. No real API key is used.
 */
$source=dirname(__DIR__);$driver=getenv('SF_TEST_DRIVER')?:'sqlite';
if(!in_array($driver,['sqlite','mysql'],true)||!extension_loaded('pdo_'.$driver)||!extension_loaded('curl'))throw new RuntimeException('Required PDO/cURL test runtime unavailable.');
$tmp=sys_get_temp_dir().'/sf-saved-lyrics-'.bin2hex(random_bytes(6));
foreach(['config','storage/media','sessions','data'] as $dir)mkdir($tmp.'/'.$dir,0700,true);
$cfg=$driver==='sqlite'?['driver'=>'sqlite','path'=>$tmp.'/test.sqlite']:
    ['driver'=>'mysql','host'=>'127.0.0.1','port'=>33306,'database'=>'stonefellow_lyrics_test','username'=>'root','password'=>(string)getenv('SF_TEST_MYSQL_PASSWORD')];
if($driver==='mysql'&&$cfg['password']==='')throw new RuntimeException('Isolated MySQL password required.');
file_put_contents($tmp.'/config/database.php','<?php return '.var_export($cfg,true).';');
file_put_contents($tmp.'/config/store.php',"<?php return ['mode'=>'test'];");
define('SF_ROOT',$tmp);session_save_path($tmp.'/sessions');
require $source.'/api/bootstrap.php';require $source.'/api/agent-runtime.php';
require $source.'/api/agent-song-catalog-core.php';require $source.'/api/song-moments-core.php';
$db=sf_db();if($driver==='sqlite')$db->exec('PRAGMA busy_timeout=15000');
$checks=0;$server=null;$pipes=[];ob_start();
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);$checks++;echo "PASS $label\n";}
function dropFixture(PDO $db,string $driver):void {
    if($driver!=='mysql')return;
    if($db->query('SELECT DATABASE()')->fetchColumn()!=='stonefellow_lyrics_test')throw new RuntimeException('Refusing a non-fixture database.');
    $db->exec('SET FOREIGN_KEY_CHECKS=0');foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');$db->exec('SET FOREIGN_KEY_CHECKS=1');
}
function removeFixture(string $dir):void {if(!is_dir($dir))return;foreach(new DirectoryIterator($dir) as $file){if($file->isDot())continue;$path=$file->getPathname();if($file->isDir()&&!$file->isLink())removeFixture($path);else unlink($path);}rmdir($dir);}
function countRows(string $table):int {$q=sf_db()->query('SELECT COUNT(*) FROM '.$table);$n=(int)$q->fetchColumn();$q->closeCursor();return $n;}
function http(string $base,string $path,string $method='GET',?string $body=null,?string $jar=null,?string $csrf=null):array {
    $curl=curl_init($base.$path);$headers=['Content-Type: application/json'];if($csrf!==null)$headers[]='X-CSRF-Token: '.$csrf;
    $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>10];
    if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;if($jar!==null){$opts[CURLOPT_COOKIEFILE]=$jar;$opts[CURLOPT_COOKIEJAR]=$jar;}
    curl_setopt_array($curl,$opts);$raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if(!is_string($raw))throw new RuntimeException('Local saved-lyrics HTTP request failed.');
    return ['status'=>$status,'body'=>json_decode($raw,true),'raw'=>$raw];
}
function payload(array $changes=[]):array {global $catalog,$fingerprint;return array_replace([
    'track_id'=>'fixture-song','start_ms'=>1000,'end_ms'=>4000,'captured_at'=>gmdate('Y-m-d\TH:i:s\Z'),
    'request_id'=>str_repeat('a',32),'source_ref'=>$catalog[0]['audio'],'source_fingerprint'=>$fingerprint],$changes);}
try{
    dropFixture($db,$driver);
    $db->exec('CREATE TABLE users(id '.($driver==='sqlite'?'INTEGER':'BIGINT UNSIGNED').' PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(100),role VARCHAR(30),status VARCHAR(30),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40),password_hash VARCHAR(255))'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $db->exec('CREATE TABLE app_meta(meta_key VARCHAR(190) PRIMARY KEY,meta_value TEXT NOT NULL,updated_at VARCHAR(40) NOT NULL)'.($driver==='mysql'?' ENGINE=InnoDB':''));
    $now=gmdate('c');$q=$db->prepare('INSERT INTO users(id,email,display_name,role,status,created_at,updated_at,password_hash) VALUES(?,?,?,?,?,?,?,?)');
    foreach([1=>'user',2=>'admin'] as $id=>$role)$q->execute([$id,'fixture'.$id.'@example.invalid','Fixture '.$id,$role,'active',$now,$now,'unused']);
    sf_customer_lifecycle_ensure_schema();sf_media_ensure_schema();sf_auth_ensure_schema();sf_ai_ensure_schema();sf_ai_reservation_schema();sf_song_catalog_request_schema();sf_song_moments_schema();
    // Zero balance and disabled provider are intentional: text lookup requires neither credits nor credentials.
    $db->prepare('INSERT INTO user_token_balances(user_id,balance,updated_at) VALUES(?,?,?)')->execute([1,0,$now]);
    $samples=str_repeat(pack('v',900),8000*120);$wav='RIFF'.pack('V',36+strlen($samples)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($samples)).$samples;
    $audio=$tmp.'/storage/media/fixture.wav';file_put_contents($audio,$wav);$uuid='M'.str_repeat('A',20);
    $q=$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,caption,technical_json,variants_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$uuid,'audio','audio/wav','wav','fixture.wav','storage/media/fixture.wav',hash_file('sha256',$audio),filesize($audio),'Fixture','','{}','{}',$now,$now]);
    $asset=(int)$db->lastInsertId();sf_media_attach($asset,'track','fixture-song','primary_audio',['public_visible'=>true]);
    $catalog=[['id'=>'fixture-song','title'=>'Synthetic fixture','release'=>'Test release','duration'=>120,'audio'=>'api/media.php?id='.$uuid,'public_visible'=>true,'state'=>'published']];
    $otherSong=array_replace($catalog[0],['id'=>'other-song','title'=>'Other song']);
    file_put_contents($tmp.'/data/catalog.json',json_encode([...$catalog,$otherSong,['id'=>'private-song','title'=>'Private','duration'=>120,'audio'=>$catalog[0]['audio'],'state'=>'private']]));
    file_put_contents($tmp.'/data/releases.json','[]');
    $fingerprint=sf_song_catalog_source('fixture-song',$catalog,['id'=>1,'role'=>'user'],true)['fingerprint'];
    $publicText='Synthetic public saved lyrics: café déjà vu.';
    $import=sf_song_moments_ingest_lyrics('fixture-song',$fingerprint,[
        ['source_key'=>'fixture:public','start_ms'=>0,'end_ms'=>60000,'text'=>$publicText],
        ['source_key'=>'fixture:draft','start_ms'=>60000,'end_ms'=>120000,'text'=>'Unverified artist draft kept out of public lookup.']]);
    $approved=$import['segments'][0];
    sf_song_moments_admin_save(2,['track_id'=>'fixture-song','kind'=>'lyric','at_ms'=>0,'end_ms'=>60000,'body'=>$publicText,
        'id'=>$approved['id'],'revision'=>$approved['revision'],'status'=>'published','lyric_state'=>'approved',
        'timing_source'=>'catalog_chunk_estimate','confirmed'=>true]);
    // Even malformed legacy private annotation rows must remain inaccessible through this route.
    $db->prepare("INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at,lyric_state,source_annotation_id,source_fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(['fixture-song',0,60000,'lyric','','Private listener secret','',0,1,'Listener','published',$now,$now,'approved',99,$fingerprint]);
    $story=sf_story_save(['entry_type'=>'track_story','entity_id'=>'fixture-song','title'=>'Writing the first verse',
        'summary'=>'The verse began as an image of rain.','body_text'=>'Fixture songwriter story.','status'=>'published'],2);
    $otherStory=sf_story_save(['entry_type'=>'track_story','entity_id'=>'other-song','title'=>'Wrong song story',
        'summary'=>'Unrelated story text.','body_text'=>'Fixture story for a different song.','status'=>'published'],2);
    $saveMoment=static fn(array $changes):array=>sf_song_moments_admin_save(2,array_replace(
        ['track_id'=>'fixture-song','status'=>'published','confirmed'=>true],$changes));
    $storyMoment=$saveMoment(['kind'=>'story','at_ms'=>1000,'story_id'=>$story['id']]);
    $saveMoment(['kind'=>'story','at_ms'=>999,'story_id'=>$story['id']]);
    $saveMoment(['kind'=>'story','at_ms'=>4000,'story_id'=>$story['id']]);
    $wrongStoryMoment=$saveMoment(['kind'=>'story','at_ms'=>1200,'story_id'=>$otherStory['id']]);
    $noteMoment=$saveMoment(['kind'=>'note','at_ms'=>1300,'body'=>'A published timeline note stays outside this receipt.']);
    $imageUuid='M'.str_repeat('C',20);
    $image=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=',true);
    file_put_contents($tmp.'/storage/media/context.png',$image);
    $q=$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,caption,alt_text,technical_json,variants_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$imageUuid,'image','image/png','png','context.png','storage/media/context.png',hash('sha256',$image),strlen($image),
        'Rain photo','Rain in the rehearsal room.','Rain on a window','{}',json_encode(['thumb'=>['path'=>'storage/media/context.png','mime_type'=>'image/png']]),$now,$now]);
    $imageAsset=(int)$db->lastInsertId();
    sf_media_attach($imageAsset,'site','fixture','gallery',['public_visible'=>true]);
    sf_media_attach($imageAsset,'member_content','fixture','gallery',['public_visible'=>true]);
    $photoMoment=$saveMoment(['kind'=>'photo','at_ms'=>1600,'media_uuid'=>$imageUuid]);
    $approvedComment=sf_song_moments_comment(1,['track_id'=>'fixture-song','at_ms'=>3900,'body'=>'Approved listener observation.']);
    sf_song_moments_admin_moderate(2,$approvedComment['id'],'approve');
    $otherComment=sf_song_moments_comment(1,['track_id'=>'other-song','at_ms'=>1800,'body'=>'Other song listener observation.']);
    sf_song_moments_admin_moderate(2,$otherComment['id'],'approve');
    $db->prepare('UPDATE song_timeline_moments SET track_id=? WHERE id=?')->execute(['FIXTURE-SONG',$otherComment['id']]);
    sf_song_moments_comment(1,['track_id'=>'fixture-song','at_ms'=>1900,'body'=>'Pending listener secret.']);
    $unreviewedComment=sf_song_moments_comment(1,['track_id'=>'fixture-song','at_ms'=>2000,'body'=>'Legacy unreviewed listener text.']);
    $db->prepare("UPDATE song_timeline_moments SET status='published' WHERE id=?")->execute([$unreviewedComment['id']]);
    check(sf_site_settings()['orb_song_moments_enabled']===false,'Existing sites start with automatic orb moments off');
    sf_site_settings_update(['orb_song_moments_enabled'=>true]);
    check(sf_site_settings_update(['announcement_text'=>'Fixture announcement'])['orb_song_moments_enabled']===true,
        'Admin can enable the orb and an older settings save preserves the preference');
    check(sf_site_settings_update(['orb_song_moments_enabled'=>false])['orb_song_moments_enabled']===false,
        'Admin can persistently turn off automatic orb moments');
    $ledger=countRows('token_ledger');$reservations=countRows('ai_token_reservations');$claims=countRows('song_lyric_requests');$files=glob(sys_get_temp_dir().'/sf-lyric-*')?:[];
    $router='<?php define("SF_ROOT",'.var_export($tmp,true).');session_save_path(SF_ROOT."/sessions");'.
        '$path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH);if($path==="/ping"){echo function_exists("curl_exec")?"unsafe":"ready";return;}'.
        'if($path==="/login-user"||$path==="/login-admin"){require '.var_export($source.'/api/bootstrap.php',true).';sf_login_user(sf_user_by_email($path==="/login-admin"?"fixture2@example.invalid":"fixture1@example.invalid"));sf_json_response(["csrf"=>sf_user_csrf()]);}'.
        'if($path==="/analyze"){require '.var_export($source.'/api/agent-song-analyze.php',true).';return;}'.
        'if($path==="/moments"){require '.var_export($source.'/api/song-moments.php',true).';return;}'.
        'require '.var_export($source.'/api/song-lyrics-section.php',true).';';
    file_put_contents($tmp.'/router.php',$router);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('Cannot allocate a local test port.');$address=stream_socket_get_name($socket,false);fclose($socket);
    $server=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec','-S',$address,$tmp.'/router.php'],[0=>['pipe','r'],1=>['file',$tmp.'/http.log','w'],2=>['file',$tmp.'/http.err','w']],$pipes,$source);
    if(!is_resource($server))throw new RuntimeException('Cannot launch saved lyric HTTP test server.');fclose($pipes[0]);$base='http://'.$address;
    $until=microtime(true)+10;$ping=[];do{try{$ping=http($base,'/ping');}catch(RuntimeException){}if(($ping['raw']??'')==='ready')break;usleep(20000);}while(microtime(true)<$until);
    check(($ping['raw']??'')==='ready','Production endpoint fixture runs with outbound cURL execution disabled');
    $momentsUrl='/moments?track_id=fixture-song';
    $r=http($base,$momentsUrl);
    $orbFlags=array_column($r['body']['moments']??[],'orb_eligible','id');
    check($r['status']===200&&($orbFlags[$storyMoment['id']]??null)===true&&($orbFlags[$photoMoment['id']]??null)===true
        &&($orbFlags[$approvedComment['id']]??null)===true,'Public timeline marks the three eligible moment kinds for the orb');
    check(($orbFlags[$approved['id']]??null)===false&&($orbFlags[$wrongStoryMoment['id']]??null)===false
        &&($orbFlags[$noteMoment['id']]??null)===false&&($orbFlags[$unreviewedComment['id']]??null)===false
        &&!array_key_exists($otherComment['id'],$orbFlags)&&!str_contains($r['raw'],'Pending listener secret'),
        'The orb eligibility receipt excludes lyrics, notes, wrong-track stories and unapproved comments');
    $query='/lyrics?'.http_build_query(['track_id'=>'fixture-song','source_ref'=>$catalog[0]['audio']]);
    check(http($base,$query)['status']===401,'Readiness requires a signed-in account');
    check(http($base,'/lyrics','POST',json_encode(payload()))['status']===401,'Stored section reads require a signed-in account');
    check(http($base,'/lyrics','PUT')['status']===405,'Unsupported HTTP methods are rejected');
    $jar=$tmp.'/user-cookies';$login=http($base,'/login-user','GET',null,$jar);$csrf=$login['body']['csrf']??'';check(strlen($csrf)===64,'Real account login produces a CSRF token');
    $r=http($base,$query,'GET',null,$jar);check($r['status']===200&&$r['body']['available']===true&&$r['body']['segments_available']===1,'Saved readiness works with a disabled provider and only counts reviewed public segments');
    check($r['body']['source_fingerprint']===$fingerprint&&!str_contains($r['raw'],$tmp),'Readiness binds stored text to current audio without leaking file paths');
    check(!array_key_exists('section_context',$r['body'])&&!str_contains($r['raw'],'Writing the first verse'),'Readiness returns availability without preloading section context');
    check(http($base,'/lyrics','POST',json_encode(payload()),$jar)['status']===403,'Missing CSRF cannot read a submitted section');
    check(http($base,'/lyrics','POST',json_encode(payload()),$jar,str_repeat('0',64))['status']===403,'Forged CSRF cannot read a submitted section');
    $r=http($base,'/lyrics','POST',json_encode(payload()),$jar,$csrf);$transcript=$r['body']['transcript']??[];
    check($r['status']===200&&($transcript['text']??'')===$publicText,'Actual endpoint returns Unicode saved lyrics with no paid API capability');
    check($transcript['provider']==='stored'&&$transcript['source']==='saved-catalog-lyrics'&&$transcript['review_status']==='approved','Receipt records storage provenance and approved status');
    check($transcript['start_ms']===1000&&$transcript['end_ms']===4000&&$transcript['segments'][0]['start_ms']===0&&$transcript['segments'][0]['end_ms']===60000,'Selection timing remains distinct from approximate stored block timing');
    check(!str_contains($r['raw'],'Private listener secret')&&!str_contains($r['raw'],'Unverified artist draft'),'Public lookup excludes personal annotations and unreviewed canonical drafts');
    $sectionContext=$transcript['section_context']??null;
    check(is_array($sectionContext)&&array_is_list($sectionContext)&&array_column($sectionContext,'id')===[$storyMoment['id'],$photoMoment['id'],$approvedComment['id']],
        'Section context contains only current-song public story, photo and approved comment within the half-open selection');
    check(array_column($sectionContext,'at_ms')===[1000,1600,3900]&&array_column($sectionContext,'kind')===['story','photo','comment'],
        'Section context includes the exact start, excludes the end, and follows playback order');
    check($sectionContext[0]['title']==='Writing the first verse'&&$sectionContext[0]['text']==='The verse began as an image of rain.'
        &&($sectionContext[0]['href']??'')==='story/writing-the-first-verse','Public story context uses the live story title, summary and managed route');
    check(($sectionContext[1]['image_url']??'')==='api/media.php?id='.$imageUuid.'&variant=thumb'&&($sectionContext[1]['alt']??'')==='Rain on a window',
        'Public photo context includes managed imagery and its alternative text');
    check($sectionContext[2]['text']==='Approved listener observation.'&&!array_key_exists('user_id',$sectionContext[2])
        &&!array_key_exists('display_name',$sectionContext[2]),'Approved listener context omits account identity');
    foreach($sectionContext as $item)check(!array_diff(array_keys($item),['id','kind','at_ms','title','text','href','image_url','alt']),
        'Section context returns only public fields for '.$item['kind']);
    $analysis=array_intersect_key($transcript,array_flip(['track_id','start_ms','end_ms','captured_at','source_fingerprint']))+
        ['transcript'=>$publicText,'question'=>'What do the selected lyrics suggest?','saved_lyric_ids'=>[$approved['id']]];
    $r=http($base,'/analyze','POST',json_encode(array_replace($analysis,['transcript'=>'Listener correction',
        'listener_edited'=>true,'source_fingerprint'=>str_repeat('0',64)])),$jar,$csrf);
    check($r['status']===409&&($r['body']['error']??'')==='song_source_changed','Edited lyrics cannot revive a stale saved listening copy');
    $r=http($base,'/analyze','POST',json_encode(array_replace($analysis,['saved_lyric_ids'=>[$approved['id']+100000]])),$jar,$csrf);
    check($r['status']===422&&($r['body']['error']??'')==='invalid_lyric_analysis','Analysis rejects an invented saved lyric ID');
    $again=http($base,'/lyrics','POST',json_encode(payload()),$jar,$csrf);
    check($again['status']===200&&$again['body']['transcript']['text']===$publicText,'Identical read requests safely reuse the same saved lyrics');
    $r=http($base,'/lyrics','POST',json_encode(payload(['start_ms'=>5000,'end_ms'=>7000])),$jar,$csrf);
    check($r['status']===200&&($r['body']['transcript']['section_context']??null)===[],'A section without public context still returns its saved lyrics');
    $db->prepare("UPDATE story_entries SET status='archived' WHERE id=?")->execute([$story['id']]);
    $db->prepare("UPDATE media_links SET public_visible=0 WHERE asset_id=? AND entity_type='site'")->execute([$imageAsset]);
    $r=http($base,'/lyrics','POST',json_encode(payload()),$jar,$csrf);
    check($r['status']===200&&array_column($r['body']['transcript']['section_context']??[],'id')===[$approvedComment['id']],
        'Archiving a story or revoking a photo’s general public link immediately removes it from the receipt');
    $orbFlags=array_column(http($base,$momentsUrl)['body']['moments']??[],'orb_eligible','id');
    check(!array_key_exists($storyMoment['id'],$orbFlags)&&!array_key_exists($photoMoment['id'],$orbFlags)
        &&($orbFlags[$approvedComment['id']]??null)===true,'A fresh timeline excludes archived stories and revoked photos');
    check(http($base,'/lyrics','POST',json_encode(payload(['start_ms'=>65000,'end_ms'=>70000])),$jar,$csrf)['status']===404,'An ordinary listener cannot retrieve draft lyrics');
    check(http($base,'/lyrics','POST',json_encode(payload(['start_ms'=>120000,'end_ms'=>120500])),$jar,$csrf)['status']===422,'Selection cannot extend beyond song duration');
    check(http($base,'/lyrics','POST',json_encode(payload(['source_fingerprint'=>str_repeat('0',64)])),$jar,$csrf)['status']===409,'A stale source fingerprint is rejected');
    check(http($base,'/lyrics','POST',json_encode(payload(['track_id'=>'private-song'])),$jar,$csrf)['status']===422,'A hidden catalog song cannot be read');
    check(http($base,'/lyrics','POST',str_repeat('x',8193),$jar,$csrf)['status']===413,'Oversized JSON is rejected before lookup');
    check(http($base,'/lyrics','POST','[]',$jar,$csrf)['status']===422,'A JSON list cannot masquerade as a selection');
    check(http($base,'/lyrics?track_id[]=fixture-song&source_ref=x','GET',null,$jar)['status']===422,'Array query parameters cannot bypass strict identity checks');
    $adminJar=$tmp.'/admin-cookies';$adminLogin=http($base,'/login-admin','GET',null,$adminJar);$adminCsrf=$adminLogin['body']['csrf']??'';
    $r=http($base,'/analyze','POST',json_encode($analysis),$adminJar,$adminCsrf);
    check($r['status']===503&&($r['body']['error']??'')==='analysis_provider_unavailable',
        'Analysis of an authorized saved range reaches the existing provider gate');
    $r=http($base,$query,'GET',null,$adminJar);check($r['status']===200&&$r['body']['segments_available']===2,'Admin preview sees canonical drafts without exposing personal annotation rows');
    $r=http($base,'/lyrics','POST',json_encode(payload(['start_ms'=>65000,'end_ms'=>70000])),$adminJar,$adminCsrf);
    check($r['status']===200&&$r['body']['transcript']['review_status']==='unverified'&&str_contains($r['body']['transcript']['text'],'Unverified artist draft'),'Admin preview retains the unverified state');
    $db->prepare('UPDATE media_links SET public_visible=0 WHERE asset_id=?')->execute([$asset]);
    check(http($base,$query,'GET',null,$adminJar)['status']===404,'Administrator preview cannot bypass revoked listening-copy media access');
    $db->prepare('UPDATE media_links SET public_visible=1 WHERE asset_id=?')->execute([$asset]);
    $db->prepare('UPDATE media_assets SET sha256=? WHERE id=?')->execute([str_repeat('b',64),$asset]);
    check(http($base,'/lyrics','POST',json_encode(payload()),$jar,$csrf)['status']===409,'Replacing the source invalidates an in-flight section');
    $r=http($base,$query,'GET',null,$jar);check($r['status']===200&&$r['body']['available']===false,'A new audio version cannot serve the previous version’s saved lyrics');
    check(countRows('token_ledger')===$ledger&&countRows('ai_token_reservations')===$reservations&&countRows('song_lyric_requests')===$claims,'All stored lookups leave provider claims, escrow, and token ledger unchanged');
    check(sf_token_balance(1)===0,'A listener with zero Active Tokens can retrieve saved lyrics');
    check((glob(sys_get_temp_dir().'/sf-lyric-*')?:[])===$files&&is_file($audio)&&hash_file('sha256',$audio)===hash('sha256',$wav),'Stored lookup creates no temporary audio and leaves the source bytes untouched');
    echo "91B22A saved section $driver: $checks assertions passed\n";
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();dropFixture($db,$driver);removeFixture($tmp);
}
ob_end_flush();
