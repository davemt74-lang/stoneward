<?php
declare(strict_types=1);

// Exercise composer selections, approval, model context and live reopening with real storage.
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-lyrics-flow-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_LYRIC_DB_CONFIG');
$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
$a='sf-0000000000000001';$b='sf-0000000000000002';$draft='sf-0000000000000003';$hidden='sf-0000000000000004';
$words='PUBLIC_LYRIC_A '.str_repeat('rain ',100).'END_OF_LYRIC_A';
$catalog=[
    ['id'=>$a,'title'=>'Same Song Name','lyrics'=>$words],
    ['id'=>$b,'title'=>'Same Song Name','lyrics'=>'UNSELECTED_LYRIC_B'],
    ['id'=>$draft,'title'=>'Public song with draft words','lyrics'=>'DRAFT_LYRIC_SENTINEL'],
    ['id'=>$hidden,'title'=>'Private track','lyrics'=>'PRIVATE_LYRIC_SENTINEL','public_visible'=>false],
];
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
define('SF_ROOT',$fixture);

$calls=0;$prompt=[];$system='';$checks=0;$serial=0;$onLyricModel=null;
function sf_openai_call(array $provider,string $instructions,array $history,string $message,int $maxOutput,int $timeout=0):array {
    $GLOBALS['calls']++;
    $GLOBALS['system']=$instructions;
    $GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    if(is_callable($GLOBALS['onLyricModel']))($GLOBALS['onLyricModel'])();
    $reply=str_contains($instructions,'creative project planner')
        ?['title'=>'Words in motion','concept'=>'A typographic design using the selected lyric source.']
        :['title'=>'Words in motion',
            'html'=>'<section class="sf-dataset-published_lyrics sf-filter-kind--text"><article class="sf-data-row"><p class="sf-field-text"></p></article></section>',
            'css'=>'section{color:#eee} p{white-space:pre-wrap}'];
    return ['text'=>json_encode($reply,JSON_THROW_ON_ERROR),'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
function lyric_flow_check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}
function lyric_flow_reject(callable $run,string $message,string $class=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){if($error instanceof $class){lyric_flow_check(true,$message);return;}throw $error;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function lyric_flow_request():string {return str_pad(dechex(++$GLOBALS['serial']),32,'0',STR_PAD_LEFT);}
function lyric_flow_child(string $code):array {
    $child=SF_ROOT.'/worker.php';
    $root=$GLOBALS['root'];
    file_put_contents($child,'<?php define("SF_ROOT",'.var_export(SF_ROOT,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/canvas-gallery-core.php',true).';'.$code);
    $command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').' '.escapeshellarg($child);
    exec($command,$output,$status);
    if($status!==0)throw new RuntimeException('Independent lyrics worker failed.');
    return json_decode(implode("\n",$output),true,16,JSON_THROW_ON_ERROR);
}
function lyric_flow_rows(array $scene):array {return $scene['datasets']['published_lyrics']['rows']??[];}
function lyric_flow_saved(array $user,int $id,string $currentTrack=''):array {
    return sf_canvas_build_workflow_scene($user,['candidate_id'=>$id],$currentTrack)??throw new RuntimeException('The saved canvas is unavailable.');
}

require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';
require_once $root.'/api/canvas-gallery-core.php';
require_once $root.'/api/canvas-project-ideas.php';
require_once $root.'/api/songbook-core.php';
$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(16),status VARCHAR(16),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB':
    'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,9] as $uid)$q->execute([$uid,'lyrics'.$uid.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),$uid===9?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);
sf_token_adjust(1,1000000,'admin_grant','lyrics-flow');
$user=['id'=>1,'role'=>'user'];$admin=['id'=>9,'role'=>'admin'];
foreach([$a,$b,$draft] as $trackId){
    sf_songbook_save(['track_id'=>$trackId,'status'=>$trackId===$draft?'draft':'published',
        'sections'=>[['label'=>'Verse','start_line'=>1,'end_line'=>1]],'notes'=>'ADMIN_NOTES_SENTINEL',
        'annotations'=>[['line'=>1,'note'=>'ANNOTATION_SENTINEL']]],9);
}
$catalogRows=sf_canvas_resource_inventory(0,'lyrics');
$ids=array_column($catalogRows,'id');sort($ids);
lyric_flow_check($ids===[$a,$b]&&array_column($catalogRows,'timed')===[false,false]&&
    !str_contains(json_encode($catalogRows,JSON_THROW_ON_ERROR),'SENTINEL')&&!str_contains(json_encode($catalogRows,JSON_THROW_ON_ERROR),'PUBLIC_LYRIC_A'),
    'Guest inventory lists published song IDs and timing availability without exposing lyric bodies or notes');
$before=sf_token_balance(1);
lyric_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Arrange {lyrics:'.$draft.'}']]),
    'A hand-written draft lyric reference cannot enter a project');
lyric_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Arrange {lyrics:'.$hidden.'}']]),
    'A hidden track cannot enter a project through the lyric resource picker contract');
lyric_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Arrange {lyrics:'.$a.'} {lyrics-clear}']]),
    'Conflicting selections and clears are rejected');
$project=['brief'=>'Arrange the selected words as typography {lyrics:'.$a.'} {lyrics:'.$a.'}'];
$proposal=sf_canvas_project_propose($user,['project'=>$project]);
lyric_flow_check($proposal['project']['artist_inputs']['lyrics_ids']===[$a]&&$proposal['project']['track_id']===''&&
    $proposal['project']['context_track_id']===''&&$calls===0&&sf_token_balance(1)===$before,
    'An explicit lyric selection deduplicates, needs no player song and consumes no tokens');
sf_canvas_project_approve(1,$proposal['id']);
$approved=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>lyric_flow_request()];
$changed=$approved;$changed['artist_inputs']['lyrics_ids']=[$b];
lyric_flow_reject(fn()=>sf_agent_scene_create($user,$changed),'The same title does not authorize a different canonical song',SfCanvasProjectApprovalRequired::class);

