<?php
declare(strict_types=1);

// Actual project, generation, history and gallery paths share one disposable catalog/database.
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/sf-playlists-integration-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/audio','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }rmdir($fixture);
});
$config=getenv('SF_TEST_PLAYLIST_DB_CONFIG');$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/assets/audio/public.mp3',"ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat('A',413),8));
file_put_contents($fixture.'/data/catalog.json',json_encode([
    ['id'=>'song-a','title'=>'Song A','duration'=>120,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'song-b','title'=>'Song B','duration'=>240,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'song-c','title'=>'Song C','duration'=>180,'audio'=>'assets/audio/public.mp3'],
    ['id'=>'secret-track','title'=>'DO_NOT_DISCLOSE_PRIVATE_SONG','public_visible'=>false,'audio'=>'assets/audio/public.mp3']
],JSON_THROW_ON_ERROR));
foreach(['releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);$calls=0;$prompt=[];$system='';$checks=0;
function sf_openai_call(array $provider,string $instructions,array $history,string $message,int $maxOutput,int $timeout=0):array {
    $GLOBALS['calls']++;$GLOBALS['system']=$instructions;$GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    if(is_callable($GLOBALS['onPlaylistModel']??null))($GLOBALS['onPlaylistModel'])();
    return ['text'=>json_encode(['title'=>'Authored geometry','html'=>'<section class="scene"></section>','css'=>'.scene{background:#111;color:#ddd}']),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
function playlist_check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;}
function playlist_reject(callable $run,string $message,string $class=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){if($error instanceof $class){playlist_check(true,$message);return;}throw $error;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function playlist_input(array $item):array {return ['items'=>[['ref'=>$item['ref'],'version'=>$item['revision_hash']]]];}
function playlist_item(int $uid,string $ref):array {foreach(sf_canvas_playlists_inventory($uid) as $item)if($item['ref']===$ref)return $item;throw new RuntimeException('Missing inventory '.$ref);}
function playlist_scene(array $inputs,array $manifest,?array $direct):array {
    return ['canvas_kind'=>'custom','track_id'=>'','title'=>'Resource composition','html'=>'<section class="scene"></section>','css'=>'.scene{color:#abc}',
        'music_inputs'=>sf_canvas_playlists_effective_music($direct,$manifest),'direct_music_inputs'=>$direct,'playlist_inputs'=>$inputs,'playlist_manifest'=>$manifest];
}
function playlist_gallery_request(int $scene,string $id):array {return ['scene_id'=>$scene,'request_id'=>$id,'title'=>'Public composition','description'=>'A coded composition.',
    'creator_label'=>'Fixture maker','publication_consent'=>true];}
require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';require_once $root.'/api/agent-scene-core.php';
require_once $root.'/api/canvas-gallery-core.php';require_once $root.'/api/canvas-project-ideas.php';
$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(16),status VARCHAR(16),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB':
    'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');foreach([1,2,9] as $uid)$q->execute([$uid,'playlist'.$uid.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),$uid===9?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);sf_token_adjust(1,100000,'admin_grant','playlist-integration');
$user=['id'=>1,'role'=>'user'];$admin=['id'=>9,'role'=>'admin'];sf_canvas_playlists_ensure_schema();

playlist_check(sf_agent_canvas_music_selection('Compose {music-clear}')===['Compose',['mode'=>'selected','track_ids'=>[]],true],
    'An explicit direct-music clear is distinct from an omitted selection');
playlist_reject(fn()=>sf_agent_canvas_music_selection('{music-clear} {music-track:song-a}'),'Conflicting clear and direct song bindings are rejected');
playlist_check(sf_agent_canvas_music_catalog(['mode'=>'selected','track_ids'=>[]])===['tracks'=>[],'releases'=>[]],
    'An empty selected scope cannot fall back to the entire MP3 catalog');
$own=sf_playlist_create(1,'PRIVATE_PLAYLIST_TITLE','PRIVATE_DESCRIPTION','private','user',['song-a','song-b','song-a']);
$db->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)')->execute([$own['id'],'secret-track',3,gmdate('c')]);
$inventory=playlist_item(1,'own-'.$own['id']);$inputs=playlist_input($inventory);$compiled=sf_canvas_playlists_compile(1,$inputs);$manifest=$compiled['manifest'];
$brief='Compose independent shapes {playlist:'.$inventory['ref'].'@'.$inventory['revision_hash'].'}';
$normalized=sf_canvas_project_normalize(['brief'=>$brief]);
playlist_check($normalized['brief']==='Compose independent shapes'&&$normalized['playlist_inputs']===$inputs,
    'Project normalization strips versioned playlist bindings before the design budget');
$long=str_repeat('{playlist:'.$inventory['ref'].'@'.$inventory['revision_hash'].'} ',30).'Compose geometry';
playlist_check(sf_canvas_project_normalize(['brief'=>$long])['brief']==='Compose geometry','Resource bindings do not consume the natural-language brief budget');
$direct=['mode'=>'selected','track_ids'=>['song-b','song-c']];$before=sf_token_balance(1);
$proposal=sf_canvas_project_propose($user,['project'=>['brief'=>$brief,'music_inputs'=>$direct]]);
playlist_check($proposal['project']['playlist_manifest']===$manifest&&$proposal['project']['music_inputs']===$direct&&$calls===0&&sf_token_balance(1)===$before,
    'Proposal freezes the exact server manifest while preserving direct resources without a model call');
$unapproved=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>str_repeat('f1',16)];
playlist_reject(fn()=>sf_agent_scene_create($user,$unapproved),'An unapproved playlist-bearing project cannot begin generation',SfCanvasProjectApprovalRequired::class);
playlist_check($calls===0&&sf_token_balance(1)===$before&&sf_canvas_project_get(1,$proposal['id'])['status']==='awaiting_approval',
    'A rejected unapproved playlist build leaves model calls, balance and approval state unchanged');
playlist_reject(fn()=>sf_canvas_project_propose(['id'=>2,'role'=>'user'],['project'=>['brief'=>'Compose','playlist_inputs'=>$inputs]]),
    'Another account cannot propose a build with a private playlist reference');
$forged=$manifest;$forged['playlists'][0]['items'][0]['title']='Forged song';
playlist_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Compose','playlist_inputs'=>$inputs,'playlist_manifest'=>$forged]]),
    'Client-supplied manifests cannot replace the current authorized projection');
