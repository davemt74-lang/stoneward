<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-canvas-history-'.bin2hex(random_bytes(8));
mkdir($fixture.'/config',0700,true);
mkdir($fixture.'/data',0700,true);
mkdir($fixture.'/assets/js',0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');
file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(function()use($fixture){
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';

function check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}
function rejects(callable $fn,string $label): void {
    try{$fn();}catch(InvalidArgumentException $error){check(true,$label);return;}
    throw new RuntimeException('Expected rejection: '.$label);
}

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($uid=1;$uid<=3;$uid++)$q->execute([$uid,"fan$uid@example.invalid","Fixture $uid",password_hash('fixture-only-password',PASSWORD_DEFAULT),$uid===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$db->exec("CREATE TABLE agent_scene_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, track_id TEXT NOT NULL,
    title TEXT NOT NULL, html TEXT NOT NULL, css TEXT NOT NULL, previous_id INTEGER NULL, created_at TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
    INSERT INTO agent_scene_history(user_id,track_id,title,html,css,created_at)
    VALUES(1,'legacy','Legacy room','<p>Legacy room</p>','','fixture-legacy')");
sf_agent_scene_history_ensure_schema();
$legacy=sf_agent_scene_history_get(1,1);
check($legacy['canvas_kind']==='listening_room'&&$legacy['lyric_section']===null&&$legacy['catalog_refs']===[]&&$legacy['html']==='<p>Legacy room</p>',
    'additive schema upgrade preserves saved rooms and supplies a legacy canvas kind');
check(!array_key_exists('design_brief',$legacy)&&in_array('design_brief',$db->query('PRAGMA table_info(agent_scene_history)')->fetchAll(PDO::FETCH_COLUMN,1),true),
    'brief migration preserves legacy rows without inventing a prompt');
check(!array_key_exists('workflow_required',$legacy)&&in_array('workflow_required',$db->query('PRAGMA table_info(agent_scene_history)')->fetchAll(PDO::FETCH_COLUMN,1),true),
    'workflow migration leaves legacy canvas response shapes and execution eligibility unchanged');

$brief="Use the selected songs to make a moving paper sculpture.\nKeep the artwork quiet around the agent.";
$scene=['track_id'=>'desert-lights','title'=>'Desert Lights','html'=>'<p>Original draft</p>','css'=>'p { color: white; }','design_brief'=>$brief,'story'=>['private'=>'ignored']];
$first=sf_agent_scene_history_save(2,$scene);
$internationalTitle=str_repeat('音楽',80);
$different=sf_agent_scene_history_save(3,array_replace($scene,['title'=>$internationalTitle,'html'=>'<p>Different account</p>']));
check(sf_agent_scene_history_get(3,$different['id'])['title']===$internationalTitle,'long multibyte titles survive save and restore');
$revisionBrief='Change the shapes to folded leaves and keep the selected songs.';
$revision=sf_agent_scene_history_save(2,array_replace($scene,['html'=>'<p>Revision</p>','design_brief'=>$revisionBrief]),$first['id']);
check($revision['previous_id']===$first['id']&&sf_agent_scene_history_get(2,$first['id'])['html']===$scene['html'],'revisions append without altering earlier scene code');
check($first['design_brief']===$brief&&sf_agent_scene_history_get(2,$first['id'])['design_brief']===$brief&&
    sf_agent_scene_history_list(2)[0]['design_brief']===$revisionBrief&&sf_agent_scene_history_export(2)[1]['design_brief']===$revisionBrief,
    'actual create and revision prompts survive private get, summary and export without replacing earlier prompts');
check(sf_agent_scene_design_brief(" \r\nA real request.\r\nA second line.\r\n ")==="A real request.\nA second line."&&
    sf_agent_scene_design_brief('   ')===null&&sf_agent_scene_design_brief(null)===null,
    'saved brief normalization keeps actual words and treats missing or blank prompts as unavailable');
check(sf_agent_scene_design_brief(str_repeat('音',600))===str_repeat('音',600),'the brief byte limit supports complete UTF-8 text');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($scene,['design_brief'=>str_repeat('音',601)])),'oversized design briefs cannot be stored');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($scene,['design_brief'=>['invented'=>'prompt']])),'non-text briefs cannot be stored');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($scene,['design_brief'=>"hidden\0instruction"])),'control bytes cannot enter saved brief metadata');
check(sf_agent_scene_history_get(3,$first['id'])===null&&sf_agent_scene_history_get(1,$first['id'])===null,'another fan and site administrator cannot read private scenes');
check(!isset($first['story'])&&!isset(sf_agent_scene_history_list(2)[0]['html']),'only authored code and grounded track metadata persist, with a lightweight list');
rejects(fn()=>sf_agent_scene_history_save(3,$scene,$first['id']),'another account cannot use a private scene as a revision parent');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($scene,['track_id'=>'DESERT-LIGHTS']),$first['id']),'a revision must retain the exact original track ID');
rejects(fn()=>sf_agent_scene_history_save(2,array_replace($scene,['html'=>str_repeat('x',16001)])),'oversized authored code cannot be stored');
check(array_column(sf_agent_scene_history_list(2),'id')===[$revision['id'],$first['id']],'private history lists newest revisions first');
for($i=0;$i<30;$i++)sf_agent_scene_history_save(2,$scene);
check(count(sf_agent_scene_history_list(2))===30&&count(sf_agent_scene_history_export(2))===32,'history summaries are bounded and privacy export retains earlier revisions');
$export=sf_privacy_export(2,sf_user_by_email('fan2@example.invalid'));
check($export['canvas_history'][0]===$first&&count($export['canvas_history'])===32,'full account export contains only the caller’s scene code');

