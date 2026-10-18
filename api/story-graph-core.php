<?php
declare(strict_types=1);
/** 88D2D: Private editorial production entity graph with provenance.
 * Entity types are story concepts, never confirmed identities or likeness consent.
 */
function sf_story_graph_schema():void{
 static $ready=false;if($ready)return;
 sf_capture_schema();$db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
 if($sqlite){
  $db->exec("CREATE TABLE IF NOT EXISTS story_graph_entities(
   id INTEGER PRIMARY KEY AUTOINCREMENT,entity_type TEXT NOT NULL,
   label TEXT NOT NULL,description TEXT NOT NULL,origin TEXT NOT NULL,
   review_status TEXT NOT NULL,created_by INTEGER NOT NULL,
   created_at TEXT NOT NULL,reviewed_by INTEGER NULL,reviewed_at TEXT NULL,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
  $db->exec("CREATE TABLE IF NOT EXISTS story_graph_links(
   id INTEGER PRIMARY KEY AUTOINCREMENT,entity_id INTEGER NOT NULL,
   submission_id INTEGER NOT NULL,source_type TEXT NOT NULL,source_id INTEGER NOT NULL,
   relation_type TEXT NOT NULL,created_by INTEGER NOT NULL,created_at TEXT NOT NULL,
   FOREIGN KEY(entity_id) REFERENCES story_graph_entities(id) ON DELETE CASCADE,
   FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
   UNIQUE(entity_id,source_type,source_id,relation_type))");
  $db->exec('CREATE INDEX IF NOT EXISTS idx_graph_entity_label ON story_graph_entities(entity_type,label)');
  $db->exec('CREATE INDEX IF NOT EXISTS idx_graph_links_submission ON story_graph_links(submission_id)');
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_graph_entities(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   entity_type VARCHAR(20) NOT NULL,label VARCHAR(160) NOT NULL,
   description TEXT NOT NULL,origin VARCHAR(24) NOT NULL,
   review_status VARCHAR(24) NOT NULL,created_by BIGINT UNSIGNED NOT NULL,
   created_at VARCHAR(40) NOT NULL,reviewed_by BIGINT UNSIGNED NULL,
   reviewed_at VARCHAR(40) NULL,
   INDEX idx_graph_entity_label(entity_type,label),
   CONSTRAINT fk_graph_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $db->exec("CREATE TABLE IF NOT EXISTS story_graph_links(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_id BIGINT UNSIGNED NOT NULL,
   submission_id BIGINT UNSIGNED NOT NULL,source_type VARCHAR(20) NOT NULL,
   source_id BIGINT UNSIGNED NOT NULL,relation_type VARCHAR(25) NOT NULL,
   created_by BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,
   UNIQUE KEY uq_graph_link(entity_id,source_type,source_id,relation_type),
   INDEX idx_graph_links_submission(submission_id),
   CONSTRAINT fk_graph_link_entity FOREIGN KEY(entity_id) REFERENCES story_graph_entities(id) ON DELETE CASCADE,
   CONSTRAINT fk_graph_link_submission FOREIGN KEY(submission_id) REFERENCES story_capture_submissions(id) ON DELETE CASCADE,
   CONSTRAINT fk_graph_link_actor FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_story_graph_require_editor(int $editorId):void{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
}
function sf_story_graph_create_entity(int $editorId,array $input):int{
 sf_story_graph_require_editor($editorId);
 sf_story_graph_schema();
 $type=(string)($input['entity_type']??'');
 if(!in_array($type,['character','location','event','theme'],true))
  throw new InvalidArgumentException('Invalid production entity type.');
 $label=sf_capture_clean($input['label']??'',160);
 $description=sf_capture_clean($input['description']??'',3000);
 if($label==='')throw new InvalidArgumentException('Entity label required.');
 $origin=(string)($input['origin']??'human');
 if(!in_array($origin,['human','machine_draft'],true))
  throw new InvalidArgumentException('Invalid production origin.');
 // All records begin in draft: no inference of person identity or release.
 $q=sf_db()->prepare('INSERT INTO story_graph_entities
  (entity_type,label,description,origin,review_status,created_by,created_at)
  VALUES(?,?,?,?,?,?,?)');
 $q->execute([$type,$label,$description,$origin,'draft',$editorId,gmdate('c')]);
 $id=(int)sf_db()->lastInsertId();
 sf_team_audit($editorId,'capture.story_entity_created','story_entity',(string)$id,'Private production concept created');
 return $id;
}
function sf_story_graph_review_entity(int $editorId,int $entityId,string $decision):void{
 sf_story_graph_require_editor($editorId);
 if(!in_array($decision,['verified','rejected'],true))
  throw new InvalidArgumentException('Invalid production concept decision.');
 sf_story_graph_schema();
 $q=sf_db()->prepare('UPDATE story_graph_entities SET review_status=?,reviewed_by=?,reviewed_at=?
  WHERE id=? AND review_status=?');
 $q->execute([$decision,$editorId,gmdate('c'),$entityId,'draft']);
 if(!$q->rowCount())throw new InvalidArgumentException('Concept missing or already reviewed.');
 sf_team_audit($editorId,'capture.story_entity_reviewed','story_entity',(string)$entityId,'Production concept reviewed');
}
function sf_story_graph_add_link(int $editorId,int $entityId,int $submissionId,string $sourceType,int $sourceId,string $relation):int{
 sf_story_graph_require_editor($editorId);
 if($entityId<1||$submissionId<1||$sourceId<1)
  throw new InvalidArgumentException('Invalid graph link identity.');
 if(!in_array($sourceType,['scene','transcript','submission'],true)
   ||!in_array($relation,['appears_in','mentioned_in','associated_with'],true))
  throw new InvalidArgumentException('Unsupported provenance relation.');
 sf_story_graph_schema();$db=sf_db();
 $q=$db->prepare('SELECT review_status FROM story_graph_entities WHERE id=?');
 $q->execute([$entityId]);$state=$q->fetchColumn();
 if($state===false||$state==='rejected')throw new InvalidArgumentException('Entity is not available for links.');
 $q=$db->prepare('SELECT review_status FROM story_capture_submissions WHERE id=?');
 $q->execute([$submissionId]);
 if($q->fetchColumn()!=='approved_for_edit')
   throw new InvalidArgumentException('Graph provenance requires an editorially approved submission.');
 if($sourceType==='submission'){
  if($sourceId!==$submissionId)throw new InvalidArgumentException('Submission reference mismatch.');
 }else{
  if($sourceType==='scene')sf_scene_schema();
  else sf_transcript_schema();
  $table=$sourceType==='scene'?'story_scene_annotations':'story_transcripts';
  $q=$db->prepare("SELECT submission_id,review_status FROM $table WHERE id=?");
  $q->execute([$sourceId]);
  $evidence=$q->fetch(PDO::FETCH_ASSOC);
  if(!$evidence||(int)$evidence['submission_id']!==$submissionId)
    throw new InvalidArgumentException('Evidence provenance mismatch.');
  if((string)$evidence['review_status']!=='verified')
    throw new InvalidArgumentException('Graph provenance requires verified source evidence.');
 }
 $q=$db->prepare('INSERT INTO story_graph_links
  (entity_id,submission_id,source_type,source_id,relation_type,created_by,created_at)
  VALUES(?,?,?,?,?,?,?)');
 $q->execute([$entityId,$submissionId,$sourceType,$sourceId,$relation,$editorId,gmdate('c')]);
 $id=(int)$db->lastInsertId();
 sf_team_audit($editorId,'capture.story_link_created','story_graph_link',(string)$id,'Linked provenance to production entity');
 return $id;
}
function sf_story_graph_read_entity(int $viewerId,int $entityId):?array{
 if($viewerId<1||$entityId<1||!sf_team_can($viewerId,'team.assign_roles'))return null;
 sf_story_graph_schema();
 $q=sf_db()->prepare('SELECT * FROM story_graph_entities WHERE id=?');
 $q->execute([$entityId]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row)return null;
 $q=sf_db()->prepare('SELECT id,submission_id,source_type,source_id,relation_type,created_at
  FROM story_graph_links WHERE entity_id=? ORDER BY id');
 $q->execute([$entityId]);$row['links']=$q->fetchAll(PDO::FETCH_ASSOC);
 return $row;
}