sf_canvas_project_approve(1,$proposal['id']);
$request=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>str_repeat('a1',16)];
$built=sf_agent_scene_create($user,$request);$scene=$built['scene'];
playlist_check($calls===1&&$scene['direct_music_inputs']===$direct&&$scene['music_inputs']===['mode'=>'selected','track_ids'=>['song-b','song-c','song-a']],
    'Generation unions MP3 authorization while retaining separate direct provenance');
playlist_check($prompt['selected_playlists']===$manifest&&count($prompt['selected_playlists']['playlists'][0]['items'])===4&&
    !str_contains(json_encode($prompt['selected_playlists']),'secret-track')&&!str_contains(json_encode($prompt['selected_playlists']),'DO_NOT_DISCLOSE'),
    'The coding model receives ordered repeats and a safe unavailable placeholder without hidden song metadata');
playlist_check(str_contains($system,'not widgets')&&str_contains($system,'StonefellowAudio.catalog().playlists')&&$scene['html']==='<section class="scene"></section>',
    'Playlist resources prescribe no player template or extra generated controls');
$stored=sf_agent_scene_history_get(1,$scene['id']);
playlist_check($stored['playlist_manifest']===$manifest&&$stored['playlist_inputs']===$inputs&&$stored['direct_music_inputs']===$direct,
    'Scene history round-trips exact ordered membership and direct provenance');
$runtime=sf_canvas_audio_runtime_context($scene['id'],null,$user);$runtimeCatalog=sf_canvas_audio_runtime_catalog($runtime);
playlist_check($runtimeCatalog['playlists']===$manifest['playlists']&&array_column($runtimeCatalog['tracks'],'track_id')===['song-b','song-c','song-a']&&
    str_contains($runtimeCatalog['tracks'][0]['source_url'],'scene_id='.$scene['id']),
    'Trusted runtime context freshly resolves the same ordered manifest and contextual native source URLs');
