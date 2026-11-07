<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/sf-build-agents-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());}rmdir($fixture);});
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['catalog','releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);$active=true;$lastPrompt=[];$lastSystem='';$modelMode='fixed';$calls=0;
function check(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function rejected(callable $run,string $message):void {try{$run();}catch(Throwable){check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function sf_canvas_build_workflow_assert_active(int $uid,string $requestId,string $stepId,?int $expectedEpoch=null,?int $expectedCandidateId=null):void {
    $job=$GLOBALS['job'];
    if(!$GLOBALS['active']||$uid!==1||$requestId!==$job['request_id']||$stepId!==$job['step_id']||
        sf_agent_scene_history_epoch($uid)!==$job['history_epoch']||
        ($expectedEpoch!==null&&$expectedEpoch!==$job['history_epoch'])||
        ($expectedCandidateId!==null&&$expectedCandidateId!==$job['candidate_id']))throw new SfAgentSceneBuildCanceled();
}
function sf_canvas_build_workflow_record_review(int $uid,string $requestId,string $stepId,array $report,array $usage,int $charged,?int $balance,?int $expectedEpoch=null,?int $expectedCandidateId=null):void {
    sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);
    check(sf_db()->inTransaction(),'Testing usage and the workflow result settle in the same transaction');
    sf_db()->prepare('INSERT INTO fixture_steps(role,report,charged) VALUES(?,?,?)')->execute(['testing',json_encode($report),$charged]);
}
function sf_canvas_build_workflow_record_repair(int $uid,string $requestId,string $stepId,array $scene,array $usage,int $charged,?int $balance,?int $expectedEpoch=null,?int $expectedCandidateId=null):void {
    sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);
    check(sf_db()->inTransaction(),'Repair usage and its new private candidate settle in the same transaction');
    sf_db()->prepare('INSERT INTO fixture_steps(role,report,charged) VALUES(?,?,?)')->execute(['repair',json_encode(['scene_id'=>$scene['id'],'usage'=>$usage]),$charged]);
}
function sf_canvas_build_workflow_register_reservation(int $uid,string $requestId,string $stepId,?string $key,?int $expectedEpoch=null,?int $expectedCandidateId=null):void {
    sf_canvas_build_workflow_assert_active($uid,$requestId,$stepId,$expectedEpoch,$expectedCandidateId);$GLOBALS['registeredReservation']=$key;
}
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput,int $timeout):array {
    $GLOBALS['calls']++;$GLOBALS['lastPrompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);$GLOBALS['lastSystem']=$system;
    if($GLOBALS['modelMode']==='cancel'){
        sf_agent_scene_request_cancel(1,$GLOBALS['repairRequestId']);$GLOBALS['active']=false;
    }
    $id=$GLOBALS['imageId'];
    $code=['title'=>'Repaired interaction','html'=>'<section class="scene"><figure class="sf-image-'.$id.'"></figure></section>',
        'css'=>'.scene{background:#122;color:#def}','js'=>'console.log("repaired");','workflow_required'=>true,'verification_parent_id'=>999999,
        'gallery_attribution'=>['source'=>['id'=>str_repeat('9',32),'title'=>'Model invented credit','creator_label'=>'Invented'],'ancestors'=>[]]];
    if($GLOBALS['modelMode']==='image')$code['image_request']=['prompt'=>'Generate unapproved replacement pixels','title'=>'New pixels'];
    return ['text'=>json_encode($code),'provider'=>$provider['provider'],'model'=>$provider['model'],'input_tokens'=>17,'output_tokens'=>23];
}
require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';require_once $root.'/api/canvas-build-agents.php';
$db=sf_db();$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');foreach([1,2] as $uid)$q->execute([$uid,'test'.$uid.'@example.invalid','Listener',password_hash('fixture',PASSWORD_DEFAULT),'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_token_adjust(1,1000000,'admin_grant','build-agent-fixture');
sf_enroll_user(1,null);
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'gpt-6-luna']);
sf_ai_meta_set('ai.coding_models',json_encode(['openai'=>['gpt-6-luna'],'anthropic'=>[]]));
$db->exec('CREATE TABLE fixture_steps(id INTEGER PRIMARY KEY AUTOINCREMENT,role TEXT,report TEXT,charged INTEGER)');
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'Candidate','html'=>'<section class="scene"></section>','css'=>'.scene{color:#fff}',
    'js'=>'console.log("candidate");'];
