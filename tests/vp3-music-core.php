<?php
declare(strict_types=1);
/** Actual Stoneward private storage/catalog writer with a controlled provider transport boundary. */
$repository=dirname(__DIR__);
$root=sys_get_temp_dir().'/stoneward-vp3-core-'.bin2hex(random_bytes(6));
mkdir($root.'/data',0700,true);mkdir($root.'/assets/js',0700,true);mkdir($root.'/config',0700,true);
symlink($repository.'/api',$root.'/api');symlink($repository.'/admin',$root.'/admin');
copy($repository.'/config/store.default.php',$root.'/config/store.default.php');
file_put_contents($root.'/data/catalog.json','[]');file_put_contents($root.'/data/releases.json','[]');
define('SF_ROOT',$root);
require_once $repository.'/admin/api/bootstrap.php';
require_once $repository.'/api/vp3-music-core.php';
require_once $repository.'/api/homeserver-music-core.php';
require_once $repository.'/api/kunaki-publishing-jobs.php';
require_once $repository.'/api/canvas-audio-core.php';
$checks=0;
function vp3check(bool $condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS '.$message.PHP_EOL;}
function vp3fails(callable $call,string $code):void {
    try{$call();}catch(SfVp3MusicException $e){vp3check($e->publicCode===$code,'Reject '.$code);return;}
    throw new RuntimeException('Expected '.$code);
}
function vp3denied(callable $call,string $message):void {try{$call();}catch(Throwable){vp3check(true,$message);return;}throw new RuntimeException('Expected refusal: '.$message);}
function vp3uuid(int $n):string {return sprintf('00000000-0000-4000-8000-%012x',$n);}
function vp3remove(string $path):void {
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry)if($entry!=='.'&&$entry!=='..')vp3remove($path.'/'.$entry);
    rmdir($path);
}
$installation=vp3uuid(1);$siteId=vp3uuid(2);$api='https://vp3.music.example.com/api/music-provider-v1.php';
$credential='vmp1_'.str_repeat('b',64);$proof=['ok'=>true,'protocol'=>'vp3_music_site_v1','site_id'=>$siteId,'origin'=>'https://artist.music.example.com','challenge'=>str_repeat('c',64)];
$remote=['active'=>true,'revision'=>1,'selection_revision'=>0,'selected'=>[],'receipts'=>[],'calls'=>[],
    'items'=>[],'lose_selection_response'=>false,'change_catalog_after_selection'=>false,'wrong_page_selection'=>false];
