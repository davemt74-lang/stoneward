<?php
declare(strict_types=1);
function check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejected(callable $run,string $class,string $message): void {try{$run();}catch(Throwable $error){if($error instanceof $class){check(true,$message);return;}throw $error;}throw new RuntimeException('Expected rejection: '.$message);}
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/sf-canvas-projects-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());}rmdir($fixture);});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'],true).';');copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['catalog','releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);$calls=0;$prompt=[];
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput,int $timeout): array {$GLOBALS['calls']++;$GLOBALS['lastCanvasSystem']=$system;$GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);return ['text'=>json_encode(['title'=>'Authored geometry','html'=>$GLOBALS['fixtureCanvasHtml']??'<section class="scene"></section>','css'=>'.scene{background:#111;color:#ddd}']),'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];}
require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';require_once $root.'/api/agent-scene-core.php';
$db=sf_db();$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');foreach([1,2] as $uid)$q->execute([$uid,'project'.$uid.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);sf_token_adjust(1,100000,'admin_grant','canvas-project-fixture');$user=['id'=>1,'role'=>'user'];
check(sf_canvas_project_preferences(1)===['ideas_enabled'=>true,'auto_approve'=>false],'Projects default to ideas enabled and explicit per-project approval');
$project=['canvas_kind'=>'custom','brief'=>'Build a kinetic paper sculpture'];
$proposal=sf_canvas_project_propose($user,['project'=>$project,'title'=>'Paper motion','concept'=>'A folding sculpture with deliberate motion.']);
check($proposal['status']==='awaiting_approval'&&$proposal['request_id']===null&&$calls===0,'Planning a stored proposal performs no build/model call');
check(sf_canvas_project_get(2,$proposal['id'])===null&&sf_canvas_project_list(2)===[],'Project reads are private to the current account');
$request=$project+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>str_repeat('a',32)];$before=sf_token_balance(1);
rejected(fn()=>sf_agent_scene_create($user,$request),SfCanvasProjectApprovalRequired::class,'Managed builds require a genuine server approval');
check($calls===0&&sf_token_balance(1)===$before,'Unapproved builds cannot reach paid inference or deduct tokens');
rejected(fn()=>sf_agent_scene_create(['id'=>2,'role'=>'user'],$request),SfCanvasProjectApprovalRequired::class,'Another account cannot consume project approval');
$approved=sf_canvas_project_approve(1,$proposal['id']);check($approved['status']==='approved'&&$approved['approval_mode']==='manual','Explicit approval is recorded for the exact project');
foreach(['brief'=>'A different design','coding_model'=>['provider'=>'openai','model'=>'different'],'music_inputs'=>['mode'=>'all'],'image_count'=>1,'image_reference_ids'=>[str_repeat('a',32)],'context_track_id'=>'song-one'] as $key=>$value){
    rejected(fn()=>sf_agent_scene_create($user,array_replace($request,[$key=>$value])),SfCanvasProjectApprovalRequired::class,'Approval rejects changed '.$key.' before any build');
}
check($calls===0&&sf_token_balance(1)===$before,'Changed creative scopes never reach paid code or image generation');
$built=sf_agent_scene_create($user,$request);$stored=sf_canvas_project_get(1,$proposal['id']);
check($calls===1&&$stored['status']==='built'&&$stored['scene_id']===$built['scene']['id'],'An approved project builds once and records its scene');
check($built['scene']['design_brief']===$project['brief']&&sf_agent_scene_history_get(1,$built['scene']['id'])['design_brief']===$project['brief'],'New generated canvases persist only their actual submitted design brief');
$replay=sf_agent_scene_create($user,$request);check($calls===1&&$replay['scene']['id']===$built['scene']['id'],'The same approved request replays without a second charge');
rejected(fn()=>sf_agent_scene_create($user,array_replace($request,['request_id'=>str_repeat('b',32)])),SfCanvasProjectConflict::class,'A built project cannot start another paid request under its old approval');
$prefs=sf_canvas_project_preferences_save(1,['ideas_enabled'=>false,'auto_approve'=>true]);check($prefs===['ideas_enabled'=>false,'auto_approve'=>true]&&sf_canvas_project_preferences(2)['auto_approve']===false,'Explicit preferences persist per account without changing other listeners');
$automatic=sf_canvas_project_propose($user,['project'=>['brief'=>'Make a quiet composition']]);check($automatic['status']==='approved'&&$automatic['approval_mode']==='automatic','Only an explicit account preference enables automatic project approval');
sf_canvas_project_preferences_save(1,['auto_approve'=>false]);
check(sf_canvas_project_get(1,$automatic['id'])['status']==='awaiting_approval','Disabling automatic approval returns unbuilt automatic proposals to review in the UI');
rejected(fn()=>sf_agent_scene_create($user,$automatic['project']+['project_managed'=>true,'proposal_id'=>$automatic['id'],'request_id'=>str_repeat('c',32)]),SfCanvasProjectApprovalRequired::class,'Turning automatic approval off protects an unbuilt automatically approved proposal');
$replacement=sf_canvas_project_propose($user,['project'=>['brief'=>'A revised quiet composition'],'replace_id'=>$automatic['id']]);
check($replacement['status']==='awaiting_approval'&&sf_canvas_project_get(1,$automatic['id'])['status']==='canceled','Editing a pending project replaces its approval scope atomically');
$reused=sf_canvas_project_propose($user,['project'=>$replacement['project'],'reuse_id'=>$replacement['id']]);check($reused['id']===$replacement['id'],'An unchanged pending or approved proposal can be reused without another planning request');
sf_canvas_project_cancel(1,$replacement['id']);rejected(fn()=>sf_canvas_project_approve(1,$replacement['id']),SfCanvasProjectConflict::class,'Canceled projects cannot regain approval silently');
$sourceIds=[];for($i=0;$i<5;$i++){$source=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Source '.$i,'html'=>'<section class="source"></section>','css'=>'.source{color:#abc}',...($i?['design_brief'=>'Real saved request '.$i]:[])]);$sourceIds[]=$source['id'];}
$foreign=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Foreign secret','html'=>'<section></section>','css'=>'']);
rejected(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Combine sources','source_scene_ids'=>[$foreign['id']]]]),InvalidArgumentException::class,'Projects cannot reference another account source canvas');
$combined=sf_canvas_project_propose($user,['project'=>['brief'=>'Combine the selected compositions','source_scene_ids'=>$sourceIds]]);sf_canvas_project_approve(1,$combined['id']);
$result=sf_agent_scene_create($user,$combined['project']+['project_managed'=>true,'proposal_id'=>$combined['id'],'request_id'=>str_repeat('d',32)]);
check(count($prompt['source_canvases']['sources'])===5&&$prompt['source_canvases']['omitted_source_count']===0,'The generator receives all five owned source canvases without a three-source cap');
check(!isset($prompt['source_canvases']['sources'][0]['design_brief'])&&$prompt['source_canvases']['sources'][1]['design_brief']==='Real saved request 1','Old prompts are never fabricated and actual saved prompts are reusable reference data');
$budget=sf_canvas_project_source_context(1,$sourceIds,1000);check(strlen(json_encode($budget))<1300,'Source code reference context obeys a byte budget');
$legacy=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'An explicit legacy API build','request_id'=>str_repeat('e',32)]);check(isset($legacy['scene']['id']),'Explicit legacy API executions retain their established contract');
check(sf_canvas_project_export(1)['proposals']!==[]&&sf_canvas_project_export(2)['proposals']===[],'Project privacy export retains the account boundary');

$fresh=sf_canvas_project_propose($user,['project'=>['brief'=>'Use this saved source','source_scene_ids'=>[$sourceIds[0]]]]);sf_canvas_project_approve(1,$fresh['id']);
$freshRequest=$fresh['project']+['project_managed'=>true,'proposal_id'=>$fresh['id'],'request_id'=>str_repeat('f',32)];
$childFile=$fixture.'/fresh-request.php';
file_put_contents($childFile,'<?php define("SF_ROOT",'.var_export($fixture,true).');require '.var_export($root.'/api/bootstrap.php',true).';require_once '.var_export($root.'/api/agent-scene-core.php',true).';$result=sf_canvas_project_begin(["id"=>1,"role"=>"user"],'.var_export($freshRequest,true).');echo $result["status"];');
$command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').' '.escapeshellarg($childFile);
$output=[];$status=0;exec($command,$output,$status);
check($status===0&&implode("\n",$output)==='building','A fresh PHP request initializes source history before its atomic approval transaction');
$beforeCancel=$calls;$canceled=sf_canvas_project_cancel(1,$fresh['id']);
check($canceled['status']==='canceled'&&sf_agent_scene_request_get(1,str_repeat('f',32))['state']==='canceled'&&$calls===$beforeCancel,'Canceling a claimed project also tombstones its worker request before any delayed paid build');
$private=sf_canvas_project_propose($user,['project'=>['brief'=>'Private project cleared before worker claim']]);sf_canvas_project_approve(1,$private['id']);
$privateRequest=$private['project']+['project_managed'=>true,'proposal_id'=>$private['id'],'request_id'=>str_repeat('9',32)];
$claim=sf_canvas_project_begin($user,$privateRequest);$capturedEpoch=$claim['_history_epoch'];
sf_privacy_clear_agent_history(1);$beforeLate=$calls;$balanceBeforeLate=sf_token_balance(1);
rejected(fn()=>sf_agent_scene_create_approved($user,$claim['project']+array_intersect_key($privateRequest,array_flip(['request_id','project_managed','proposal_id'])),null,null,null,$capturedEpoch),InvalidArgumentException::class,'A privacy clear between approval and worker claim invalidates the captured approval epoch');
check($calls===$beforeLate&&sf_token_balance(1)===$balanceBeforeLate&&sf_agent_scene_history_list(1)===[]&&sf_agent_scene_request_get(1,str_repeat('9',32))===null&&sf_canvas_project_get(1,$private['id'])===null,'Cleared approval cannot spend tokens, recreate a worker, or restore private project or scene data');
