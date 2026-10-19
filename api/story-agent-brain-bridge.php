<?php
declare(strict_types=1);
require_once __DIR__.'/media-core.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-capture-rights-core.php';
require_once __DIR__.'/story-transcript-core.php';
require_once __DIR__.'/story-scene-core.php';
require_once __DIR__.'/story-assembly-core.php';
require_once __DIR__.'/story-assembly-history-core.php';
require_once __DIR__.'/story-assembly-restore-core.php';
require_once __DIR__.'/story-composer-core.php';
require_once __DIR__.'/storyboard-preview-core.php';
require_once __DIR__.'/story-media-evidence-core.php';
require_once __DIR__.'/story-render-source-core.php';
require_once __DIR__.'/story-render-worker-core.php';
/**
 * G4I: private editorial read model for the *existing* Admin Agent Brain.
 * No second job executor, model call, approval, source path or media bytes.
 */
function sf_story_agent_brain_summary(int $editor):array{
 sf_assembly_editor($editor);
 $rows=array_slice(sf_assembly_list($editor),0,8);
 $recent=[];$needs=0;
 foreach($rows as $a){
  $id=(int)$a['id'];$revision=(int)$a['revision'];
  sf_composer_schema();
  $q=sf_db()->prepare("SELECT status,COUNT(*) AS total FROM story_composer_proposals
    WHERE assembly_id=? GROUP BY status");$q->execute([$id]);$composer=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)$composer[(string)$p['status']]=(int)$p['total'];
  sf_assembly_restore_schema();
  $q=sf_db()->prepare("SELECT status,COUNT(*) AS total FROM story_assembly_restore_proposals
    WHERE assembly_id=? GROUP BY status");
  $q->execute([$id]);$restores=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)$restores[(string)$p['status']]=(int)$p['total'];
  sf_render_jobs_schema();
  $q=sf_db()->prepare("SELECT status,COUNT(*) AS total FROM story_render_jobs
    WHERE assembly_id=? GROUP BY status");
  $q->execute([$id]);$render=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)$render[(string)$p['status']]=(int)$p['total'];
  $attention=(int)($composer['pending_review']??0)+(int)($restores['pending_review']??0)
    +(int)($render['private_review']??0);
  $needs+=$attention;
  $recent[]=['assembly_id'=>$id,'revision'=>$revision,
   'title'=>substr((string)$a['title'],0,180),'status'=>(string)$a['status'],
   'item_count'=>(int)$a['item_count'],'approval_attention'=>$attention,
   'composer_statuses'=>$composer,'restore_statuses'=>$restores,'render_statuses'=>$render];
 }
 return ['available'=>true,'scope'=>'admin_editor_only','assemblies'=>$recent,
  'attention_total'=>$needs,'total_shown'=>count($recent),
  'authorizes_edits'=>false,'authorizes_render'=>false,'authorizes_publication'=>false];
}
function sf_story_agent_brain_inspect(int $editor,int $assembly):array{
 sf_assembly_editor($editor);
 if($assembly<1)throw new InvalidArgumentException('Choose a valid private assembly.');
 $saved=sf_assembly_read($editor,$assembly);
 if(!$saved)throw new RuntimeException('Private editorial assembly unavailable.');
 $revision=(int)$saved['revision'];
 $plan=(array)($saved['director']??[]);
 $composers=sf_composer_list($editor,$assembly);
 $restores=sf_assembly_restore_list($editor,$assembly);
 $jobs=sf_render_jobs($editor,$assembly);
 $history=sf_assembly_history_list($editor,$assembly);
 $cert=sf_render_source_admin_status($editor,$assembly,$revision);
 $proposals=array_map(static fn($v)=>[
   'id'=>(int)$v['id'],'revision'=>(int)$v['revision'],
   'status'=>(string)$v['status'],'provider'=>(string)($v['provider']??''),
   'reviewed_at'=>(string)($v['reviewed_at']??''),
   'applied_revision'=>(int)($v['applied_revision']??0)
 ],array_slice($composers,0,12));
 $restoration=array_map(static fn($v)=>[
   'id'=>(int)$v['id'],'expected_revision'=>(int)$v['expected_revision'],
   'target_revision'=>(int)$v['target_revision'],'status'=>(string)$v['status'],
   'reviewed_at'=>(string)($v['reviewed_at']??''),
   'applied_revision'=>(int)($v['applied_revision']??0)
 ],array_slice($restores,0,12));
 $renders=array_map(static fn($v)=>[
   'job_id'=>(int)$v['id'],'revision'=>(int)$v['revision'],
   'status'=>(string)$v['status'],'updated_at'=>(string)$v['updated_at']
 ],array_slice($jobs,0,12));
 $review=[];
 foreach($proposals as $p)if($p['status']==='pending_review')$review[]=[
   'kind'=>'ai_director_proposal','id'=>$p['id'],'revision'=>$p['revision']];
 foreach($restoration as $p)if($p['status']==='pending_review')$review[]=[
   'kind'=>'revision_restore','id'=>$p['id'],'revision'=>$p['expected_revision']];
 foreach($renders as $p)if($p['status']==='private_review')$review[]=[
   'kind'=>'private_video','id'=>$p['job_id'],'revision'=>$p['revision']];
 $rightsPending=(int)($plan['publication_rights_pending']??0);
 $invalid=(int)($plan['invalid_source_items']??0);
 $gaps=array_map(static fn($g)=>[
   'code'=>(string)($g['code']??''),'missing'=>(int)($g['missing']??0),
   'role'=>(string)($g['target_role']??'')
 ],array_slice((array)($plan['gaps']??[]),0,6));
 // Deliberately do not return full private source manifests, titles, notes,
 // contributor identities, transcripts, LLM proposals, hashes or asset paths.
 return [
  'assembly_id'=>$assembly,'revision'=>$revision,'status'=>(string)$saved['status'],
  'clip_count'=>count($saved['items']),'director_coverage_gaps'=>$gaps,
  'invalid_source_count'=>$invalid,'publication_rights_pending'=>$rightsPending,
  'source_evidence'=>'verified_at_editorial_save_with_live_read_checks',
  'source_certification'=>['eligible'=>(bool)($cert['eligible']??false),
    'checks'=>(string)($cert['checks']??'blocked')],
  'render_ready'=>false,'render_statuses'=>$renders,
  'ai_director_proposals'=>$proposals,'restore_proposals'=>$restoration,
  'revision_history'=>array_map(static fn($v)=>[
    'revision'=>(int)$v['revision'],'item_count'=>(int)$v['item_count']
  ],array_slice($history,0,12)),
  'review_queue'=>array_slice($review,0,25),
  'next_actions'=>sf_story_agent_brain_next_actions($gaps,$invalid,$rightsPending,$review),
  'private_only'=>true,'authorizes_edits'=>false,'authorizes_render'=>false,
  'authorizes_publication'=>false,'external_model_access'=>false
 ];
}
function sf_story_agent_brain_next_actions(array $gaps,int $invalid,int $rightsPending,array $review):array{
 $actions=[];
 if($invalid>0)$actions[]=['code'=>'revalidate_sources','priority'=>'high','requires_human'=>true];
 if($rightsPending>0)$actions[]=['code'=>'review_publication_rights','priority'=>'high','requires_human'=>true];
 if($gaps)$actions[]=['code'=>'review_director_shot_gaps','priority'=>'normal','requires_human'=>true];
 if($review)$actions[]=['code'=>'review_pending_editorial_decisions','priority'=>'normal','requires_human'=>true];
 if(!$actions)$actions[]=['code'=>'inspect_private_production_readiness','priority'=>'normal','requires_human'=>true];
 return $actions;
}
function sf_story_agent_brain_brief(int $editor,?int $assembly=null):string{
 if($assembly!==null){
  $a=sf_story_agent_brain_inspect($editor,$assembly);
  $queue=array_map(static fn($x)=>$x['kind'].' #'.$x['id'].' (rev '.$x['revision'].')',$a['review_queue']);
  $actions=array_column($a['next_actions'],'code');
  return 'Private editorial assembly #'.$a['assembly_id'].' · revision '.$a['revision'].
    '. '.(int)$a['clip_count'].' saved clips; '.count($a['director_coverage_gaps']).' coverage gaps; '.
    $a['invalid_source_count'].' invalid sources; '.$a['publication_rights_pending'].
    ' publication clearances pending. Pending human review: '.($queue?implode('; ',array_slice($queue,0,8)):'none').
    '. Recommended: '.implode(', ',$actions).
    '. These are evidence-backed, read-only Admin Brain findings; open Production Review to inspect the actual rights, approval evidence and private video. No editing, rendering or publishing occurred.';
 }
 $s=sf_story_agent_brain_summary($editor);
 return 'Stonefellow editorial production: '.$s['total_shown'].' recent private assemblies, '.
   $s['attention_total'].' recorded human review items across those assemblies. '.
   ($s['assemblies']?implode('; ',array_map(static fn($a)=>
    '#'.$a['assembly_id'].' rev '.$a['revision'].' ('.$a['approval_attention'].' review)',array_slice($s['assemblies'],0,6))):
     'No private assemblies recorded.').
   '. Ask "editorial assembly #ID" for verified coverage, proposal decisions and production queue. No actions were executed.';
}
