<?php
declare(strict_types=1);
/** Read-only, editor-scoped Agent Chat view of supervised inference drafts. */
function sf_chat_footage_intelligence(int $editor):array{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-media-evidence-core.php';
 require_once __DIR__.'/story-capture-rights-core.php';
 require_once __DIR__.'/story-inference-queue-core.php';
 require_once __DIR__.'/story-inference-worker-core.php';
 require_once __DIR__.'/story-production-review-core.php';
 $items=sf_production_review_items($editor,35);
 $rows=[];
 foreach($items as $job){
  $draft=(array)($job['draft']??[]);
  $windows=[];
  foreach(array_slice((array)($draft['frame_observations']??[]),0,3) as $frame){
   if(!is_array($frame))continue;
   $windows[]=[
     'sampled_frame_ms'=>(int)($frame['sampled_frame_ms']??0),
     'description'=>sf_capture_clean((string)($frame['description']??''),260),
     'usable_suggestion'=>(bool)($frame['usable_suggestion']??false),
     'retake_note'=>sf_capture_clean((string)($frame['retake_note']??''),220)];
  }
  $rows[]=['job_id'=>(int)$job['job_id'],
    'submission_id'=>(int)$job['submission_id'],
    'capture_title'=>sf_capture_clean((string)$job['capture_title'],150),
    'task_type'=>(string)$job['task_type'],
    'status'=>(string)($job['job_status']??$job['status']??'unknown'),
    'review_status'=>(string)($job['review_status']??'unreviewed'),
    'external_ai_clearance'=>(string)($job['external_ai_clearance']??'unreviewed'),
    'description'=>sf_capture_clean((string)($draft['description']??''),480),
    'text_summary'=>sf_capture_clean((string)($draft['text']??''),320),
    'timing'=>(string)($draft['timing']??''),
    'frames'=>$windows,'shot_recommendations'=>array_values(array_map(
      static fn($v)=>sf_capture_clean((string)$v,180),
      array_slice((array)($draft['shot_recommendations']??[]),0,3))),
    'imported_type'=>(string)($job['imported_type']??''),
    'imported_id'=>(int)($job['imported_id']??0)];
 }
 $q=sf_db()->query("SELECT s.id AS submission_id,s.title,a.category
    FROM story_capture_submissions s JOIN media_assets a ON a.id=s.asset_id
    WHERE s.review_status='approved_for_edit' AND a.category IN ('image','video','audio')
    ORDER BY s.id DESC LIMIT 30");
 $eligible=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c){
  $task=$c['category']==='audio'?'transcript':'scene';
  try{
   sf_inference_source((int)$c['submission_id'],$task);
   $eligible[]=['submission_id'=>(int)$c['submission_id'],
    'title'=>sf_capture_clean((string)$c['title'],150),
    'category'=>(string)$c['category'],'task_type'=>$task];
  }catch(Throwable $e){continue;}
  if(count($eligible)>=12)break;
 }
 return ['enabled'=>sf_inference_enabled(),'eligible_captures'=>$eligible,
   'jobs'=>$rows,'worker'=>'supervised_cli_only',
   'review_required'=>true,'automatic_import'=>false,
   'private_only'=>true,'read_only'=>true,'model_invoked'=>false,
   'published'=>false,'audio_understanding'=>'transcription_only'];
}
