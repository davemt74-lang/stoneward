<?php
declare(strict_types=1);
/**
 * 88D3A — governed, durable Artist World inference queue.
 *
 * This queue intentionally does NOT invoke an AI provider or write machine
 * transcripts/scenes. A future worker MUST call sf_inference_lease_valid()
 * immediately before transferring any content to a model provider.
 * Interrupted leases require explicit editor retry: uncertain provider calls
 * must never be automatically replayed.
 */
function sf_inference_schema():void {
  static $ready=false;if($ready)return;
  sf_capture_schema();sf_evidence_schema();sf_capture_rights_schema();
  $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
  if($sqlite){
    $db->exec("CREATE TABLE IF NOT EXISTS story_inference_jobs(
      id INTEGER PRIMARY KEY AUTOINCREMENT,submission_id INTEGER NOT NULL,
      asset_id INTEGER NOT NULL,task_type TEXT NOT NULL,
      source_sha256 TEXT NOT NULL,source_size_bytes INTEGER NOT NULL,
      status TEXT NOT NULL,attempt_count INTEGER NOT NULL DEFAULT 0,
      lease_token_sha256 TEXT NULL,lease_expires_at INTEGER NULL,
      error_code TEXT NOT NULL DEFAULT '',created_by INTEGER NOT NULL,
      created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
      UNIQUE(submission_id,task_type),
      FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE RESTRICT,
      FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_inference_queue ON story_inference_jobs(status,id)');
  }else{
    $db->exec("CREATE TABLE IF NOT EXISTS story_inference_jobs(
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      submission_id BIGINT UNSIGNED NOT NULL,asset_id BIGINT UNSIGNED NOT NULL,
      task_type VARCHAR(24) NOT NULL,source_sha256 CHAR(64) NOT NULL,
      source_size_bytes BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,
      attempt_count INT NOT NULL DEFAULT 0,lease_token_sha256 CHAR(64) NULL,
      lease_expires_at BIGINT NULL,error_code VARCHAR(64) NOT NULL DEFAULT '',
      created_by BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,
      updated_at VARCHAR(40) NOT NULL,
      UNIQUE KEY uq_inference_submission_task(submission_id,task_type),
      INDEX idx_inference_queue(status,id),
      CONSTRAINT fk_inference_capture FOREIGN KEY(submission_id)
        REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      CONSTRAINT fk_inference_asset FOREIGN KEY(asset_id)
        REFERENCES media_assets(id) ON DELETE RESTRICT,
      CONSTRAINT fk_inference_actor FOREIGN KEY(created_by)
        REFERENCES users(id) ON DELETE RESTRICT)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $ready=true;
}
function sf_inference_require_editor(int $editorId):void {
  if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
    throw new RuntimeException('Inference access requires production editor authority.');
}
/** Only approved, immutable, source-bound and independently cleared media. */
function sf_inference_source(int $submissionId,string $taskType):array {
  if($submissionId<1||!in_array($taskType,['transcript','scene'],true))
    throw new InvalidArgumentException('Unsupported inference task.');
  sf_inference_schema();
  $q=sf_db()->prepare("SELECT s.id AS submission_id,s.asset_id,s.review_status,
      a.category,a.sha256 AS asset_sha256,a.size_bytes AS asset_bytes,
      e.sha256 AS evidence_sha256,e.byte_size AS evidence_bytes,e.media_category
    FROM story_capture_submissions s
    JOIN media_assets a ON a.id=s.asset_id
    JOIN story_media_evidence e ON e.submission_id=s.id AND e.asset_id=s.asset_id
    WHERE s.id=?");
  $q->execute([$submissionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||$row['review_status']!=='approved_for_edit'
     ||!sf_capture_rights_may_use($submissionId,'external_ai')
     ||sf_media_protected_master_asset((int)$row['asset_id']))
    throw new RuntimeException('Capture is not authorized for AI processing.');
  $category=(string)$row['category'];
  if(($taskType==='transcript'&&!in_array($category,['audio','video'],true))
     ||($taskType==='scene'&&!in_array($category,['image','video'],true)))
    throw new InvalidArgumentException('Media type cannot serve requested inference.');
  if($category!==(string)$row['media_category']
     ||!is_string($row['evidence_sha256'])
     ||!preg_match('/^[a-f0-9]{64}$/',$row['evidence_sha256'])
     ||!hash_equals((string)$row['asset_sha256'],(string)$row['evidence_sha256'])
     ||(int)$row['asset_bytes']!==(int)$row['evidence_bytes'])
    throw new RuntimeException('Media bytes do not match immutable capture evidence.');
  return ['submission_id'=>$submissionId,'asset_id'=>(int)$row['asset_id'],
    'task_type'=>$taskType,'source_sha256'=>(string)$row['evidence_sha256'],
    'source_size_bytes'=>(int)$row['evidence_bytes']];
}
/** Idempotent per source/task: at most one job regardless of repeated requests. */
function sf_inference_enqueue(int $editorId,int $submissionId,string $taskType):array {
  sf_inference_require_editor($editorId);
  $source=sf_inference_source($submissionId,$taskType);$db=sf_db();
  $read=$db->prepare('SELECT id,status,attempt_count FROM story_inference_jobs WHERE submission_id=? AND task_type=?');
  $read->execute([$submissionId,$taskType]);$existing=$read->fetch(PDO::FETCH_ASSOC);
  if($existing)return ['job_id'=>(int)$existing['id'],'status'=>$existing['status'],'existing'=>true];
  $quota=$db->prepare("SELECT COUNT(*) FROM story_inference_jobs
    WHERE created_by=? AND status IN ('queued','leased')");
  $quota->execute([$editorId]);
  if((int)$quota->fetchColumn()>=12)throw new RuntimeException('Too many active inference jobs; review queue before submitting more.');
  $now=gmdate('c');
  // UNIQUE(submission_id,task_type) remains the concurrency authority.
  try{
    if($db->inTransaction())throw new RuntimeException('Nested inference transaction unsupported.');
    $db->beginTransaction();
    $q=$db->prepare('INSERT INTO story_inference_jobs
      (submission_id,asset_id,task_type,source_sha256,source_size_bytes,status,
       attempt_count,lease_token_sha256,lease_expires_at,error_code,created_by,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$submissionId,$source['asset_id'],$taskType,$source['source_sha256'],
      $source['source_size_bytes'],'queued',0,null,null,'',$editorId,$now,$now]);
    $id=(int)$db->lastInsertId();
    sf_team_audit($editorId,'capture.inference_enqueued','story_inference_job',(string)$id,
      'Private '.$taskType.' inference queued; no provider transfer');
    $db->commit();
    return ['job_id'=>$id,'status'=>'queued','existing'=>false];
  }catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    // A concurrent identical enqueue may have won the uniqueness race.
    $read->execute([$submissionId,$taskType]);$existing=$read->fetch(PDO::FETCH_ASSOC);
    if($existing)return ['job_id'=>(int)$existing['id'],'status'=>$existing['status'],'existing'=>true];
    throw $e;
  }
}
function sf_inference_jobs(int $editorId,int $limit=40):array {
  sf_inference_require_editor($editorId);sf_inference_schema();
  $limit=max(1,min(100,$limit));
  $q=sf_db()->query("SELECT id,submission_id,task_type,status,attempt_count,
    error_code,created_by,created_at,updated_at FROM story_inference_jobs ORDER BY id DESC LIMIT $limit");
  return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** An internal worker may claim one job; this is not an HTTP endpoint. */
function sf_inference_claim(int $editorId):?array {
  sf_inference_require_editor($editorId);sf_inference_schema();
  $db=sf_db();
  for($i=0;$i<8;$i++){
    $q=$db->query("SELECT id,submission_id,asset_id,task_type,source_sha256,source_size_bytes
      FROM story_inference_jobs WHERE status='queued' AND attempt_count<3 ORDER BY id LIMIT 1");
    $job=$q->fetch(PDO::FETCH_ASSOC);
    if(!$job)return null;
    try{
      $source=sf_inference_source((int)$job['submission_id'],(string)$job['task_type']);
      if((int)$source['asset_id']!==(int)$job['asset_id']
         ||$source['source_sha256']!==(string)$job['source_sha256']
         ||$source['source_size_bytes']!==(int)$job['source_size_bytes'])
        throw new RuntimeException('Capture evidence changed.');
    }catch(Throwable $e){
      $u=$db->prepare("UPDATE story_inference_jobs SET status='cancelled',
        error_code='authorization_changed',lease_token_sha256=NULL,lease_expires_at=NULL,updated_at=?
        WHERE id=? AND status='queued'");
      $u->execute([gmdate('c'),(int)$job['id']]);
      continue;
    }
    $lease=bin2hex(random_bytes(24));
    $expiry=time()+300;
    $u=$db->prepare("UPDATE story_inference_jobs SET status='leased',
      attempt_count=attempt_count+1,lease_token_sha256=?,lease_expires_at=?,
      error_code='',updated_at=? WHERE id=? AND status='queued' AND attempt_count<3");
    $u->execute([hash('sha256',$lease),$expiry,gmdate('c'),(int)$job['id']]);
    if($u->rowCount()===1)return ['job_id'=>(int)$job['id'],'lease_token'=>$lease,
      'expires_at'=>$expiry,'submission_id'=>(int)$job['submission_id'],
      'task_type'=>$job['task_type']];
  }
  return null;
}
/**
 * Workers must check before every provider call AND before accepting output.
 * Revoked rights, changed evidence, expired leases, cancellation fail closed.
 */
function sf_inference_lease_valid(int $jobId,string $leaseToken):bool {
  if($jobId<1||!preg_match('/^[a-f0-9]{48}$/',$leaseToken))return false;
  sf_inference_schema();
  $q=sf_db()->prepare('SELECT * FROM story_inference_jobs WHERE id=?');
  $q->execute([$jobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
  if(!$job||$job['status']!=='leased'
     ||(int)$job['lease_expires_at']<=time()
     ||!is_string($job['lease_token_sha256'])
     ||!hash_equals($job['lease_token_sha256'],hash('sha256',$leaseToken)))return false;
  try{$source=sf_inference_source((int)$job['submission_id'],(string)$job['task_type']);}
  catch(Throwable $e){return false;}
  return (int)$source['asset_id']===(int)$job['asset_id']
    &&hash_equals($source['source_sha256'],(string)$job['source_sha256'])
    &&$source['source_size_bytes']===(int)$job['source_size_bytes'];
}
/** Unexpected provider outcomes are not replayed automatically. */
function sf_inference_recover(int $editorId):int {
  sf_inference_require_editor($editorId);sf_inference_schema();
  $q=sf_db()->prepare("UPDATE story_inference_jobs SET status='interrupted',
    error_code='lease_expired',lease_token_sha256=NULL,lease_expires_at=NULL,
    updated_at=? WHERE status='leased' AND lease_expires_at<=?");
  $q->execute([gmdate('c'),time()]);
  return $q->rowCount();
}
/** Explicit reviewer action; every retry rechecks rights and immutable evidence. */
function sf_inference_retry(int $editorId,int $jobId):bool {
  sf_inference_require_editor($editorId);sf_inference_schema();
  $q=sf_db()->prepare('SELECT submission_id,task_type,source_sha256,attempt_count,status FROM story_inference_jobs WHERE id=?');
  $q->execute([$jobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
  if(!$job||!in_array($job['status'],['failed','interrupted','cancelled'],true)
     ||(int)$job['attempt_count']>=3)return false;
  $source=sf_inference_source((int)$job['submission_id'],(string)$job['task_type']);
  if(!hash_equals($source['source_sha256'],(string)$job['source_sha256']))return false;
  $u=sf_db()->prepare("UPDATE story_inference_jobs SET status='queued',error_code='',
    lease_token_sha256=NULL,lease_expires_at=NULL,updated_at=? WHERE id=? AND
    status IN ('failed','interrupted','cancelled') AND attempt_count<3");
  $u->execute([gmdate('c'),$jobId]);
  return $u->rowCount()===1;
}
function sf_inference_cancel(int $editorId,int $jobId):bool {
  sf_inference_require_editor($editorId);sf_inference_schema();
  $q=sf_db()->prepare("UPDATE story_inference_jobs SET status='cancelled',
    lease_token_sha256=NULL,lease_expires_at=NULL,error_code='cancelled_by_editor',
    updated_at=? WHERE id=? AND status IN ('queued','leased','interrupted','failed')");
  $q->execute([gmdate('c'),$jobId]);
  return $q->rowCount()===1;
}
