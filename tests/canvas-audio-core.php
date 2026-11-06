<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-canvas-audio-'.bin2hex(random_bytes(5));
foreach(['config','data','assets/audio','uploads/audio','storage/media/audio'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
register_shutdown_function(static function()use($fixture):void{
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
file_put_contents($fixture.'/config/store.php','<?php return [];');
$bytes="ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat("A",413),8);
foreach(['assets/audio/public.mp3','assets/audio/Encoded title.mp3','uploads/audio/public.mp3','storage/media/audio/public.mp3','storage/media/audio/master.mp3'] as $path)file_put_contents($fixture.'/'.$path,$bytes);
symlink($fixture.'/storage/media/audio/master.mp3',$fixture.'/assets/audio/link.mp3');
symlink($fixture.'/storage/media/audio',$fixture.'/uploads/audio/alias');
$catalog=[];$bulk=[];
for($i=1;$i<=135;$i++){$id='catalog-'.$i;$bulk[]=$id;$catalog[]=['id'=>$id,'title'=>'Published song '.$i,'release_id'=>'bulk'];}
$cases=[
    ['id'=>'managed','audio'=>'api/media.php?id=MPUBLIC'],
    ['id'=>'legacy-assets','audio'=>'assets/audio/public.mp3'],
    ['id'=>'legacy-uploads','audio'=>'uploads/audio/public.mp3'],
    ['id'=>'encoded-title','audio'=>'assets/audio/Encoded%20title.mp3'],
    ['id'=>'root-relative','audio'=>'/assets/audio/public.mp3'],
    ['id'=>'remote','audio'=>'https://example.invalid/private-signed-source?token=SECRET_URL_TOKEN'],
    ['id'=>'remote-relative','audio'=>'//example.invalid/remote.mp3'],
    ['id'=>'private-master','audio'=>'api/media.php?id=MMASTER'],
    ['id'=>'nonprimary','audio'=>'api/media.php?id=MALTERNATE'],
    ['id'=>'wrong-track','audio'=>'api/media.php?id=MWRONGTRACK'],
    ['id'=>'wrong-file','audio'=>'api/media.php?id=MTRAVERSAL'],
    ['id'=>'download-query','audio'=>'api/media.php?id=MPUBLIC&download=1'],
    ['id'=>'private-storage','audio'=>'storage/media/audio/master.mp3'],
    ['id'=>'traversal','audio'=>'assets/../storage/media/audio/master.mp3'],
    ['id'=>'encoded-traversal','audio'=>'assets/%2e%2e/storage/media/audio/master.mp3'],
    ['id'=>'double-encoded-traversal','audio'=>'assets/%252e%252e/storage/media/audio/master.mp3'],
    ['id'=>'symlink-file','audio'=>'assets/audio/link.mp3'],
    ['id'=>'symlink-dir','audio'=>'uploads/audio/alias/master.mp3'],
    ['id'=>'legacy-query','audio'=>'assets/audio/public.mp3?token=SECRET_LOCAL_TOKEN'],
    ['id'=>'missing-file','audio'=>'assets/audio/missing.mp3'],
    ['id'=>'missing-source'],
    ['id'=>'wrong-format','audio'=>'uploads/audio/source.wav'],
    ['id'=>'private-track','audio'=>'api/media.php?id=MPUBLIC','public_visible'=>false],
    ['id'=>'draft-track','audio'=>'api/media.php?id=MPUBLIC','state'=>'draft'],
    ['id'=>'hidden-release-track','audio'=>'api/media.php?id=MPUBLIC','release_id'=>'hidden-release'],
    ['id'=>'hidden-legacy-release-track','audio'=>'assets/audio/public.mp3','release_id'=>'','release'=>'Hidden release'],
    ['id'=>'standalone','audio'=>'assets/audio/public.mp3','release_id'=>'','release'=>'Unreleased'],
];
foreach($cases as $case)$catalog[]=$case+['title'=>'Song '.$case['id'],'release_id'=>'main','duration'=>123,'private_note'=>'SECRET_CATALOG_METADATA'];
$ordered=array_reverse(array_column($cases,'id'));
$releases=[['id'=>'bulk','title'=>'Bulk release','track_ids'=>$bulk,'release_date'=>'2050-01-01'],
    ['id'=>'main','title'=>'Requested release','track_ids'=>$ordered,'release_date'=>'2001-01-01','private_notes'=>'SECRET_RELEASE_METADATA'],
    ['id'=>'hidden-release','title'=>'Hidden release','track_ids'=>['hidden-release-track'],'public_visible'=>false]];
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require $root.'/api/canvas-audio-core.php';
$db=sf_db();$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');sf_media_ensure_schema();
function asset(string $uuid,string $stored):int{
    global $db,$bytes;
    $q=$db->prepare("INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,created_at,updated_at) VALUES(?,'audio','audio/mpeg','mp3','private-original-name.mp3',?,?,?,'now','now')");
    $q->execute([$uuid,$stored,hash('sha256',$uuid),(string)strlen($bytes)]);return (int)$db->lastInsertId();
}
function linkAudio(int $asset,string $track,string $role='primary_audio',bool $public=true):void{
    global $db;
    $db->prepare("INSERT INTO media_links(asset_id,entity_type,entity_id,role,public_visible,created_at,updated_at) VALUES(?,'track',?,?,?,'now','now')")->execute([$asset,$track,$role,$public?1:0]);
}
$public=asset('MPUBLIC','storage/media/audio/public.mp3');linkAudio($public,'managed');
$master=asset('MMASTER','storage/media/audio/master.mp3');linkAudio($master,'private-master');linkAudio($master,'private-master','master_audio',false);
$alternate=asset('MALTERNATE','storage/media/audio/public.mp3');linkAudio($alternate,'nonprimary','alternate_audio');
$wrongTrack=asset('MWRONGTRACK','storage/media/audio/public.mp3');linkAudio($wrongTrack,'another-song');
$wrongFile=asset('MTRAVERSAL','storage/media/../../assets/audio/public.mp3');linkAudio($wrongFile,'wrong-file');
$checks=0;
function check(bool $condition,string $label):void{
    global $checks;
    if(!$condition)throw new RuntimeException($label);
    $checks++;echo 'PASS '.$label.PHP_EOL;
}
foreach(['managed','legacy-assets','legacy-uploads','encoded-title','root-relative','standalone'] as $id){
    $source=sf_canvas_audio_source($id);
    check($source!==null&&file_get_contents($source['path'])===$bytes&&$source['mime']==='audio/mpeg'&&$source['size']===strlen($bytes),
        $id.' resolves the exact current public MP3');
}
foreach(['remote','remote-relative','private-master','nonprimary','wrong-track','wrong-file','download-query','private-storage','traversal','encoded-traversal','double-encoded-traversal','symlink-file','symlink-dir','legacy-query','missing-file','missing-source','wrong-format','private-track','draft-track','hidden-release-track','hidden-legacy-release-track','unknown'] as $id){
    check(sf_canvas_audio_source($id)===null,$id.' cannot expose MP3 bytes through the canvas API');
}
$data=sf_canvas_audio_catalog('main');$ids=array_column($data['tracks'],'track_id');
$expected=array_values(array_filter($ordered,static fn(string $id):bool=>!in_array($id,['private-track','draft-track','hidden-release-track','hidden-legacy-release-track'],true)));
check($ids===$expected&&$data['releases'][0]['track_ids']===$expected,
    'a requested release beyond 135 unrelated songs retains its complete public order');
$map=array_column($data['tracks'],null,'track_id');
check($map['managed']['audio_url']==='api/canvas-audio.php?action=source&track_id=managed'&&
    $map['managed']['source_url']===$map['managed']['audio_url']&&$map['managed']['source_available']===true&&
    $map['managed']['release_ids']===['main'],'available metadata exposes only a track-authorized source URL');
check($map['remote']['source_available']===false&&$map['remote']['audio_url']===null&&$map['remote']['source_reason']==='remote_source'&&
    $map['wrong-format']['source_reason']==='unsupported_source','unsupported and remote sources remain explicit metadata without raw URLs');
$serialized=json_encode($data,JSON_THROW_ON_ERROR);
check(!str_contains($serialized,'SECRET_')&&!str_contains($serialized,'storage/media')&&!str_contains($serialized,'MPUBLIC')&&
    !str_contains($serialized,'private-original-name')&&!str_contains($serialized,'example.invalid'),
    'public JSON does not serialize raw catalog URLs, source paths or private metadata');
check(count(sf_canvas_audio_catalog()['tracks'])===135+count($expected)&&
    sf_canvas_audio_catalog('not-real')===['tracks'=>[],'releases'=>[]],
    'catalog access has no sample-song cap and unknown releases remain empty');
$one=sf_canvas_audio_catalog(null,'managed');
check(count($one['tracks'])===1&&$one['tracks'][0]['track_id']==='managed'&&$one['releases'][0]['release_id']==='main',
    'track filtering resolves the real song and associated public release');
$db->prepare("UPDATE media_links SET public_visible=0 WHERE asset_id=?")->execute([$public]);
check(sf_canvas_audio_source('managed')===null&&sf_canvas_audio_catalog(null,'managed')['tracks'][0]['source_available']===false,
    'source authorization rechecks public attachment revocation');
$db->prepare("UPDATE media_links SET public_visible=1 WHERE asset_id=?")->execute([$public]);
$releases[1]['public_visible']=false;
file_put_contents($fixture.'/data/releases.json',json_encode($releases,JSON_THROW_ON_ERROR));
check(sf_canvas_audio_source('managed')===null&&sf_canvas_audio_catalog('main')===['tracks'=>[],'releases'=>[]],
    'release visibility revocation closes both metadata and source access');
check(sf_canvas_audio_id(str_repeat('a',180))===str_repeat('a',180)&&sf_canvas_audio_id(str_repeat('a',181))===null&&
    sf_canvas_audio_id('not/a/catalog/id')===null&&sf_canvas_audio_id('not a catalog id')===null,
    'Audio API IDs use the same opaque canonical format and 180-character bound as the SDK');
echo 'Canvas audio core: '.$checks.' checks passed'.PHP_EOL;
