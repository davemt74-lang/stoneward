<?php
declare(strict_types=1);

require_once __DIR__.'/../api/agent-canvas-music-core.php';
function verify(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $run,string $message): void {
    try{$run();}catch(InvalidArgumentException){verify(true,$message);return;}
    throw new RuntimeException('Expected rejection. '.$message);
}
verify(sf_agent_canvas_music_inputs(['mode'=>'selected','track_ids'=>['last','first','last','01','1']])===
    ['mode'=>'selected','track_ids'=>['last','first','01','1']]&&!function_exists('sf_canvas_audio_catalog'),
    'Pure music normalization preserves canonical ordered IDs without loading the audio inventory');
verify(sf_agent_canvas_music_inputs(['mode'=>'all'])===['mode'=>'all']&&sf_agent_canvas_music_inputs(null)===null,
    'All mode stays compact and explicit null clears a selection');
verify(sf_agent_canvas_music_inputs(['mode'=>'selected','track_ids'=>[]])===['mode'=>'selected','track_ids'=>[]]&&
    sf_agent_canvas_music_selection('Build {music-clear}')===['Build',['mode'=>'selected','track_ids'=>[]],true],
    'Explicit empty selection and music-clear preserve a deny-all direct resource scope');
foreach([['mode'=>'selected','track_ids'=>['../private']],['mode'=>'selected','track_ids'=>[1]],
    ['mode'=>'all','track_ids'=>[]],['mode'=>'selected','track_ids'=>['valid'],'user_id'=>2]] as $invalid)
    rejects(fn()=>sf_agent_canvas_music_inputs($invalid),'Invalid or authority-bearing music selections are rejected');
$mixed='Draw an instrument {music-track:last} {image-'.str_repeat('a',32).'} {music-track:first}';
[$description,$selection,$provided]=sf_agent_canvas_music_selection($mixed);
verify($provided&&$selection===['mode'=>'selected','track_ids'=>['last','first']]&&
    str_contains($description,'{image-')&&!str_contains($description,'music-track'),
    'Music tokens leave image references and design prose intact');
verify(sf_agent_canvas_music_selection('Build a composition (/music-all).')[1]===['mode'=>'all']&&
    sf_agent_canvas_music_selection('Use https://example.invalid/music-all?private=1')[1]===null&&
    sf_agent_canvas_music_selection('Use https://example.invalid/(/music-all).')[1]===null,
    'The raw all operator accepts punctuation boundaries without matching URL substrings');
rejects(fn()=>sf_agent_canvas_music_selection('Build {music-all} {music-track:first}'),'All and selected token modes cannot conflict');
rejects(fn()=>sf_agent_canvas_music_selection('Build {music-track:first}',['mode'=>'selected','track_ids'=>['last']],true),
    'Structured selections and composer tokens must agree');

$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-canvas-music-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/audio','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/fixture.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
$mp3="ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat('A',413),8);
file_put_contents($fixture.'/assets/audio/shared.mp3',$mp3);file_put_contents($fixture.'/assets/audio/first.mp3',$mp3);
$tracks=[];$ids=[];
for($index=1;$index<=135;$index++){
    $id='song'.str_pad((string)$index,3,'0',STR_PAD_LEFT);$ids[]=$id;
    $tracks[]=['id'=>$id,'title'=>'Published song '.$index,'release_id'=>'bulk','duration'=>80,
        'audio'=>$index===1?'assets/audio/first.mp3':'assets/audio/shared.mp3'];
}
$tracks[]=['id'=>'hidden','title'=>'Secret unpublished title','public_visible'=>false,'audio'=>'assets/audio/shared.mp3'];
$tracks[]=['id'=>'remote','title'=>'Remote source','audio'=>'https://example.invalid/signed.mp3?key=SECRET'];
$tracks[]=['id'=>'foreign','title'=>'Secret release title','release_id'=>'hidden-release','audio'=>'assets/audio/shared.mp3'];
file_put_contents($fixture.'/data/catalog.json',json_encode($tracks));
file_put_contents($fixture.'/data/releases.json',json_encode([
    ['id'=>'bulk','title'=>'Published album','track_ids'=>array_reverse($ids),'state'=>'published'],
    ['id'=>'hidden-release','title'=>'Secret album','track_ids'=>['foreign'],'public_visible'=>false,'state'=>'published']]));
