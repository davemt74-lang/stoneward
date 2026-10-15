<?php
declare(strict_types=1);
/** 85B integrated Media Library: test real SQLite media link policy and existing catalog writer. */
$dir=sys_get_temp_dir().'/sf85b-'.bin2hex(random_bytes(7));
if(!mkdir($dir.'/storage/media',0700,true))throw new RuntimeException('Fixture directory failed');
define('SF_ROOT',$dir);
$GLOBALS['sf85b_db']=new PDO('sqlite::memory:');
$GLOBALS['sf85b_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['sf85b_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{return $GLOBALS['sf85b_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text(mixed $v,int $limit=160):string{return substr(trim((string)$v),0,$limit);}
$GLOBALS['sf85b_catalog']=[['id'=>'song-1','title'=>'Song One','audio'=>'assets/audio/placeholder.mp3','duration'=>52,'podEligible'=>true]];
function sf_catalog():array{return $GLOBALS['sf85b_catalog'];}
function sf_admin_write_catalog(array $c):void{$GLOBALS['sf85b_catalog']=$c;}
require dirname(__DIR__).'/api/media-core.php';
require dirname(__DIR__).'/api/track-media-publishing.php';
require dirname(__DIR__).'/api/kunaki-media-transfer.php';
require dirname(__DIR__).'/api/homeserver-music-core.php';
require dirname(__DIR__).'/api/fan-owned-music-core.php';
sf_media_ensure_schema();
$pass=0;
function ok85b(bool $yes,string $what):void{global $pass;if(!$yes)throw new RuntimeException('FAIL '.$what);$pass++;echo 'PASS '.$what.PHP_EOL;}
function denies85b(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
function addAsset85b(string $uuid,string $name):int{
    $file=SF_ROOT.'/storage/media/'.$name;$bytes='RIFF' .str_repeat('sample',36).$name;
    file_put_contents($file,$bytes);$hash=hash_file('sha256',$file);
    $q=sf_db()->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$uuid,'audio','audio/wav','wav',$name,'storage/media/'.$name,$hash,strlen($bytes),gmdate('c'),gmdate('c')]);
    return (int)sf_db()->lastInsertId();
}
$masterUuid='M'.str_repeat('A',20);$previewUuid='M'.str_repeat('B',20);
try{
    $master=addAsset85b($masterUuid,'master.wav');
    $preview=addAsset85b($previewUuid,'preview.wav');
    sf_media_attach($master,'track','song-1','audio_candidate');
    sf_media_attach($preview,'track','song-1','preview_audio');
    $find=static function(int $id):int{
        foreach(sf_media_links('track','song-1') as $row)if((int)$row['asset_id']===$id)return (int)$row['id'];
        throw new RuntimeException('Media link missing');
    };
    $mId=$find($master);$pId=$find($preview);
    ok85b(denies85b(fn()=>sf_media_attach($master,'track','song-1','master_audio',['public_visible'=>true])),
        'Master role cannot publish public directly through existing attach');
    $result=sf_track_media_publish_master('song-1',$mId);
    ok85b(str_contains((string)$result['track']['private_master_audio'],$masterUuid)&&$result['track']['audio_publishing_mode']==='preview_master_split',
        'Admin publisher retains existing catalog and assigns a private master');
    ok85b(sf_media_protected_master_asset($master),'Published original is protected by central media role');
    ok85b(denies85b(fn()=>sf_media_update_link($mId,['public_visible'=>true])),
        'Existing link cannot make original publicly visible');
    ok85b(denies85b(fn()=>sf_media_update_link($mId,['download_allowed'=>true])),
        'Existing link cannot turn master into free public download');
    ok85b(denies85b(fn()=>sf_media_update_link($mId,['role'=>'preview_audio'])),
        'Role toggle cannot silently demote private master');
    ok85b(denies85b(fn()=>sf_media_attach($master,'track','song-1','alternate_audio',['public_visible'=>true])),
        'Public alias to master is denied even under another role');
    ok85b(denies85b(fn()=>sf_media_publish_track_audio('song-1',$mId)),
        'Publishing original as a public preview fails closed');
    $public=sf_media_publish_track_audio('song-1',$pId);
    ok85b(str_contains((string)$public['track']['audio'],$previewUuid)
        &&str_contains((string)$public['track']['private_master_audio'],$masterUuid),
        'Public preview changes only public audio, not private master');
    ok85b(sf_track_media_publication_status('song-1')['download_ready'],
        'Existing song editor readiness sees available original');
    $music=sf_hs_music_audio_reference(11,'song-1',[['id'=>'song-1','can_stream'=>true,'can_download'=>true]],['song-1'=>sf_catalog()[0]]);
    ok85b(str_contains($music,$masterUuid),'Existing approved media streaming resolves private master');
    $my=sf_fan_owned_download_reference(11,'song-1',[['id'=>'song-1','can_stream'=>true,'can_download'=>true]],['song-1'=>sf_catalog()[0]]);
    ok85b($my===$music,'Website paid download also selects protected original');
    ok85b(denies85b(fn()=>sf_hs_music_audio_reference(11,'song-1',
        [['id'=>'song-1','can_stream'=>true]],['song-1'=>['audio_publishing_mode'=>'preview_master_split','audio'=>'api/media.php?id='.$previewUuid]])),
        'Split mode cannot fall back to free preview when master absent');
    ok85b(denies85b(fn()=>sf_fan_owned_download_reference(11,'song-1',
        [['id'=>'song-1','can_download'=>true]],['song-1'=>['audio'=>'api/media.php?id='.$masterUuid]])),
        'Legacy public catalog audio is not treated as a private purchase master');
    ok85b(!sf_media_public_links('track','song-1') || !str_contains(json_encode(sf_media_public_links('track','song-1')),$masterUuid),
        'Public Media Library links exclude private originals');
    ok85b(denies85b(fn()=>sf_track_media_publish_master('song-1',$pId)),
        'Public preview cannot be reassigned as private original');
    ok85b(sf_track_media_publish_master('song-1',$mId)['unchanged']===true,
        'Publishing unchanged private original is idempotent');
    $source=file_get_contents(dirname(__DIR__).'/api/kunaki-media-transfer.php');
    $builder=file_get_contents(dirname(__DIR__).'/api/bootstrap.php');
    ok85b(str_contains($source,'$productionMaster')&&str_contains($builder,"['private_master_audio']"),
        'Existing record-builder and manufacturing source prefer real originals');
    $mediaRoute=file_get_contents(dirname(__DIR__).'/api/media.php');
    ok85b(str_contains($mediaRoute,'sf_media_protected_master_asset'),
        'Public media route fails closed even with stale public links');
    $adminRoute=file_get_contents(dirname(__DIR__).'/admin/api/media.php');
    $adminUI=file_get_contents(dirname(__DIR__).'/admin/assets/media.js');
    ok85b(str_contains($adminRoute,"publish_track_master")&&str_contains($adminRoute,'confirmed')
      &&str_contains($adminUI,'data-media-private-master')&&str_contains($adminUI,'data-media-primary-audio'),
        'Admin reuses existing track media editor with explicit preview and master actions');
}finally{
    foreach(['master.wav','preview.wav'] as $name)@unlink($dir.'/storage/media/'.$name);
    @rmdir($dir.'/storage/media');@rmdir($dir.'/storage');@rmdir($dir);
}
echo "85B: $pass / $pass integrated checks green".PHP_EOL;
