<?php
declare(strict_types=1);
/** 90Z: actual MP3 private-master -> public-player copy without FFmpeg at publish time. */
$root=sys_get_temp_dir().'/sf90z-'.bin2hex(random_bytes(6));
mkdir($root.'/storage/media',0700,true);mkdir($root.'/data',0700,true);
define('SF_ROOT',$root);
file_put_contents($root.'/data/catalog.json','[]');
$GLOBALS['sf90z_db']=new PDO('sqlite::memory:');
$GLOBALS['sf90z_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['sf90z_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{return $GLOBALS['sf90z_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $max=160):string{return substr(trim((string)$v),0,$max);}
function sf_admin_public_credits(array $metadata):array{return [];}
function sf_catalog():array{return json_decode((string)file_get_contents(SF_ROOT.'/data/catalog.json'),true)?:[];}
function sf_admin_write_catalog(array $rows,?string $hash=null):void {
    file_put_contents(SF_ROOT.'/data/catalog.json',json_encode($rows,JSON_THROW_ON_ERROR));
}
require dirname(__DIR__).'/api/media-core.php';
require dirname(__DIR__).'/api/media-catalog-intake.php';
function assert90z(bool $v,string $message):void {
    if(!$v)throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message.PHP_EOL;
}
function cleanup90z(string $dir):void {
    if(!is_dir($dir))return;
    foreach(new FilesystemIterator($dir) as $entry){
        if($entry->isDir())cleanup90z($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($dir);
}
try{
    $ffmpeg=is_executable('/usr/local/bin/ffmpeg')?'/usr/local/bin/ffmpeg':'/usr/bin/ffmpeg';
    assert90z(is_executable($ffmpeg),'CI has FFmpeg for generating real test MP3 audio.');
    $source=$root.'/storage/media/private.mp3';
    $cmd=[$ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-f','lavfi',
        '-i','sine=frequency=440:duration=1','-codec:a','libmp3lame','-b:a','192k',
        '-metadata','title=DO-NOT-PUBLISH-PRIVATE-TAG','-id3v2_version','3','-y',$source];
    $pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    assert90z(is_resource($p),'Generate MP3 test master.');
    fclose($pipes[0]);assert90z(proc_close($p)===0&&is_file($source),'Audio master is valid.');
    $db=sf_db();$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');$db->exec('INSERT INTO users(id) VALUES(7)');
    sf_media_ensure_schema();
    $master=sf_media_register_existing_file('storage/media/private.mp3','private.mp3',7);
    sf_media_attach((int)$master['id'],'track','older-recording','master_audio',
        ['public_visible'=>false,'download_allowed'=>false]);
    $key=str_repeat('f',32);
    $preview=sf_media_catalog_remux_mp3_preview($master,7,$key);
    $copy=file_get_contents(SF_ROOT.'/'.$preview['stored_path']);
    assert90z((int)$preview['id']!==(int)$master['id']
        &&!hash_equals($preview['sha256'],$master['sha256']),
        'Fallback creates a separate asset, never reuses the protected master.');
    assert90z((float)$preview['duration_seconds']>0.5,
        'Fallback listening MP3 remains recognized as playable audio.');
    assert90z(str_starts_with($copy,'ID3')&&!str_contains($copy,'DO-NOT-PUBLISH-PRIVATE-TAG'),
        'Fallback strips original private ID3 title/owner metadata.');
    assert90z((int)sf_media_catalog_remux_mp3_preview($master,7,$key)['id']===(int)$preview['id'],
        'Fallback is deterministic and safe to retry.');
    assert90z(sf_media_protected_master_asset((int)$master['id'])
        &&!sf_media_protected_master_asset((int)$preview['id']),
        'Older private-master protection remains intact.');
    try{sf_media_catalog_remux_mp3_preview($master,8,$key);
        throw new RuntimeException('FAIL: Unauthorized user obtained private song bytes.');
    }catch(InvalidArgumentException $expected){
        assert90z(true,'Another administrator cannot remux a private source.');
    }
    $record=['request_key'=>$key,'owner_id'=>7,'status'=>'draft',
        'asset_id'=>(int)$master['id'],'asset_sha256'=>$master['sha256'],
        'title'=>'New Test Song','release'=>'Unreleased','year'=>2026,
        'words_by'=>'Test Writer','music_by'=>'Test Writer','artist_display'=>'Test Artist',
        'master_owner'=>'Test Owner','composition_owner'=>'Test Owner','genre'=>'Rock',
        'isrc'=>'','release_date'=>'','explicit'=>false,'pod_eligible'=>true,
        'digital_purchase_enabled'=>true,'template_metadata'=>['price'=>3.50],
        'imported_asset_ids'=>[(int)$master['id']],'candidate_preview_asset_id'=>0];
    $draft=sf_agent_song_intake_save(7,$record)['draft'];
    // The same exact public Catalog writer accepts the new listening copy.
    $result=sf_media_catalog_publish_draft(7,$key,$draft['payload_hash'],true,(int)$preview['id']);
    $track=sf_catalog()[0]??[];
    assert90z($result['publication']==='published'
        &&(int)($track['media_primary_asset']??0)===(int)$preview['id'],
        'A previously protected master now publishes through the real Catalog writer.');
    assert90z((int)($track['media_private_master_asset']??0)===(int)$master['id']
        &&!empty($track['digital_purchase_enabled'])&&!empty($track['podEligible']),
        'Downloads/vinyl keep the original privately associated with the song.');
    $public=sf_media_public_links('track',(string)$result['track_id']);
    assert90z(count($public)===1&&$public[0]['uuid']===$preview['uuid'],
        'Public player exposes only the distinct listening copy.');
    $retry=sf_media_catalog_publish_draft(7,$key,$draft['payload_hash'],true,(int)$preview['id']);
    assert90z($retry['changed']===false,'Retry produces no second Catalog song.');
}finally{cleanup90z($root);}
echo "Section 90Z MP3 publishing and privacy: PASS\n";