$candidate=sf_agent_scene_history_save(1,$base);$foreign=sf_agent_scene_history_save(2,$base);
$user=['id'=>1,'role'=>'user'];$project=sf_canvas_project_normalize(['brief'=>'Build an authored interactive environment.',
    'coding_model'=>['provider'=>'openai','model'=>'gpt-6-luna'],'music_inputs'=>['mode'=>'all'],'artist_inputs'=>['merch_ids'=>[]]]);
$job=['request_id'=>str_repeat('a',32),'project'=>$project,'history_epoch'=>sf_agent_scene_history_epoch(1),'candidate_id'=>$candidate['id'],'step_id'=>'testing-one'];
$evidence=['state'=>'passed','checks'=>[['id'=>'runtime','label'=>'Initialization completed','status'=>'passed']],
    'errors'=>[],'warnings'=>[],'manual'=>[['code'=>'playback','message'=>'Real playback needs a user tap.']],
    'observed'=>['main'=>true,'width'=>375,'height'=>850,'imageBindings'=>[],'datasets'=>[['id'=>'published_merch','rows'=>0,'empty'=>true,'unavailable'=>false]]]];
if(($argv[1]??'')==='--stream-only'){
    class FixtureRepairStream {
        public $context;private string $bytes='';private int $offset=0;
        public function stream_open($path,$mode,$options,&$openedPath):bool {
            if($path!=='https://api.openai.com/v1/responses')return false;
            $GLOBALS['repairStreamOptions']=stream_context_get_options($this->context);
            $GLOBALS['repairStreamPayload']=json_decode($GLOBALS['repairStreamOptions']['http']['content'],true,20,JSON_THROW_ON_ERROR);
            $code=json_encode(['concept'=>'the corrected interaction','title'=>'Streamed repair','html'=>'<section class="scene"></section>',
                'css'=>'.scene{color:#def}','js'=>'console.log("stream-fixed");']);
            $this->bytes='event: response.output_text.delta' . "\n".'data: '.json_encode(['type'=>'response.output_text.delta','delta'=>$code])."\n\n".
                'event: response.completed'."\n".'data: '.json_encode(['type'=>'response.completed','response'=>['status'=>'completed','usage'=>['input_tokens'=>17,'output_tokens'=>23]]])."\n\n";
            return true;
        }
        public function stream_read($count):string {$part=substr($this->bytes,$this->offset,$count);$this->offset+=strlen($part);return $part;}
        public function stream_eof():bool {return $this->offset>=strlen($this->bytes);}
        public function stream_stat():array {return [];}
        public function stream_set_option($option,$arg1,$arg2):bool {return true;}
    }
    stream_wrapper_unregister('https');stream_wrapper_unregister('http');stream_wrapper_register('https',FixtureRepairStream::class);
    $job['step_id']='repair-stream';$job['evidence']=$evidence;
    $report=['summary'=>'A static initialization defect needs a correction.','verdict'=>'repair','findings'=>[
        ['id'=>'initialization','severity'=>'error','area'=>'script','description'=>'Correct the candidate startup.']]];
    $before=sf_token_balance(1);$raw=[];
    $result=sf_canvas_build_repair($user,$job,$candidate,$report,str_repeat('9',32),static function(string $part)use(&$raw):void {$raw[]=$part;});
    check($result['scene']['previous_id']===$candidate['id']&&$result['scene']['js']==='console.log("stream-fixed");'&&$calls===0,
        'The Repair helper executes the actual streamed provider and internal core revision path');
    check($repairStreamOptions['http']['timeout']===-1&&$raw!==[]&&$repairStreamPayload['stream']===true,
        'Repair streams without a fixed provider deadline or stall timer');
    $streamPrompt=json_decode($repairStreamPayload['input'][0]['content'][0]['text'],true,20,JSON_THROW_ON_ERROR);
    check($streamPrompt['design_brief']===$project['brief']&&$streamPrompt['previous']['js']===$candidate['js']&&isset($streamPrompt['repair_diagnostics']),
        'The streamed Repair prompt uses the exact approved brief, actual code and separate diagnostics');
    check(is_string($registeredReservation??null)&&sf_token_balance(1)===$before-40&&$result['active_tokens_used']===40,
        'The streamed Repair hold is registered and actual usage settles with its workflow result');
    exit;
}
$normalized=sf_canvas_build_evidence($evidence);
check($normalized['observed']['width']===375&&$normalized['observed']['datasets'][0]['id']==='published_merch'&&count($normalized['manual'])===1,
    'Testing retains real bounded browser metadata and explicitly unperformed gesture checks');
