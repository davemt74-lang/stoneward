<?php
declare(strict_types=1);
require __DIR__.'/canvas-projects.php';
require_once $root.'/api/canvas-build-workflow-core.php';
$workflowChecks=0;
function workflow_check(bool $ok,string $message): void {global $workflowChecks;if(!$ok)throw new RuntimeException($message);$workflowChecks++;echo 'WORKFLOW PASS '.$message.PHP_EOL;}
function workflow_reject(callable $run,string $class,string $message): void {try{$run();}catch(Throwable $error){if($error instanceof $class){workflow_check(true,$message);return;}throw $error;}throw new RuntimeException('Expected rejection: '.$message);}
function workflow_seed(string $requestId,string $brief='Build a kinetic paper sculpture'): array {
    global $user;
    $proposal=sf_canvas_project_propose($user,['project'=>['canvas_kind'=>'custom','brief'=>$brief]]);sf_canvas_project_approve(1,$proposal['id']);
    $result=sf_agent_scene_create($user,$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>$requestId]);
    return [$proposal,$result['scene']];
}
$original=str_repeat('2a',16);[$approved,$candidate]=workflow_seed($original);$before=$calls;
workflow_check(sf_canvas_build_workflow_for_scene(1,$candidate['id'])===null&&!sf_canvas_build_workflow_table_exists('canvas_build_jobs'),
    'Reading saved-scene workflow state does not create optional workflow storage');
$job=sf_canvas_build_workflow_start($user,$original,$candidate['id']);
workflow_check($job['state']==='testing'&&$job['candidate_id']===$candidate['id']&&$job['proposal_id']===$approved['id'],'Workflow starts from exactly its approved saved candidate');
workflow_check(sf_canvas_build_workflow_for_scene(1,$candidate['id'])===$job&&sf_canvas_build_workflow_for_scene(2,$candidate['id'])===null,
    'Saved-scene lookup exposes untested state and role logs only to the owning account');
workflow_check(count($job['steps'])===1&&$job['steps'][0]['role']==='coding'&&$job['total_active_tokens_used']===30&&$calls===$before,'Original Coding usage is authoritative and starting a workflow makes no new model call');
workflow_check(sf_canvas_build_workflow_start($user,$original,$candidate['id'])===$job,'Starting again returns the same durable job without a duplicate Coding step');
workflow_check(sf_canvas_build_workflow_get(2,$original)===null,'A workflow and its role logs are private to the account');
workflow_reject(fn()=>sf_canvas_build_workflow_start(['id'=>2,'role'=>'user'],$original,$candidate['id']),InvalidArgumentException::class,'Another account cannot start a workflow from an owned scene');
workflow_reject(fn()=>sf_canvas_build_workflow_apply($user,$original,$candidate['id']),SfCanvasBuildWorkflowConflict::class,'Untested code can never be acknowledged as applied');
$evidence=['state'=>'passed','checks'=>[['id'=>'startup','label'=>'Startup observed','status'=>'passed']],'errors'=>[],'manual'=>[['code'=>'real-playback','message'=>'Actual playback still requires a user gesture.']],'observed'=>['main'=>true,'elements'=>2]];
$testingCalls=0;$pass=static function(array $provider,string $system,string $message,int $maxOutput,callable $isCanceled)use(&$testingCalls):array {$testingCalls++;$isCanceled();return ['text'=>json_encode(['verdict'=>'pass','summary'=>'Static code and supplied startup checks passed. Playback remains a manual check.','findings'=>[]]),'provider'=>'openai','model'=>'fixture','input_tokens'=>12,'output_tokens'=>23];};
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$original,str_repeat('3a',16),$candidate['id']+100,$evidence,$pass),SfCanvasBuildWorkflowConflict::class,'Browser evidence is bound to the exact current candidate before any paid review');
$balance=sf_token_balance(1);$ready=sf_canvas_build_workflow_review($user,$original,str_repeat('3a',16),$candidate['id'],$evidence,$pass);
workflow_check($ready['state']==='ready'&&$ready['review_id']===str_repeat('3a',16)&&$testingCalls===1,'Testing records a grounded assessment and leaves manual checks visible without falsely claiming them performed');
workflow_check(sf_token_balance(1)===$balance-35&&$ready['total_active_tokens_used']===65&&$ready['steps'][1]['usage']['input_tokens']===12,'Testing charges once and records its separate provider usage and aggregate credits');
$again=sf_canvas_build_workflow_review($user,$original,str_repeat('3a',16),$candidate['id'],$evidence,$pass);
workflow_check($again['state']==='ready'&&$testingCalls===1&&sf_token_balance(1)===$balance-35,'Replaying a completed Testing request neither re-runs nor double-charges');
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$original,str_repeat('3a',16),$candidate['id'],array_replace($evidence,['state'=>'failed']),$pass),SfCanvasBuildWorkflowConflict::class,'A step ID cannot be reused for different browser evidence');
$applied=sf_canvas_build_workflow_apply($user,$original,$candidate['id']);workflow_check($applied['state']==='applied'&&sf_canvas_build_workflow_cancel($user,$original)['state']==='applied','Only a tested candidate becomes applied, and a later Stop never rewrites a completed result');