for($i=10;$i<65;$i++){
    $id=vp3uuid($i);
    $remote['items'][$id]=['recording_id'=>$id,'work_id'=>vp3uuid($i+100),'asset_id'=>vp3uuid($i+200),
        'metadata_revision'=>1,'work_revision'=>1,'catalog_revision'=>str_repeat('d',64),'album_id'=>1,'track_number'=>$i-9,
        'duration_hint_seconds'=>180,'availability'=>'ready','metadata'=>['title'=>'Song '.$i,'album'=>'Public album',
            'isrc'=>'USABC2600001','isrc_status'=>'provided','writers'=>[['name'=>'Writer','role'=>'composer','private_ipi'=>'SECRET']],
            'performers'=>[['name'=>'Artist','role'=>'lead']],'publishers'=>[['name'=>'Publisher','role'=>'publisher']],
            'label'=>['name'=>'Label','private_contract'=>'SECRET'],'private_notes'=>'SECRET','copyright_notice'=>'© Writer'],
        'artwork'=>null,'private_master'=>'SECRET'];
}
$context=['site_base_url'=>'https://artist.music.example.com','transport'=>function(string $url,string $method,?array $body,?string $bearer)use(&$remote,$api,$credential,$installation,$siteId,$root):array{
    vp3check($url===$api,'Transport uses the pinned canonical provider endpoint');
    $remote['calls'][]=[$method,$body,$bearer];
    if(is_file($root.'/storage/vp3-music/.writer.lock')){
        $lock=fopen($root.'/storage/vp3-music/.writer.lock','c');$unlocked=flock($lock,LOCK_EX|LOCK_NB);
        if($unlocked)flock($lock,LOCK_UN);fclose($lock);vp3check($unlocked,'Provider network work occurs outside the private writer lock');
    }
    $envelope=fn(array $v,int $status=200):array=>['status'=>$status,'body'=>['protocol'=>SF_VP3_PROVIDER_PROTOCOL]+$v];
    if($method==='GET'){
        vp3check($bearer===null&&$body===null,'Discovery never carries the backend credential');
        return $envelope(['ok'=>true,'installation_id'=>$installation,'api_url'=>$api,'supported_protocols'=>[SF_VP3_PROVIDER_PROTOCOL],
            'site_verification'=>['path'=>'/api/vp3-music-site.php'],
            'capabilities'=>['catalog.read','selection.update','import.manifest','playback.begin','playback.events','playback.renew']]);
    }
    vp3check($bearer===$credential&&($body['site_id']??null)===$siteId,'Only authenticated backend calls receive the scoped credential');
    if(!$remote['active'])return $envelope(['ok'=>false,'code'=>'connection_inactive'],403);
    $site=function()use(&$remote,$siteId):array{return ['site_id'=>$siteId,'origin'=>'https://artist.music.example.com','status'=>'active','revision'=>$remote['revision'],
        'selection_revision'=>$remote['selection_revision'],'verified_ms'=>1,'environment'=>'production'];};
    $action=$body['action'];
    if($action==='selection.update'){
        $id=$body['request_id'];$hash=hash('sha256',json_encode($body));
        if(isset($remote['receipts'][$id])){
            if($remote['receipts'][$id]['hash']!==$hash)return $envelope(['ok'=>false,'code'=>'request_conflict'],409);
            return $envelope($remote['receipts'][$id]['result']);
        }
        if($body['expected_selection_revision']!==$remote['selection_revision'])return $envelope(['ok'=>false,'code'=>'selection_conflict'],409);
        $ids=$body['recording_ids'];sort($ids,SORT_STRING);
        if($ids!==$remote['selected']){$remote['selected']=$ids;$remote['selection_revision']++;}
        $result=['ok'=>true,'site'=>$site(),'recording_ids'=>$remote['selected'],'unexpected_media_url'=>'https://media.example.com/SECRET-ticket'];
        $result['site']['unexpected_private_field']='SECRET-site-extra';$remote['receipts'][$id]=['hash'=>$hash,'result'=>$result];
        if($remote['change_catalog_after_selection']){
            $remote['change_catalog_after_selection']=false;$rows=sf_catalog(true);$rows[0]['story']='Concurrent local edit';sf_admin_write_catalog($rows);
        }
        if($remote['lose_selection_response']){$remote['lose_selection_response']=false;sf_vp3_music_fail('provider_unreachable','Simulated lost acknowledgement.',503,true);}
        return $envelope($result);
    }
    $ids=$action==='import.manifest'?$remote['selected']:array_keys($remote['items']);
    $offset=$body['offset'];$slice=array_slice($ids,$offset,$body['limit']);$items=[];
    foreach($slice as $id){$item=$remote['items'][$id]??['recording_id'=>$id,'availability'=>'unavailable'];$item['selected']=in_array($id,$remote['selected'],true);$items[]=$item;}
    $result=['ok'=>true,'installation_id'=>$installation,'site'=>$site(),'items'=>$items,'recording_ids'=>$remote['selected'],
        'next_offset'=>$offset+count($slice)<count($ids)?$offset+count($slice):null];
    if($action==='import.manifest'&&$remote['wrong_page_selection']&&$offset>0)$result['site']['selection_revision']++;
    return $envelope($result);
}];
$connect=['action'=>'connect','request_id'=>vp3uuid(500),'expected_revision'=>0,'provider_url'=>'https://vp3.music.example.com',
    'credential'=>$credential,'verification'=>$proof];
$makeImport=function(array $ids,int $request)use(&$remote):array{return ['action'=>'import','request_id'=>vp3uuid($request),
    'expected_revision'=>sf_vp3_music_read_state()['revision'],'expected_selection_revision'=>$remote['selection_revision'],
    'catalog_hash'=>sf_vp3_music_catalog_snapshot()['hash'],'recording_ids'=>$ids];};