check(sf_canvas_build_evidence(['state'=>'failed','observed'=>null])['observed']===[],
    'Blocked browser runs can supply no snapshot without inventing one');
$pass=['summary'=>'Static review and supplied startup observations found no blocker; playback still needs a user tap.','verdict'=>'pass','findings'=>[]];
$report=sf_canvas_build_report($pass,$normalized);
check($report['verdict']==='pass','Unperformed optional gestures remain visible without preventing every interactive canvas from passing');
$guarded=$evidence;$guarded['observed']['guarded']=true;$guarded['observed']['verification_complete']=true;
$guardedReport=sf_canvas_build_report($pass,sf_canvas_build_evidence($guarded));
check($guardedReport['verdict']==='pass'&&str_contains($guardedReport['summary'],'Instrumented preview observations.'),
    'Completed guarded previews identify their instrumentation rather than claiming pristine live verification');
$guarded['state']='incomplete';$guarded['observed']['execution_budget_exhausted']=true;$guarded['observed']['verification_complete']=false;
$guarded['observed']['execution_guard_limit']=200000;$guarded['manual'][]=['code'=>'sf_preview_budget','message'=>'The counted preview operation budget was exhausted.'];
$guardEvidence=sf_canvas_build_evidence($guarded);$guardReport=sf_canvas_build_report($pass,$guardEvidence);
check($guardEvidence['observed']['guarded']===true&&$guardEvidence['observed']['execution_budget_exhausted']===true&&
    $guardEvidence['observed']['verification_complete']===false&&$guardEvidence['observed']['execution_guard_limit']===200000,
    'Preview execution metadata survives evidence normalization with exact boolean completion flags');
check($guardReport['verdict']==='manual'&&$guardReport['findings']===[]&&str_contains($guardReport['summary'],'execution guard stopped verification'),
    'Controlled preview exhaustion requires manual attention without inventing a runtime error or automatic repair');
check(sf_canvas_build_report(array_replace($pass,['verdict'=>'repair']),$guardEvidence)['verdict']==='manual',
    'An optimistic or repair model response cannot restart an automatic loop after incomplete guarded verification');
$guarded['observed']['execution_budget_exhausted']='true';
rejected(fn()=>sf_canvas_build_evidence($guarded),'Browser completion flags must be actual booleans rather than coercible strings');
$failure=$normalized;$failure['state']='failed';$failure['errors'][]=['code'=>'runtime_error','message'=>'ReferenceError observed during initialization.'];
check(sf_canvas_build_report($pass,$failure)['verdict']==='repair','An optimistic model verdict cannot erase an actual browser error');
$resourceFailure=$failure;$resourceFailure['errors']=[['code'=>'resource_unavailable','message'=>'The authorized media service is unavailable.']];
$manualFailure=sf_canvas_build_report(array_replace($pass,['verdict'=>'manual','summary'=>'Restore the authorized resource service before verification can finish.']),$resourceFailure);
check($manualFailure['verdict']==='manual'&&array_filter($manualFailure['findings'],static fn(array $finding):bool=>
    $finding['severity']==='error'&&str_contains($finding['description'],'authorized media service')),
    'A real resource failure remains visible while a manual blocker avoids an unrelated paid code-repair loop');
