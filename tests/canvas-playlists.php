<?php
declare(strict_types=1);

$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-canvas-playlists-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/audio'] as $directory)mkdir($fixture.'/'.$directory,0700,true);
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_PLAYLIST_DB_CONFIG');$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
$mp3="ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat('A',413),8);
file_put_contents($fixture.'/assets/audio/public.mp3',$mp3);
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'song-a','title'=>'Published A','duration'=>120,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'song-b','title'=>'Published B','duration'=>240,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'song-remote','title'=>'Public remote song','duration'=>30,'audio'=>'https://example.invalid/no.mp3'],
    ['id'=>'private-song-id','title'=>'Private forbidden song title','public_visible'=>false,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'hidden-release-song','title'=>'Private release title','release_id'=>'hidden-release','audio'=>'assets/audio/public.mp3']
],JSON_THROW_ON_ERROR));
file_put_contents($fixture.'/data/releases.json',json_encode([['id'=>'hidden-release','title'=>'Private release','state'=>'draft','track_ids'=>['hidden-release-song']]]));
file_put_contents($fixture.'/data/shows.json','[]');
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';require_once $root.'/api/canvas-playlists-core.php';
$checks=0;
function verify(bool $value,string $message): void {global $checks;if(!$value)throw new RuntimeException($message);$checks++;echo 'PASS '.$message.PHP_EOL;}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException|DomainException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function by_ref(array $items,string $ref): ?array {foreach($items as $item)if($item['id']===$ref)return $item;return null;}
function selection(array $item): array {return ['items'=>[['ref'=>$item['ref'],'version'=>$item['revision_hash']]]];}

$hash=str_repeat('a',64);$input=['items'=>[['ref'=>'own-17','version'=>$hash],['ref'=>'artist-42','version'=>$hash],['ref'=>'own-17','version'=>$hash]]];
verify(sf_canvas_playlists_inputs($input)===['items'=>array_slice($input['items'],0,2)]&&!function_exists('sf_canvas_audio_catalog'),
    'Pure versioned selection normalization preserves order and avoids catalog loading');
verify(sf_canvas_playlists_inputs(null)===null&&sf_canvas_playlists_inputs(['items'=>[]])===['items'=>[]],
    'Omitted input and explicitly empty playlist scope remain distinct');
foreach([['playlist_ids'=>['own-1']],['items'=>[['ref'=>'own-01','version'=>$hash]]],['items'=>[['ref'=>'own-1','version'=>'bad']]],
    ['items'=>[['ref'=>'own-1','version'=>$hash,'user_id'=>2]]],['items'=>[['ref'=>'own-1','version'=>$hash],['ref'=>'own-1','version'=>str_repeat('b',64)]]],
    ['items'=>[['ref'=>'artist-92233720368547758080','version'=>$hash]]]] as $invalid)
    rejects(fn()=>sf_canvas_playlists_inputs($invalid),'Invalid, conflicting or authority-bearing selections are rejected');
[$brief,$parsed,$provided]=sf_canvas_playlists_selection('Build `{playlist:own-17@'.$hash.'}` {music-track:song-a}.');
verify($provided&&$parsed===['items'=>[$input['items'][0]]]&&!str_contains($brief,'playlist:')&&str_contains($brief,'{music-track:song-a}'),
    'Playlist tokens leave independent music resources and design prose intact');
verify(sf_canvas_playlists_selection('Design {playlists-clear}')===['Design',['items'=>[]],true]&&
    sf_canvas_playlists_selection('Design',null,true)===['Design',null,true],
    'Clear and explicit-null tokens keep their request presence for revision inheritance');
rejects(fn()=>sf_canvas_playlists_selection('Design {playlists-clear} {playlist:own-17@'.$hash.'}'),'Clear cannot conflict with a selected playlist');
rejects(fn()=>sf_canvas_playlists_selection('Design {playlist:own-17@'.$hash.'}',['items'=>[]],true),'Stale tokens cannot replace an explicit cleared picker selection');
rejects(fn()=>sf_canvas_playlists_selection('Design {playlist:own-17}'),'Unversioned playlist tokens cannot bypass snapshot review');