$built=sf_agent_scene_create($user,$approved)['scene'];
$preview=$prompt['selected_artist_resources']['published_lyrics'];
$promptJson=json_encode($prompt,JSON_THROW_ON_ERROR);
lyric_flow_check($built['artist_inputs']['lyrics_ids']===[$a]&&array_column($preview['preview'],'id')===[$a]&&
    $preview['song_count']===1&&$preview['creative_excerpts'][0]['track_id']===$a&&
    strlen($preview['creative_excerpts'][0]['text'])<=420&&str_contains($promptJson,'PUBLIC_LYRIC_A')&&
    !str_contains($promptJson,'END_OF_LYRIC_A')&&!str_contains($promptJson,'UNSELECTED_LYRIC_B')&&!str_contains($promptJson,'SENTINEL')&&
    str_contains($system,'published_lyrics'),
    'The model receives only a bounded excerpt and metadata from the selected published lyrics with live binding guidance');
$saved=lyric_flow_saved($user,$built['id'],$b);
$rows=lyric_flow_rows($saved);
lyric_flow_check(count($rows)===1&&$rows[0]['track_id']===$a&&$rows[0]['kind']==='text'&&$rows[0]['text']===$words&&
    $rows[0]['start_ms']===null&&$rows[0]['end_ms']===null&&!str_contains(json_encode($built,JSON_THROW_ON_ERROR),'PUBLIC_LYRIC_A'),
    'An owned canvas resolves complete selected untimed text live, independently of current playback');

$legacy=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>$b,'lyric_section'=>'whole',
    'brief'=>'Design around this song’s published words','request_id'=>lyric_flow_request()])['scene'];
lyric_flow_check($legacy['lyric_section']==='whole'&&$prompt['published_lyric_excerpt']['text']==='UNSELECTED_LYRIC_B',
    'The existing explicitly requested legacy lyric section still provides its approved excerpt');
