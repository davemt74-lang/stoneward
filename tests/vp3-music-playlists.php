<?php
declare(strict_types=1);
/** Real SQLite guest migration and catalog consumers, without provider network access. */
$repository=dirname(__DIR__);$root=sys_get_temp_dir().'/stoneward-vp3-playlists-'.bin2hex(random_bytes(6));
foreach(['config','data','storage'] as $directory)mkdir($root.'/'.$directory,0700,true);
symlink($repository.'/api',$root.'/api');
register_shutdown_function(static function()use($root):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($root);
});
file_put_contents($root.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$root.'/fixture.sqlite'],true).';');
copy($repository.'/config/store.default.php',$root.'/config/store.default.php');
$source=['kind'=>'vp3','installation_id'=>'00000000-0000-4000-8000-000000000001',
    'site_id'=>'00000000-0000-4000-8000-000000000002','recording_id'=>'00000000-0000-4000-8000-000000000003'];
$native=['id'=>'native','title'=>'Native song','audio'=>'assets/audio/native.mp3','state'=>'published','duration'=>120];
$provider=['id'=>'provider','title'=>'VP3 song','audio'=>'','audio_source'=>$source,'audio_publishing_mode'=>'vp3_stream_only',
    'vp3_availability'=>'ready','state'=>'published','duration'=>180];
$unavailable=array_replace($provider,['id'=>'unavailable','vp3_availability'=>'temporarily_unavailable','audio'=>'assets/audio/forged-fallback.mp3']);
$malformed=array_replace($provider,['id'=>'malformed','audio'=>'assets/audio/forged-fallback.mp3',
    'audio_source'=>array_replace($source,['recording_id'=>'https://untrusted.example.com/audio.mp3'])]);
$hidden=array_replace($provider,['id'=>'hidden','state'=>'hidden']);
$catalog=[$native,$provider,$unavailable,$malformed,$hidden,['id'=>'silent','title'=>'No audio','audio'=>''],
    ['id'=>'reference-only','title'=>'Arbitrary remote descriptor','audio_source'=>['kind'=>'url','url'=>'https://untrusted.example.com/audio.mp3']]];
file_put_contents($root.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
file_put_contents($root.'/data/releases.json','[]');file_put_contents($root.'/data/shows.json','[]');
define('SF_ROOT',$root);
require $repository.'/api/bootstrap.php';require_once $repository.'/api/agent-runtime.php';
require_once $repository.'/api/agent-song-catalog-core.php';
$db=sf_db();$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,status TEXT,updated_at TEXT)');
$db->exec("INSERT INTO users VALUES(1,'First listener','active',''),(2,'Second listener','active','')");
$checks=0;
function playlist_check(bool $condition,string $message):void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;}
function playlist_denied(callable $run,string $message):void {
    try{$run();}catch(InvalidArgumentException){playlist_check(true,$message);return;}
    throw new RuntimeException('Expected refusal: '.$message);
}
playlist_check(sf_track_has_playable_source($native)&&sf_track_has_playable_source($provider),'Native and valid ready typed sources are eligible for catalog selection');
playlist_check(!sf_track_has_playable_source($unavailable)&&!sf_track_has_playable_source($malformed),'Unavailable or malformed provider references cannot fall back to a native audio field');
playlist_check(!sf_track_has_playable_source(['audio'=>'native.mp3','audio_publishing_mode'=>'vp3_stream_only'])
    &&!sf_track_has_playable_source(['audio_source'=>$source])
    &&!sf_track_has_playable_source(end($catalog)),'Missing provider identities/readiness and arbitrary remote descriptors are rejected');
playlist_check(sf_track_has_playable_source(['audio'=>'https://legacy.example.com/native.mp3']),'Existing native URL policy remains unchanged');
$published=sf_agent_public_catalog();$mix=sf_agent_random_playlist_track_ids($published,20);sort($mix,SORT_STRING);
playlist_check($mix===['native','provider'],'Agent random mix includes both native and VP3 tracks while excluding hidden or unusable references');
playlist_check(sf_song_catalog_track('provider',$published)['id']==='provider','Published song selector recognizes a typed provider recording');
try{
    sf_song_catalog_source('provider',[array_replace($provider,['audio'=>'api/media.php?id=M'.str_repeat('A',20)])],['id'=>1]);
    throw new RuntimeException('Provider extraction should be refused.');
}catch(SFSongCatalogError $error){playlist_check($error->error==='song_source_unavailable','Provider streaming cannot authorize local extraction even with a forged local-media reference');}
$payload=sf_playlist_track_payload('provider');
playlist_check($payload['audio']===''&&$payload['audio_source']===$source&&$payload['vp3_availability']==='ready','Playlist payload carries the safe typed reference without a media URL');
$catalog[1]['audio_source']['credential']='SECRET';$catalog[1]['audio_source']['provider_api']='https://untrusted.example.com/api/music-provider-v1.php';
file_put_contents($root.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));sf_catalog(true);
playlist_check(!str_contains(json_encode(sf_playlist_track_payload('provider')),'SECRET')
    &&!str_contains(json_encode(sf_playlist_track_payload('provider')),'provider_api'),'Playlist projection strips provider credentials and caller-supplied endpoint addresses');
$request=['request_id'=>'vp3-guest-import-one','playlists'=>[['name'=>'Mixed listening','track_ids'=>['native','provider']]],'recent_track_ids'=>['provider']];
$result=sf_guest_migration_import(1,$request);$saved=sf_playlist_list(1);
playlist_check($result['playlist_count']===1&&$result['recent_count']===1&&array_column($saved[0]['tracks'],'id')===['native','provider'],
    'Actual guest migration atomically saves a mixed native and VP3 playlist with provider recent preference');
playlist_check(sf_guest_migration_import(1,$request)['already_imported']&&count(sf_playlist_list(1))===1,'Exact guest import retry creates no duplicate playlist');
playlist_check(sf_playlist_list(2)===[]&&sf_guest_migration_recent_for_user(2)===[],'Guest imported playlists and preferences stay scoped to the signed-in account');
foreach(['unavailable','malformed','hidden','reference-only'] as $id){
    $bad=['request_id'=>'vp3-invalid-'.$id,'playlists'=>[['name'=>'Invalid list','track_ids'=>[$id]]]];
    playlist_denied(fn()=>sf_guest_migration_import(1,$bad),'Guest migration rejects '.$id.' song before creating a playlist');
}
playlist_check(count(sf_playlist_list(1))===1,'Rejected provider references leave the saved playlist set unchanged');
$db->prepare('INSERT INTO user_profiles(user_id,personalization_enabled,updated_at) VALUES(?,?,?)')->execute([2,0,gmdate('c')]);
playlist_denied(fn()=>sf_guest_migration_import(2,['request_id'=>'vp3-private-recents','recent_track_ids'=>['provider']]),
    'Provider recent preferences still require the listener personalization setting');
echo 'STONEWARD_VP3_MUSIC_PLAYLISTS=PASS checks='.$checks.PHP_EOL;