// Actual browser failures remain failures even when a language model says pass.
$repairOriginal=str_repeat('4a',16);[, $broken]=workflow_seed($repairOriginal);sf_canvas_build_workflow_start($user,$repairOriginal,$broken['id']);
$failedEvidence=['state'=>'failed','checks'=>[['id'=>'startup','label'=>'Startup observed','status'=>'failed','detail'=>'The source did not initialize.']],'errors'=>[['code'=>'runtime_reference','message'=>'ReferenceError','line'=>4]],'observed'=>null];
$repairReviewId=str_repeat('5a',16);$needs=sf_canvas_build_workflow_review($user,$repairOriginal,$repairReviewId,$broken['id'],$failedEvidence,$pass);
workflow_check($needs['state']==='needs_repair'&&$needs['steps'][1]['report']['verdict']==='repair','A model pass cannot erase the browser failure evidence');
$repairCalls=0;
$worker=static function(array $account,array $state,array $old,array $report,string $repairRequestId)use(&$repairCalls):void {
    $repairCalls++;sf_canvas_build_workflow_assert_active(1,$state['request_id'],$state['step_id']);
    $key=sf_ai_reserve_tokens($account,100,'fixture-repair');sf_canvas_build_workflow_register_reservation(1,$state['request_id'],$state['step_id'],$key);
    sf_ai_finish_reservation($account,$key,25,['provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>15],'fixture-repair',true,
        static function(int $remaining)use($state,$old):void {
            $scene=$old;unset($scene['id'],$scene['previous_id'],$scene['created_at']);$scene['html']='<section class="scene"><div class="repaired"></div></section>';
            $stored=sf_agent_scene_history_save(1,$scene,$old['id'],$state['history_epoch']);
            sf_canvas_build_workflow_record_repair(1,$state['request_id'],$state['step_id'],$stored,['provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>15],25,$remaining);
        });
};
workflow_reject(fn()=>sf_canvas_build_workflow_repair($user,$repairOriginal,str_repeat('6a',16),str_repeat('fa',16),null,null,$worker),SfCanvasBuildWorkflowConflict::class,'Repair accepts only the current server-owned Testing report');
$repaired=sf_canvas_build_workflow_repair($user,$repairOriginal,str_repeat('6a',16),$repairReviewId,null,null,$worker);
workflow_check($repaired['state']==='testing'&&$repaired['candidate_id']!==$broken['id']&&$repaired['steps'][2]['role']==='repair'&&$repaired['steps'][2]['active_tokens_used']===25&&$repaired['steps'][2]['report']['changed_fields']===['html'],'A metered repair atomically saves a new candidate for another test without applying it');
workflow_check(sf_canvas_build_workflow_for_scene(1,$broken['id'])===$repaired&&sf_canvas_build_workflow_for_scene(1,$repaired['candidate_id'])===$repaired,
    'Both original and repaired saved scenes reveal the current candidate rather than authorizing the older scene');
workflow_check($repaired['project']===$approved['project']&&$repaired['total_active_tokens_used']===90,'Repair keeps the immutable approved scope and sums Coding, Testing and Repair charges once');
sf_canvas_build_workflow_repair($user,$repairOriginal,str_repeat('6a',16),$repairReviewId,null,null,$worker);workflow_check($repairCalls===1,'A repair step replay never repeats provider work');
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$repairOriginal,str_repeat('7a',16),$broken['id'],$evidence,$pass),SfCanvasBuildWorkflowConflict::class,'Observations of the previous candidate cannot be attached to repaired code');
$verified=sf_canvas_build_workflow_review($user,$repairOriginal,str_repeat('7a',16),$repaired['candidate_id'],$evidence,$pass);workflow_check($verified['state']==='ready'&&$verified['candidate_id']===$repaired['candidate_id'],'Repaired code becomes ready only after its own fresh test assessment');

// Stop before start is a persistent tombstone addressable by the original request.
$stoppedId=str_repeat('8a',16);[, $stoppedScene]=workflow_seed($stoppedId);sf_canvas_build_workflow_cancel($user,$stoppedId);
$stopped=sf_canvas_build_workflow_start($user,$stoppedId,$stoppedScene['id']);workflow_check($stopped['state']==='canceled','Stop before workflow start prevents a late startup response from restarting the agents');
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$stoppedId,str_repeat('9a',16),$stoppedScene['id'],$evidence,$pass),SfCanvasBuildWorkflowConflict::class,'Canceled workflows cannot begin a later Testing call');