$epoch=sf_agent_scene_history_epoch(2);
$requestId=bin2hex(random_bytes(16));
$fingerprint=hash('sha256','fixture listening room request');
rejects(fn()=>sf_agent_scene_request_id(str_repeat('A',32)),'room request IDs require canonical lowercase hex');
check(sf_agent_scene_request_claim(2,$requestId,$fingerprint,$epoch),'the first request claims its own generation slot');
check(!sf_agent_scene_request_claim(2,$requestId,$fingerprint,$epoch),'an equal request replay does not generate twice');
rejects(fn()=>sf_agent_scene_request_claim(2,$requestId,hash('sha256','different fixture request'),$epoch),'a request ID cannot be reused for different input');
check(sf_agent_scene_request_get(2,$requestId)===['state'=>'pending']&&sf_agent_scene_request_get(3,$requestId)===null,'pending status is private to the account');
check(sf_agent_scene_request_claim(3,$requestId,$fingerprint),'another account can independently claim the same random ID');
sf_agent_scene_request_fail(3,$requestId);
check(sf_agent_scene_request_get(3,$requestId)===['state'=>'failed']&&sf_agent_scene_request_get(2,$requestId)===['state'=>'pending'],'failed requests are account scoped');
check(!sf_agent_scene_request_claim(3,$requestId,$fingerprint),'failed request replay still cannot run twice');
check(sf_agent_scene_request_design_source(2,$first['id'])==='model','saved rooms without a request tracker default to model design');
$db->beginTransaction();
rejects(fn()=>sf_agent_scene_request_finish(2,$requestId,$different['id'],0,125,'standard'),'a request cannot attach another account’s room');
$temporary=sf_agent_scene_history_save(2,$scene,null,$epoch);
sf_agent_scene_request_finish(2,$requestId,$temporary['id'],0,125,'standard');
check($db->inTransaction(),'scene storage can join the token settlement transaction');
$db->rollBack();
check(sf_agent_scene_history_get(2,$temporary['id'])===null&&sf_agent_scene_request_get(2,$requestId)===['state'=>'pending'],'rolling back settlement also rolls back the scene and ready status');
$db->beginTransaction();
sf_agent_scene_request_finish(2,$requestId,$first['id'],0,125,'standard');
$db->commit();
$receipt=['state'=>'ready','scene_id'=>$first['id'],'active_tokens_used'=>0,'active_tokens_remaining'=>125,'design_source'=>'standard'];
sf_agent_scene_request_fail(2,$requestId,$epoch);
check(sf_agent_scene_request_get(2,$requestId)===$receipt&&sf_agent_scene_request_design_source(2,$first['id'])==='standard','a committed result exposes its original receipt and cannot later fail');
$expiredId=bin2hex(random_bytes(16));
sf_agent_scene_request_claim(2,$expiredId,$fingerprint,$epoch);
$db->prepare('UPDATE agent_scene_requests SET created_at=? WHERE user_id=? AND request_id=?')->execute([time()-301,2,$expiredId]);
check(sf_agent_scene_request_get(2,$expiredId)===['state'=>'failed']&&!sf_agent_scene_request_claim(2,$expiredId,$fingerprint,$epoch),'an expired request reports failure and cannot be replayed');
$db->beginTransaction();
rejects(fn()=>sf_agent_scene_request_finish(2,$expiredId,$revision['id'],0,125,'standard'),'an expired request cannot attach a late room');
$db->rollBack();