$db=sf_db();$mysql=($config['driver']??'')==='mysql';
$db->exec($mysql?"CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,role VARCHAR(16) NOT NULL,status VARCHAR(16) NOT NULL) ENGINE=InnoDB":'CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT NOT NULL,status TEXT NOT NULL)');
$db->exec("INSERT INTO users VALUES(1,'user','active'),(2,'user','active'),(9,'admin','active'),(10,'user','suspended')");
sf_canvas_playlists_ensure_schema();
$owner=sf_playlist_create(1,'Own private collection','Private description','private','user',['song-a','song-b','song-a']);
$public=sf_playlist_create(2,'Public but not artist','Public source','public','user',['song-b','song-a']);
$foreign=sf_playlist_create(2,'Foreign private title','Foreign private description','private','user',['song-a']);
$disabled=sf_playlist_create(10,'Suspended owner list','','public','user',['song-a']);
$id=$owner['id'];$publicId=$public['id'];$foreignId=$foreign['id'];$admin=['id'=>9,'role'=>'admin'];
$insert=$db->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)');
for($position=3;$position<263;$position++)$insert->execute([$id,$position===260?'private-song-id':($position===261?'missing-secret-song':($position===262?'hidden-release-song':'song-a')),$position,gmdate('c')]);
$insert->execute([$id,'song-remote',263,gmdate('c')]);
$inventory=sf_canvas_playlists_inventory(1);$own=by_ref($inventory,'own-'.$id);
verify(count($inventory)===1&&$own['track_count']===264&&count($own['items'])===264,
    'Owned inventory includes complete membership beyond 200 entries without unrelated public or foreign lists');
verify($own['items'][0]['track_id']==='song-a'&&$own['items'][2]['track_id']==='song-a'&&$own['items'][2]['item_id']!==$own['items'][0]['item_id'],
    'Repeated songs remain independent ordered playlist entries');
verify($own['available_count']===260&&$own['items'][260]['position']===260&&$own['items'][260]['track_id']===null&&
    $own['items'][260]['title']==='Unavailable track'&&$own['items'][263]['track_id']==='song-remote'&&!$own['items'][263]['available'],
    'Unavailable positions remain explicit while public non-hosted sources keep only public metadata');
$json=json_encode($own,JSON_THROW_ON_ERROR);
verify(!str_contains($json,'private-song-id')&&!str_contains($json,'missing-secret-song')&&!str_contains($json,'hidden-release-song')&&
    !str_contains($json,'Private forbidden')&&!str_contains($json,'Private release')&&!str_contains($json,'assets/audio'),
    'Public DTOs omit hidden identity, hidden release metadata and storage paths');
verify(sf_canvas_playlists_inventory(0)===[],'Guests do not receive merely public user playlists');
rejects(fn()=>sf_canvas_playlists_inventory(10),'Inactive accounts cannot retrieve owned playlist inventory');
$compiled=sf_canvas_playlists_compile(1,selection($own));$manifest=$compiled['manifest'];
verify(count($manifest['playlists'][0]['items'])===264&&$compiled['track_ids']===['song-a','song-b'],
    'Compiler preserves all ordered entries while deduplicating only canonical audio authorization');
verify(sf_canvas_playlists_compile(1,selection($own),$manifest)===$compiled,'An exact previously approved manifest is accepted');
rejects(fn()=>sf_canvas_playlists_compile(2,selection($own)),'Another signed-in account cannot reuse owned playlist references');
rejects(fn()=>sf_canvas_playlists_compile(0,selection($own)),'Guests cannot compile private owned playlist references');
verify(sf_canvas_playlists_effective_music(['mode'=>'selected','track_ids'=>['song-b']],$manifest)===['mode'=>'selected','track_ids'=>['song-b','song-a']],
    'Effective audio unions direct songs with playlist resources without destroying direct provenance');
verify(sf_canvas_playlists_effective_music(['mode'=>'all'],$manifest)===['mode'=>'all']&&sf_canvas_playlists_effective_music(null,null)===null,
    'Explicit all mode remains intentional and legacy omitted scopes remain compatible');