check(sf_canvas_build_report($pass,sf_canvas_build_evidence(['state'=>'canceled']))['verdict']==='manual',
    'Incomplete observations require attention rather than a fabricated runtime pass');
$captured=[];$infer=static function(array $provider,string $system,string $message,int $limit,callable $cancel)use(&$captured,$pass):array {
    $captured=['provider'=>$provider,'system'=>$system,'message'=>json_decode($message,true,16,JSON_THROW_ON_ERROR)];
    check($cancel()===false,'Testing checks the durable workflow cancellation state during inference');
    return ['text'=>json_encode($pass),'provider'=>$provider['provider'],'model'=>$provider['model'],'input_tokens'=>19,'output_tokens'=>31];
};
$before=sf_token_balance(1);
$privateEvidence=$evidence;
$privateEvidence['manual'][]=['code'=>'personal_data','message'=>'Personal records are withheld from testing.'];
$review=sf_canvas_build_review($user,$job,array_replace($candidate,['js'=>'client-forged-code',
    'datasets'=>['my_purchases'=>['rows'=>[['item_label'=>'PRIVATE_PURCHASE_FIXTURE']]]]]),$privateEvidence,$job['step_id'],$infer);
check($review['active_tokens_used']===50&&sf_token_balance(1)===$before-50&&$review['report']['verdict']==='pass',
    'The Testing agent charges exactly actual selected-model usage and saves its structured assessment');
check($captured['provider']['model']===$project['coding_model']['model']&&$captured['message']['candidate']['js']===$candidate['js']&&
    $captured['message']['approved_project']['brief']===$project['brief'],
    'Testing uses the approved enabled model and actual owner-saved code rather than client-supplied source');
check(str_contains($captured['system'],'You do not run a browser')&&$review['assessment_scope']['manual_checks'][0]['code']==='playback',
    'The assessment distinguishes code review, supplied observations and tests that were not performed');
check(str_contains($captured['system'],'An empty private binding is expected in this preview')&&
    $review['assessment_scope']['manual_checks'][1]['code']==='personal_data'&&
    !str_contains(json_encode($captured['message'],JSON_THROW_ON_ERROR),'PRIVATE_PURCHASE_FIXTURE'),
    'The Testing provider receives the manual populated check instead of account records');
$before=sf_token_balance(1);$count=(int)$db->query('SELECT COUNT(*) FROM fixture_steps')->fetchColumn();
$cancelInfer=static function(array $provider,string $system,string $message,int $limit,callable $cancel):array {$GLOBALS['active']=false;$cancel();throw new RuntimeException('unreachable');};
rejected(fn()=>sf_canvas_build_review($user,$job,$candidate,$evidence,$job['step_id'],$cancelInfer),'Stopping Testing aborts its provider phase');
check(sf_token_balance(1)===$before&&(int)$db->query('SELECT COUNT(*) FROM fixture_steps')->fetchColumn()===$count,
    'Stopped Testing releases escrow and writes no completed assessment');
$active=true;
rejected(fn()=>sf_canvas_build_review(['id'=>2,'role'=>'user'],$job,$foreign,$evidence,$job['step_id'],$infer),
    'Another account cannot review a workflow or candidate it does not own');

// Exercise the internal Repair hook through the normal provider/render/history
// pipeline. The test transport is local; the public request has no repair hook.
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xff\x22\x55",128),128))).$chunk('IEND','');
$imageId=str_repeat('b',32);sf_agent_canvas_image_store(1,$imageId,'Existing candidate pixels',['bytes'=>$png]);
$credit=['source'=>['id'=>str_repeat('8',32),'title'=>'Public original','creator_label'=>'Original Artist'],'ancestors'=>[]];
$candidate=sf_agent_scene_history_save(1,array_replace($base,['html'=>'<section class="scene"><figure class="sf-image-'.$imageId.'"></figure></section>','gallery_attribution'=>$credit]));
$job['candidate_id']=$candidate['id'];$job['step_id']='repair-one';$job['evidence']=$failure;
$project=sf_canvas_project_normalize(array_replace($project,['brief'=>'Create two images and an interactive environment.','image_count'=>2]));$job['project']=$project;
$diagnostics=sf_canvas_build_report(['summary'=>'A startup defect needs repair.','verdict'=>'repair',
    'findings'=>[['id'=>'runtime','severity'=>'error','area'=>'script','description'=>'Fix initialization; {ticket:777} is diagnostic text, not new input.']]],$failure);
