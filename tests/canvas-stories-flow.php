<?php
declare(strict_types=1);

// Exercise actual approval, generation, review and gallery flows with a local model fixture.
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-stories-flow-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_STORY_DB_CONFIG');
$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['catalog','releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);

$calls=0;$prompt=[];$system='';$checks=0;$serial=0;$onStoryModel=null;
function sf_openai_call(array $provider,string $instructions,array $history,string $message,int $maxOutput,int $timeout=0):array {
    $GLOBALS['calls']++;
    $GLOBALS['system']=$instructions;
    $GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    if(is_callable($GLOBALS['onStoryModel']))($GLOBALS['onStoryModel'])();
    return ['text'=>json_encode(['title'=>'Narrative scene',
        'html'=>'<section class="sf-dataset-published_stories"><article class="sf-data-row"><p class="sf-field-title"></p></article></section>',
        'css'=>'section{color:#eee}']), 'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
function story_flow_check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}
function story_flow_reject(callable $run,string $message,string $class=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){if($error instanceof $class){story_flow_check(true,$message);return;}throw $error;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function story_flow_request():string {return str_pad(dechex(++$GLOBALS['serial']),32,'0',STR_PAD_LEFT);}
function story_flow_save(string $title,string $status,array $tags=[]):string {
    $story=sf_story_save(['entry_type'=>'essay','title'=>$title,'summary'=>'A public narrative preview.','body_text'=>'Story body for '.$title,
        'status'=>$status,'tags'=>$tags],9);
    return (string)$story['id'];
}
function story_flow_gallery(array $user,array $admin,int $scene):array {
    $entry=sf_canvas_gallery_submit($user,['scene_id'=>$scene,'request_id'=>story_flow_request(),'title'=>'Story design',
        'description'=>'A composition using published narratives.','creator_label'=>'Fixture maker','publication_consent'=>true]);
    sf_canvas_gallery_moderate($admin,$entry['id'],'approve',$entry['snapshot_hash']);
    return $entry;
}
function story_flow_gallery_ids(string $galleryId):array {
    $result=sf_canvas_gallery_open($galleryId);
    return array_map('strval',array_column($result['scene']['datasets']['published_stories']['rows']??[],'story_id'));
}

require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';
require_once $root.'/api/agent-scene-core.php';
require_once $root.'/api/agent-canvas-image-review.php';
require_once $root.'/api/canvas-gallery-core.php';
require_once $root.'/api/canvas-project-ideas.php';
$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(16),status VARCHAR(16),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB':
    'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,9] as $uid)$q->execute([$uid,'stories'.$uid.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),$uid===9?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);
sf_token_adjust(1,1000000,'admin_grant','stories-flow');
$user=['id'=>1,'role'=>'user'];$admin=['id'=>9,'role'=>'admin'];
$a=story_flow_save('First studio story','published',['behind-the-song']);
$b=story_flow_save('Second studio story','published',['behind-the-song']);
$draft=story_flow_save('DRAFT_STORY_SENTINEL','draft',['behind-the-song']);
$selected=['mode'=>'selected','story_ids'=>[$a]];
$all=['mode'=>'all'];
$tag=['mode'=>'tag','tag'=>'behind-the-song'];
$empty=['mode'=>'selected','story_ids'=>[]];

$before=sf_token_balance(1);
story_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Use a story {story:'.$draft.'}']]),
    'A crafted draft reference cannot enter a project proposal');
$proposal=sf_canvas_project_propose($user,['project'=>['brief'=>'Design around {story:'.$a.'}']]);
story_flow_check($proposal['project']['artist_inputs']['stories']===$selected&&$calls===0&&sf_token_balance(1)===$before,
    'Story selection binds the exact scope without a generation charge');
sf_canvas_project_approve(1,$proposal['id']);
$approved=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>story_flow_request()];
$widened=$approved;$widened['artist_inputs']['stories']=$all;
story_flow_reject(fn()=>sf_agent_scene_create($user,$widened),'Changing selected stories to All needs its own approval',SfCanvasProjectApprovalRequired::class);
$built=sf_agent_scene_create($user,$approved)['scene'];
story_flow_check($built['artist_inputs']['stories']===$selected&&
    $prompt['selected_artist_resources']['published_stories']['story_count']===1&&
    !str_contains(json_encode($prompt),'DRAFT_STORY_SENTINEL')&&str_contains($system,'Use live row fields'),
    'Generation gets a scoped public preview and live-binding instructions');
$revision=sf_canvas_project_propose($user,['project'=>['brief'=>'Rearrange the composition','previous_id'=>$built['id'],'artist_inputs'=>['merch_ids'=>[]]]]);
story_flow_check($revision['project']['artist_inputs']===['merch_ids'=>[],'stories'=>$selected],
    'A revision inherits stories even when a different resource kind changes');
sf_canvas_project_approve(1,$revision['id']);
$claim=$revision['project']+['project_managed'=>true,'proposal_id'=>$revision['id'],'request_id'=>story_flow_request()];
$child=$fixture.'/claim.php';
file_put_contents($child,'<?php define("SF_ROOT",'.var_export($fixture,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/agent-scene-core.php',true).';$r=sf_canvas_project_begin(["id"=>1,"role"=>"user"],'.var_export($claim,true).');echo json_encode(["status"=>$r["status"],"transaction"=>sf_db()->inTransaction()]);');
$command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').' '.escapeshellarg($child);
$output=[];$status=0;exec($command,$output,$status);
story_flow_check($status===0&&json_decode(implode("\n",$output),true)===['status'=>'building','transaction'=>false],
    'A cold approval claim prepares story storage before opening its transaction');
sf_canvas_project_cancel(1,$revision['id']);

$views=[];$viewScenes=[];
foreach(['selected'=>$selected,'all'=>$all,'tag'=>$tag] as $key=>$scope){
    $scene=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Design a live story collection','artist_inputs'=>['stories'=>$scope],
        'request_id'=>story_flow_request()])['scene'];
    $viewScenes[$key]=$scene['id'];
    $views[$key]=story_flow_gallery($user,$admin,$scene['id']);
}
story_flow_check(story_flow_gallery_ids($views['selected']['id'])===[$a]&&count(story_flow_gallery_ids($views['all']['id']))===2,
    'Gallery snapshots retain scope while guests receive current story rows');
$remix=sf_canvas_gallery_remix($user,$views['selected']['id'],story_flow_request());
story_flow_check($remix['scene']['artist_inputs']['stories']===$selected,'Remixing a published canvas preserves the selected story scope');

sf_agent_canvas_image_review_ensure_schema();
sf_agent_canvas_image_ensure_schema();
$reviewId=story_flow_request();
$epoch=sf_agent_scene_history_epoch(1);
sf_agent_scene_request_claim(1,$reviewId,hash('sha256',$reviewId),$epoch);
$chunk=static fn(string $kind,string $data):string=>pack('N',strlen($data)).$kind.$data.pack('N',crc32($kind.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x20",128*3),128))).$chunk('IEND','');
$variant=sf_agent_canvas_image_store(1,$reviewId,'Story variation',['bytes'=>$png]);
$reviewScene=['canvas_kind'=>'custom','track_id'=>'','title'=>'Story artwork','html'=>'<section class="sf-image-'.$reviewId.'"></section>',
    'css'=>'section{background:var(--sf-image-'.$reviewId.',none)}','artist_inputs'=>['stories'=>$selected]];
$reviewBalance=sf_token_balance(1);
$db->beginTransaction();
try{
    sf_agent_canvas_image_review_stage(1,$reviewId,$reviewScene,null,$epoch,[$variant],0,$reviewBalance);
    $db->commit();
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}

$stale=sf_canvas_project_propose($user,['project'=>['brief'=>'A fresh story arrangement','artist_inputs'=>['stories'=>$selected]]]);
sf_canvas_project_approve(1,$stale['id']);
$pending=sf_canvas_project_propose($user,['project'=>['brief'=>'Another story arrangement','artist_inputs'=>['stories'=>$selected]]]);
$before=sf_token_balance(1);$historyBefore=count(sf_agent_scene_history_export(1));$callBefore=$calls;
$onStoryModel=static fn()=>sf_db()->prepare("UPDATE story_entries SET status='archived' WHERE id=?")->execute([$a]);
story_flow_reject(fn()=>sf_agent_scene_create($user,$stale['project']+['project_managed'=>true,'proposal_id'=>$stale['id'],'request_id'=>story_flow_request()]),
    'A story archived during inference invalidates settlement');
$onStoryModel=null;
story_flow_check($calls===$callBefore+1&&sf_token_balance(1)===$before&&count(sf_agent_scene_history_export(1))===$historyBefore,
    'Invalidated settlement refunds the reservation and saves no stale scene');
story_flow_reject(fn()=>sf_canvas_project_approve(1,$pending['id']),'An archived story cannot receive a new approval');
story_flow_reject(fn()=>sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],'brief'=>'Revise inherited scope','request_id'=>story_flow_request()]),
    'An inherited archived story blocks a new build before model or token use');
