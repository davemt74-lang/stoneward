<?php
declare(strict_types=1);
/** G4M — reviewed date extensions, never model-driven actions. */
function sf_production_deadline_schema():void{
 static $ready=false;if($ready)return;
 sf_production_schedule_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_deadline_requests(
   id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER NOT NULL,requested_by INTEGER NOT NULL,
   assigned_to INTEGER NOT NULL,expected_due_at TEXT NOT NULL,proposed_due_at TEXT NOT NULL,
   priority TEXT NOT NULL,status TEXT NOT NULL,reason TEXT NOT NULL,
   requested_at TEXT NOT NULL,reviewed_by INTEGER NULL,reviewed_at TEXT NULL,
   review_basis TEXT NOT NULL DEFAULT '',applied_at TEXT NULL,
   UNIQUE(task_id,requested_by,expected_due_at,proposed_due_at),
   FOREIGN KEY(task_id) REFERENCES story_production_tasks(id) ON DELETE CASCADE,
   FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_deadline_requests(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,task_id BIGINT UNSIGNED NOT NULL,
   requested_by BIGINT UNSIGNED NOT NULL,assigned_to BIGINT UNSIGNED NOT NULL,
   expected_due_at VARCHAR(24) NOT NULL,proposed_due_at VARCHAR(24) NOT NULL,
   priority VARCHAR(12) NOT NULL,status VARCHAR(24) NOT NULL,reason VARCHAR(900) NOT NULL,
   requested_at VARCHAR(40) NOT NULL,reviewed_by BIGINT UNSIGNED NULL,reviewed_at VARCHAR(40) NULL,
   review_basis VARCHAR(900) NOT NULL,applied_at VARCHAR(40) NULL,
   UNIQUE KEY uq_deadline_request(task_id,requested_by,expected_due_at,proposed_due_at),
   KEY idx_deadline_review(status,requested_at),
   CONSTRAINT fk_deadline_task FOREIGN KEY(task_id)
     REFERENCES story_production_tasks(id) ON DELETE CASCADE,
   CONSTRAINT fk_deadline_requester FOREIGN KEY(requested_by)
     REFERENCES users(id) ON DELETE RESTRICT,
   CONSTRAINT fk_deadline_reviewer FOREIGN KEY(reviewed_by)
     REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_production_deadline_requests(int $viewer):array{
 if($viewer<1)throw new RuntimeException('Login required.');
 sf_production_deadline_schema();$editor=sf_team_can($viewer,'team.assign_roles');
 $rows=sf_db()->query("SELECT r.id,r.task_id,r.requested_by,r.assigned_to,r.expected_due_at,
 r.proposed_due_at,r.priority,r.status,r.reason,r.requested_at,r.reviewed_by,r.reviewed_at,
 r.review_basis,r.applied_at,t.title,t.target_role,t.status AS task_status
 FROM story_production_deadline_requests r JOIN story_production_tasks t ON t.id=r.task_id
 ORDER BY r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
 if($editor)return array_slice($rows,0,30);
 $eligible=[];
 foreach($rows as $row){
  if((int)$row['requested_by']!==$viewer ||(int)$row['assigned_to']!==$viewer)continue;
  // Revocation or reassignment invalidates access to private requests as well.
  try{sf_production_task_read($viewer,(int)$row['task_id']);}
  catch(Throwable $e){continue;}
  $eligible[]=$row;if(count($eligible)>=30)break;
 }
 return $eligible;
}
function sf_production_deadline_request(int $member,int $task,string $proposed,string $reason):array{
 sf_production_deadline_schema();
 $item=sf_production_task_read($member,$task);
 if((int)$item['assigned_to']!==$member||
    !in_array($item['status'],['assigned','in_progress'],true))
   throw new RuntimeException('Only the current assigned crew member may request an extension.');
 $proposed=sf_production_schedule_instant($proposed);
 $reason=sf_capture_clean($reason,900);
 if(strlen($reason)<25)throw new InvalidArgumentException('Describe the extension reason (25+ characters).');
 $db=sf_db();$query=$db->prepare('SELECT due_at,priority FROM story_production_task_schedules WHERE task_id=?');
 $query->execute([$task]);$schedule=$query->fetch(PDO::FETCH_ASSOC);
 if(!$schedule)throw new RuntimeException('Task has no approved deadline to extend.');
 $expected=(string)$schedule['due_at'];
 if($proposed<=$expected||strtotime($proposed)>strtotime($expected)+90*86400||
    strtotime($proposed)<=time())
   throw new InvalidArgumentException('New deadline must be later than current, future and within 90 days.');
 if($db->inTransaction())throw new RuntimeException('Nested deadline request forbidden.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT status,assigned_to FROM story_production_tasks WHERE id=?");
  $q->execute([$task]);$latest=$q->fetch(PDO::FETCH_ASSOC);
  if(!$latest||(int)$latest['assigned_to']!==$member||
     !in_array($latest['status'],['assigned','in_progress'],true))
    throw new RuntimeException('Assignment changed; refresh.');
  $q=$db->prepare('SELECT due_at FROM story_production_task_schedules WHERE task_id=?');
  $q->execute([$task]);if($q->fetchColumn()!==$expected)
    throw new RuntimeException('Deadline changed before request.');
  $q=$db->prepare("SELECT id,status FROM story_production_deadline_requests WHERE task_id=?
    AND requested_by=? AND expected_due_at=? AND proposed_due_at=?");
  $q->execute([$task,$member,$expected,$proposed]);$existing=$q->fetch(PDO::FETCH_ASSOC);
  if($existing){
   if($existing['status']!=='pending_review')
    throw new RuntimeException('This exact extension already has a review decision.');
   $db->commit();return ['id'=>(int)$existing['id'],'status'=>'pending_review','existing'=>true];
  }
  $q=$db->prepare("SELECT id FROM story_production_deadline_requests
   WHERE task_id=? AND status='pending_review'");
  $q->execute([$task]);
  if($q->fetchColumn()!==false)throw new RuntimeException('Review the existing extension before proposing another.');
  $q=$db->prepare("INSERT INTO story_production_deadline_requests
   (task_id,requested_by,assigned_to,expected_due_at,proposed_due_at,priority,status,reason,requested_at,review_basis)
   VALUES(?,?,?,?,?,?,'pending_review',?,?,'')");
  $q->execute([$task,$member,$member,$expected,$proposed,(string)$schedule['priority'],$reason,gmdate('c')]);
  $id=(int)$db->lastInsertId();
  sf_production_task_event($task,$member,null,'deadline_requested',$item['status'],$item['status'],
   'Crew requested deadline extension to '.$proposed.'. '.$reason);
  sf_team_audit($member,'production.deadline_requested','story_production_task',(string)$task,
   'Crew deadline extension #'.$id.' sent for separate human editor review');
  $db->commit();return ['id'=>$id,'status'=>'pending_review','existing'=>false];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Separate Admin approval atomically changes date and consumes the precise request. */
function sf_production_deadline_review(int $editor,int $request,string $decision,string $basis):array{
 sf_production_task_editor($editor);sf_production_deadline_schema();
 if(!in_array($decision,['approved','rejected'],true))
  throw new InvalidArgumentException('Unsupported review decision.');
 $basis=sf_capture_clean($basis,900);
 if(strlen($basis)<25)throw new InvalidArgumentException('Human review requires 25+ characters.');
 $db=sf_db();
 $q=$db->prepare('SELECT * FROM story_production_deadline_requests WHERE id=?');
 $q->execute([$request]);$p=$q->fetch(PDO::FETCH_ASSOC);
 if(!$p||$p['status']!=='pending_review')throw new RuntimeException('Request not pending review.');
 if((int)$p['requested_by']===$editor)
  throw new RuntimeException('Extension requester cannot independently approve their own request.');
 $task=(int)$p['task_id'];$item=sf_production_task_read($editor,$task);
 if($db->inTransaction())throw new RuntimeException('Nested extension review forbidden.');
 $db->beginTransaction();
 try{
  if($decision==='approved'){
   $q=$db->prepare('SELECT status,assigned_to FROM story_production_tasks WHERE id=?');
   $q->execute([$task]);$current=$q->fetch(PDO::FETCH_ASSOC);
   if(strtotime((string)$p['proposed_due_at'])<=time())
     throw new RuntimeException('Requested deadline is no longer in the future.');
   if(!$current||!in_array($current['status'],['assigned','in_progress'],true)||
      (int)$current['assigned_to']!==(int)$p['assigned_to']||
      $p['proposed_due_at']<=$p['expected_due_at'])
     throw new RuntimeException('Assignment, task status or proposed deadline became stale.');
   $q=$db->prepare('UPDATE story_production_task_schedules SET due_at=?,updated_by=?,updated_at=?
     WHERE task_id=? AND due_at=? AND priority=?');
   $q->execute([$p['proposed_due_at'],$editor,gmdate('c'),$task,
     $p['expected_due_at'],$p['priority']]);
   if($q->rowCount()!==1)throw new RuntimeException('Deadline or priority changed; approval is stale.');
  }
  $q=$db->prepare('UPDATE story_production_deadline_requests SET
    status=?,reviewed_by=?,reviewed_at=?,review_basis=?,applied_at=?
    WHERE id=? AND status=?');
  $q->execute([$decision,$editor,gmdate('c'),$basis,
    $decision==='approved'?gmdate('c'):null,$request,'pending_review']);
  if($q->rowCount()!==1)throw new RuntimeException('Request was already decided.');
  sf_production_task_event($task,$editor,(int)$p['requested_by'],
    $decision==='approved'?'deadline_approved':'deadline_rejected',
    $item['status'],$item['status'],
    'Deadline extension #'.$request.' '.$decision.'. '.$basis);
  sf_team_audit($editor,'production.deadline_reviewed','story_production_task',(string)$task,
    'Independent human '. $decision.' extension #'.$request.'; no task assignment or media changed');
  $db->commit();
  return ['id'=>$request,'status'=>$decision,'task_id'=>$task,
    'current_due_at'=>$decision==='approved'?$p['proposed_due_at']:$p['expected_due_at'],
    'publication_allowed'=>false,'auto_reassigned'=>false];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_production_calendar_chat(int $editor,string $now=''):array{
 sf_production_task_editor($editor);
 $board=sf_production_schedule_board($editor,$now);
 $requests=sf_production_deadline_requests($editor);
 $pending=array_values(array_filter($requests,static fn($p)=>$p['status']==='pending_review'));
 $tasks=$board['tasks'];
 usort($tasks,static function(array $a,array $b):int{
  $aKey=$a['due_at']??'9999-12-31T23:59:59Z';
  $bKey=$b['due_at']??'9999-12-31T23:59:59Z';
  return strcmp($aKey,$bKey)?:($a['task_id']<=>$b['task_id']);
 });
 $tasks=array_slice($tasks,0,25);
 return ['as_of'=>$board['as_of'],'counts'=>$board['counts'],
   'tasks'=>$tasks,'pending_requests'=>array_map(static fn($p)=>[
      'id'=>(int)$p['id'],'task_id'=>(int)$p['task_id'],
      'title'=>(string)$p['title'],'status'=>(string)$p['status'],
      'requested_by'=>(int)$p['requested_by'],'expected_due_at'=>(string)$p['expected_due_at'],
      'proposed_due_at'=>(string)$p['proposed_due_at'],'reason'=>(string)$p['reason']
   ],array_slice($pending,0,15)),
   'human_approval_required'=>true,'auto_reschedule'=>false,
   'external_provider_data'=>false,'automatic_notifications_sent'=>false];
}