// Cancellation and privacy clearing during inference roll back settlement.
$cancelId=str_repeat('aa',16);[, $cancelScene]=workflow_seed($cancelId);sf_canvas_build_workflow_start($user,$cancelId,$cancelScene['id']);$beforeCancel=sf_token_balance(1);
$cancelInfer=static function(array $provider,string $system,string $message,int $limit,callable $isCanceled)use($user,$cancelId):array {sf_canvas_build_workflow_cancel($user,$cancelId);$isCanceled();throw new RuntimeException('Unreachable provider continuation.');};
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$cancelId,str_repeat('ab',16),$cancelScene['id'],$evidence,$cancelInfer),SfAgentSceneBuildCanceled::class,'A stopped in-flight Testing call cannot commit a late result');
workflow_check(sf_canvas_build_workflow_get(1,$cancelId)['state']==='canceled'&&sf_token_balance(1)===$beforeCancel,'Stopped Testing releases its hold and preserves the canceled job');
$privacyId=str_repeat('ac',16);[, $privacyScene]=workflow_seed($privacyId);sf_canvas_build_workflow_start($user,$privacyId,$privacyScene['id']);$beforePrivacy=sf_token_balance(1);
$privacyInfer=static function(array $provider,string $system,string $message,int $limit,callable $isCanceled):array {sf_privacy_clear_agent_history(1);$isCanceled();throw new RuntimeException('Unreachable late inference.');};
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$privacyId,str_repeat('ad',16),$privacyScene['id'],$evidence,$privacyInfer),SfAgentSceneBuildCanceled::class,'Privacy reset invalidates an in-flight workflow before its token/result commit');
workflow_check(sf_canvas_build_workflow_get(1,$privacyId)===null&&sf_agent_scene_history_list(1)===[]&&sf_token_balance(1)===$beforePrivacy,'Late workflow results cannot recreate cleared jobs, steps, candidates or charges');
workflow_check(sf_canvas_build_workflow_for_scene(1,$privacyScene['id'])===null&&sf_canvas_build_workflow_for_scene(1,$broken['id'])===null,
    'Privacy clearing removes saved-scene workflow associations and role logs');
