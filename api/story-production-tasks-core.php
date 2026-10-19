<?php
declare(strict_types=1);
/** 88D3G4J: explicit editorial production tasks backed by existing capture and review records. */
function sf_production_tasks_schema():void{
 static $ready=false;if($ready)return;
 sf_capture_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_tasks(
   id INTEGER PRIMARY KEY AUTOINCREMENT,task_key TEXT NOT NULL UNIQUE,
   kind TEXT NOT NULL,source_id INTEGER NOT NULL,target_role TEXT NOT NULL,
   title TEXT NOT NULL,brief TEXT NOT NULL,status TEXT NOT NULL,
   assigned_to INTEGER NULL,evidence_id INTEGER NULL,contributor_note TEXT NOT NULL DEFAULT '',
   review_basis TEXT NOT NULL DEFAULT '',created_by INTEGER NOT NULL,
   reviewed_by INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
   FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE RESTRICT,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_production_tasks(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   task_key VARCHAR(120) NOT NULL UNIQUE,kind VARCHAR(20) NOT NULL,
   source_id BIGINT UNSIGNED NOT NULL,target_role VARCHAR(40) NOT NULL,
   title VARCHAR(180) NOT NULL,brief TEXT NOT NULL,
   status VARCHAR(24) NOT NULL,assigned_to BIGINT UNSIGNED NULL,
   evidence_id BIGINT UNSIGNED NULL,contributor_note TEXT NOT NULL,
   review_basis TEXT NOT NULL,created_by BIGINT UNSIGNED NOT NULL,
   reviewed_by BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
   INDEX idx_production_tasks_assignee(assigned_to,status),
   CONSTRAINT fk_production_task_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT,
   CONSTRAINT fk_production_task_assignee FOREIGN KEY(assigned_to) REFERENCES users(id) ON DELETE RESTRICT,
   CONSTRAINT fk_production_task_review FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_production_task_editor(int $editor):void{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Production-editor permission required.');
}
function sf_production_task_read(int $viewer,int $task):array{
 if($viewer<1||$task<1)throw new RuntimeException('Private task not available.');
 sf_production_tasks_schema();
 $q=sf_db()->prepare('SELECT * FROM story_production_tasks WHERE id=?');$q->execute([$task]);
 $row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row||(!sf_team_can($viewer,'team.assign_roles')&&(
   (int)$row['assigned_to']!==$viewer||
   ($row['target_role']!=='all'&&!in_array($row['target_role'],sf_capture_roles($viewer),true)))))
  throw new RuntimeException('Private task not available.');
 return $row;
}
function sf_production_task_list(int $viewer):array{
 if($viewer<1)throw new RuntimeException('Login required.');
 sf_production_tasks_schema();
 $admin=sf_team_can($viewer,'team.assign_roles');
 $q=sf_db()->prepare('SELECT id,task_key,kind,source_id,target_role,title,brief,status,
 assigned_to,evidence_id,contributor_note,review_basis,created_by,reviewed_by,created_at,updated_at
 FROM story_production_tasks '.($admin?'':'WHERE assigned_to=? ').
 'ORDER BY id DESC LIMIT 75');
 $q->execute($admin?[]:[$viewer]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
 if(!$admin)$rows=array_values(array_filter($rows,static fn($x)=>
   $x['target_role']==='all'||in_array($x['target_role'],sf_capture_roles($viewer),true)));
 return $rows;
}
/** Human-triggered conversion of verified Director gap to private task, never publish shot request. */
function sf_production_task_propose_gap(int $editor,string $code):array{
 sf_production_task_editor($editor);
 $catalog=sf_director_catalog();if(!isset($catalog[$code]))
  throw new InvalidArgumentException('Unknown Director gap.');
 $key='gap:'.$code;sf_production_tasks_schema();$db=sf_db();
 $q=$db->prepare('SELECT id FROM story_production_tasks WHERE task_key=?');
 $q->execute([$key]);$prior=$q->fetchColumn();
 if($prior!==false)return ['id'=>(int)$prior,'existing'=>true];
 $coverage=sf_director_coverage($editor);$found=null;
 foreach($coverage['gaps'] as $gap)if($gap['code']===$code)$found=$gap;
 if(!$found)throw new RuntimeException('Director gap has already been filled.');
 $request=(int)($found['draft_request_id']??0);
 if(!$request)$request=sf_director_create_draft($editor,$code);
 $now=gmdate('c');$spec=$catalog[$code];
 $q=$db->prepare("INSERT INTO story_production_tasks(task_key,kind,source_id,target_role,title,brief,
 status,contributor_note,review_basis,created_by,created_at,updated_at)
 VALUES(?,'shot',?,?,?,?, 'proposed','','',?,?,?)");
 $q->execute([$key,$request,$spec['target_role'],$spec['title'],$spec['brief'],$editor,$now,$now]);
 $id=(int)$db->lastInsertId();
 sf_team_audit($editor,'production.task_proposed','story_production_task',(string)$id,
  'Private Director coverage task proposed; shot request remains human-reviewed draft');
 return ['id'=>$id,'existing'=>false,'request_id'=>$request];
}
/** One source AI proposal, one reviewed production task; no AI tool authority. */
function sf_production_task_propose_editorial(int $editor,int $proposal):array{
 sf_production_task_editor($editor);if($proposal<1)throw new InvalidArgumentException('Invalid proposal.');
 sf_composer_schema();sf_production_tasks_schema();$db=sf_db();
 $q=$db->prepare('SELECT id,assembly_id,revision,status FROM story_composer_proposals WHERE id=?');
 $q->execute([$proposal]);$source=$q->fetch(PDO::FETCH_ASSOC);
 if(!$source||$source['status']!=='pending_review')
  throw new RuntimeException('Only actual pending human editorial proposals can be assigned.');
 $key='composer:'.$proposal;$q=$db->prepare('SELECT id FROM story_production_tasks WHERE task_key=?');
 $q->execute([$key]);$prior=$q->fetchColumn();
 if($prior!==false)return ['id'=>(int)$prior,'existing'=>true];
 $now=gmdate('c');
 $q=$db->prepare("INSERT INTO story_production_tasks(task_key,kind,source_id,target_role,title,brief,
 status,contributor_note,review_basis,created_by,created_at,updated_at)
 VALUES(?,'editorial',?,'producer',?,?,'proposed','','',?,?,?)");
 $q->execute([$key,$proposal,'Review AI Director proposal #'.$proposal,
  'Review saved private storyboard proposal and provide an editorial assessment; approval still belongs to an authorized editor.',$editor,$now,$now]);
 $id=(int)$db->lastInsertId();sf_team_audit($editor,'production.task_proposed','story_production_task',(string)$id,
  'Private editorial review task proposed for verified AI Director proposal');
 return ['id'=>$id,'existing'=>false];
}
function sf_production_task_assign(int $editor,int $task,int $assignee):void{
 sf_production_task_editor($editor);$row=sf_production_task_read($editor,$task);
 if($row['status']!=='proposed'&&$row['status']!=='assigned')
  throw new RuntimeException('Task cannot be reassigned after work submission.');
 if($assignee<1||($row['target_role']!=='all'&&
   !in_array($row['target_role'],sf_capture_roles($assignee),true)))
  throw new RuntimeException('Assignee is not an active member of the requested role.');
 $u=sf_db()->prepare('SELECT id,status FROM users WHERE id=?');$u->execute([$assignee]);
 if(($u->fetch(PDO::FETCH_ASSOC)['status']??'')!=='active')
  throw new RuntimeException('Assignee is not active.');
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested assignment unsupported.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("UPDATE story_production_tasks SET assigned_to=?,status='assigned',
   updated_at=? WHERE id=? AND status IN ('proposed','assigned')");
  $q->execute([$assignee,gmdate('c'),$task]);
  if($q->rowCount()<1)throw new RuntimeException('Task changed; reload.');
  sf_team_audit($editor,'production.task_assigned','story_production_task',(string)$task,
   'Production task assigned to active role-eligible contributor');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Submit an existing contributor-owned capture; never silently approve the media. */
function sf_production_task_submit(int $actor,int $task,int $evidence,string $note):void{
 $row=sf_production_task_read($actor,$task);
 if((int)$row['assigned_to']!==$actor||$row['status']!=='assigned')
  throw new RuntimeException('Only assigned contributor can submit.');
 $note=sf_capture_clean($note,750);
 if(strlen($note)<15)throw new InvalidArgumentException('Provide a 15-character production note.');
 if($row['kind']==='shot'){
  if($evidence<1)throw new InvalidArgumentException('Existing capture evidence ID required.');
  $q=sf_db()->prepare('SELECT request_id,contributor_id,review_status FROM story_capture_submissions WHERE id=?');
  $q->execute([$evidence]);$proof=$q->fetch(PDO::FETCH_ASSOC);
  if(!$proof||(int)$proof['contributor_id']!==$actor||
    (int)$proof['request_id']!==(int)$row['source_id'])
   throw new RuntimeException('Evidence must be your own capture for this exact shot request.');
  // Its review can still be pending; task approval requires reviewed evidence.
 }elseif($row['kind']==='editorial'){
  if($evidence!==(int)$row['source_id'])
   throw new RuntimeException('Editorial evidence must reference original proposal.');
 }else throw new RuntimeException('Unknown task type.');
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested submission unsupported.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("UPDATE story_production_tasks SET status='submitted',evidence_id=?,
   contributor_note=?,updated_at=? WHERE id=? AND status='assigned' AND assigned_to=?");
  $q->execute([$evidence,$note,gmdate('c'),$task,$actor]);
  if($q->rowCount()!==1)throw new RuntimeException('Task was modified; reload.');
  sf_team_audit($actor,'production.task_evidence_submitted','story_production_task',(string)$task,
   'Linked contributor evidence submitted for independent human review');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_production_task_review(int $editor,int $task,string $decision,string $basis):void{
 sf_production_task_editor($editor);$row=sf_production_task_read($editor,$task);
 if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Unknown decision.');
 if($row['status']!=='submitted'||$editor===(int)$row['assigned_to'])
  throw new RuntimeException('Independent reviewer required for submitted task.');
 $basis=sf_capture_clean($basis,900);
 if(strlen($basis)<25)throw new InvalidArgumentException('Human review basis must be 25+ characters.');
 if($decision==='approved'){
  if($row['kind']==='shot'){
   $q=sf_db()->prepare('SELECT contributor_id,request_id,review_status FROM story_capture_submissions WHERE id=?');
   $q->execute([(int)$row['evidence_id']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
   if(!$proof||(int)$proof['contributor_id']!==(int)$row['assigned_to']||
     (int)$proof['request_id']!==(int)$row['source_id']||
     $proof['review_status']!=='approved_for_edit')
    throw new RuntimeException('Capture must first pass existing independent editorial review.');
  }elseif($row['kind']==='editorial'){
   $q=sf_db()->prepare('SELECT status FROM story_composer_proposals WHERE id=?');
   $q->execute([(int)$row['source_id']]);
   if(!in_array($q->fetchColumn(),['approved','rejected','applied'],true))
    throw new RuntimeException('AI Director proposal requires an independent editor decision.');
  }else throw new RuntimeException('Unsupported task kind.');
 }
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested task review not allowed.');
 $db->beginTransaction();try{
  $q=$db->prepare("UPDATE story_production_tasks SET status=?,review_basis=?,reviewed_by=?,
 updated_at=? WHERE id=? AND status='submitted'");
  $q->execute([$decision,$basis,$editor,gmdate('c'),$task]);
  if($q->rowCount()!==1)throw new RuntimeException('Task already reviewed.');
  sf_team_audit($editor,'production.task_reviewed','story_production_task',(string)$task,
   'Human-verified production task '.$decision.' (does not imply publication clearance)');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