$empty=sf_canvas_playlists_compile(1,['items'=>[]]);
verify($empty===['manifest'=>['version'=>1,'playlists'=>[]],'track_ids'=>[]]&&
    sf_canvas_playlists_effective_music(null,$empty['manifest'])===['mode'=>'selected','track_ids'=>[]],
    'Clearing playlists without direct music denies all audio instead of opening the entire catalog');
verify(sf_canvas_playlists_effective_music(['mode'=>'selected','track_ids'=>['song-b']],$empty['manifest'])===['mode'=>'selected','track_ids'=>['song-b']],
    'Clearing a playlist retains directly selected overlapping songs');
$bad=$manifest;$bad['playlists'][0]['items'][260]['title']='Invented hidden title';
rejects(fn()=>sf_canvas_playlists_manifest_validate($bad),'Hidden-entry projection cannot carry arbitrary private metadata');
$bad=$manifest;$bad['playlists'][0]['items']=array_reverse($bad['playlists'][0]['items']);
rejects(fn()=>sf_canvas_playlists_manifest_validate($bad),'Persisted entry order is validated rather than silently resorted');
$bad=$manifest;$bad['playlists'][0]['items'][0]['title']='Forged public title';
rejects(fn()=>sf_canvas_playlists_compile(1,selection($own),$bad),'A client-supplied fabricated manifest must exactly match the authorized server projection');

$review=by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$publicId);
verify($review!==null&&$review['can_publish']&&!$review['published']&&by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$foreignId)===null,
    'Administrator publication candidates include public sources without revealing unsubmitted foreign private playlists');
rejects(fn()=>sf_canvas_playlists_moderate(['id'=>1,'role'=>'admin'],$publicId,'publish',$review['revision_hash']),
    'Forged admin role claims cannot override the current account role');
rejects(fn()=>sf_canvas_playlists_moderate($admin,$foreignId,'publish',sf_canvas_playlists_public_version(sf_canvas_playlists_hash(sf_canvas_playlists_raw(sf_playlist_row($foreignId))),0)),
    'Administrative publication cannot turn a foreign private playlist into artist data');
rejects(fn()=>sf_canvas_playlists_moderate($admin,$publicId,'publish',str_repeat('0',64)),'Publication compares the exact reviewed revision hash');
$published=sf_canvas_playlists_moderate($admin,$publicId,'publish',$review['revision_hash']);
$artist=by_ref(sf_canvas_playlists_inventory(0),'artist-'.$publicId);
verify($published['status']==='published'&&$artist!==null&&count(sf_canvas_playlists_inventory(1))===2,
    'Explicit admin publication makes an artist playlist available to guests and signed-in users');
verify(sf_canvas_playlists_compile(0,selection($artist))['track_ids']===['song-b','song-a'],
    'Published artist songs compile without borrowing the publisher account identity');
$db->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['Renamed in the same second',$publicId]);
verify(by_ref(sf_canvas_playlists_inventory(0),'artist-'.$publicId)===null,
    'A name edit invalidates artist publication even when updated_at did not change');
rejects(fn()=>sf_canvas_playlists_compile(1,selection($artist)),'An artist reference cannot keep using a changed published source');
rejects(fn()=>sf_canvas_playlists_moderate($admin,$publicId,'publish',$artist['revision_hash']),'Stale admin review cannot approve a newer name or membership');
$review=by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$publicId);
sf_canvas_playlists_moderate($admin,$publicId,'publish',$review['revision_hash']);
$artist=by_ref(sf_canvas_playlists_inventory(0),'artist-'.$publicId);
$db->prepare('UPDATE user_playlists SET visibility=?,name=?,description=? WHERE id=?')->execute(['private','New private title','New private description',$publicId]);
$privateReview=by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$publicId);
verify($privateReview!==null&&!$privateReview['source_available']&&!$privateReview['can_publish']&&$privateReview['title']==='Unavailable playlist'&&
    $privateReview['description']===''&&$privateReview['items']===[]&&!str_contains(json_encode($privateReview),'New private'),
    'A former publication that becomes foreign private exposes only a generic withdrawal control');