$manualId=str_repeat('ba',16);[, $manualScene]=workflow_seed($manualId);sf_canvas_build_workflow_start($user,$manualId,$manualScene['id']);
$manualInfer=static fn()=>['text'=>json_encode(['verdict'=>'manual','summary'=>'The requested device interaction still needs a person to verify it.','findings'=>[]]),'provider'=>'openai','model'=>'fixture','input_tokens'=>4,'output_tokens'=>6];
$manualJob=sf_canvas_build_workflow_review($user,$manualId,str_repeat('bb',16),$manualScene['id'],$evidence,$manualInfer);
workflow_check($manualJob['state']==='needs_attention','A blocking manual assessment never auto-applies unverified code');
$noProgressId=str_repeat('bc',16);[, $sameScene]=workflow_seed($noProgressId);sf_canvas_build_workflow_start($user,$noProgressId,$sameScene['id']);
$sameReview=str_repeat('bd',16);sf_canvas_build_workflow_review($user,$noProgressId,$sameReview,$sameScene['id'],$failedEvidence,$pass);
$sameWorker=static function(array $account,array $state,array $old):void {
    $key=sf_ai_reserve_tokens($account,100,'fixture-repair');sf_canvas_build_workflow_register_reservation(1,$state['request_id'],$state['step_id'],$key);
    sf_ai_finish_reservation($account,$key,20,['provider'=>'openai','model'=>'fixture','input_tokens'=>8,'output_tokens'=>12],'fixture-repair',true,static function(int $remaining)use($state,$old):void {
        $copy=$old;unset($copy['id'],$copy['previous_id'],$copy['created_at']);$stored=sf_agent_scene_history_save(1,$copy,$old['id'],$state['history_epoch']);
        sf_canvas_build_workflow_record_repair(1,$state['request_id'],$state['step_id'],$stored,['provider'=>'openai','model'=>'fixture','input_tokens'=>8,'output_tokens'=>12],20,$remaining);
    });
};
$unchanged=sf_canvas_build_workflow_repair($user,$noProgressId,str_repeat('be',16),$sameReview,null,null,$sameWorker);
workflow_check($unchanged['state']==='needs_attention'&&$unchanged['steps'][2]['report']['no_progress']===true,'An unchanged repair halts durably for attention without a timer or retry-count rule');
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$noProgressId,str_repeat('bf',16),$unchanged['candidate_id'],$evidence,$pass),SfCanvasBuildWorkflowConflict::class,'No-progress candidates cannot silently restart an automatic test-repair loop');
$repairStopId=str_repeat('ca',16);[, $repairStopScene]=workflow_seed($repairStopId);sf_canvas_build_workflow_start($user,$repairStopId,$repairStopScene['id']);
$repairStopReview=str_repeat('cb',16);sf_canvas_build_workflow_review($user,$repairStopId,$repairStopReview,$repairStopScene['id'],$failedEvidence,$pass);$beforeRepairStop=sf_token_balance(1);$repairStopped=false;
$stopRepairWorker=static function(array $account,array $state,array $old,array $report,string $repairRequestId)use(&$repairStopped):void {
    sf_canvas_build_workflow_cancel($account,$state['request_id']);
    $repairStopped=(sf_agent_scene_request_get(1,$repairRequestId)['state']??null)==='canceled';
    sf_canvas_build_workflow_assert_active(1,$state['request_id'],$state['step_id']);
};
workflow_reject(fn()=>sf_canvas_build_workflow_repair($user,$repairStopId,str_repeat('cc',16),$repairStopReview,null,null,$stopRepairWorker),SfAgentSceneBuildCanceled::class,'Stop cancels the persisted Repair request before a delayed worker can claim it');
workflow_check($repairStopped&&sf_token_balance(1)===$beforeRepairStop&&sf_canvas_build_workflow_get(1,$repairStopId)['candidate_id']===$repairStopScene['id'],'Stopping Repair preserves the old candidate and adds no repair charge');
$concurrentId=str_repeat('cd',16);[, $concurrentScene]=workflow_seed($concurrentId);sf_canvas_build_workflow_start($user,$concurrentId,$concurrentScene['id']);$concurrentStep=str_repeat('ce',16);$observedPending=false;$concurrentCalls=0;
$concurrentInfer=static function()use($user,$concurrentId,$concurrentStep,$concurrentScene,$evidence,$pass,&$observedPending,&$concurrentCalls):array {
    $concurrentCalls++;$existing=sf_canvas_build_workflow_review($user,$concurrentId,$concurrentStep,$concurrentScene['id'],$evidence,$pass);$observedPending=$existing['state']==='reviewing';
    return ['text'=>json_encode(['verdict'=>'pass','summary'=>'The supplied startup checks passed; other interactions remain manual.','findings'=>[]]),'provider'=>'openai','model'=>'fixture','input_tokens'=>4,'output_tokens'=>6];
};
sf_canvas_build_workflow_review($user,$concurrentId,$concurrentStep,$concurrentScene['id'],$evidence,$concurrentInfer);
workflow_check($observedPending&&$concurrentCalls===1,'A simultaneous duplicate step observes running status and never starts another metered agent');
$paletteId=str_repeat('da',16);[, $paletteScene]=workflow_seed($paletteId);sf_canvas_build_workflow_start($user,$paletteId,$paletteScene['id']);$paletteReview=str_repeat('db',16);
sf_canvas_build_workflow_review($user,$paletteId,$paletteReview,$paletteScene['id'],$failedEvidence,$pass);
$paletteWorker=static function(array $account,array $state,array $old):void {
    $key=sf_ai_reserve_tokens($account,100,'fixture-repair');sf_canvas_build_workflow_register_reservation(1,$state['request_id'],$state['step_id'],$key);
    sf_ai_finish_reservation($account,$key,20,['provider'=>'openai','model'=>'fixture','input_tokens'=>8,'output_tokens'=>12],'fixture-repair',true,static function(int $remaining)use($state,$old):void {
        $copy=$old;unset($copy['id'],$copy['previous_id'],$copy['created_at']);$copy['agent_palette']=['accent'=>'#aaddff','secondary'=>'#eeddcc','surface'=>'#101820'];
        $stored=sf_agent_scene_history_save(1,$copy,$old['id'],$state['history_epoch']);sf_canvas_build_workflow_record_repair(1,$state['request_id'],$state['step_id'],$stored,['provider'=>'openai','model'=>'fixture','input_tokens'=>8,'output_tokens'=>12],20,$remaining);
    });
};
$paletteJob=sf_canvas_build_workflow_repair($user,$paletteId,str_repeat('dc',16),$paletteReview,null,null,$paletteWorker);
workflow_check($paletteJob['state']==='testing'&&$paletteJob['steps'][2]['report']['no_progress']===false&&$paletteJob['steps'][2]['report']['changed_fields']===['agent_palette'],'A real agent palette correction counts as presentation progress and receives a fresh test');
$guardId=str_repeat('ea',16);[, $guardScene]=workflow_seed($guardId);sf_canvas_build_workflow_start($user,$guardId,$guardScene['id']);
$guardEvidence=['state'=>'incomplete','checks'=>[['id'=>'execution-budget','label'=>'Instrumented execution did not finish','status'=>'manual']],
    'errors'=>[],'warnings'=>[['code'=>'preview-guard','message'=>'The protected preview stopped before verification completed.']],
    'observed'=>['execution_budget_exhausted'=>true,'verification_complete'=>false,'guarded'=>true]];
