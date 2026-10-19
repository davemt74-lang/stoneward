<?php
declare(strict_types=1);
/** G4K: private, ordered production task events and in-app coordination. No broadcasts. */
function sf_production_task_events_schema():void{
 static $ready=false;if($ready)return;
 sf_production_tasks_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_task_events(
    id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER NOT NULL,actor_id INTEGER NOT NULL,
    recipient_id INTEGER NULL,event_type TEXT NOT NULL,status_from TEXT NOT NULL,status_to TEXT NOT NULL,
    evidence_id INTEGER NULL,note TEXT NOT NULL,created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES story_production_tasks(id) ON DELETE CASCADE,
    FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE SET NULL)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_task_events_task ON story_production_task_events(task_id,id)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_task_events(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,task_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,recipient_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(32) NOT NULL,status_from VARCHAR(24) NOT NULL,status_to VARCHAR(24) NOT NULL,
    evidence_id BIGINT UNSIGNED NULL,note VARCHAR(900) NOT NULL,created_at VARCHAR(40) NOT NULL,
    INDEX idx_task_events_task(task_id,id),
    CONSTRAINT fk_task_event_task FOREIGN KEY(task_id) REFERENCES story_production_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_task_event_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_task_event_recipient FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE SET NULL)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
/** Called inside the same active SQL transaction as the corresponding task mutation. */
function sf_production_task_event(int $task,int $actor,?int $recipient,string $type,
 string $before,string $after,string $note='',?int $evidence=null):void{
 if(!sf_db()->inTransaction())throw new RuntimeException('Task event must be transactional.');
 if(!in_array($type,['proposed','assigned','reassigned','progress','submitted','approved','rejected','reopened','scheduled','deadline_requested','deadline_approved','deadline_rejected'],true))
  throw new InvalidArgumentException('Unknown production event.');
 $q=sf_db()->prepare('INSERT INTO story_production_task_events
   (task_id,actor_id,recipient_id,event_type,status_from,status_to,evidence_id,note,created_at)
   VALUES(?,?,?,?,?,?,?,?,?)');
 $q->execute([$task,$actor,$recipient,$type,$before,$after,$evidence,
   sf_capture_clean($note,900),gmdate('c')]);
}
function sf_production_task_timeline(int $viewer,int $task):array{
 sf_production_task_read($viewer,$task);sf_production_task_events_schema();
 $q=sf_db()->prepare('SELECT id,task_id,actor_id,recipient_id,event_type,status_from,status_to,
 evidence_id,note,created_at FROM story_production_task_events WHERE task_id=?
 ORDER BY id DESC LIMIT 60');
 $q->execute([$task]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** In-app only; previous assignees cannot see a task after reassignment. */
function sf_production_task_notifications(int $viewer):array{
 if($viewer<1)throw new RuntimeException('Login required.');
 sf_production_task_events_schema();
 $tasks=sf_production_task_list($viewer);
 $ids=array_map('intval',array_column($tasks,'id'));
 if(!$ids)return [];
 $markers=implode(',',array_fill(0,count($ids),'?'));
 $sql='SELECT e.id,e.task_id,e.actor_id,e.event_type,e.status_to,e.created_at,t.title
  FROM story_production_task_events e JOIN story_production_tasks t ON t.id=e.task_id
  WHERE e.task_id IN ('.$markers.') AND e.event_type IN
  (\'assigned\',\'reassigned\',\'submitted\',\'approved\',\'rejected\',\'reopened\',\'progress\')
  ORDER BY e.id DESC LIMIT 25';
 $q=sf_db()->prepare($sql);$q->execute($ids);
 return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Incremental progress report; no evidence or completion claims. */
function sf_production_task_progress(int $actor,int $task,string $update):void{
 $row=sf_production_task_read($actor,$task);
 if((int)$row['assigned_to']!==$actor||!in_array($row['status'],['assigned','in_progress'],true))
  throw new RuntimeException('Only active assignee can report task progress.');
 $update=sf_capture_clean($update,600);
 if(strlen($update)<15)throw new InvalidArgumentException('Progress note must contain 15+ characters.');
 sf_production_task_events_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested task progress.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("UPDATE story_production_tasks SET status='in_progress',updated_at=?
    WHERE id=? AND assigned_to=? AND status IN ('assigned','in_progress')");
  $q->execute([gmdate('c'),$task,$actor]);
  if($q->rowCount()!==1)throw new RuntimeException('Task changed before progress was recorded.');
  sf_production_task_event($task,$actor,null,'progress',$row['status'],'in_progress',$update);
  sf_team_audit($actor,'production.task_progress','story_production_task',(string)$task,
    'Private production progress recorded for existing assigned task');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Rejected completion requires a conscious new editor assignment and basis. */
function sf_production_task_reopen(int $editor,int $task,int $assignee,string $basis):void{
 sf_production_task_editor($editor);
 $row=sf_production_task_read($editor,$task);
 if($row['status']!=='rejected')throw new RuntimeException('Only rejected work may be reopened.');
 $basis=sf_capture_clean($basis,900);
 if(strlen($basis)<25)throw new InvalidArgumentException('Recovery basis must contain 25+ characters.');
 if($assignee<1||($row['target_role']!=='all'&&
   !in_array($row['target_role'],sf_capture_roles($assignee),true)))
  throw new RuntimeException('Reassigned contributor lacks the required role.');
 $q=sf_db()->prepare("SELECT status FROM users WHERE id=?");$q->execute([$assignee]);
 if($q->fetchColumn()!=='active')throw new RuntimeException('Assignee account is not active.');
 sf_production_task_events_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested task recovery.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("UPDATE story_production_tasks SET status='assigned',assigned_to=?,
   evidence_id=NULL,contributor_note='',review_basis='',reviewed_by=NULL,updated_at=?
   WHERE id=? AND status='rejected'");
  $q->execute([$assignee,gmdate('c'),$task]);
  if($q->rowCount()!==1)throw new RuntimeException('Task was already reopened.');
  sf_production_task_event($task,$editor,$assignee,'reopened','rejected','assigned',$basis,
    (int)($row['evidence_id']??0)?:null);
  sf_team_audit($editor,'production.task_reopened','story_production_task',(string)$task,
    'Human reviewed rejected evidence and returned task for correction');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
