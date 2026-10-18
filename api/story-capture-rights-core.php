<?php
declare(strict_types=1);
/**
 * 88D2H4 — independent human editorial clearance ledger.
 * A contributor's rights_attested / people_release_status fields are NEVER
 * clearance for external AI transfer or public distribution.
 */
function sf_capture_rights_schema():void {
  static $ready=false;if($ready)return;
  sf_capture_schema();
  $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
  if($sqlite){
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_rights_reviews(
      submission_id INTEGER NOT NULL,purpose TEXT NOT NULL,status TEXT NOT NULL,
      basis TEXT NOT NULL,reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
      PRIMARY KEY(submission_id,purpose),
      FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
  }else{
    $db->exec("CREATE TABLE IF NOT EXISTS story_capture_rights_reviews(
      submission_id BIGINT UNSIGNED NOT NULL,purpose VARCHAR(32) NOT NULL,
      status VARCHAR(24) NOT NULL,basis TEXT NOT NULL,
      reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
      PRIMARY KEY(submission_id,purpose),INDEX idx_rights_reviewer(reviewed_by),
      CONSTRAINT fk_capture_rights_submission FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      CONSTRAINT fk_capture_rights_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $ready=true;
}
/** Only editor-reviewed, approved captures with explicit purpose clearance pass. */
function sf_capture_rights_status(int $submissionId,string $purpose):string {
  if($submissionId<1||!in_array($purpose,['external_ai','publication'],true))return 'unreviewed';
  sf_capture_rights_schema();
  $q=sf_db()->prepare('SELECT s.review_status,r.status
    FROM story_capture_submissions s LEFT JOIN story_capture_rights_reviews r
     ON r.submission_id=s.id AND r.purpose=?
    WHERE s.id=?');
  $q->execute([$purpose,$submissionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row||$row['review_status']!=='approved_for_edit')return 'unreviewed';
  return in_array((string)($row['status']??''),['cleared','restricted'],true)
    ?(string)$row['status']:'unreviewed';
}
function sf_capture_rights_may_use(int $submissionId,string $purpose):bool {
  return sf_capture_rights_status($submissionId,$purpose)==='cleared';
}
/** Audit-backed separate editor decision; not a legal guarantee or consent inference. */
function sf_capture_rights_review(int $editorId,int $submissionId,string $purpose,string $decision,string $basis):void {
  if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
    throw new RuntimeException('Rights review requires editor authority.');
  if(!in_array($purpose,['external_ai','publication'],true)
    ||!in_array($decision,['cleared','restricted'],true))
    throw new InvalidArgumentException('Invalid rights purpose or decision.');
  $basis=sf_capture_clean($basis,1000);
  if(strlen($basis)<25)throw new InvalidArgumentException('Document the independent rights evidence or restriction (25+ characters).');
  sf_capture_rights_schema();
  if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
  $db=sf_db();
  if($db->inTransaction())throw new RuntimeException('Nested rights review transaction unsupported.');
  $db->beginTransaction();
  try{
    $q=$db->prepare('SELECT review_status FROM story_capture_submissions WHERE id=?');
    $q->execute([$submissionId]);$reviewState=$q->fetchColumn();
    if($reviewState===false)throw new InvalidArgumentException('Capture submission not found.');
    if($decision==='cleared'&&$reviewState!=='approved_for_edit')
      throw new InvalidArgumentException('Cannot clear a capture before editorial approval.');
    $now=gmdate('c');
    $q=$db->prepare('SELECT status FROM story_capture_rights_reviews WHERE submission_id=? AND purpose=?');
    $q->execute([$submissionId,$purpose]);
    if($q->fetchColumn()===false){
      $q=$db->prepare('INSERT INTO story_capture_rights_reviews(submission_id,purpose,status,basis,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?)');
      $q->execute([$submissionId,$purpose,$decision,$basis,$editorId,$now]);
    }else{
      $q=$db->prepare('UPDATE story_capture_rights_reviews SET status=?,basis=?,reviewed_by=?,reviewed_at=? WHERE submission_id=? AND purpose=?');
      $q->execute([$decision,$basis,$editorId,$now,$submissionId,$purpose]);
    }
    sf_team_audit($editorId,'capture.rights_reviewed','capture_submission',(string)$submissionId,
      'Human rights decision for '.$purpose.': '.$decision);
    $db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
