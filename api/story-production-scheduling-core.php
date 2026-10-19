<?php
declare(strict_types=1);
/**
 * G4L: private production deadlines and self-reported crew availability.
 * Explicitly advisory: never alters assignees, approvals, media or jobs.
 */
function sf_production_schedule_schema():void{
 static $ready=false;if($ready)return;
 sf_production_task_events_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_task_schedules(
   task_id INTEGER PRIMARY KEY,due_at TEXT NOT NULL,priority TEXT NOT NULL,
   updated_by INTEGER NOT NULL,updated_at TEXT NOT NULL,
   FOREIGN KEY(task_id) REFERENCES story_production_tasks(id) ON DELETE CASCADE,
   FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE RESTRICT)");
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_crew_availability(
   id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,
   start_date TEXT NOT NULL,end_date TEXT NOT NULL,status TEXT NOT NULL,
   note TEXT NOT NULL,updated_at TEXT NOT NULL,
   FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_task_schedules(
   task_id BIGINT UNSIGNED PRIMARY KEY,due_at VARCHAR(24) NOT NULL,
   priority VARCHAR(12) NOT NULL,updated_by BIGINT UNSIGNED NOT NULL,
   updated_at VARCHAR(40) NOT NULL,
   CONSTRAINT fk_task_schedule_task FOREIGN KEY(task_id)
    REFERENCES story_production_tasks(id) ON DELETE CASCADE,
   CONSTRAINT fk_task_schedule_editor FOREIGN KEY(updated_by)
    REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_crew_availability(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,
   start_date CHAR(10) NOT NULL,end_date CHAR(10) NOT NULL,
   status VARCHAR(16) NOT NULL,note VARCHAR(500) NOT NULL,
   updated_at VARCHAR(40) NOT NULL,
   INDEX idx_crew_availability_window(user_id,start_date,end_date),
   CONSTRAINT fk_crew_availability_user FOREIGN KEY(user_id)
    REFERENCES users(id) ON DELETE CASCADE)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_production_schedule_date(string $day):string{
 if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day))throw new InvalidArgumentException('Use a calendar date YYYY-MM-DD.');
 $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('UTC'));
 if(!$dt||$dt->format('Y-m-d')!==$day)throw new InvalidArgumentException('Calendar date is invalid.');
 return $day;
}
function sf_production_schedule_instant(string $iso):string{
 if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$iso))
  throw new InvalidArgumentException('Deadline must be UTC ISO-8601 including Z.');
 try{$d=new DateTimeImmutable($iso,new DateTimeZone('UTC'));}
 catch(Throwable $e){throw new InvalidArgumentException('Invalid deadline.');}
 if($d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')!==$iso)
  throw new InvalidArgumentException('Deadline is not a valid UTC instant.');
 return $iso;
}
/** Human-set priority and date: no silent changes by Agent Brain. */
function sf_production_schedule_set(int $editor,int $task,string $dueAt,string $priority,string $reason):void{
 sf_production_task_editor($editor);sf_production_schedule_schema();
 $item=sf_production_task_read($editor,$task);
 if(in_array($item['status'],['approved','rejected'],true))
  throw new RuntimeException('Completed or rejected work cannot be rescheduled.');
 $dueAt=sf_production_schedule_instant($dueAt);
 if(!in_array($priority,['normal','high','critical'],true))
  throw new InvalidArgumentException('Choose a supported scheduling priority.');
 $reason=sf_capture_clean($reason,750);
 if(strlen($reason)<20)throw new InvalidArgumentException('Explain this deadline in 20+ characters.');
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested schedule updates are unsupported.');
 $db->beginTransaction();
 try{
  $check=$db->prepare('SELECT status FROM story_production_tasks WHERE id=?');
  $check->execute([$task]);
  if($check->fetchColumn()!==$item['status'])
   throw new RuntimeException('Task status changed during scheduling.');
  $q=$db->prepare('SELECT task_id FROM story_production_task_schedules WHERE task_id=?');
  $q->execute([$task]);$existing=$q->fetchColumn();
  if($existing!==false){
   $q=$db->prepare('UPDATE story_production_task_schedules
    SET due_at=?,priority=?,updated_by=?,updated_at=? WHERE task_id=?');
   $q->execute([$dueAt,$priority,$editor,gmdate('c'),$task]);
  }else{
   $q=$db->prepare('INSERT INTO story_production_task_schedules
    (task_id,due_at,priority,updated_by,updated_at) VALUES(?,?,?,?,?)');
   $q->execute([$task,$dueAt,$priority,$editor,gmdate('c')]);
  }
  sf_production_task_event($task,$editor,(int)($item['assigned_to']??0)?:null,
    'scheduled',$item['status'],$item['status'],
    'Human scheduled '.$dueAt.' at '.$priority.' priority. '.$reason);
  sf_team_audit($editor,'production.task_scheduled','story_production_task',(string)$task,
    'Human-set private production deadline and priority; no task approval or reassignment');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Crew may declare their own availability. No admin may impersonate a contributor. */
function sf_production_availability_set(int $actor,string $start,string $end,string $status,string $note):int{
 if($actor<1)throw new RuntimeException('Login required.');
 $q=sf_db()->prepare("SELECT status FROM users WHERE id=?");$q->execute([$actor]);
 if($q->fetchColumn()!=='active')throw new RuntimeException('Active contributor required.');
 $start=sf_production_schedule_date($start);$end=sf_production_schedule_date($end);
 if($end<$start||(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days>31)
  throw new InvalidArgumentException('Availability window must span 0–31 days.');
 if(!in_array($status,['available','limited','unavailable'],true))
  throw new InvalidArgumentException('Unknown availability status.');
 $note=sf_capture_clean($note,500);
 if(strlen($note)<10)throw new InvalidArgumentException('Availability note requires 10+ characters.');
 sf_production_schedule_schema();$db=sf_db();
 if($db->inTransaction())throw new RuntimeException('Nested availability changes unsupported.');
 $db->beginTransaction();
 try{
  $q=$db->prepare('DELETE FROM story_production_crew_availability
   WHERE user_id=? AND NOT (end_date<? OR start_date>?)');
  $q->execute([$actor,$start,$end]);
  $q=$db->prepare('INSERT INTO story_production_crew_availability
    (user_id,start_date,end_date,status,note,updated_at) VALUES(?,?,?,?,?,?)');
  $q->execute([$actor,$start,$end,$status,$note,gmdate('c')]);
  $id=(int)$db->lastInsertId();
  sf_team_audit($actor,'production.crew_availability','story_production_crew_availability',(string)$id,
    'Crew availability was voluntarily updated; assignment authority unchanged');
  $db->commit();return $id;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_production_availability_list(int $viewer,int $member):array{
 if($viewer<1||$member<1||($viewer!==$member&&!sf_team_can($viewer,'team.assign_roles')))
  throw new RuntimeException('Availability access denied.');
 sf_production_schedule_schema();
 $q=sf_db()->prepare('SELECT id,start_date,end_date,status,note,updated_at
 FROM story_production_crew_availability WHERE user_id=? ORDER BY start_date DESC LIMIT 25');
 $q->execute([$member]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_production_schedule_board(int $viewer,string $now=''):array{
 if($viewer<1)throw new RuntimeException('Login required.');
 sf_production_schedule_schema();
 $at=sf_production_schedule_instant($now!==''?$now:gmdate('Y-m-d\TH:i:s\Z'));
 $tasks=sf_production_task_list($viewer);
 $out=[];$counts=['overdue'=>0,'due_soon'=>0,'availability_conflict'=>0,'unassigned'=>0];
 $av=sf_db()->prepare('SELECT status FROM story_production_crew_availability
   WHERE user_id=? AND start_date<=? AND end_date>=? ORDER BY id DESC LIMIT 1');
 $sq=sf_db()->prepare('SELECT due_at,priority,updated_at FROM story_production_task_schedules WHERE task_id=?');
 foreach($tasks as $t){
  $sq->execute([(int)$t['id']]);$s=$sq->fetch(PDO::FETCH_ASSOC)?:null;
  $due=$s['due_at']??null;$member=(int)($t['assigned_to']??0);
  $availability='unknown';
  if($member>0&&$due){
   $day=substr($due,0,10);$av->execute([$member,$day,$day]);$v=$av->fetchColumn();
   if($v!==false)$availability=(string)$v;
  }
  $active=!in_array($t['status'],['approved','rejected'],true);
  $delta=$due?(strtotime($due)-strtotime($at)):null;
  $flags=[];
  if($active&&$due&&$delta<0){$flags[]='overdue';$counts['overdue']++;}
  elseif($active&&$due&&$delta<=86400){$flags[]='due_soon';$counts['due_soon']++;}
  if($active&&$member===0){$flags[]='unassigned';$counts['unassigned']++;}
  if($active&&$member>0&&in_array($availability,['limited','unavailable'],true)){
   $flags[]='availability_conflict';$counts['availability_conflict']++;
  }
  $out[]=['task_id'=>(int)$t['id'],'title'=>(string)$t['title'],
   'status'=>(string)$t['status'],'target_role'=>(string)$t['target_role'],
   'assigned_to'=>$member,'due_at'=>$due,'priority'=>$s['priority']??'normal',
   'availability'=>$availability,'flags'=>$flags,
   'requires_human_assignment'=>true,'automatically_escalated'=>false];
 }
 return ['as_of'=>$at,'tasks'=>$out,'counts'=>$counts,
  'human_approval_required'=>true,'auto_reschedule'=>false,
  'automatic_notifications_sent'=>false];
}
function sf_production_schedule_recommendations(int $editor,string $now=''):array{
 sf_production_task_editor($editor);
 $board=sf_production_schedule_board($editor,$now);$suggestions=[];
 foreach(['overdue'=>'review_overdue_tasks','availability_conflict'=>'review_crew_conflicts',
  'unassigned'=>'assign_role_eligible_crew','due_soon'=>'check_upcoming_deadlines'] as $flag=>$action){
  if($board['counts'][$flag]>0)$suggestions[]=['code'=>$action,
    'affected_tasks'=>$board['counts'][$flag],'requires_human'=>true];
 }
 return ['as_of'=>$board['as_of'],'counts'=>$board['counts'],
  'suggestions'=>$suggestions,'performed_changes'=>false,
  'automatic_notifications_sent'=>false];
}