$replacement=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>$legacy['track_id'],'previous_id'=>$legacy['id'],
    'brief'=>'Use this lyric selection instead {lyrics:'.$a.'}','request_id'=>lyric_flow_request()])['scene'];
lyric_flow_check($replacement['artist_inputs']['lyrics_ids']===[$a]&&$replacement['lyric_section']===null&&
    !isset($prompt['published_lyric_excerpt'])&&!str_contains(json_encode($prompt,JSON_THROW_ON_ERROR),'UNSELECTED_LYRIC_B'),
    'A revision using the picker replaces inherited legacy lyrics without forwarding the unrelated excerpt');

$planned=sf_canvas_project_ideas_plan($user,['project'=>['brief'=>'Restyle the typography','previous_id'=>$built['id'],'artist_inputs'=>['merch_ids'=>[]]],
    'request_id'=>lyric_flow_request()]);
lyric_flow_check($planned['proposal']['project']['artist_inputs']===['merch_ids'=>[],'lyrics_ids'=>[$a]]&&
    array_column($prompt['references']['selected_artist_resources']['published_lyrics']['preview'],'id')===[$a]&&
    !str_contains(json_encode($prompt,JSON_THROW_ON_ERROR),'PUBLIC_LYRIC_A'),
    'Planning inherits lyric scope across a different resource edit and uses metadata without sending lyric bodies');
$revision=$planned['proposal'];
sf_canvas_project_approve(1,$revision['id']);
$claim=$revision['project']+['project_managed'=>true,'proposal_id'=>$revision['id'],'request_id'=>lyric_flow_request()];
$cold=lyric_flow_child('$r=sf_canvas_project_begin(["id"=>1,"role"=>"user"],'.var_export($claim,true).');echo json_encode(["status"=>$r["status"],"transaction"=>sf_db()->inTransaction()]);');
lyric_flow_check($cold===['status'=>'building','transaction'=>false],
    'A cold approval worker prepares lyric storage before opening its claim transaction');
sf_canvas_project_cancel(1,$revision['id']);

$shareable=sf_agent_scene_create($user,$proposal['project']+['request_id'=>lyric_flow_request()])['scene'];
$submission=['scene_id'=>$shareable['id'],'title'=>'Words in motion','description'=>'Lyrics rendered live.',
    'creator_label'=>'Fixture maker','publication_consent'=>true];
$share=sf_canvas_gallery_submit($user,$submission+['request_id'=>lyric_flow_request()]);
$pendingShare=sf_canvas_gallery_submit($user,$submission+['request_id'=>lyric_flow_request()]);
sf_canvas_gallery_moderate($admin,$share['id'],'approve',$share['snapshot_hash']);
$gallery=sf_canvas_gallery_open($share['id']);
lyric_flow_check(lyric_flow_rows($gallery['scene'])===$rows&&
    !str_contains(sf_canvas_gallery_row($share['id'])['snapshot_json'],'PUBLIC_LYRIC_A'),
    'Approved gallery guests receive current rows while the submitted snapshot stores scope and code only');
$remix=sf_canvas_gallery_remix($user,$share['id'],lyric_flow_request());
lyric_flow_check($remix['scene']['artist_inputs']['lyrics_ids']===[$a],'Remixing preserves the approved lyric selection');
$catalog[0]['lyrics']='The reviewed correction';
file_put_contents($fixture.'/data/catalog.json',json_encode($catalog,JSON_THROW_ON_ERROR));
$refresh=lyric_flow_child('$u=["id"=>1,"role"=>"user"];$saved=sf_canvas_build_workflow_scene($u,["candidate_id"=>'.$built['id'].']);'.
    '$gallery=sf_canvas_gallery_open('.var_export($share['id'],true).');echo json_encode([$saved["datasets"]["published_lyrics"]["rows"],$gallery["scene"]["datasets"]["published_lyrics"]["rows"]]);');