$guardJob=sf_canvas_build_workflow_review($user,$guardId,str_repeat('eb',16),$guardScene['id'],$guardEvidence,$pass);
workflow_check($guardJob['state']==='needs_attention'&&$guardJob['steps'][1]['report']['verdict']==='manual','Incomplete protected preview evidence cannot become ready or start an automatic repair loop');
$removedId=str_repeat('fa',16);[, $removedScene]=workflow_seed($removedId);sf_canvas_build_workflow_start($user,$removedId,$removedScene['id']);sf_agent_scene_delete(1,$removedScene['id']);$beforeRemoved=$testingCalls;
$removed=sf_canvas_build_workflow_review($user,$removedId,str_repeat('fb',16),$removedScene['id'],$evidence,$pass);
workflow_check($removed['state']==='needs_attention'&&$testingCalls===$beforeRemoved,'A deleted candidate halts before paid Testing instead of leaving a runnable retry loop');
workflow_check(sf_canvas_build_workflow_for_scene(1,$removedScene['id'])===null,
    'A deleted scene cannot expose a former workflow through its old numeric ID');

// A saved intermediate repair is neither the original nor the latest candidate.
$intermediateId=$paletteJob['candidate_id'];$nextReview=str_repeat('dd',16);
sf_canvas_build_workflow_review($user,$paletteId,$nextReview,$intermediateId,$failedEvidence,$pass);
$latest=sf_canvas_build_workflow_repair($user,$paletteId,str_repeat('de',16),$nextReview,null,null,$worker);
workflow_check($latest['candidate_id']!==$intermediateId&&sf_canvas_build_workflow_for_scene(1,$intermediateId)===$latest,
    'Intermediate repair scenes retain their role history while identifying the latest untested candidate');
$latestReady=sf_canvas_build_workflow_review($user,$paletteId,str_repeat('df',16),$latest['candidate_id'],$evidence,$pass);
workflow_check(sf_canvas_build_workflow_for_scene(1,$latestReady['candidate_id'])===$latestReady&&$latestReady['state']==='ready',
    'A current verified saved scene exposes its durable ready state and complete role history');