file_put_contents($fixture.'/data/shows.json','[]');
define('SF_ROOT',$fixture);
$providerCalls=0;$modelPrompt=[];$modelSystem='';
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput,int $timeout): array {
    $GLOBALS['providerCalls']++;$GLOBALS['modelPrompt']=json_decode($message,true,8,JSON_THROW_ON_ERROR);$GLOBALS['modelSystem']=$system;
    return ['text'=>json_encode(['title'=>'Music geometry','html'=>'<section class="nodes"></section>','css'=>'.nodes{color:#fff}',
        'js'=>'const {tracks} = await StonefellowAudio.catalog(); console.log(tracks.length);',
        'catalog_refs'=>['media-albums','media-bulk']]),'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';require_once $root.'/api/agent-scene-core.php';
$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,2] as $owner)$q->execute([$owner,'fixture'.$owner.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only-provider','model'=>'fixture']);
sf_token_adjust(1,100000,'admin_grant','music-fixture');$user=['id'=>1,'role'=>'user'];
$catalog=sf_agent_canvas_music_catalog();
verify(count($catalog['tracks'])===135&&count($catalog['releases'])===1&&count($catalog['releases'][0]['track_ids'])===135,
    'The music inventory retains the full hosted public MP3 catalog and complete eligible album membership');
$selected=['mode'=>'selected','track_ids'=>['song135','song001','song002']];
verify(array_column(sf_agent_canvas_music_catalog($selected)['tracks'],'track_id')===$selected['track_ids'],
    'Selected music catalog order follows the user selection rather than release or catalog order');
foreach(['hidden','remote','foreign','missing'] as $unavailable)
    rejects(fn()=>sf_agent_canvas_music_validate(['mode'=>'selected','track_ids'=>[$unavailable]]),
        'Unavailable, private and non-hosted music inputs share a non-disclosing rejection');
$request=['canvas_kind'=>'custom','brief'=>'Build independent musical shapes {music-track:song135} {music-track:song001} {music-track:song002}',
    'music_inputs'=>$selected,'request_id'=>str_repeat('a',32)];
$created=sf_agent_scene_create($user,$request);$scene=$created['scene'];
verify($scene['music_inputs']===$selected&&$modelPrompt['design_brief']==='Build independent musical shapes'&&
    $modelPrompt['music_inputs']===['mode'=>'selected','count'=>3],
    'The normal coding path strips input tokens and supplies a compact complete selected-resource descriptor');
verify($scene['catalog_refs']===[]&&$scene['catalog_blocks']===[]&&
    !array_intersect(['published_catalog','published_release_tracks'],array_column($modelPrompt['canvas_data_tools'],'id'))&&
    !isset($modelPrompt['available_release_sources'])&&str_contains($modelSystem,'Iterate the returned tracks'),
    'Music inputs produce no host album widgets and direct authored code toward the complete runtime catalog');
verify(sf_agent_scene_history_get(1,$scene['id'])['music_inputs']===$selected&&
    sf_agent_scene_history_get(2,$scene['id'])===null&&sf_agent_scene_history_export(1)[0]['music_inputs']===$selected,
    'Saved music selections retain their owner boundary and appear in privacy exports');
$longBrief=str_repeat('x',1790).' '.implode(' ',array_map(static fn(string $id):string=>'{music-track:'.$id.'}',$ids));
$large=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>$longBrief,'music_inputs'=>['mode'=>'selected','track_ids'=>$ids],
    'request_id'=>str_repeat('b',32)]);
verify(strlen($longBrief)>1800&&strlen($modelPrompt['design_brief'])===1790&&count($large['scene']['music_inputs']['track_ids'])===135,
    'Resource tokens do not consume the prose budget or impose image-style or 120-track caps');
