<?php
declare(strict_types=1);
/**
 * 88D3G3 — Admin-authenticated private MP4 review, NEVER publication.
 * All sources/rights/output bytes reverified before streaming or approval.
 */
function sf_render_review_schema():void{
 static $ready=false;if($ready)return;
 sf_render_jobs_schema();
 $db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_reviews(
    job_id INTEGER PRIMARY KEY,decision TEXT NOT NULL,basis TEXT NOT NULL,
    reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
    FOREIGN KEY(job_id) REFERENCES story_render_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_reviews(
    job_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,decision VARCHAR(24) NOT NULL,
    basis TEXT NOT NULL,reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
    CONSTRAINT fk_render_review_job FOREIGN KEY(job_id) REFERENCES story_render_jobs(id) ON DELETE CASCADE,
    CONSTRAINT fk_render_review_actor FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_render_review_job(int $editor,int $jobId):array {
 sf_assembly_editor($editor);sf_render_review_schema();
 $q=sf_db()->prepare("SELECT j.id,j.assembly_id,j.revision,j.status,j.output_name,
    j.output_sha256,j.output_bytes,j.created_at,
    r.decision,r.reviewed_at,r.basis
    FROM story_render_jobs j
    LEFT JOIN story_render_reviews r ON r.job_id=j.id
    WHERE j.id=?");
 $q->execute([$jobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
 if(!$job)throw new InvalidArgumentException('Private render not found.');
 return $job;
}
/** Output is never supplied by the browser, only read from a trusted DB reference. */
function sf_render_review_file(int $editor,int $jobId):array {
 $job=sf_render_review_job($editor,$jobId);
 if(!in_array($job['status'],['private_review','approved_private'],true))
  throw new RuntimeException('Private render is not reviewable.');
 $assembly=(int)$job['assembly_id'];$rev=(int)$job['revision'];
 $plan=sf_render_plan($editor,$assembly,$rev); // current source hashes and rights
 if($plan['total_ms']<1000)throw new RuntimeException('Source preflight no longer valid.');
 $name=(string)$job['output_name'];
 if(!preg_match('/^assembly-[1-9][0-9]*-rev-[1-9][0-9]*-job-[1-9][0-9]*-[a-f0-9]{16}\.mp4$/D',$name)
  ||!str_contains($name,'assembly-'.$assembly.'-rev-'.$rev.'-job-'.$jobId.'-'))
  throw new RuntimeException('Output identifier invalid.');
 $root=sf_render_private_root();
 $path=realpath($root.'/'.$name);
 if(!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)
   ||!is_file($path)||!is_readable($path))throw new RuntimeException('Private output missing.');
 $size=filesize($path);
 if(!is_int($size)||$size<100||$size>150*1024*1024||$size!==(int)$job['output_bytes']
   ||!preg_match('/^[a-f0-9]{64}$/D',(string)$job['output_sha256'])
   ||!hash_equals((string)$job['output_sha256'],(string)hash_file('sha256',$path)))
  throw new RuntimeException('Private video digest no longer matches certified output.');
 return ['path'=>$path,'size'=>$size,'sha256'=>$job['output_sha256'],
  'job_id'=>$jobId,'assembly_id'=>$assembly,'revision'=>$rev];
}
/** Header parser: one bounded range, rejects suffix/multiple/out-of-bounds requests. */
function sf_render_review_range(?string $header,int $size):array {
 if($size<1)throw new InvalidArgumentException('Empty video.');
 if($header===null||$header==='')return [0,min($size-1,1048575),$size>1048576];
 if(!preg_match('/^bytes=([0-9]+)-([0-9]*)$/D',trim($header),$m))
  throw new InvalidArgumentException('Unsupported byte range.');
 $start=(int)$m[1];$end=$m[2]===''?$size-1:(int)$m[2];
 if($start<0||$start>=$size||$end<$start)throw new InvalidArgumentException('Byte range unavailable.');
 $end=min($size-1,$end,$start+1048575);
 return [$start,$end,true];
}
/** Never return private path, hash, release documentation or contributor identity to browser. */
function sf_render_review_admin(int $editor,int $assemblyId):array {
 sf_assembly_editor($editor);sf_render_review_schema();
 $q=sf_db()->prepare("SELECT j.id,j.revision,j.status,j.output_bytes,j.created_at,
    r.decision,r.reviewed_at
    FROM story_render_jobs j LEFT JOIN story_render_reviews r ON r.job_id=j.id
    WHERE j.assembly_id=? ORDER BY j.id DESC LIMIT 30");
 $q->execute([$assemblyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_render_review_decide(int $editor,int $jobId,string $decision,string $basis):bool{
 sf_assembly_editor($editor);sf_render_review_schema();
 if(!in_array($decision,['approved_private','rejected'],true))
  throw new InvalidArgumentException('Invalid private video review.');
 $basis=sf_capture_clean($basis,1000);
 if(strlen($basis)<25)throw new InvalidArgumentException('Editorial decision requires a substantive review basis (25+ characters).');
 $job=sf_render_review_job($editor,$jobId);
 if($job['status']!=='private_review')return false;
 if($decision==='approved_private')sf_render_review_file($editor,$jobId);
 // Rejection is always possible even after rights revocation.
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db=sf_db();
 if($db->inTransaction())throw new RuntimeException('Nested render review transaction unsupported.');
 $db->beginTransaction();
 try{
  if($decision==='approved_private'){
   // Recheck before committing the approval; no outbound publication.
   sf_render_review_file($editor,$jobId);
  }
  $q=$db->prepare("UPDATE story_render_jobs SET status=?,updated_at=?
    WHERE id=? AND status='private_review'");
  $q->execute([$decision,gmdate('c'),$jobId]);
  if($q->rowCount()!==1){$db->rollBack();return false;}
  $q=$db->prepare('INSERT INTO story_render_reviews
    (job_id,decision,basis,reviewed_by,reviewed_at) VALUES(?,?,?,?,?)');
  $q->execute([$jobId,$decision,$basis,$editor,gmdate('c')]);
  sf_team_audit($editor,'capture.private_render_reviewed','story_render_job',(string)$jobId,
   'Private video editorial review: '.$decision.'; no public distribution');
  $db->commit();return true;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
