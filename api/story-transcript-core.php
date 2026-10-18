<?php
declare(strict_types=1);
/**
 * 88D2B: Private, timecoded dialogue drafts and editorial verification.
 * No automatic speech recognition is claimed here. A machine suggestion is
 * explicitly unverified until an authorized human accepts the transcript.
 */
function sf_transcript_schema():void{
  static $ready=false;if($ready)return;
  sf_capture_schema();$db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
  if($sqlite){
    $db->exec("CREATE TABLE IF NOT EXISTS story_transcripts(
      id INTEGER PRIMARY KEY AUTOINCREMENT,submission_id INTEGER NOT NULL,
      actor_id INTEGER NOT NULL,origin TEXT NOT NULL,language TEXT NOT NULL,
      review_status TEXT NOT NULL,created_at TEXT NOT NULL,reviewed_by INTEGER NULL,
      reviewed_at TEXT NULL,FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT)");
    $db->exec("CREATE TABLE IF NOT EXISTS story_transcript_segments(
      id INTEGER PRIMARY KEY AUTOINCREMENT,transcript_id INTEGER NOT NULL,
      ordinal INTEGER NOT NULL,start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,
      speaker_label TEXT NOT NULL,dialogue_text TEXT NOT NULL,
      FOREIGN KEY(transcript_id) REFERENCES story_transcripts(id) ON DELETE CASCADE,
      UNIQUE(transcript_id,ordinal))");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_story_transcripts_submission ON story_transcripts(submission_id,id)');
  }else{
    $db->exec("CREATE TABLE IF NOT EXISTS story_transcripts(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      submission_id BIGINT UNSIGNED NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,
      origin VARCHAR(24) NOT NULL,language VARCHAR(12) NOT NULL,
      review_status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,
      reviewed_by BIGINT UNSIGNED NULL,reviewed_at VARCHAR(40) NULL,
      INDEX idx_story_transcripts_submission(submission_id,id),
      CONSTRAINT fk_transcripts_submission FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
      CONSTRAINT fk_transcripts_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS story_transcript_segments(
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      transcript_id BIGINT UNSIGNED NOT NULL,ordinal INT NOT NULL,
      start_ms BIGINT UNSIGNED NOT NULL,end_ms BIGINT UNSIGNED NOT NULL,
      speaker_label VARCHAR(80) NOT NULL,dialogue_text TEXT NOT NULL,
      UNIQUE KEY uq_transcript_ordinal(transcript_id,ordinal),
      CONSTRAINT fk_transcript_segment FOREIGN KEY(transcript_id) REFERENCES story_transcripts(id) ON DELETE CASCADE)
      ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $ready=true;
}
/** Never interpret an unlabeled speaker as proof of someone's identity. */
function sf_transcript_segments_validate(array $segments):array{
  if(count($segments)<1||count($segments)>300)
    throw new InvalidArgumentException('Transcript must have 1 to 300 segments.');
  $out=[];$prevStart=-1;
  foreach($segments as $i=>$item){
    if(!is_array($item))throw new InvalidArgumentException('Invalid segment.');
    $start=$item['start_ms']??null;$end=$item['end_ms']??null;
    if(!is_int($start)||!is_int($end)||$start<0||$end<=$start||$end>24*3600*1000)
      throw new InvalidArgumentException('Invalid segment timecodes.');
    if($start<$prevStart)throw new InvalidArgumentException('Segments must be chronological.');
    $text=sf_capture_clean($item['text']??'',1200);
    $speaker=sf_capture_clean($item['speaker_label']??'',80);
    if($text==='')throw new InvalidArgumentException('Empty transcript segment.');
    $out[]=['ordinal'=>$i,'start_ms'=>$start,'end_ms'=>$end,'speaker_label'=>$speaker,'dialogue_text'=>$text];
    $prevStart=$start;
  }
  return $out;
}
/** Inserts an immutable candidate transcript; editorial review is separate. */
function sf_transcript_create(int $actorId,int $submissionId,array $input):int{
  if($actorId<1||$submissionId<1)throw new InvalidArgumentException('Invalid transcript.');
  sf_transcript_schema();$db=sf_db();
  $q=$db->prepare('SELECT contributor_id,asset_id,submission_type FROM story_capture_submissions WHERE id=?');
  $q->execute([$submissionId]);$capture=$q->fetch(PDO::FETCH_ASSOC);
  if(!$capture)throw new InvalidArgumentException('Capture not found.');
  $editor=sf_team_can($actorId,'team.assign_roles');
  if(!$editor&&(int)$capture['contributor_id']!==$actorId)
    throw new RuntimeException('Not permitted to transcribe this capture.');
  if(!in_array($capture['submission_type'],['video','audio','ambient','dialogue'],true))
    throw new InvalidArgumentException('Capture is not transcribable.');
  $origin=(string)($input['origin']??'human');
  if(!in_array($origin,['human','machine_draft'],true))
    throw new InvalidArgumentException('Invalid transcript origin.');
  if($origin==='machine_draft'&&!$editor)
    throw new RuntimeException('Machine draft intake requires editorial authorization.');
  $language=(string)($input['language']??'und');
  if(!preg_match('/^(und|[a-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2})$/',$language))
    throw new InvalidArgumentException('Invalid language code.');
  $items=$input['segments']??null;
  if(!is_array($items))throw new InvalidArgumentException('Transcript segments required.');
  $segments=sf_transcript_segments_validate($items);
  $db->beginTransaction();
  try{
    $now=gmdate('c');
    $stmt=$db->prepare('INSERT INTO story_transcripts(submission_id,actor_id,origin,language,review_status,created_at) VALUES(?,?,?,?,?,?)');
    $stmt->execute([$submissionId,$actorId,$origin,$language,'draft',$now]);
    $id=(int)$db->lastInsertId();
    $stmt=$db->prepare('INSERT INTO story_transcript_segments(transcript_id,ordinal,start_ms,end_ms,speaker_label,dialogue_text) VALUES(?,?,?,?,?,?)');
    foreach($segments as $s)$stmt->execute([$id,$s['ordinal'],$s['start_ms'],$s['end_ms'],$s['speaker_label'],$s['dialogue_text']]);
    $db->commit();
    sf_team_audit($actorId,'capture.transcript_created','story_transcript',(string)$id,'Private timecoded transcript draft');
    return $id;
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_transcript_read(int $viewerId,int $transcriptId):?array{
  if($viewerId<1||$transcriptId<1)return null;
  sf_transcript_schema();
  $editor=sf_team_can($viewerId,'team.assign_roles');
  $sql='SELECT t.*,s.contributor_id FROM story_transcripts t
    JOIN story_capture_submissions s ON s.id=t.submission_id WHERE t.id=?';
  if(!$editor)$sql.=' AND s.contributor_id=?';
  $q=sf_db()->prepare($sql);
  $q->execute($editor?[$transcriptId]:[$transcriptId,$viewerId]);
  $row=$q->fetch(PDO::FETCH_ASSOC);
  if(!$row)return null;
  unset($row['contributor_id']);
  $q=sf_db()->prepare('SELECT ordinal,start_ms,end_ms,speaker_label,dialogue_text FROM story_transcript_segments WHERE transcript_id=? ORDER BY ordinal');
  $q->execute([$transcriptId]);$row['segments']=$q->fetchAll(PDO::FETCH_ASSOC);
  return $row;
}
function sf_transcript_review(int $editorId,int $transcriptId,string $decision):void{
  if(!sf_team_can($editorId,'team.assign_roles'))throw new RuntimeException('Editor required.');
  if(!in_array($decision,['verified','rejected'],true))
    throw new InvalidArgumentException('Invalid transcript decision.');
  sf_transcript_schema();
  $q=sf_db()->prepare('UPDATE story_transcripts SET review_status=?,reviewed_by=?,reviewed_at=? WHERE id=? AND review_status=?');
  $q->execute([$decision,$editorId,gmdate('c'),$transcriptId,'draft']);
  if(!$q->rowCount())throw new InvalidArgumentException('Transcript already reviewed or unavailable.');
  sf_team_audit($editorId,'capture.transcript_reviewed','story_transcript',(string)$transcriptId,'Editorial transcript decision: '.$decision);
}