verify(sf_canvas_playlists_inventory(0)===[],'Making a published source private withdraws it from public discovery');
rejects(fn()=>sf_canvas_playlists_compile(0,selection($artist)),'Existing artist references are rechecked after privacy withdrawal');
sf_canvas_playlists_moderate($admin,$publicId,'withdraw',$privateReview['revision_hash']);
$db->prepare('UPDATE user_playlists SET visibility=? WHERE id=?')->execute(['public',$publicId]);
verify(sf_canvas_playlists_inventory(0)===[],'Explicit withdrawal persists when the source is made public again');
$review=by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$publicId);sf_canvas_playlists_moderate($admin,$publicId,'publish',$review['revision_hash']);
$artist=by_ref(sf_canvas_playlists_inventory(0),'artist-'.$publicId);
$beforeEpoch=$artist;
sf_canvas_playlists_moderate($admin,$publicId,'withdraw',$artist['revision_hash']);
$withdrawnReview=by_ref(sf_canvas_playlists_admin_inventory($admin),'artist-'.$publicId);
sf_canvas_playlists_moderate($admin,$publicId,'publish',$withdrawnReview['revision_hash']);
$artist=by_ref(sf_canvas_playlists_inventory(0),'artist-'.$publicId);
verify($beforeEpoch['revision_hash']!==$artist['revision_hash'],'Same-content same-second republication receives a new monotonic publication version');
rejects(fn()=>sf_canvas_playlists_compile(0,selection($beforeEpoch)),'Withdraw and republish cannot revive an older project resource approval');
rejects(fn()=>sf_canvas_playlists_moderate($admin,$publicId,'withdraw',$beforeEpoch['revision_hash']),'Publication CAS rejects an old token after an ABA status transition');
$db->exec("UPDATE users SET status='suspended' WHERE id=2");
verify(sf_canvas_playlists_inventory(0)===[],'Artist discovery stops when the source owner account is inactive');
rejects(fn()=>sf_canvas_playlists_compile(1,selection($artist)),'Saved artist references cannot bypass account suspension');
$db->exec("UPDATE users SET status='active' WHERE id=2");
$db->exec("UPDATE users SET role='user' WHERE id=9");
rejects(fn()=>sf_canvas_playlists_moderate($admin,$publicId,'withdraw',$artist['revision_hash']),'A revoked administrator cannot publish or withdraw through a stale session');
$db->exec("UPDATE users SET role='admin' WHERE id=9");

$db->prepare('UPDATE user_playlist_tracks SET position=? WHERE id=?')->execute([1000,$own['items'][0]['item_id']]);
$reordered=by_ref(sf_canvas_playlists_inventory(1),'own-'.$id);
verify($reordered['revision_hash']!==$own['revision_hash']&&$reordered['items'][263]['item_id']===$own['items'][0]['item_id'],
    'The revision hash binds full repeated membership order and stable entry identities');
rejects(fn()=>sf_canvas_playlists_compile(1,selection($own)),'Reordered owned playlists require an updated selection version');
$nowCompiled=sf_canvas_playlists_compile(1,selection($reordered));
unlink($fixture.'/assets/audio/public.mp3');
rejects(fn()=>sf_canvas_playlists_compile(1,selection($reordered),$nowCompiled['manifest']),
    'An approved build detects source availability drift before execution');
$unavailable=sf_canvas_playlists_compile(1,selection($reordered));
verify($unavailable['track_ids']===[]&&count($unavailable['manifest']['playlists'][0]['items'])===264&&
    sf_canvas_playlists_effective_music(null,$unavailable['manifest'])===['mode'=>'selected','track_ids'=>[]],
    'An entirely unavailable playlist still describes its entries and never authorizes unrelated MP3s');
$db->prepare('UPDATE user_playlists SET user_id=? WHERE id=?')->execute([2,$id]);
rejects(fn()=>sf_canvas_playlists_compile(1,selection($reordered)),'Ownership changes invalidate previously selected owned references');
$db->prepare('DELETE FROM user_playlists WHERE id=?')->execute([$id]);
rejects(fn()=>sf_canvas_playlists_compile(1,selection($reordered)),'Deleted playlist references fail without a foreign identity fallback');
verify(!$db->inTransaction(),'Validation failures leave no open transaction behind');
echo 'Canvas playlists: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