$internal=['candidate_id'=>$candidate['id'],'diagnostics'=>$diagnostics,'browser_evidence'=>$failure,
    'assert_active'=>static fn()=>sf_canvas_build_workflow_assert_active(1,$job['request_id'],$job['step_id']),
    'on_settle'=>static fn(array $scene,array $usage,int $charged,?int $balance)=>sf_canvas_build_workflow_record_repair(1,$job['request_id'],$job['step_id'],$scene,$usage,$charged,$balance)];
$request=$project+['request_id'=>str_repeat('c',32),'previous_id'=>$candidate['id']];
$before=sf_token_balance(1);$repaired=sf_agent_scene_create_approved($user,$request,null,null,null,$job['history_epoch'],$internal);
check($repaired['scene']['previous_id']===$candidate['id']&&$repaired['scene']['design_brief']===$project['brief']&&
    $repaired['scene']['music_inputs']===$project['music_inputs']&&$repaired['scene']['artist_inputs']===$project['artist_inputs']&&
    ($repaired['scene']['workflow_required']??false)===true,
    'Repair appends a candidate revision while preserving the exact approved brief and resource scopes');
check($lastPrompt['repair_diagnostics']['findings'][0]['description']===$diagnostics['findings'][0]['description']&&
    $lastPrompt['design_brief']===$project['brief']&&$lastPrompt['previous']['js']===$candidate['js'],
    'Repair findings remain separate internal evidence and cannot become resource-picker instructions');
check(($repaired['scene']['gallery_attribution']??null)===$credit,
    'The actual Repair generation keeps trusted saved gallery attribution and ignores model-invented credits');
check($lastPrompt['canvas_image_tool']['requested_new_count']===0&&!$lastPrompt['canvas_image_tool']['generation_enabled']&&
    array_column($lastPrompt['canvas_image_tool']['saved_images'],'id')===[$imageId]&&sf_token_balance(1)===$before-40,
    'Repair reuses only existing candidate pixels and charges just its actual coding usage');
$modelMode='image';$before=sf_token_balance(1);$request['request_id']=str_repeat('d',32);
rejected(fn()=>sf_agent_scene_create_approved($user,$request,null,null,null,$job['history_epoch'],$internal),
    'A Repair model cannot mint new image requests even when the approved original brief asked for images');
check(sf_token_balance(1)===$before,'Rejected image generation in Repair releases its coding reservation');
$modelMode='cancel';$repairRequestId=str_repeat('e',32);$request['request_id']=$repairRequestId;$before=sf_token_balance(1);
$count=count(sf_agent_scene_history_export(1));$stopped=sf_agent_scene_create_approved($user,$request,null,null,null,$job['history_epoch'],$internal);
check(($stopped['state']??null)==='canceled'&&count(sf_agent_scene_history_export(1))===$count&&sf_token_balance(1)===$before,
    'A late stopped Repair response cannot save a candidate or charge the listener');
$active=true;$modelMode='fixed';$request=['canvas_kind'=>'custom','track_id'=>'','brief'=>'An ordinary canvas.','request_id'=>str_repeat('f',32),
    'repair_diagnostics'=>$diagnostics,'repairData'=>$internal,'workflow_required'=>true];
$legacyResult=sf_agent_scene_create_approved($user,$request);
check(!isset($lastPrompt['repair_diagnostics'])&&!str_contains($lastSystem,'You are the Repair agent'),
    'Public request fields cannot activate the internal Repair agent hook');