try{
    vp3check(sf_vp3_music_provider_url('https://VP3.MUSIC.EXAMPLE.COM:443/vp3/')==='https://vp3.music.example.com/vp3/api/music-provider-v1.php','Canonical HTTPS base-path support');
    foreach(['http://vp3.example.com','https://u:p@vp3.example.com','https://127.0.0.1','https://[::1]','https://localhost',
        'https://internal.local','https://vp3.example.com:8443','https://vp3.example.com/?token=secret','https://vp3.example.com/#x',
        'https://vp3.example.com/%2e%2e','https://vp3.example.com/a/../b','https://vp3.example.com\\@127.0.0.1'] as $url)
        vp3fails(fn()=>sf_vp3_music_provider_url($url),'invalid_provider_url');
    foreach(['127.0.0.1','10.2.1.1','169.254.169.254','100.64.1.1','192.0.2.1','198.18.1.1','224.1.1.1','::1','::ffff:8.8.8.8','2001:db8::1','2002:0808:0808::'] as $ip)
        vp3check(!sf_vp3_music_public_ip($ip),'Reject nonpublic address '.$ip);
    vp3check(sf_vp3_music_public_ip('8.8.8.8')&&sf_vp3_music_public_ip('2606:4700:4700::1111'),'Public IPv4 and IPv6 admitted');
    vp3fails(fn()=>sf_vp3_music_resolve('vp3.example.com',fn()=>[['ip'=>'8.8.8.8'],['ip'=>'127.0.0.1']]),'unsafe_provider_address');
    vp3fails(fn()=>sf_vp3_music_origin('https://artist.music.example.com/api/music-provider-v1.php'),'invalid_site_origin');
    vp3check(sf_vp3_music_status()['connection']['state']==='disconnected'&&!is_file(sf_vp3_music_state_path()),'Status is read-only before connection');
    vp3fails(fn()=>sf_vp3_music_action($connect,7,array_replace($context,['site_base_url'=>'https://other.example.com'])),'site_origin_mismatch');
    vp3fails(fn()=>sf_vp3_music_action($connect,7,array_replace($context,['site_base_url'=>'https://artist.music.example.com/subdirectory'])),'unsupported_site_address');
    $saved=sf_vp3_music_action($connect,7,$context);$status=sf_vp3_music_status();
    vp3check($saved['connection']['state']==='pending_verification'&&$status['connection']['revision']===1,'Connection is saved pending real VP3 verification');
    $raw=file_get_contents(sf_vp3_music_state_path());
    vp3check(!str_contains($raw,$credential)&&str_contains($raw,'credential_payload'),'Credential is encrypted in private persistent storage');
    vp3check(sf_ai_decrypt(sf_vp3_music_read_state()['connection']['credential_payload'])===$credential,'Stored credential decrypts with the installation key');
    vp3check(!str_contains(json_encode($status),$credential)&&!str_contains(json_encode($status),'credential_payload'),'Admin status never returns credential or encrypted payload');
    vp3check(sf_vp3_music_site_proof()===$proof,'Fixed site proof returns only the configured exact challenge');
    vp3check(sf_vp3_music_action($connect,7,$context)===$saved,'Exact connection retry replays the original receipt');
    vp3fails(fn()=>sf_vp3_music_action(array_replace($connect,['credential'=>'vmp1_'.str_repeat('a',64)]),7,$context),'request_conflict');
    vp3fails(fn()=>sf_vp3_music_action(array_replace($connect,['request_id'=>vp3uuid(501)]),7,$context),'connection_conflict');
    $remote['active']=false;vp3fails(fn()=>sf_vp3_music_action(['action'=>'catalog'],7,$context),'connection_unavailable');
    vp3check(sf_vp3_music_status()['connection']['state']==='unavailable','An unverified/revoked remote connection is not advertised ready');
    $remote['active']=true;$page=sf_vp3_music_action(['action'=>'catalog'],7,$context);
    vp3check($page['connection']['state']==='ready'&&count($page['items'])===50&&$page['next_offset']===50,'Authenticated catalog proves readiness and paginates');
    vp3check(!str_contains(json_encode($page),'SECRET')&&!str_contains(json_encode($page),'private_master'),'Public metadata is explicitly allowlisted');
    $request=$makeImport([vp3uuid(10),vp3uuid(11)],510);$first=sf_vp3_music_action($request,7,$context);
    $rows=sf_catalog(true);$firstId=$first['tracks'][0]['id'];$firstRow=sf_track_map()[$firstId];
    vp3check($first['imported_count']===2&&count($rows)===2&&sf_vp3_music_status()['pending']===null,'Selected recordings are published through the actual local catalog writer');
    vp3check($firstRow['audio']===''&&!$firstRow['digital_purchase_enabled']&&!$firstRow['podEligible']&&count($firstRow['audio_source'])===4,'Imported source contains stable identifiers and no media ticket or commerce grant');
    $public=file_get_contents($root.'/assets/js/catalog.js');
    vp3check(!str_contains($public,$credential)&&!str_contains($public,'credential_payload')&&!str_contains($public,'request_id')&&!str_contains($public,'SECRET'),'Public catalog asset contains no connection, journal, private metadata or receipt data');
    $source=sf_vp3_music_source_for_track($firstRow);
    vp3check($source['provider_api']===$api&&$source['recording_id']===vp3uuid(10),'Public resolver binds to current imported identity');
    $snapshot=sf_vp3_music_read_state();
    vp3check(sf_vp3_music_source_for_track($firstRow,$snapshot)===$source
        &&sf_canvas_audio_track_source($firstRow,$snapshot)===sf_canvas_audio_track_source($firstRow),
        'One caller-owned private snapshot preserves the exact public source descriptor');
    $canvasBefore=sf_canvas_audio_catalog();
    vp3check(count($canvasBefore['tracks'])===2&&count(array_filter($canvasBefore['tracks'],static fn($track)=>$track['source_available']))===2,
        'Canvas catalog resolves all imported references from one current private snapshot');
    sf_vp3_music_mutate(function(array &$state):void{$state['connection']['provider_url']='https://vp3.music.example.com/api/music-provider-v1.php?credential=SECRET';});
    vp3check(sf_vp3_music_source_for_track($firstRow)===null,'Public resolver rejects a corrupt saved provider URL without exposing it');
    vp3check(!array_filter(sf_canvas_audio_catalog()['tracks'],static fn($track)=>$track['source_available']),
        'A later canvas catalog operation reads fresh private state after a write');
    sf_vp3_music_mutate(function(array &$state)use($api):void{$state['connection']['provider_url']=$api;});
    vp3check(sf_canvas_audio_catalog()===$canvasBefore,'Fresh catalog resolution restores identical public output after state repair');
    vp3check(sf_vp3_music_source_for_track(array_replace($firstRow,['state'=>'hidden']))===null,'Hidden local track is unavailable through the resolver');
    $calls=count($remote['calls']);vp3check(sf_vp3_music_action($request,7,$context)===$first&&count($remote['calls'])===$calls,'Completed import retry performs no remote mutation and duplicates no row');
    vp3fails(fn()=>sf_vp3_music_action(array_replace($request,['recording_ids'=>[vp3uuid(10)]]),7,$context),'request_conflict');
    $rows[0]['title']='My local title';$rows[0]['story']='My local story';$rows[0]['metadata']['publisher']='My local publisher';sf_admin_write_catalog($rows);
    $remote['items'][vp3uuid(10)]['metadata']['title']='Remote revised title';$remote['items'][vp3uuid(10)]['metadata']['label']['name']='New label';
    $updated=sf_vp3_music_action($makeImport([vp3uuid(10),vp3uuid(11)],511),7,$context);$row=sf_catalog(true)[0];
    vp3check($updated['updated_count']===2&&$row['id']===$firstId&&$row['title']==='My local title'&&$row['story']==='My local story','Refresh preserves stable identity and local title/story edits');
    vp3check($row['metadata']['publisher']==='My local publisher'&&$row['metadata']['label']==='New label','Refresh preserves an edited credit and updates untouched provider metadata');
    $remote['lose_selection_response']=true;$lost=$makeImport([vp3uuid(10),vp3uuid(12)],512);
    vp3fails(fn()=>sf_vp3_music_action($lost,7,$context),'provider_unreachable');$afterRemote=$remote['selection_revision'];
    vp3check(sf_vp3_music_status()['pending']['state']==='selection_pending','Lost remote acknowledgement retains the frozen original import request');
    vp3fails(fn()=>sf_vp3_music_action(['action'=>'disconnect','request_id'=>$lost['request_id'],'expected_revision'=>1],7,$context),'request_conflict');
    $resumed=sf_vp3_music_action(['action'=>'resume','request_id'=>$lost['request_id'],'expected_revision'=>1],7,$context);
    vp3check($resumed['status']==='complete'&&$remote['selection_revision']===$afterRemote&&count(sf_catalog(true))===3,'Resume replays one remote receipt and completes one local import');
    $second=sf_catalog(true)[1];vp3check(sf_vp3_music_source_for_track($second)===null,'Removing a selected recording disables its retained local source');
    $remote['change_catalog_after_selection']=true;$conflict=$makeImport([vp3uuid(10),vp3uuid(13)],513);
    vp3fails(fn()=>sf_vp3_music_action($conflict,7,$context),'catalog_conflict');
    vp3check(sf_catalog(true)[0]['story']==='Concurrent local edit'&&sf_vp3_music_status()['pending']!==null,'Concurrent editorial changes survive with an explicit pending conflict');
    $discard=sf_vp3_music_action(['action'=>'discard','request_id'=>$conflict['request_id'],'expected_revision'=>1,'confirmed'=>true],7,$context);
    vp3check($discard['remote_selection_may_have_changed']&&sf_vp3_music_status()['pending']===null,'Explicit discard keeps the remote selection warning and clears the local pending operation');
    $crash=$makeImport([vp3uuid(10),vp3uuid(14)],514);
    unlink($root.'/assets/js/catalog.js');mkdir($root.'/assets/js/catalog.js',0700);
    set_error_handler(static fn()=>true);
    try{vp3fails(fn()=>sf_vp3_music_action($crash,7,$context),'import_pending');}finally{restore_error_handler();}
    vp3check(sf_vp3_music_status()['pending']['state']==='staged'&&count(sf_catalog(true))===4,'Interrupted derived-JS publication retains the staged catalog and recovery journal');
    vp3check(!str_contains(file_get_contents(sf_vp3_music_state_path()),'SECRET'),'Frozen import recovery never stores arbitrary upstream media tickets or extra private fields');
    rmdir($root.'/assets/js/catalog.js');$calls=count($remote['calls']);
    $recovered=sf_vp3_music_action(['action'=>'resume','request_id'=>$crash['request_id'],'expected_revision'=>1],7,$context);
    vp3check($recovered['status']==='complete'&&count($remote['calls'])===$calls&&is_file($root.'/assets/js/catalog.js'),'Recovery repairs the derived asset without repeating the provider mutation');
    vp3check(hash_file('sha256',$root.'/assets/js/catalog.js')===hash('sha256',sf_vp3_music_public_catalog_bytes(sf_catalog(true))),'Recovered public JavaScript exactly matches the current public catalog projection');
    $remote['wrong_page_selection']=true;$many=$makeImport(array_keys($remote['items']),515);
    vp3fails(fn()=>sf_vp3_music_action($many,7,$context),'provider_conflict');
    vp3check(count(sf_catalog(true))===4,'Changed selection across manifest pages cannot publish a partial import');
    sf_vp3_music_action(['action'=>'discard','request_id'=>$many['request_id'],'expected_revision'=>1,'confirmed'=>true],7,$context);
    $remote['wrong_page_selection']=false;
    $many=sf_vp3_music_action($makeImport(array_keys($remote['items']),516),7,$context);
    vp3check(count(sf_catalog(true))===55&&$many['imported_count']===51,'A stable multi-page selection imports every recording exactly once');
    $fake=sf_catalog(true)[0];$fake['audio']='assets/audio/fake.mp3';$fake['private_master_audio']='api/media.php?id=M'.str_repeat('A',20);
    $fake['digital_purchase_enabled']=true;$fake['podEligible']=true;$fake['vinyl_license_packages']=[['licenses'=>1,'price_cents'=>100]];
    $fake['audio_source']['credential']=$credential;$fake['vp3_journal']=['secret'=>$credential];
    $projection=sf_public_track_projection($fake);
    vp3check(count($projection['audio_source'])===4&&!$projection['digital_purchase_enabled']&&!$projection['podEligible']&&!isset($projection['vp3_journal'])&&!isset($projection['private_master_audio']),'Public projection refuses credential fields and falsely enabled commercial rights');
    vp3denied(fn()=>sf_hs_music_audio_reference(7,$fake['id'],[['id'=>$fake['id'],'can_download'=>true,'can_stream'=>true]],[$fake['id']=>$fake],true),'Owned-download adapter rejects a VP3 source even with fabricated ownership/master');
    vp3denied(fn()=>sf_vinyl_license_quote(['type'=>'vinyl_license_pack','track_id'=>$fake['id'],'licenses'=>1],[$fake['id']=>$fake],[]),'Print-license checkout rejects a typed VP3 source even with commercial flags');
    $rights=sf_hs_music_rights([['id'=>$fake['id'],'can_stream'=>true,'can_download'=>true]],[$fake['id']=>['available'=>2]],[$fake['id']=>$fake]);
    vp3check(!$rights[0]['can_download']&&!$rights[0]['can_stream']&&!$rights[0]['vinyl_print_rights']['packages'],'HomeServer rights projection never converts provider access into owned media');
    $cleanRows=sf_catalog(true);$fakeRows=$cleanRows;$fakeRows[0]=$fake;sf_admin_write_catalog($fakeRows);sf_catalog(true);
    $quote=sf_quote([['type'=>'track','track_id'=>$fake['id']]]);
    vp3check(!$quote['ok']&&!$quote['items']&&str_contains(implode(' ',$quote['errors']),'VP3 streaming only'),'Actual digital cart refuses a VP3 song even with forged sale flags');
    file_put_contents($root.'/data/releases.json',json_encode([['id'=>'vp3-album','title'=>'Provider album','type'=>'album',
        'state'=>'published','purchasable'=>true,'public_visible'=>true,'digital_price'=>10,'track_ids'=>[$fake['id']]]]));
    $quote=sf_quote([['type'=>'release','release_id'=>'vp3-album']]);
    vp3check(!$quote['ok']&&!$quote['items']&&str_contains(implode(' ',$quote['errors']),'streaming-only'),'Actual album cart cannot bundle a VP3 song into a digital sale');
    $quote=sf_quote([['type'=>'custom_media','format'=>'vinyl','builder'=>['A'=>[$fake['id']],'B'=>[]]]]);
    vp3check(!$quote['ok']&&!$quote['items']&&str_contains(implode(' ',$quote['errors']),'VP3 streaming only'),'Actual custom-vinyl quote refuses a VP3 source despite fabricated master eligibility');
    $payment=['provider'=>'stripe','status'=>'paid','livemode'=>true,'payment_intent'=>'pi_vp3_boundary_fixture',
        'postpayment_done'=>['library'=>true,'pod'=>true]];
    $paid=['id'=>'SF-VP3-DIGITAL','user_id'=>7,'status'=>'paid','payment'=>$payment,
        'quote'=>['items'=>[['type'=>'track','track_id'=>$fake['id'],'price_cents'=>100]]]];
    $physical=['id'=>'SF-VP3-VINYL','user_id'=>7,'status'=>'paid','payment'=>$payment,
        'quote'=>['items'=>[['type'=>'custom_media','format'=>'vinyl','builder'=>['sides'=>['A'=>['tracks'=>[['id'=>$fake['id']]]],'B'=>['tracks'=>[]]]]]]]];
    $library=[['item_type'=>'track','item_key'=>$fake['id'],'source_order_id'=>$paid['id']]];
    $localMaster=$fake;unset($localMaster['audio_source'],$localMaster['audio_publishing_mode']);
    vp3check(count(sf_kunaki_publishing_entitlements($physical,0,$library,[$paid['id']=>$paid],[$fake['id']=>$localMaster]))===1,
        'Manufacturing control permits a genuine local paid-master fixture');
    vp3denied(fn()=>sf_kunaki_publishing_entitlements($physical,0,$library,[$paid['id']=>$paid],[$fake['id']=>$fake]),
        'Actual manufacturing admission rejects provider source despite otherwise valid purchase and print grants');
    sf_admin_write_catalog($cleanRows);sf_catalog(true);
    $before=sf_vp3_music_status();$disconnect=sf_vp3_music_action(['action'=>'disconnect','request_id'=>vp3uuid(520),'expected_revision'=>1],7,$context);
    vp3check(!$disconnect['remote_revoked']&&sf_vp3_music_site_proof()===null&&sf_vp3_music_source_for_track($fake)===null,'Local disconnect clears credentials/proof/source without claiming remote revocation');
    vp3check(count(sf_catalog(true))===55,'Disconnect preserves local catalog identities');
    $badContext=['transport'=>fn()=>['status'=>200,'body'=>['ok'=>true,'protocol'=>SF_VP3_PROVIDER_PROTOCOL,'api_url'=>'https://other.example.com/api/music-provider-v1.php']]];
    vp3fails(fn()=>sf_vp3_music_action(array_replace($connect,['request_id'=>vp3uuid(521),'expected_revision'=>2]),7,$badContext),'provider_protocol_mismatch');
    vp3check(sf_vp3_music_status()['connection']['state']==='disconnected','Mismatched provider identity cannot change the saved connection');
}finally{vp3remove($root);}
echo 'STONEWARD_VP3_MUSIC_CORE=PASS checks='.$checks.PHP_EOL;
