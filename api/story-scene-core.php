<?php
declare(strict_types=1);
/**
 * 88D2C — Private shot/scene observations. AI observations remain draft,
 * with no inferred identity treated as likeness consent or publication approval.
 */
function sf_scene_schema():void{
 static $ready=false;if($ready)return;sf_capture_schema();
 $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
 if($sqlite){
  $db->exec("CREATE TABLE IF NOT EXISTS story_scene_annotations(
   id INTEGER PRIMARY KEY AUTOINCREMENT,submission_id INTEGER NOT NULL,
   asset_id INTEGER NOT NULL,created_by INTEGER NOT NULL,
   start_ms INTEGER NOT NULL,end_ms INTEGER NOT NULL,description TEXT NOT NULL,
   location_label TEXT NOT NULL,tags_json TEXT NOT NULL,origin TEXT NOT NULL,
   review_status TEXT NOT NULL,created_at TEXT NOT NULL,reviewed_by INTEGER NULL,reviewed_at TEXT NULL,
   FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
   FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
  $db->exec('CREATE INDEX IF NOT EXISTS idx_story_scene_submission ON story_scene_annotations(submission_id,start_ms)');
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_scene_annotations(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   submission_id BIGINT UNSIGNED NOT NULL,asset_id BIGINT UNSIGNED NOT NULL,
   created_by BIGINT UNSIGNED NOT NULL,start_ms BIGINT UNSIGNED NOT NULL,
   end_ms BIGINT UNSIGNED NOT NULL,description TEXT NOT NULL,
   location_label VARCHAR(180) NOT NULL,tags_json TEXT NOT NULL,
   origin VARCHAR(24) NOT NULL,review_status VARCHAR(24) NOT NULL,
   created_at VARCHAR(40) NOT NULL,reviewed_by BIGINT UNSIGNED NULL,reviewed_at VARCHAR(40) NULL,
   INDEX idx_story_scene_submission(submission_id,start_ms),
   CONSTRAINT fk_story_scene_submission FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
   CONSTRAINT fk_story_scene_asset FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE CASCADE,
   CONSTRAINT fk_story_scene_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_scene_validate_tags(mixed $raw):string{
 if(!is_array($raw)||count($raw)>12)throw new InvalidArgumentException('Up to twelve tags required.');
 $tags=[];
 foreach($raw as $t){
  if(!is_string($t))throw new InvalidArgumentException('Invalid scene tag.');
  $tag=strtolower(trim($t));
  if(!preg_match('/^[a-z0-9][a-z0-9 _-]{0,39}$/',$tag))
   throw new InvalidArgumentException('Invalid scene tag format.');
  $tags[$tag]=$tag;
 }
 return json_encode(array_values($tags),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function sf_scene_create(int $actorId,int $submissionId,array $input):int{
 if($actorId<1||$submissionId<1)throw new InvalidArgumentException('Invalid scene identity.');
 sf_scene_schema();$db=sf_db();
 $q=$db->prepare('SELECT s.asset_id,s.contributor_id,s.submission_type,a.category FROM story_capture_submissions s
  JOIN media_assets a ON a.id=s.asset_id WHERE s.id=?');
 $q->execute([$submissionId]);$capture=$q->fetch(PDO::FETCH_ASSOC);
 if(!$capture||!in_array($capture['category'],['image','video'],true)||
  sf_media_protected_master_asset((int)$capture['asset_id']))
  throw new InvalidArgumentException('Capture cannot be visually annotated.');
 $editor=sf_team_can($actorId,'team.assign_roles');
 if(!$editor&&(int)$capture['contributor_id']!==$actorId)
  throw new RuntimeException('Not allowed to annotate this capture.');
 $origin=(string)($input['origin']??'human');
 if(!in_array($origin,['human','machine_draft'],true))
  throw new InvalidArgumentException('Invalid annotation origin.');
 if($origin==='machine_draft'&&!$editor)
  throw new RuntimeException('AI scene intake requires editor authority.');
 $start=$input['start_ms']??null;$end=$input['end_ms']??null;
 if(!is_int($start)||!is_int($end)||$start<0||$end<0||$end>86400000)
  throw new InvalidArgumentException('Invalid scene timecodes.');
 if($capture['category']==='image'&&($start!==0||$end!==0))
  throw new InvalidArgumentException('Still image timecode must be zero.');
 if($capture['category']==='video'&&$end<=$start)
  throw new InvalidArgumentException('Video scene requires positive duration.');
 $description=sf_capture_clean($input['description']??'',2500);
 $location=sf_capture_clean($input['location_label']??'',180);
 if($description==='')throw new InvalidArgumentException('Scene description required.');
 $tags=sf_scene_validate_tags($input['tags']??[]);
 $q=$db->prepare('INSERT INTO story_scene_annotations
  (submission_id,asset_id,created_by,start_ms,end_ms,description,location_label,tags_json,origin,review_status,created_at)
  VALUES(?,?,?,?,?,?,?,?,?,?,?)');
 $q->execute([$submissionId,(int)$capture['asset_id'],$actorId,$start,$end,$description,
  $location,$tags,$origin,'draft',gmdate('c')]);
 $id=(int)$db->lastInsertId();
 sf_team_audit($actorId,'capture.scene_drafted','story_scene',(string)$id,'Private scene annotation draft');
 return $id;
}
function sf_scene_read(int $viewerId,int $sceneId):?array{
 if($viewerId<1||$sceneId<1)return null;
 sf_scene_schema();$editor=sf_team_can($viewerId,'team.assign_roles');
 $sql='SELECT n.* FROM story_scene_annotations n JOIN story_capture_submissions s
  ON s.id=n.submission_id WHERE n.id=?';
 if(!$editor)$sql.=' AND s.contributor_id=?';
 $q=sf_db()->prepare($sql);
 $q->execute($editor?[$sceneId]:[$sceneId,$viewerId]);
 $row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row)return null;
 $row['tags']=json_decode($row['tags_json'],true)??[];
 unset($row['tags_json']);
 return $row;
}
function sf_scene_review(int $editorId,int $sceneId,string $decision):void{
 if(!sf_team_can($editorId,'team.assign_roles'))throw new RuntimeException('Editor required.');
 if(!in_array($decision,['verified','rejected'],true))
  throw new InvalidArgumentException('Invalid scene review decision.');
 sf_scene_schema();
 $q=sf_db()->prepare('UPDATE story_scene_annotations SET review_status=?,reviewed_by=?,reviewed_at=? WHERE id=? AND review_status=?');
 $q->execute([$decision,$editorId,gmdate('c'),$sceneId,'draft']);
 if(!$q->rowCount())throw new InvalidArgumentException('Scene already reviewed or missing.');
 sf_team_audit($editorId,'capture.scene_reviewed','story_scene',(string)$sceneId,'Scene annotation reviewed: '.$decision);
}