playlist_check(sf_agent_scene_create($user,$request)['scene']['id']===$scene['id']&&$calls===1,'An approved playlist build replays without another paid call');
$fresh=sf_canvas_project_propose($user,['project'=>['brief'=>'Fresh-request composition','playlist_inputs'=>$inputs]]);sf_canvas_project_approve(1,$fresh['id']);
$child=$fixture.'/fresh-playlist-request.php';$freshRequest=$fresh['project']+['project_managed'=>true,'proposal_id'=>$fresh['id'],'request_id'=>str_repeat('f2',16)];
file_put_contents($child,'<?php define("SF_ROOT",'.var_export($fixture,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/agent-scene-core.php',true).';$result=sf_canvas_project_begin(["id"=>1,"role"=>"user"],'.var_export($freshRequest,true).');echo json_encode(["status"=>$result["status"],"transaction"=>sf_db()->inTransaction()]);');
$command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').' '.escapeshellarg($child);$output=[];$status=0;exec($command,$output,$status);
playlist_check($status===0&&json_decode(implode("\n",$output),true)===['status'=>'building','transaction'=>false]&&sf_canvas_project_get(1,$fresh['id'])['status']==='building',
    'A cold PHP worker preinitializes playlist and media helpers before its atomic approval claim');
sf_canvas_project_cancel(1,$fresh['id']);
$inherited=sf_canvas_project_bind_playlists(1,sf_canvas_project_normalize(['brief'=>'Rearrange geometry','previous_id'=>$scene['id']]));
playlist_check($inherited['playlist_inputs']===$inputs&&$inherited['playlist_manifest']===$manifest&&$inherited['music_inputs']===$direct,
    'An omitted edit inherits exact playlist membership and only the original direct music');
$explicitNull=sf_canvas_project_bind_playlists(1,sf_canvas_project_normalize(['brief'=>'Rearrange','previous_id'=>$scene['id'],'playlist_inputs'=>null]));
playlist_check($explicitNull['playlist_inputs']===$inputs,'Explicit null playlist input also inherits the saved selection');
$cleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Rearrange {playlists-clear}','previous_id'=>$scene['id'],'request_id'=>str_repeat('a2',16)])['scene'];
playlist_check($cleared['playlist_manifest']===['version'=>1,'playlists'=>[]]&&$cleared['direct_music_inputs']===$direct&&$cleared['music_inputs']===$direct,
    'Clearing a playlist removes its songs while preserving overlapping direct selections');
$directClear=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Rearrange {music-clear}','previous_id'=>$scene['id'],'request_id'=>str_repeat('a3',16)])['scene'];
playlist_check($directClear['direct_music_inputs']===['mode'=>'selected','track_ids'=>[]]&&$directClear['music_inputs']===['mode'=>'selected','track_ids'=>['song-a','song-b']]&&$directClear['playlist_manifest']===$manifest,
    'Clearing direct songs retains independent playlist resources');
$only=sf_agent_scene_history_save(1,playlist_scene($inputs,$manifest,null));
playlist_check(array_key_exists('direct_music_inputs',sf_agent_scene_history_get(1,$only['id']))&&sf_agent_scene_history_get(1,$only['id'])['direct_music_inputs']===null,
    'A new playlist-only scene preserves intentional null direct provenance across storage');
$empty=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Rearrange {playlists-clear}','previous_id'=>$only['id'],'request_id'=>str_repeat('a4',16)])['scene'];
playlist_check($empty['music_inputs']===['mode'=>'selected','track_ids'=>[]]&&$empty['direct_music_inputs']===null,
    'Clearing the only playlist produces a persistent deny-all audio scope');
$emptyRuntime=sf_canvas_audio_runtime_context($empty['id'],null,$user);
playlist_check(sf_canvas_audio_runtime_catalog($emptyRuntime)['tracks']===[]&&!sf_canvas_audio_context_allows($emptyRuntime,'song-a'),
    'Server runtime catalog and source checks preserve an explicitly empty audio scope');
$legacy=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Legacy scope','html'=>'<section></section>','css'=>'','music_inputs'=>$direct]);
playlist_check(!array_key_exists('direct_music_inputs',sf_agent_scene_history_get(1,$legacy['id'])),'Legacy scenes retain the original music field without inventing null provenance');
playlist_reject(fn()=>sf_agent_scene_history_save(1,array_replace(playlist_scene($inputs,$manifest,$direct),['music_inputs'=>['mode'=>'all']])),
    'History rejects a mismatched effective scope instead of opening unrelated songs');

$changed=sf_canvas_project_propose($user,['project'=>['brief'=>'A new arrangement','playlist_inputs'=>$inputs]]);sf_canvas_project_approve(1,$changed['id']);
$callsBefore=$calls;$balanceBefore=sf_token_balance(1);
$db->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)')->execute([$own['id'],'song-c',4,gmdate('c')]);
playlist_reject(fn()=>sf_agent_scene_create($user,$changed['project']+['project_managed'=>true,'proposal_id'=>$changed['id'],'request_id'=>str_repeat('a5',16)]),
    'Playlist membership drift invalidates the exact approved project before execution');
playlist_check($calls===$callsBefore&&sf_token_balance(1)===$balanceBefore&&sf_canvas_project_get(1,$changed['id'])['status']==='approved',
    'A stale playlist cannot consume approval, model work or Active Tokens');
playlist_reject(fn()=>sf_canvas_audio_runtime_context($scene['id'],null,$user),'A new runtime request rejects changed playlist membership',SfCanvasAudioContextUnavailable::class);
playlist_reject(fn()=>sf_canvas_project_bind_playlists(1,sf_canvas_project_normalize(['brief'=>'Revise','previous_id'=>$only['id']])),
    'An inherited old manifest cannot silently expand to newly added playlist songs');
playlist_check(sf_agent_scene_create($user,$request)['scene']['id']===$scene['id']&&$calls===$callsBefore,
    'Completed exact-request recovery returns its frozen canvas after playlist membership changes');
$freshInputs=playlist_input(playlist_item(1,'own-'.$own['id']));$balanceBefore=sf_token_balance(1);$historyBefore=count(sf_agent_scene_history_export(1));
$onPlaylistModel=static function()use($db,$own):void {$db->prepare('UPDATE user_playlists SET name=? WHERE id=?')->execute(['PRIVATE_PLAYLIST_TITLE_CHANGED_IN_FLIGHT',$own['id']]);};
playlist_reject(fn()=>sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'A composition','playlist_inputs'=>$freshInputs,'request_id'=>str_repeat('a6',16)]),
    'A source changed during inference is revalidated before saving or charging');
