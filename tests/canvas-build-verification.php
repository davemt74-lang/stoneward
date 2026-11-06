<?php
declare(strict_types=1);
require __DIR__.'/canvas-projects.php';
require_once $root.'/api/canvas-build-workflow-core.php';
$verificationChecks=0;$beforeProviderCalls=$calls;
function verification_check(bool $ok,string $message):void {global $verificationChecks;if(!$ok)throw new RuntimeException($message);$verificationChecks++;echo 'VERIFICATION PASS '.$message.PHP_EOL;}
function verification_reject(callable $run,string $message):void {try{$run();}catch(InvalidArgumentException){verification_check(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$imageA=str_repeat('a',32);$imageB=str_repeat('b',32);
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'Verified code fixture','html'=>'<section class="sf-image-'.$imageA.'"></section>',
    'css'=>'section{background:var(--sf-image-'.$imageA.')}','js'=>'window.fixtureReady=true;'];
$legacy=sf_agent_scene_history_save(1,$base);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$legacy)===null&&!sf_canvas_build_workflow_table_exists('canvas_build_jobs'),
    'A lineage read makes no workflow tables and does not invent a link for an ordinary legacy scene');
$pixel=$base;$pixel['workflow_required']=true;$pixel['verification_parent_id']=$legacy['id'];
$pixel['html']=str_replace($imageA,'ref-'.$imageB,$pixel['html']);$pixel['css']=str_replace($imageA,'ref-'.$imageB,$pixel['css']);
$pixel=sf_agent_scene_history_save(1,$pixel,$legacy['id']);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$pixel)===['verified'=>true,'source_scene_id'=>$legacy['id']],
    'Only image binding identities may change when a free derivative reuses legacy verified code');
verification_check(sf_agent_scene_history_get(1,$pixel['id'])['verification_parent_id']===$legacy['id']&&
    sf_agent_scene_history_list(1)[0]['verification_parent_id']===$legacy['id']&&
    array_column(sf_agent_scene_history_export(1),'verification_parent_id','id')[$pixel['id']]===$legacy['id']&&
    !array_key_exists('verification_parent_id',$legacy),
    'Explicit lineage survives history get, summary and export while old scene shapes stay unchanged');
$metadata=$pixel;unset($metadata['id'],$metadata['created_at'],$metadata['previous_id']);$metadata['verification_parent_id']=$pixel['id'];
$metadata['agent_design']='hidden';$metadata['music_inputs']=['mode'=>'all'];$metadata=sf_agent_scene_history_save(1,$metadata,$pixel['id']);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$metadata)===['verified'=>true,'source_scene_id'=>$legacy['id']],
    'Owned same-code metadata and music changes can follow an explicit multi-generation image lineage');
verification_check(sf_canvas_build_workflow_verified_derivative(2,$metadata)===null&&sf_canvas_build_workflow_verified_derivative(1,['id'=>PHP_INT_MAX])===null,
    'Foreign and missing scene IDs never inherit another owner verification');
foreach(['js'=>'window.fixtureReady=false;','html'=>'<article class="sf-image-'.$imageA.'"></article>','css'=>'section{color:red}'] as $field=>$changed){
    $copy=$metadata;unset($copy['id'],$copy['created_at'],$copy['previous_id']);$copy['verification_parent_id']=$metadata['id'];$copy[$field]=$changed;
    $copy=sf_agent_scene_history_save(1,$copy,$metadata['id']);
    verification_check(sf_canvas_build_workflow_verified_derivative(1,$copy)===null,'A '.$field.' code change cannot reuse ancestor verification');
}
$model=sf_agent_scene_history_save(1,$base+['workflow_required'=>true]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$model)===null,'Matching model code without an explicit host lineage does not inherit verification');
$unreadyChild=sf_agent_scene_history_save(1,$base+['workflow_required'=>true,'verification_parent_id'=>$model['id']],$model['id']);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$unreadyChild)===null,'An untested model parent cannot launder its code through a free same-code derivative');
sf_canvas_build_workflow_ensure_schema();$request=str_repeat('91',16);$now=gmdate('c');
sf_db()->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,original_scene_id,candidate_scene_id,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,?,?,'ready','',?,?)")
    ->execute([1,$request,$model['id'],$model['id'],sf_agent_scene_history_epoch(1),$now,$now]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$unreadyChild)===['verified'=>true,'source_scene_id'=>$model['id'],'request_id'=>$request],
    'An exact current ready workflow candidate can pass verification to an explicitly linked same-code derivative');
sf_db()->prepare("UPDATE canvas_build_jobs SET state='needs_attention' WHERE user_id=1 AND request_id=?")->execute([$request]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$unreadyChild)===null,'A workflow needing attention cannot lend verification to an image or metadata copy');
$replacement=sf_agent_scene_history_save(1,$base+['workflow_required'=>true]);
sf_db()->prepare("UPDATE canvas_build_jobs SET state='applied',candidate_scene_id=? WHERE user_id=1 AND request_id=?")->execute([$replacement['id'],$request]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$unreadyChild)===null,'A superseded original candidate cannot borrow the newer candidate applied status');
sf_db()->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,original_scene_id,candidate_scene_id,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,?,?,'needs_attention','',?,?)")
    ->execute([1,str_repeat('93',16),$pixel['id'],$pixel['id'],sf_agent_scene_history_epoch(1),$now,$now]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$metadata)===null,
    'A later failed assessment of an intermediate copy blocks walking past it to an older verified ancestor');
sf_db()->prepare('DELETE FROM canvas_build_jobs WHERE user_id=1 AND request_id=?')->execute([str_repeat('93',16)]);
sf_db()->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,original_scene_id,candidate_scene_id,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,?,?,'needs_attention','',?,?)")
    ->execute([1,str_repeat('92',16),$legacy['id'],$legacy['id'],sf_agent_scene_history_epoch(1),$now,$now]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$metadata)===null,
    'A known failed workflow takes precedence over a legacy ancestor default eligibility');
foreach([0,-1,'1',$legacy['id']+100] as $invalidParent)verification_reject(
    fn()=>sf_agent_scene_history_save(1,$base+['verification_parent_id'=>$invalidParent],$legacy['id']),
    'Verification parent must be a positive integer equal to the actual revision parent');
$cycleA=sf_agent_scene_history_save(1,$base+['workflow_required'=>true]);
$cycleB=sf_agent_scene_history_save(1,$base+['workflow_required'=>true,'verification_parent_id'=>$cycleA['id']],$cycleA['id']);
sf_db()->prepare('UPDATE agent_scene_history SET previous_id=?,verification_parent_id=? WHERE user_id=1 AND id=?')->execute([$cycleB['id'],$cycleB['id'],$cycleA['id']]);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$cycleB)===null,'Malformed cyclic lineage stops at its visited-ID guard');
sf_agent_scene_delete(1,$legacy['id']);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$metadata)===null,'Deleting an owned verification ancestor invalidates its saved derivative chain');
sf_privacy_clear_agent_history(1);
verification_check(sf_canvas_build_workflow_verified_derivative(1,$unreadyChild)===null&&$calls===$beforeProviderCalls,
    'Privacy clearing removes lineage eligibility and every verification check remains free of model calls');
echo 'Canvas verification lineage: '.$verificationChecks.' checks passed'.PHP_EOL;