lyric_flow_check($refresh[0]===$refresh[1]&&$refresh[0][0]['text']==='The reviewed correction'&&
    $refresh[0][0]['revision_hash']!==$rows[0]['revision_hash'],
    'Opening an existing private or gallery scene reads the corrected publication and its changed revision');

$permissionId='canvas.data.published_lyrics';
$policy=sf_canvas_permission_save($admin,sf_canvas_permission_policy()['revision'],[$permissionId=>false]);
lyric_flow_check(sf_canvas_resource_inventory(0,'lyrics')===[]&&
    lyric_flow_rows(lyric_flow_saved($user,$built['id']))===[]&&lyric_flow_rows(sf_canvas_gallery_open($share['id'])['scene'])===[],
    'The administrator can disable lyric discovery and delivery for owners and guests');
lyric_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>$project]),'A disabled lyric attachment cannot enter a proposal');
sf_canvas_permission_save($admin,$policy['policy']['revision'],[$permissionId=>true]);
lyric_flow_check(count(lyric_flow_rows(lyric_flow_saved($user,$built['id'])))===1,'Re-enabling lyrics restores authorized live rows');

$inference=sf_canvas_project_propose($user,['project'=>$project]);sf_canvas_project_approve(1,$inference['id']);
$pending=sf_canvas_project_propose($user,['project'=>$project]);
$before=sf_token_balance(1);$historyBefore=count(sf_agent_scene_history_export(1));$callBefore=$calls;
$onLyricModel=static fn()=>sf_songbook_save(['track_id'=>$a,'status'=>'archived',
    'sections'=>[['label'=>'Verse','start_line'=>1,'end_line'=>1]]],9);
lyric_flow_reject(fn()=>sf_agent_scene_create($user,$inference['project']+['project_managed'=>true,'proposal_id'=>$inference['id'],'request_id'=>lyric_flow_request()]),
    'A lyric withdrawn during generation invalidates settlement');
$onLyricModel=null;
lyric_flow_check($calls===$callBefore+1&&sf_token_balance(1)===$before&&count(sf_agent_scene_history_export(1))===$historyBefore,
    'Invalidated settlement refunds the reservation and stores no stale scene');
lyric_flow_reject(fn()=>sf_canvas_project_approve(1,$pending['id']),'A withdrawn lyric cannot receive a new approval');
lyric_flow_reject(fn()=>sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],'brief'=>'Restyle the typography','request_id'=>lyric_flow_request()]),
    'An inherited withdrawn lyric blocks new generation until the selection changes');
lyric_flow_reject(fn()=>sf_canvas_gallery_moderate($admin,$pendingShare['id'],'approve',$pendingShare['snapshot_hash']),
    'Pending gallery moderation rechecks whether the selected lyrics remain published');
lyric_flow_reject(fn()=>sf_canvas_gallery_remix($user,$share['id'],lyric_flow_request()),'A new remix cannot reattach withdrawn lyrics');
lyric_flow_check(lyric_flow_rows(lyric_flow_saved($user,$built['id']))===[]&&lyric_flow_rows(sf_canvas_gallery_open($share['id'])['scene'])===[]&&
    lyric_flow_rows(lyric_flow_saved($user,$remix['scene']['id']))===[],
    'Withdrawing publication removes the words from owned, remixed and gallery canvases without hiding their code');
lyric_flow_check(sf_agent_scene_create($user,$approved)['scene']['id']===$built['id']&&$calls===$callBefore+1&&sf_token_balance(1)===$before,
    'Exact recovery returns the existing canvas without re-running or spending tokens');

$cleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],'brief'=>'Restyle without words {lyrics-clear}',
    'request_id'=>lyric_flow_request()])['scene'];
lyric_flow_check($cleared['artist_inputs']['lyrics_ids']===[]&&lyric_flow_rows(lyric_flow_saved($user,$cleared['id'],$b))===[],
    'An explicit clear removes the stale scope without falling back to another song');
lyric_flow_check(!$db->inTransaction(),'Successful and rejected lyric workflows close their transactions');
echo 'Canvas lyrics flow: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