$db->exec("CREATE TEMP TRIGGER fixture_reject_clear BEFORE INSERT ON user_privacy_events
    WHEN NEW.user_id=2 AND NEW.event_type='agent_history_cleared' BEGIN SELECT RAISE(ABORT,'fixture rejection'); END");
$clearFailed=false;
try{sf_privacy_clear_agent_history(2);}catch(PDOException $error){$clearFailed=true;}
check($clearFailed&&count(sf_agent_scene_history_export(2))===32&&sf_agent_scene_history_epoch(2)===$epoch,'a failed privacy audit record rolls back the scene purge');
check(sf_agent_scene_request_get(2,$requestId)===$receipt,'a failed privacy reset also rolls back the request purge');
$db->exec('DROP TRIGGER fixture_reject_clear');

sf_token_adjust(2,100,'admin_grant','fixture-reset-balance');
$before=sf_token_balance(2);
$resetHold=sf_ai_reserve_tokens(['id'=>2,'role'=>'user'],10,'agent-scene');
$pngChunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$pngChunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $pngChunk('IDAT',gzcompress(str_repeat("\0".random_bytes(128*3),128))).$pngChunk('IEND','');
$image=['bytes'=>$png];$imageId=str_repeat('f',32);$laterImageId=str_repeat('e',32);
$libraryImage=sf_agent_canvas_image_store(2,$imageId,'Private fixture image',$image,$ownerImageFile);
check(is_file($ownerImageFile)&&sf_agent_canvas_image_file(2,$imageId)['path']===$ownerImageFile&&
    sf_agent_canvas_image_file(3,$imageId)===null&&sf_agent_canvas_image_file(1,$imageId)===null,
    'saved canvas image bytes are available to the owner alone');
$replay=sf_agent_canvas_image_store(2,$imageId,'Ignored replay title',$image,$replayFile);
check($replay===$libraryImage&&$replayFile===null&&array_keys($replay)===['id','title','width','height','created_at','url'],
    'reusing a build image ID preserves the original file and exposes only safe metadata');
$db->beginTransaction();
sf_agent_canvas_image_store(2,$laterImageId,'Unsettled fixture image',$image,$unsettledFile);
check($db->inTransaction()&&is_file($unsettledFile),'canvas image storage joins its enclosing token transaction');
$db->rollBack();
unlink($unsettledFile);
check(sf_agent_canvas_image_get(2,$laterImageId)===null,'an unsettled image cannot enter the owner inventory');
$purge=sf_privacy_clear_agent_history(2);
check($purge['canvas_scenes']===32&&sf_agent_scene_history_list(2)===[]&&sf_agent_scene_history_get(3,$different['id'])===$different,'clear Agent history deletes only the caller’s saved scenes');
check(sf_agent_canvas_images_list(2)===[$libraryImage],'clearing conversation history preserves the separate image folder');
check(sf_agent_scene_request_get(2,$requestId)===null&&sf_agent_scene_request_get(3,$requestId)===['state'=>'failed'],'clear Agent history deletes only the caller’s request data');
rejects(fn()=>sf_agent_scene_request_claim(2,$requestId,$fingerprint,$epoch),'a stale request cannot start after a privacy reset');
check(sf_agent_scene_request_claim(2,$requestId,$fingerprint,sf_agent_scene_history_epoch(2)),'a fresh request can start after a privacy reset');
sf_agent_scene_request_fail(2,$requestId,$epoch);
check(sf_agent_scene_request_get(2,$requestId)===['state'=>'pending'],'a stale failure cannot overwrite a request claimed after the reset');
rejects(fn()=>sf_agent_scene_history_save(2,$scene,null,$epoch),'an in-flight result cannot restore history after a privacy reset');
rejects(fn()=>sf_ai_finish_reservation(['id'=>2,'role'=>'user'],$resetHold,3,[],'agent-scene',true,
    fn()=>sf_agent_scene_history_save(2,$scene,null,$epoch)),'settlement rolls back if a reset invalidated the generated scene');
$restored=sf_ai_finish_reservation(['id'=>2,'role'=>'user'],$resetHold,0,[],'agent-scene',false);
check($restored===$before,'a discarded in-flight scene releases its held tokens');
check(sf_agent_scene_history_save(2,$scene,null,sf_agent_scene_history_epoch(2))['id']>0,'a request made after reset can start fresh history');

sf_token_adjust(3,100,'admin_grant','fixture-canvas-balance');
$hold=sf_ai_reserve_tokens(['id'=>3,'role'=>'user'],10,'agent-scene');
sf_agent_canvas_image_store(3,$imageId,'Closing account fixture image',$image,$closedImageFile);
$closed=sf_privacy_close_account(3,sf_user_by_email('fan3@example.invalid'),'fixture-only-password','DELETE');
check($closed['closed']===true&&sf_agent_scene_history_list(3)===[]&&count(sf_agent_scene_history_list(2))===1,'account closure purges canvas data without touching another fan');
check(sf_agent_canvas_images_list(3)===[]&&!is_file($closedImageFile)&&is_file($ownerImageFile),
    'account closure removes only the closing account’s image files and inventory');
rejects(fn()=>sf_agent_canvas_image_store(3,$laterImageId,'Late provider fixture image',$image),'a late image response cannot recreate closed account assets');
check(sf_agent_scene_request_get(3,$requestId)===null&&sf_agent_scene_request_get(2,$requestId)===['state'=>'pending'],'account closure purges only the closing account’s request data');
rejects(fn()=>sf_agent_scene_request_claim(3,$requestId,$fingerprint),'a closed account cannot claim another request');
rejects(fn()=>sf_agent_scene_history_save(3,$scene),'a provider response cannot recreate closed account scene data');
$released=false;
try{sf_ai_finish_reservation(['id'=>3,'role'=>'user'],$hold,0,[],'agent-scene',false);$released=true;}catch(RuntimeException $error){}
$balanceRow=$db->query('SELECT 1 FROM user_token_balances WHERE user_id=3')->fetchColumn();
check(!$released&&$balanceRow===false,'closing an account also invalidates held provider reservations');

$marked=sf_agent_scene_history_save(1,array_replace($scene,['canvas_kind'=>'custom','workflow_required'=>true]));
check($marked['workflow_required']===true&&sf_agent_scene_history_get(1,$marked['id'])['workflow_required']===true&&
    sf_agent_scene_history_list(1)[0]['workflow_required']===true&&sf_agent_scene_history_export(1)[1]['workflow_required']===true,
    'new workflow-required candidates retain their marker in immediate, saved, summary and exported responses');
$unmarked=sf_agent_scene_history_save(1,array_replace($scene,['workflow_required'=>false]));
check(!array_key_exists('workflow_required',$unmarked)&&!array_key_exists('workflow_required',sf_agent_scene_history_get(1,$unmarked['id'])),
    'an explicitly false workflow marker stays absent from legacy-compatible responses');
foreach([null,1,'true',[]] as $invalidMarker)rejects(fn()=>sf_agent_scene_history_save(1,array_replace($scene,['workflow_required'=>$invalidMarker])),
    'workflow requirement metadata accepts actual booleans only');