// A privacy reset permits fresh requests, but cannot let an old worker act on reused IDs.
$reusedRequest=str_repeat('f1',16);$reusedStep=str_repeat('f2',16);[, $oldReusedScene]=workflow_seed($reusedRequest);
sf_canvas_build_workflow_start($user,$reusedRequest,$oldReusedScene['id']);
$oldClaim=sf_canvas_build_workflow_claim($user,$reusedRequest,$reusedStep,'testing',['candidate_scene_id'=>$oldReusedScene['id'],'evidence'=>$evidence]);
sf_privacy_clear_agent_history(1);[, $freshScene]=workflow_seed($reusedRequest);sf_canvas_build_workflow_start($user,$reusedRequest,$freshScene['id']);
$freshClaim=sf_canvas_build_workflow_claim($user,$reusedRequest,$reusedStep,'testing',['candidate_scene_id'=>$freshScene['id'],'evidence'=>$evidence]);
$capturedEpoch=$oldClaim['workflow']['history_epoch'];$capturedCandidate=$oldClaim['candidate']['id'];
sf_canvas_build_workflow_fail(1,$reusedRequest,$reusedStep,true,$capturedEpoch,$capturedCandidate);
workflow_check(sf_canvas_build_workflow_get(1,$reusedRequest)['state']==='reviewing'&&sf_canvas_build_workflow_step(1,$reusedRequest,$reusedStep)['state']==='running',
    'An old worker failure cannot cancel fresh same-ID work after privacy clearing');
workflow_reject(fn()=>sf_canvas_build_workflow_assert_active(1,$reusedRequest,$reusedStep,$capturedEpoch,$capturedCandidate),SfAgentSceneBuildCanceled::class,
    'A captured privacy epoch and candidate reject old same-ID provider continuations');
workflow_reject(fn()=>sf_canvas_build_workflow_register_reservation(1,$reusedRequest,$reusedStep,str_repeat('f3',16),$capturedEpoch,$capturedCandidate),SfAgentSceneBuildCanceled::class,
    'A stale worker cannot attach a token hold to the new same-ID step');
$db=sf_db();$db->beginTransaction();
workflow_reject(fn()=>sf_canvas_build_workflow_record_review(1,$reusedRequest,$reusedStep,['verdict'=>'pass','summary'=>'Stale result'],[],0,null,$capturedEpoch,$capturedCandidate),SfAgentSceneBuildCanceled::class,
    'A stale same-ID Testing result cannot settle into a new workflow');
$db->rollBack();
workflow_check(sf_canvas_build_workflow_get(1,$reusedRequest)['state']==='reviewing'&&sf_canvas_build_workflow_get(1,$reusedRequest)['candidate_id']===$freshScene['id'],
    'Rejected late callbacks leave the new candidate and role state unchanged');
$providerRequest=str_repeat('f4',16);$providerStep=str_repeat('f5',16);[, $providerOldScene]=workflow_seed($providerRequest);
sf_canvas_build_workflow_start($user,$providerRequest,$providerOldScene['id']);$providerFreshId=null;
$resetSameIds=static function(array $provider,string $system,string $message,int $limit,callable $check)use($user,$providerRequest,$providerStep,$evidence,&$providerFreshId):array {
    sf_privacy_clear_agent_history(1);[, $fresh]=workflow_seed($providerRequest);$providerFreshId=$fresh['id'];
    sf_canvas_build_workflow_start($user,$providerRequest,$fresh['id']);
    sf_canvas_build_workflow_claim($user,$providerRequest,$providerStep,'testing',['candidate_scene_id'=>$fresh['id'],'evidence'=>$evidence]);
    $check();throw new RuntimeException('Unreachable stale provider continuation.');
};
workflow_reject(fn()=>sf_canvas_build_workflow_review($user,$providerRequest,$providerStep,$providerOldScene['id'],$evidence,$resetSameIds),SfAgentSceneBuildCanceled::class,
    'The actual Testing provider cancellation callback rejects a cleared worker even when IDs were reused');
workflow_check(sf_canvas_build_workflow_get(1,$providerRequest)['state']==='reviewing'&&sf_canvas_build_workflow_get(1,$providerRequest)['candidate_id']===$providerFreshId,
    'The coordinator catch preserves the fresh same-ID workflow after a stale provider callback');
echo 'Canvas build workflow: '.$workflowChecks.' checks passed'.PHP_EOL;
