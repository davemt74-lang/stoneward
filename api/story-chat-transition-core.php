<?php
declare(strict_types=1);
/**
 * G5D-4 — Chat-first read-only storyboard/EDL, authorized private playback,
 * and reviewed global/per-cut transition controls.
 * All media streams remain protected by the existing hash-verified review API.
 */
function sf_chat_transition_preview(int $editor,?int $assemblyId):array{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production editor permission required.');
 require_once __DIR__.'/story-capture-core.php';
 require_once __DIR__.'/story-capture-rights-core.php';
 require_once __DIR__.'/story-transcript-core.php';
 require_once __DIR__.'/story-scene-core.php';
 require_once __DIR__.'/story-assembly-core.php';
 if($assemblyId===null){
  $rows=sf_assembly_list($editor);
  return ['requires_selection'=>true,'assemblies'=>array_slice(array_map(
    static fn(array $a):array=>[
      'id'=>(int)$a['id'],'title'=>(string)$a['title'],
      'revision'=>(int)$a['revision'],'status'=>(string)$a['status']],$rows),0,12),
    'assembly'=>null,'cards'=>[],'cuts'=>[],'render_reviews'=>[],
    'private_only'=>true,'executed_changes'=>false];
 }
 $assembly=sf_assembly_read($editor,$assemblyId);
 if(!$assembly)throw new InvalidArgumentException('Private assembly not found.');
 require_once __DIR__.'/story-composer-core.php';
 require_once __DIR__.'/storyboard-preview-core.php';
 require_once __DIR__.'/story-media-evidence-core.php';
 require_once __DIR__.'/story-render-source-core.php';
 require_once __DIR__.'/story-render-worker-core.php';
 require_once __DIR__.'/story-render-review-core.php';
 require_once __DIR__.'/story-render-mix-core.php';
 require_once __DIR__.'/story-render-transition-core.php';
 require_once __DIR__.'/story-render-edl-core.php';
 require_once __DIR__.'/story-render-cut-core.php';
 $revision=(int)$assembly['revision'];
 $story=sf_storyboard_preview($editor,$assemblyId);
 $edl=sf_render_edl_preview($editor,$assemblyId,$revision);
 $transition=sf_render_transition_settings($editor,$assemblyId,$revision);
 $n=count($assembly['items']);
 $cutSettings=$transition['cuts']??[];
 if(!$cutSettings&&$transition!==null)
  $cutSettings=array_fill(0,max(0,$n-1),[
    'style'=>$transition['style'],'overlap_ms'=>(int)$transition['overlap_ms']]);
 $reviews=[];
 foreach(sf_render_review_admin($editor,$assemblyId) as $r){
  if((int)$r['revision']!==$revision ||
     !in_array((string)$r['status'],['private_review','approved_private'],true))continue;
  try{
   sf_render_review_file($editor,(int)$r['id']);
   $reviews[]=['job_id'=>(int)$r['id'],'status'=>(string)$r['status'],
     'revision'=>$revision];
  }catch(Throwable $e){continue;} // Never expose a stale/missing/unverified MP4.
  if(count($reviews)>=3)break;
 }
 return ['requires_selection'=>false,'assemblies'=>[],
  'assembly'=>['id'=>(int)$assembly['id'],'title'=>(string)$assembly['title'],
    'revision'=>$revision,'status'=>(string)$assembly['status'],'item_count'=>$n],
  'cards'=>array_slice(array_map(static fn(array $c):array=>[
    'position'=>(int)$c['position'],'kind'=>(string)$c['kind'],
    'label'=>(string)$c['label'],'source_id'=>(string)$c['source_id'],
    'start_ms'=>(int)$c['start_ms'],'end_ms'=>(int)$c['end_ms'],
    'source_verified'=>(bool)$c['source_verified'],
    'publication_clearance'=>(string)$c['publication_clearance'],
    'sync_clearance'=>(string)$c['sync_clearance'],
    'master_clearance'=>(string)$c['master_clearance']],$story['cards']),0,50),
  'transition'=>$transition===null?null:[
    'style'=>(string)$transition['style'],
    'overlap_ms'=>(int)$transition['overlap_ms']],
  'cuts'=>array_slice($cutSettings,0,49),
  'cut_count'=>max(0,$n-1),
  'edl'=>[
    'available'=>!empty($edl),'output_total_ms'=>(int)($edl['output_total_ms']??0),
    'dialogue_duck_windows'=>array_slice((array)($edl['dialogue_duck_windows']??[]),0,24),
    'cuts'=>array_slice((array)($edl['cuts']??[]),0,49)],
  'blockers'=>array_slice(array_map(static fn(array $b):array=>[
    'code'=>(string)$b['code'],'message'=>(string)$b['message']],
     (array)$story['blockers']),0,15),
  'render_reviews'=>$reviews,
  'transition_requirements'=>[
    'min_clips'=>2,'max_clips'=>6,'max_total_ms'=>30000,
    'styles'=>['fade','wipeleft'],'overlaps_ms'=>[250,500],
    'requires_rights_and_verified_local_master'=>true],
  'metadata_only'=>true,'private_only'=>true,
  'executed_changes'=>false,'render_started'=>false,'published'=>false];
}
function sf_chat_transition_global(int $editor,int $assemblyId,int $revision,
 string $style,int $overlap,string $basis):void{
 if(strlen(sf_capture_clean($basis,400))<25)
  throw new InvalidArgumentException('Document the transition review in 25+ characters.');
 require_once __DIR__.'/story-render-transition-core.php';
 sf_render_transition_set($editor,$assemblyId,$revision,$style,$overlap,$basis);
}
function sf_chat_transition_cuts(int $editor,int $assemblyId,int $revision,
 array $cuts,string $basis):void{
 if(strlen(sf_capture_clean($basis,400))<25)
  throw new InvalidArgumentException('Document the cut review in 25+ characters.');
 require_once __DIR__.'/story-render-cut-core.php';
 sf_render_cut_set($editor,$assemblyId,$revision,$cuts,$basis);
}
