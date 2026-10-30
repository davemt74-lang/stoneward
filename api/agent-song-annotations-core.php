<?php
declare(strict_types=1);
require_once __DIR__.'/agent-song-transcribe-core.php';

/**
 * Section 91B8D: private, user-authorized song-section notes. Audio is never
 * persisted. The lyric transcript is an unverified machine draft (or a
 * listener-edited draft), not an authoritative songbook entry.
 */
function sf_song_annotations_ensure_schema(): void {
    $db=sf_db();
    if((string)(sf_db_config()['driver']??'')==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_song_annotations (
          id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,
          track_id TEXT NOT NULL,title TEXT NOT NULL,release_title TEXT NOT NULL,
          start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,captured_at TEXT NOT NULL,
          transcript_draft TEXT NOT NULL,transcript_corrected TEXT NOT NULL DEFAULT \'\',
          analysis_question TEXT NOT NULL DEFAULT \'\',analysis_report TEXT NOT NULL DEFAULT \'\',
          analysis_mode TEXT NOT NULL DEFAULT \'\',user_note TEXT NOT NULL DEFAULT \'\',
          created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_song_annotations_owner ON agent_song_annotations(user_id,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_agent_song_annotations_track ON agent_song_annotations(user_id,track_id,id)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS agent_song_annotations (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
          track_id VARCHAR(180) NOT NULL,title VARCHAR(180) NOT NULL,release_title VARCHAR(180) NOT NULL,
          start_ms INT UNSIGNED NOT NULL,end_ms INT UNSIGNED NOT NULL,captured_at VARCHAR(40) NOT NULL,
          transcript_draft TEXT NOT NULL,transcript_corrected TEXT NOT NULL,
          analysis_question TEXT NOT NULL,analysis_report TEXT NOT NULL,
          analysis_mode VARCHAR(35) NOT NULL,user_note TEXT NOT NULL,
          created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
          INDEX idx_agent_song_annotations_owner(user_id,id),
          INDEX idx_agent_song_annotations_track(user_id,track_id,id),
          CONSTRAINT fk_agent_song_annotations_owner FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_song_annotation_text($value,int $max,string $label,bool $required=false): string {
    if(!is_string($value))throw new InvalidArgumentException($label.' must be text.');
    if(strlen($value)>$max*4)throw new InvalidArgumentException($label.' exceeds the allowed length.');
    $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value);
    if(!is_string($clean))throw new InvalidArgumentException($label.' encoding is invalid.');
    $clean=trim($clean);
    if(strlen($clean)>$max)throw new InvalidArgumentException($label.' exceeds the allowed length.');
    if($required&&strlen($clean)<3)throw new InvalidArgumentException($label.' is required.');
    return $clean;
}
function sf_song_annotation_validate(array $payload,array $publicCatalog): array {
    $song=sf_song_transcribe_request($payload,$publicCatalog);
    $draft=sf_song_annotation_text($payload['transcript']??null,4200,'Lyric draft',true);
    $corrected=sf_song_annotation_text($payload['corrected_transcript']??'',4200,'Edited lyric draft');
    $question=sf_song_annotation_text($payload['question']??'',700,'Analysis question');
    $report=sf_song_annotation_text($payload['report']??'',6500,'Analysis report');
    $note=sf_song_annotation_text($payload['annotation']??'',1800,'Personal annotation');
    $mode=sf_song_annotation_text($payload['mode']??'',35,'Analysis mode');
    if($mode!==''&&!in_array($mode,['interpretation','grounded_research','structured_report'],true))
        throw new InvalidArgumentException('Invalid analysis mode.');
    if($report!==''&&($question===''||$mode===''))throw new InvalidArgumentException('Analysis provenance required for a saved report.');
    $captured=strtotime($song['captured_at']);
    if($captured===false||$captured>time()+300)throw new InvalidArgumentException('Capture timestamp cannot be in the future.');
    return ['track_id'=>$song['track_id'],'title'=>$song['title'],'release_title'=>$song['release'],
        'start_ms'=>$song['start_ms'],'end_ms'=>$song['end_ms'],'captured_at'=>gmdate('c',$captured),
        'transcript_draft'=>$draft,'transcript_corrected'=>$corrected,'analysis_question'=>$question,
        'analysis_report'=>$report,'analysis_mode'=>$mode,'user_note'=>$note,
        'review_status'=>$corrected!==''?'user_edited_unverified':'machine_unverified'];
}
function sf_song_annotation_require_consent(int $userId): void {
    if($userId<1||!function_exists('sf_privacy_personalization_enabled')||!sf_privacy_personalization_enabled($userId))
        throw new RuntimeException('Enable personal recommendations to save song notes in your private Agent Brain.');
}
function sf_song_annotation_save(int $userId,array $payload,array $publicCatalog): array {
    sf_song_annotation_require_consent($userId);
    $note=sf_song_annotation_validate($payload,$publicCatalog);
    sf_song_annotations_ensure_schema();$db=sf_db();
    $q=$db->prepare('SELECT COUNT(*) FROM agent_song_annotations WHERE user_id=?');$q->execute([$userId]);
    if((int)$q->fetchColumn()>=250)throw new RuntimeException('Song annotation limit reached. Remove an older annotation first.');
    $now=gmdate('c');
    $q=$db->prepare('INSERT INTO agent_song_annotations
      (user_id,track_id,title,release_title,start_ms,end_ms,captured_at,transcript_draft,transcript_corrected,analysis_question,analysis_report,analysis_mode,user_note,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$userId,$note['track_id'],$note['title'],$note['release_title'],$note['start_ms'],$note['end_ms'],
        $note['captured_at'],$note['transcript_draft'],$note['transcript_corrected'],$note['analysis_question'],
        $note['analysis_report'],$note['analysis_mode'],$note['user_note'],$now,$now]);
    return ['id'=>(int)$db->lastInsertId(),'title'=>$note['title'],'release'=>$note['release_title'],
        'track_id'=>$note['track_id'],'start_ms'=>$note['start_ms'],'end_ms'=>$note['end_ms'],
        'captured_at'=>$note['captured_at'],'review_status'=>$note['review_status'],'created_at'=>$now];
}
function sf_song_annotation_list(int $userId,int $limit=30): array {
    if($userId<1)return [];
    sf_song_annotations_ensure_schema();
    $limit=max(1,min(100,$limit));
    $q=sf_db()->prepare('SELECT id,track_id,title,release_title,start_ms,end_ms,captured_at,transcript_draft,
      transcript_corrected,analysis_question,analysis_report,analysis_mode,user_note,created_at,updated_at
      FROM agent_song_annotations WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);
    $q->execute([$userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_song_annotation_delete(int $userId,int $id): bool {
    if($userId<1||$id<1)return false;
    sf_song_annotations_ensure_schema();
    $q=sf_db()->prepare('DELETE FROM agent_song_annotations WHERE user_id=? AND id=?');
    $q->execute([$userId,$id]);return $q->rowCount()===1;
}
/** Only listener-authored fields may change; the captured draft and offsets are immutable. */
function sf_song_annotation_update(int $userId,int $id,array $payload): bool {
    if($userId<1||$id<1)return false;
    sf_song_annotation_require_consent($userId);
    sf_song_annotations_ensure_schema();
    $corrected=sf_song_annotation_text($payload['corrected_transcript']??null,4200,'Edited lyric draft',true);
    $note=sf_song_annotation_text($payload['annotation']??'',1800,'Personal annotation');
    $q=sf_db()->prepare('UPDATE agent_song_annotations SET transcript_corrected=?,user_note=?,analysis_report=?,analysis_question=?,analysis_mode=?,updated_at=? WHERE user_id=? AND id=?');
    // A report about an earlier version cannot be relabeled as analysis of the edit.
    $q->execute([$corrected,$note,'','','',gmdate('c'),$userId,$id]);
    return $q->rowCount()===1;
}