$html='<section class="sf-dataset-published_catalog sf-current-track"><div class="sf-data-row"></div></section>'
    .'<section class="sf-dataset-published_release_tracks"><div class="sf-data-row"></div></section>';
$data=sf_agent_canvas_data_for_scene([],$html,'song003',$selected);
verify(array_column($data['published_catalog']['rows'],'track_id')===$selected['track_ids']&&
    $data['published_catalog']['current_rows']===[]&&array_column($data['published_release_tracks']['rows'],'track_id')===$selected['track_ids'],
    'Legacy music datasets respect the selected input boundary and current-song binding cannot escape it');
$allData=sf_agent_canvas_data_for_scene([],$html,'song003',['mode'=>'all']);
verify(count($allData['published_catalog']['rows'])===135&&count($allData['published_release_tracks']['rows'])===135,
    'All-mode music dataset delivery also avoids the legacy 120-row cutoff');
unlink($fixture.'/assets/audio/first.mp3');$before=$providerCalls;$balance=sf_token_balance(1);
$replay=sf_agent_scene_create($user,$request);
verify($replay['scene']==$scene&&$providerCalls===$before&&
    array_column(sf_agent_canvas_music_catalog($scene['music_inputs'])['tracks'],'track_id')===['song135','song002'],
    'A saved request replays without a provider call while withdrawn audio disappears from its live scope');
rejects(fn()=>sf_agent_scene_create($user,array_replace($request,['request_id'=>str_repeat('c',32)])),
    'A new explicit selection containing a withdrawn song is rejected before the coding provider');
verify($providerCalls===$before&&sf_token_balance(1)===$balance,'Source validation failures make no model call or token charge');
$edited=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$scene['id'],'brief'=>'Add motion','request_id'=>str_repeat('d',32)]);
verify($edited['scene']['music_inputs']===$selected&&$modelPrompt['music_inputs']===['mode'=>'selected','count'=>2],
    'Edits inherit the selected identity set while the prompt describes only its currently available MP3 count');
$cleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$scene['id'],'brief'=>'Use a quiet composition',
    'music_inputs'=>null,'request_id'=>str_repeat('e',32)]);
verify(!isset($cleared['scene']['music_inputs'])&&!isset(sf_agent_scene_history_get(1,$cleared['scene']['id'])['music_inputs']),
    'Explicit null clears an inherited selection without changing legacy empty-scene shapes');
$all=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Arrange every song as a node (/music-all).','request_id'=>str_repeat('f',32)]);
verify($all['scene']['music_inputs']===['mode'=>'all']&&$modelPrompt['music_inputs']===['mode'=>'all','count'=>134]&&
    !str_contains($modelPrompt['design_brief'],'/music-all'),
    'A pasted all-music operator stores dynamic mode and sends a count rather than a sampled or fixed track list');
$legacy=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Former album wall','html'=>'<section></section>','css'=>'',
    'catalog_refs'=>['media-albums','media-bulk','show-locations','price-ticket'],'music_inputs'=>['mode'=>'all']]);
verify($legacy['catalog_refs']===['show-locations','price-ticket'],
    'Saving music inputs removes legacy album widgets while preserving unrelated catalog references');
rejects(fn()=>sf_agent_scene_create($user,array_replace($request,['music_inputs'=>['mode'=>'selected','track_ids'=>['song002']]])),
    'Changing a completed request music selection cannot reuse its fingerprint');
$many=[];
for($index=0;$index<1000;$index++)$many[]=str_pad('track-'.$index,180,'x');
$largeHistory=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Large selected scope','html'=>'<section></section>',
    'css'=>'','music_inputs'=>['mode'=>'selected','track_ids'=>$many]]);
verify(strlen(json_encode($largeHistory['music_inputs']))>65535&&
    sf_agent_scene_history_get(2,$largeHistory['id'])['music_inputs']['track_ids']===$many,
    'Large ordered music selections persist intact beyond the former text-field size without a track-count cap');