$onPlaylistModel=null;
playlist_check(sf_token_balance(1)===$balanceBefore&&count(sf_agent_scene_history_export(1))===$historyBefore,
    'In-flight playlist drift rolls back settlement and saves no stale private canvas');

// Private references remain private even when the playable songs are public.
sf_canvas_gallery_ensure_schema();
$galleryCount=(int)$db->query('SELECT COUNT(*) FROM canvas_gallery_entries')->fetchColumn();
playlist_reject(fn()=>sf_canvas_gallery_submit($user,playlist_gallery_request($only['id'],str_repeat('b1',16))),
    'Private playlist origins require an explicit revision before public canvas submission');
playlist_check((int)$db->query('SELECT COUNT(*) FROM canvas_gallery_entries')->fetchColumn()===$galleryCount,
    'A rejected private-origin submission leaves no public snapshot or moderation entry');
$artistSource=sf_playlist_create(2,'Artist sequence','Published album-side sequence','public','user',['song-b','song-a','song-b']);
$review=null;foreach(sf_canvas_playlists_admin_inventory($admin) as $item)if($item['ref']==='artist-'.$artistSource['id'])$review=$item;
sf_canvas_playlists_moderate($admin,$artistSource['id'],'publish',$review['revision_hash']);
$artist=playlist_item(0,'artist-'.$artistSource['id']);$artistInputs=playlist_input($artist);$artistManifest=sf_canvas_playlists_compile(0,$artistInputs)['manifest'];
$artistScene=sf_agent_scene_history_save(1,playlist_scene($artistInputs,$artistManifest,null));
$submitted=sf_canvas_gallery_submit($user,playlist_gallery_request($artistScene['id'],str_repeat('b2',16)));
sf_canvas_gallery_moderate($admin,$submitted['id'],'approve',$submitted['snapshot_hash']);$open=sf_canvas_gallery_open($submitted['id']);
playlist_check($open['scene']['playlist_manifest']===$artistManifest&&$open['scene']['direct_music_inputs']===null&&
    !str_contains(json_encode($open),'PRIVATE_PLAYLIST_TITLE')&&!str_contains(json_encode($open),'own-')&&!str_contains(json_encode($open),'user_id'),
    'Public canvases preserve approved artist order without publisher-private playlist or account identity');
playlist_check(sf_canvas_audio_runtime_catalog(sf_canvas_audio_runtime_context(null,$submitted['id'],null))['playlists']===$artistManifest['playlists'],
    'Guest runtime context receives only the exact approved artist manifest');
$remix=sf_canvas_gallery_remix($user,$submitted['id'],str_repeat('b3',16));
playlist_check($remix['scene']['playlist_inputs']===$artistInputs&&$remix['scene']['playlist_manifest']===$artistManifest&&$remix['scene']['direct_music_inputs']===null,
    'Remix keeps exact artist membership separate from direct music in a new owned scene');