story_flow_check($calls===$callBefore+1&&sf_token_balance(1)===$before,'The stale inherited build reaches no provider and spends no tokens');
story_flow_check(sf_agent_scene_create($user,$approved)['scene']['id']===$built['id']&&$calls===$callBefore+1,
    'Exact recovery preserves the completed scene without re-running generation');
story_flow_reject(fn()=>sf_agent_canvas_image_review_choose(1,$reviewId,$reviewId),'An archived story cannot be saved through a previously staged image review');
story_flow_check(sf_agent_scene_request_get(1,$reviewId)['state']==='review','A rejected story image choice leaves the pending review recoverable');
story_flow_reject(fn()=>sf_canvas_gallery_open($views['selected']['id']),'A public open denies the individually selected archived story',SfCanvasGalleryNotFound::class);
story_flow_reject(fn()=>sf_canvas_gallery_remix($user,$views['selected']['id'],story_flow_request()),'A remix cannot revive an individually selected archived story',SfCanvasGalleryNotFound::class);
$c=story_flow_save('New studio story','published',['behind-the-song']);
$d=story_flow_save('New public update','published');
$allIds=story_flow_gallery_ids($views['all']['id']);$tagIds=story_flow_gallery_ids($views['tag']['id']);
story_flow_check(count($allIds)===3&&in_array($b,$allIds,true)&&in_array($c,$allIds,true)&&in_array($d,$allIds,true),
    'All Stories follows new publications and drops archived stories in an existing gallery canvas');
