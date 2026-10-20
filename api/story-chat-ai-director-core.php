<?php
declare(strict_types=1);
/**
 * G5E-1 — read-only, permission-bound Agent Chat window into the existing
 * supervised composer/Agent Brain. AI generation stays in the one-shot CLI.
 */
function sf_chat_ai_director_overview(int $editor,?int $assemblyId):array{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-composer-core.php';
 require_once __DIR__.'/story-assembly-core.php';
 if($assemblyId===null){
  $list=array_values(array_filter(sf_assembly_list($editor),
    static fn(array $a):bool=>(string)$a['status']==='draft'));
  return ['requires_selection'=>true,'assemblies'=>array_slice(array_map(
    static fn(array $a):array=>['id'=>(int)$a['id'],
      'title'=>(string)$a['title'],'revision'=>(int)$a['revision'],
      'item_count'=>(int)$a['item_count']],$list),0,12),
    'assembly'=>null,'proposals'=>[],'enabled'=>sf_composer_enabled(),
    'candidate_count'=>0,'read_only'=>true,'private_only'=>true];
 }
 $assembly=sf_assembly_read($editor,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft')
  throw new RuntimeException('Private editable story assembly unavailable.');
 $revision=(int)$assembly['revision'];
 $proposals=sf_composer_list($editor,$assemblyId);
 $eligible=false;$reason='';
 $context=null;
 try{
  $context=sf_composer_context($editor,$assemblyId,$revision);
  $eligible=true;
 }catch(Throwable $e){
  $reason='The current footage, dialogue, editor opt-in, or external-AI rights must be reviewed before generation.';
 }
 $out=[];
 foreach(array_slice($proposals,0,8) as $p){
  $suggestion=(array)($p['suggestion']??[]);
  $beats=[];
  foreach(array_slice((array)($suggestion['beats']??[]),0,34) as $i=>$beat)
   if(is_array($beat))$beats[]=[
     'ordinal'=>$i+1,'source_index'=>(int)($beat['source_index']??-1),
     'reason'=>sf_capture_clean((string)($beat['reason']??''),260),
     'transition'=>sf_capture_clean((string)($beat['transition']??''),40)];
  $out[]=['id'=>(int)$p['id'],'revision'=>(int)$p['revision'],
    'status'=>(string)$p['status'],'provider'=>(string)($p['provider']??''),
    'model'=>(string)($p['model']??''),
    'logline'=>sf_capture_clean((string)($suggestion['logline']??''),500),
    'beats'=>$beats,'missing_shots'=>array_slice((array)($suggestion['missing_shots']??[]),0,5),
    'proposed_additions'=>(int)($suggestion['proposed_additions']??0),
    'source_keys'=>array_slice((array)($suggestion['source_keys']??[]),0,34),
    'is_current'=>(int)$p['revision']===$revision];
 }
 return ['requires_selection'=>false,'assemblies'=>[],
  'assembly'=>['id'=>$assemblyId,'title'=>(string)$assembly['title'],
    'revision'=>$revision,'item_count'=>count($assembly['items'])],
  'enabled'=>sf_composer_enabled(),'eligible_for_external_ai'=>$eligible,
  'eligibility_note'=>$reason,'candidate_count'=>$context===null?0:count($context['candidate_pool']),
  'existing_count'=>count($assembly['items']),'proposals'=>$out,
  'scene_recommendations'=>$context===null?[]:
    sf_director_scene_recommendations(
      array_merge($context['items'],$context['candidate_pool']),
      (array)($context['source_understanding']??[])),
  'source_understanding'=>$context===null?[]:
    array_slice((array)($context['source_understanding']??[]),0,12,true),
  'worker'=>'supervised_cli_only','read_only'=>true,'private_only'=>true,
  'model_invoked'=>false,'changes_applied'=>false,'published'=>false];
}