sf_canvas_playlists_moderate($admin,$artistSource['id'],'withdraw',$artist['revision_hash']);
playlist_reject(fn()=>sf_canvas_audio_runtime_context(null,$submitted['id'],null),'A new runtime request rejects withdrawn artist publication',SfCanvasAudioContextUnavailable::class);
playlist_check(sf_canvas_gallery_get($submitted['id'])===null&&sf_canvas_gallery_list()['total']===0,'Withdrawing an artist playlist hides canvases that depend on that exact publication');
playlist_reject(fn()=>sf_canvas_gallery_open($submitted['id']),'A public open cannot serve a withdrawn artist playlist',SfCanvasGalleryNotFound::class);
playlist_reject(fn()=>sf_canvas_gallery_remix($user,$submitted['id'],str_repeat('b4',16)),'A new remix cannot bypass artist publication withdrawal',SfCanvasGalleryNotFound::class);
playlist_check(sf_agent_scene_history_get(1,$remix['scene']['id'])['playlist_manifest']===$artistManifest,'Already-owned remix history remains an exact private copy after withdrawal');

// Planning/discussion must carry the same resources through the settlement closure.
$latest=playlist_item(1,'own-'.$own['id']);$latestInputs=playlist_input($latest);$ideaContext=[];
$infer=static function(array $provider,string $instructions,string $message,int $maxOutput)use(&$ideaContext):array {
    $ideaContext=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    return ['text'=>json_encode(['reply'=>'A new geometry concept.','proposal'=>['title'=>'Ordered motion','concept'=>'Independent forms arranged in sequence.','brief'=>'Arrange each song as an independent shape.','source_scene_ids'=>[]]]),
        'provider'=>'openai','model'=>'fixture','input_tokens'=>3,'output_tokens'=>7];
};
$discussion=sf_canvas_project_ideas_discuss($user,['brief'=>'Suggest a composition','playlist_inputs'=>$latestInputs,'request_id'=>str_repeat('c1',16)],$infer,[['provider'=>'openai','model'=>'fixture','api_key'=>'fixture']]);
playlist_check($discussion['proposal']['project']['playlist_inputs']===$latestInputs&&$discussion['proposal']['project']['playlist_manifest']===$ideaContext['references']['selected_playlists'],
    'A discussed idea freezes selected playlists into its proposed project after token settlement');
playlist_reject(fn()=>sf_canvas_project_idea_validate(['title'=>'Invented selection','concept'=>'New resources.','brief'=>'Compose {playlist:'.$latest['ref'].'@'.$latest['revision_hash'].'}'],[]),
    'Model-generated ideas cannot add unselected playlist bindings',UnexpectedValueException::class);
sf_canvas_project_preferences_save(1,['ideas_enabled'=>false]);
$plan=sf_canvas_project_ideas_plan($user,['project'=>['brief'=>'Move each shape {music-clear}','previous_id'=>$cleared['id'],'playlist_inputs'=>['items'=>[]]],'request_id'=>str_repeat('c2',16)]);
playlist_check($plan['proposal']['project']['playlist_inputs']===['items'=>[]]&&$plan['proposal']['project']['music_inputs']===['mode'=>'selected','track_ids'=>[]],
    'Planning preserves independent explicit clears for direct music and playlists');
$coldPlan=['project'=>['brief'=>'Arrange a quiet composition','previous_id'=>$cleared['id'],'playlist_inputs'=>['items'=>[]]],'request_id'=>str_repeat('c3',16)];
file_put_contents($child,'<?php define("SF_ROOT",'.var_export($fixture,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/canvas-project-ideas.php',true).';$result=sf_canvas_project_ideas_plan(["id"=>1,"role"=>"user"],'.var_export($coldPlan,true).');echo json_encode(["state"=>$result["state"],"transaction"=>sf_db()->inTransaction()]);');
$output=[];$status=0;exec($command,$output,$status);
playlist_check($status===0&&json_decode(implode("\n",$output),true)===['state'=>'ready','transaction'=>false],
    'Cold no-provider planning never runs legacy token schema DDL inside its settlement transaction');
$export=sf_agent_scene_history_export(1);
playlist_check(str_contains(json_encode($export),'PRIVATE_PLAYLIST_TITLE')&&sf_agent_scene_history_export(2)===[],
    'Private playlist metadata is available in its owner history export without crossing account boundaries');
sf_privacy_clear_agent_history(1);
playlist_check(sf_agent_scene_history_export(1)===[]&&sf_canvas_project_list(1)===[]&&sf_canvas_gallery_export(1)===[],
    'Clearing agent history removes playlist-bearing scenes, proposals and owned gallery snapshots');
playlist_reject(fn()=>sf_canvas_audio_runtime_context($scene['id'],null,$user),'A privacy-cleared scene grants no future runtime audio access',SfCanvasAudioContextUnavailable::class);
playlist_check(count(sf_canvas_playlists_inventory(1))===1,'Canvas history clear preserves the independently managed user playlist');
playlist_check(!$db->inTransaction(),'All rejected and successful playlist workflows leave transactions settled');
echo 'Playlist integration: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