check(!isset($legacyResult['scene']['workflow_required']),
    'Neither a model field nor a raw legacy request can choose the internal workflow marker');
$managed=sf_agent_scene_create_approved($user,['canvas_kind'=>'custom','track_id'=>'','brief'=>'A managed canvas.',
    'request_id'=>str_repeat('1a',16),'project_managed'=>true]);
check(($managed['scene']['workflow_required']??false)===true&&!isset($managed['scene']['verification_parent_id']),
    'A new managed model candidate is marked before the workflow start handoff');
check(!isset($managed['scene']['gallery_attribution']),
    'A model cannot add gallery creator attribution to a new original canvas');
$attributed=sf_agent_scene_create_approved($user,['canvas_kind'=>'custom','track_id'=>'','brief'=>'Rework the scene composition.',
    'previous_id'=>$candidate['id'],'request_id'=>str_repeat('4a',16),'project_managed'=>true,
    'gallery_attribution'=>['source'=>['id'=>str_repeat('5',32),'title'=>'Client invented credit','creator_label'=>'Invented'],'ancestors'=>[]]]);
check(($attributed['scene']['gallery_attribution']??null)===$credit&&($attributed['scene']['workflow_required']??false)===true,
    'The actual managed Coding revision preserves owned saved ancestry despite forged request and model credit fields');
$attributedLocal=sf_agent_scene_create_approved($user,['canvas_kind'=>'custom','track_id'=>'','brief'=>'{design-agent}',
    'previous_id'=>$attributed['scene']['id'],'request_id'=>str_repeat('5a',16),'project_managed'=>true]);
check(($attributedLocal['scene']['gallery_attribution']??null)===$credit&&$attributedLocal['active_tokens_used']===0,
    'Free local agent-design revisions retain the same trusted gallery ancestry');
$callsBeforeLocal=$calls;
$local=sf_agent_scene_create_approved($user,['canvas_kind'=>'custom','track_id'=>'','brief'=>'{design-agent}',
    'previous_id'=>$managed['scene']['id'],'request_id'=>str_repeat('3a',16),'project_managed'=>true]);
check($calls===$callsBeforeLocal&&$local['active_tokens_used']===0&&($local['scene']['workflow_required']??false)===true&&
    ($local['scene']['verification_parent_id']??null)===$managed['scene']['id'],
    'Free metadata copies retain the workflow requirement and link to their exact source without a new model call');
$pixelSource=['id'=>71,'workflow_required'=>true,'verification_parent_id'=>66,'html'=>'<figure class="sf-image-'.$imageId.'"></figure>',
    'css'=>'figure{background-image:var(--sf-image-'.$imageId.',none)}','js'=>'console.log("keep behavior");'];
$pixelCopy=sf_agent_canvas_image_apply($pixelSource,str_repeat('6',32),$imageId);
check($pixelCopy['verification_parent_id']===71&&$pixelCopy['js']===$pixelSource['js']&&str_contains($pixelCopy['html'],str_repeat('6',32)),
    'Image-only copies link the current source rather than inheriting an older verification ancestor');
$initialPixels=sf_agent_canvas_image_apply(['workflow_required'=>true,'html'=>'<figure class="sf-image-new"></figure>','css'=>''],str_repeat('7',32));
check(!isset($initialPixels['verification_parent_id']),
    'Initial image placement in new generated code never invents a verification parent');
$builtIn=sf_agent_scene_create_approved($user,['canvas_kind'=>'space_eq','track_id'=>'','brief'=>'',
    'request_id'=>str_repeat('2a',16),'project_managed'=>true]);
check(!isset($builtIn['scene']['workflow_required']),
    'Safe built-in and local outputs retain their established marker-free response shape');
$command=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'').
    ' -d disable_functions=curl_init -d allow_url_fopen=0 '.escapeshellarg(__FILE__).' --stream-only';
$output=[];$status=0;exec($command,$output,$status);
if($status!==0)throw new RuntimeException('Streamed Repair fixture failed: '.implode("\n",$output));
foreach($output as $line)echo $line.PHP_EOL;
