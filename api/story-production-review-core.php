<?php
declare(strict_types=1);
/**
 * 88D3C — private editorial import into canonical transcript/scene drafts.
 * Approving an AI result does not verify or publish the canonical draft.
 */
function sf_production_import_schema():void{
 static $ready=false;if($ready)return;
 sf_inference_results_schema();sf_transcript_schema();sf_scene_schema();
 $db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_inference_imports(
    job_id INTEGER PRIMARY KEY,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,
    imported_by INTEGER NOT NULL,imported_at TEXT NOT NULL,
    FOREIGN KEY(job_id) REFERENCES story_inference_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY(imported_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_inference_imports(
    job_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,entity_type VARCHAR(24) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,imported_by BIGINT UNSIGNED NOT NULL,
    imported_at VARCHAR(40) NOT NULL,
    CONSTRAINT fk_inference_import_job FOREIGN KEY(job_id)
      REFERENCES story_inference_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_inference_import_user FOREIGN KEY(imported_by)
      REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
/** Editor queue with human-reviewed AI drafts and import identifiers. */
function sf_production_review_items(int $editorId,int $limit=80):array{
 sf_inference_require_editor($editorId);sf_production_import_schema();
 $limit=max(1,min(100,$limit));
 $q=sf_db()->query("SELECT j.id AS job_id,j.submission_id,j.task_type,j.status AS job_status,
     j.created_at,r.provider,r.model,r.status AS review_status,r.result_json,
     i.entity_type AS imported_type,i.entity_id AS imported_id,
     s.title AS capture_title,s.review_status AS capture_status,
     COALESCE(rights.status,'unreviewed') AS external_ai_clearance,
     a.duration_seconds,a.category
     FROM story_inference_jobs j
     JOIN story_capture_submissions s ON s.id=j.submission_id
     JOIN media_assets a ON a.id=j.asset_id
     LEFT JOIN story_inference_results r ON r.job_id=j.id
     LEFT JOIN story_inference_imports i ON i.job_id=j.id
     LEFT JOIN story_capture_rights_reviews rights
       ON rights.submission_id=s.id AND rights.purpose='external_ai'
     ORDER BY j.id DESC LIMIT $limit");
 $out=$q->fetchAll(PDO::FETCH_ASSOC);
 foreach($out as &$v){
  $v['draft']=$v['result_json']===null?null:json_decode((string)$v['result_json'],true);
  unset($v['result_json']); // never show private media URI, lease or provider secrets
 }unset($v);
 return $out;
}
/** Immutable source bytes and duration gate, without any network transfer. */
function sf_production_import_source(int $submissionId,string $task):array{
 $valid=sf_inference_source($submissionId,$task);
 $q=sf_db()->prepare('SELECT category,duration_seconds FROM media_assets WHERE id=?');
 $q->execute([$valid['asset_id']]);$asset=$q->fetch(PDO::FETCH_ASSOC);
 if(!$asset)throw new RuntimeException('Capture source missing.');
 $valid['category']=(string)$asset['category'];
 $valid['duration_ms']=(int)round((float)$asset['duration_seconds']*1000);
 return $valid;
}
function sf_production_import_segments(array $draft,array $changes,int $duration):array{
 // The provider produces a whole-clip approximation, never invented word timings.
 $input=$changes['segments']??null;
 if(!is_array($input)||count($input)<1)throw new InvalidArgumentException('Supply reviewed timecoded transcript segments.');
 if($duration<=0)throw new InvalidArgumentException('Source duration is unknown; confirm media duration before import.');
 $segments=sf_transcript_segments_validate($input);
 foreach($segments as $s)
  if($s['end_ms']>$duration+250)
   throw new InvalidArgumentException('Transcript segment exceeds observed media duration.');
 return $segments;
}
function sf_production_import_scene(array $draft,array $changes,string $category,int $duration):array{
 $description=sf_capture_clean($changes['description']??'',2500);
 if($description==='')throw new InvalidArgumentException('Review and confirm the scene description.');
 $start=$changes['start_ms']??null;$end=$changes['end_ms']??null;
 if(!is_int($start)||!is_int($end)||$start<0||$end<0)
  throw new InvalidArgumentException('Scene timecodes required.');
 if($category==='image'&&($start!==0||$end!==0))
  throw new InvalidArgumentException('Still image scene must have zero timecodes.');
 if($category==='video'&&($duration<=0||$end<=$start||$end>$duration+250))
  throw new InvalidArgumentException('Video scene must use observed duration and positive timecodes.');
 if($category!=='image'&&$category!=='video')throw new InvalidArgumentException('Unsupported scene source.');
 return ['description'=>$description,'start_ms'=>$start,'end_ms'=>$end,
  'location_label'=>sf_capture_clean($changes['location_label']??'',180),
  'tags_json'=>sf_scene_validate_tags($changes['tags']??[])];
}
/**
 * Idempotent one-way import. The approved result is a review gate;
 * the canonical artifact remains machine_draft + draft until separately verified.
 */
function sf_production_import(int $editorId,int $jobId,array $changes):array{
 sf_inference_require_editor($editorId);sf_production_import_schema();
 if($jobId<1)throw new InvalidArgumentException('Invalid AI result.');
 $db=sf_db();
 $q=$db->prepare('SELECT i.entity_type,i.entity_id FROM story_inference_imports i WHERE i.job_id=?');
 $q->execute([$jobId]);$old=$q->fetch(PDO::FETCH_ASSOC);
 if($old)return ['entity_type'=>$old['entity_type'],'entity_id'=>(int)$old['entity_id'],'existing'=>true];
 $q=$db->prepare("SELECT j.submission_id,j.asset_id,j.task_type,j.status AS job_status,
     r.status AS review_status,r.result_json
   FROM story_inference_jobs j JOIN story_inference_results r ON r.job_id=j.id
   WHERE j.id=?");
 $q->execute([$jobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
 if(!$job||$job['review_status']!=='approved'||$job['job_status']!=='completed')
  throw new RuntimeException('AI result must be separately reviewed and approved first.');
 $source=sf_production_import_source((int)$job['submission_id'],(string)$job['task_type']);
 if((int)$source['asset_id']!==(int)$job['asset_id'])
  throw new RuntimeException('Submission asset changed.');
 $draft=json_decode((string)$job['result_json'],true);
 if(!is_array($draft))throw new RuntimeException('AI result is invalid.');
 $type=(string)$job['task_type'];$now=gmdate('c');
 if($type==='transcript'){
  $segments=sf_production_import_segments($draft,$changes,$source['duration_ms']);
  $language=(string)($changes['language']??'und');
  if(!preg_match('/^(und|[a-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2})$/',$language))
    throw new InvalidArgumentException('Invalid transcript language.');
  $entity='transcript';
 }elseif($type==='scene'){
  $scene=sf_production_import_scene($draft,$changes,$source['category'],$source['duration_ms']);
  $entity='scene';
 }else throw new InvalidArgumentException('Unknown production artifact.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema(); // avoid implicit MySQL DDL commit
 if($db->inTransaction())throw new RuntimeException('Nested production import unsupported.');
 $db->beginTransaction();
 try{
  // Guard against rights changing between preflight and commit.
  if(!sf_capture_rights_may_use((int)$job['submission_id'],'external_ai'))
   throw new RuntimeException('Source clearance was revoked.');
  if($type==='transcript'){
   $q=$db->prepare("INSERT INTO story_transcripts(submission_id,actor_id,origin,language,review_status,created_at)
     VALUES(?,?,'machine_draft',?,'draft',?)");
   $q->execute([(int)$job['submission_id'],$editorId,$language,$now]);
   $entityId=(int)$db->lastInsertId();
   $q=$db->prepare('INSERT INTO story_transcript_segments(transcript_id,ordinal,start_ms,end_ms,speaker_label,dialogue_text) VALUES(?,?,?,?,?,?)');
   foreach($segments as $segment)$q->execute([$entityId,$segment['ordinal'],$segment['start_ms'],$segment['end_ms'],
     $segment['speaker_label'],$segment['dialogue_text']]);
  }else{
   $q=$db->prepare("INSERT INTO story_scene_annotations
     (submission_id,asset_id,created_by,start_ms,end_ms,description,location_label,tags_json,origin,review_status,created_at)
     VALUES(?,?,?,?,?,?,?,?,?,'draft',?)");
   $q->execute([(int)$job['submission_id'],(int)$job['asset_id'],$editorId,$scene['start_ms'],$scene['end_ms'],
     $scene['description'],$scene['location_label'],$scene['tags_json'],'machine_draft',$now]);
   $entityId=(int)$db->lastInsertId();
  }
  $q=$db->prepare('INSERT INTO story_inference_imports(job_id,entity_type,entity_id,imported_by,imported_at) VALUES(?,?,?,?,?)');
  $q->execute([$jobId,$entity,$entityId,$editorId,$now]);
  sf_team_audit($editorId,'capture.ai_draft_imported','story_inference_job',(string)$jobId,
    'Imported private '.$entity.' machine draft #'.$entityId.' for independent editorial verification');
  $db->commit();
  return ['entity_type'=>$entity,'entity_id'=>$entityId,'existing'=>false];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