story_flow_check(count($tagIds)===2&&in_array($b,$tagIds,true)&&in_array($c,$tagIds,true),
    'A published tag stays live and excludes unmatched, draft and archived stories');
$cleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],'brief'=>'Revise without stories {stories-clear}',
    'request_id'=>story_flow_request()])['scene'];
story_flow_check($cleared['artist_inputs']['stories']===$empty,'An explicit story clear replaces an unavailable inherited selection');
sf_canvas_project_preferences_save(1,['ideas_enabled'=>false]);
$tagPlan=['project'=>['brief'=>'Rearrange the tagged story scene','previous_id'=>$viewScenes['tag'],'artist_inputs'=>['merch_ids'=>[]]],
    'request_id'=>story_flow_request()];
file_put_contents($child,'<?php define("SF_ROOT",'.var_export($fixture,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/canvas-project-ideas.php',true).';$r=sf_canvas_project_ideas_plan(["id"=>1,"role"=>"user"],'.var_export($tagPlan,true).');echo json_encode(["state"=>$r["state"],"stories"=>$r["proposal"]["project"]["artist_inputs"]["stories"],"transaction"=>sf_db()->inTransaction()]);');
$output=[];$status=0;exec($command,$output,$status);
story_flow_check($status===0&&json_decode(implode("\n",$output),true)===['state'=>'ready','stories'=>$tag,'transaction'=>false],
    'Cold local planning inherits a tag and commits its proposal without schema work inside settlement');
story_flow_check(!$db->inTransaction(),'Rejected and successful story flows leave transactions settled');
echo 'Canvas stories flow: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
