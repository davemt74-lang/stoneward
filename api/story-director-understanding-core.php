<?php
declare(strict_types=1);
/**
 * G5E-3. Deterministic scene understanding built ONLY from separately
 * approved, currently source-verified evidence. No identities inferred from
 * images, no transcript text exposed, no additional provider requests.
 */
function sf_director_scene_understanding(int $editor,array $sources):array{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-graph-core.php';
 require_once __DIR__.'/story-scene-core.php';
 require_once __DIR__.'/story-transcript-core.php';
 require_once __DIR__.'/story-capture-rights-core.php';
 require_once __DIR__.'/story-stonefellow-music-auto-core.php';
 require_once __DIR__.'/story-inference-worker-core.php';
 sf_story_graph_schema();
 sf_inference_results_schema();
 $db=sf_db();$out=[];
 $links=$db->prepare("SELECT e.id,e.entity_type,e.label,l.relation_type
  FROM story_graph_links l
  JOIN story_graph_entities e ON e.id=l.entity_id
  JOIN story_capture_submissions s ON s.id=l.submission_id
  WHERE l.source_type=? AND l.source_id=? AND l.submission_id=?
    AND e.review_status='verified' AND s.review_status='approved_for_edit'
  ORDER BY e.id LIMIT 5");
 $visual=$db->prepare("SELECT j.id,j.source_sha256,j.source_size_bytes,r.result_json
  FROM story_inference_jobs j JOIN story_inference_results r ON r.job_id=j.id
  WHERE j.submission_id=? AND j.task_type='scene'
    AND j.status='completed' AND r.status='approved'
  ORDER BY j.id DESC LIMIT 1");
 foreach(array_slice($sources,0,38) as $source){
  $kind=(string)($source['kind']??'');
  $id=(string)($source['source_id']??'');
  if(!in_array($kind,['scene','transcript','track'],true)||$id==='')continue;
  $key=$kind.':'.$id;
  if(isset($out[$key]))continue;
  if($kind==='track'){
   $license=sf_stonefellow_music_auto_status($id);
   $out[$key]=['source_key'=>$key,'kind'=>$kind,'story_connections'=>[],
    'visual_observations'=>[],'retake_ideas'=>[],
    'music_status'=>!empty($license['eligible'])?
      'original_stonefellow_standing_authorization':'requires_individual_rights_review',
    'independent_publication_clearance'=>false,'audio_analyzed'=>false];
   continue;
  }
  if(!ctype_digit($id)||!sf_capture_rights_may_use((int)($source['submission_id']??0),'external_ai'))
   continue;
  // Re-evaluate editor verification and exact provenance: an old graph link
  // or machine description never rescues a revoked canonical annotation.
  $table=$kind==='scene'?'story_scene_annotations':'story_transcripts';
  $q=$db->prepare("SELECT submission_id,review_status FROM $table WHERE id=?");
  $q->execute([(int)$id]);$record=$q->fetch(PDO::FETCH_ASSOC);
  if(!$record||$record['review_status']!=='verified'||
    (int)$record['submission_id']!==(int)($source['submission_id']??0))continue;
  $sid=(int)$record['submission_id'];
  $links->execute([$kind,(int)$id,$sid]);$connections=[];
  foreach($links->fetchAll(PDO::FETCH_ASSOC) as $entity){
   $connections[]=['entity_id'=>(int)$entity['id'],
    'entity_type'=>(string)$entity['entity_type'],
    'label'=>sf_capture_clean((string)$entity['label'],100),
    'relation'=>(string)$entity['relation_type'],
    'origin'=>'editor_verified_story_graph'];
  }
  $frames=[];$retakes=[];
  if($kind==='scene'){
   $visual->execute([$sid]);$v=$visual->fetch(PDO::FETCH_ASSOC);
   if($v){
    // A previously approved inference is no longer evidence if its exact
    // source capture, fingerprint or explicit model-processing rights changed.
    try{
     $now=sf_inference_source($sid,'scene');
     if(!hash_equals((string)$now['source_sha256'],(string)$v['source_sha256'])
        ||(int)$now['source_size_bytes']!==(int)$v['source_size_bytes'])
      throw new RuntimeException('Capture fingerprint changed.');
     $draft=json_decode((string)$v['result_json'],true,32,JSON_THROW_ON_ERROR);
     if(($draft['timing']??'')==='three_sampled_frames_only'&&
       count((array)($draft['frame_observations']??[]))===3){
      foreach($draft['frame_observations'] as $i=>$frame){
       if(!is_array($frame)||($frame['index']??null)!==$i+1
          ||!is_int($frame['sampled_frame_ms']??null)
          ||!is_string($frame['description']??null))throw new RuntimeException('Untrusted visual draft.');
       $frames[]=['sampled_frame_ms'=>$frame['sampled_frame_ms'],
         'description'=>sf_capture_clean($frame['description'],180),
         'usable_suggestion'=>(bool)($frame['usable_suggestion']??false),
         'method'=>'approved_sparse_frame_observation'];
      }
      foreach(array_slice((array)($draft['shot_recommendations']??[]),0,3) as $tip)
       if(is_string($tip))$retakes[]=sf_capture_clean($tip,150);
     }
    }catch(Throwable $e){$frames=[];$retakes=[];}
   }
  }
  $out[$key]=['source_key'=>$key,'kind'=>$kind,
   'story_connections'=>$connections,'visual_observations'=>$frames,
   'retake_ideas'=>$retakes,'music_status'=>'not_applicable',
   'independent_publication_clearance'=>false,'audio_analyzed'=>false];
 }
 return $out;
}
function sf_director_scene_recommendations(array $sources,array $evidence):array{
 $suggestions=[];
 foreach(array_slice($sources,0,38) as $source){
  $key=(string)($source['kind']??'').':'.(string)($source['source_id']??'');
  $info=$evidence[$key]??null;if(!$info)continue;
  $links=(array)($info['story_connections']??[]);
  $frames=(array)($info['visual_observations']??[]);
  if($frames){
   $selected=array_values(array_filter($frames,
      static fn(array $f):bool=>!empty($f['usable_suggestion'])));
   $suggestions[]=['source_key'=>$key,'kind'=>'visual_moment',
     'evidence_count'=>count($frames),
     'suggestion'=>count($selected)?
        'Review the source-timecoded frames marked potentially usable for this story beat.':
        'Request an alternative or retake; all sampled frames need editorial review.',
     'editor_approval_required'=>true];
  }
  if($links)$suggestions[]=['source_key'=>$key,'kind'=>'story_connection',
   'evidence_count'=>count($links),
   'suggestion'=>'Consider the editor-verified story concepts linked to this source; these are not identity or likeness claims.',
   'editor_approval_required'=>true];
  foreach(array_slice((array)($info['retake_ideas']??[]),0,2) as $tip)
   $suggestions[]=['source_key'=>$key,'kind'=>'retake',
      'evidence_count'=>count($frames),
      'suggestion'=>sf_capture_clean((string)$tip,150),
      'editor_approval_required'=>true];
  if(($source['kind']??'')==='track'&&
     ($info['music_status']??'')==='original_stonefellow_standing_authorization')
   $suggestions[]=['source_key'=>$key,'kind'=>'original_music',
     'evidence_count'=>1,
     'suggestion'=>'Consider this eligible original Stonefellow track for the story beat; arrangement and timeline placement still need editor approval.',
     'editor_approval_required'=>true];
  if(count($suggestions)>=14)break;
 }
 return array_slice($suggestions,0,14);
}
