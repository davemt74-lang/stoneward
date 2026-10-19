<?php
declare(strict_types=1);
/**
 * 88D3G4H: proposed restorations require a distinct explicit review action.
 * Only certified historical metadata is staged. Never copy media/permissions.
 */
function sf_assembly_restore_schema():void{
 static $ready=false;if($ready)return;
 sf_assembly_history_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_restore_proposals(
   id INTEGER PRIMARY KEY AUTOINCREMENT,assembly_id INTEGER NOT NULL,
   expected_revision INTEGER NOT NULL,target_revision INTEGER NOT NULL,
   target_digest TEXT NOT NULL,status TEXT NOT NULL,reason TEXT NOT NULL,
   requested_by INTEGER NOT NULL,requested_at TEXT NOT NULL,
   reviewed_by INTEGER NULL,reviewed_at TEXT NULL,review_basis TEXT NOT NULL DEFAULT '',
   applied_revision INTEGER NULL,applied_at TEXT NULL,
   UNIQUE(assembly_id,expected_revision,target_revision),
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
   FOREIGN KEY(requested_by) REFERENCES users(id) ON DELETE RESTRICT,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_restore_proposals(
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,assembly_id BIGINT UNSIGNED NOT NULL,
   expected_revision INT NOT NULL,target_revision INT NOT NULL,
   target_digest CHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,reason TEXT NOT NULL,
   requested_by BIGINT UNSIGNED NOT NULL,requested_at VARCHAR(40) NOT NULL,
   reviewed_by BIGINT UNSIGNED NULL,reviewed_at VARCHAR(40) NULL,
   review_basis TEXT NOT NULL,applied_revision INT NULL,applied_at VARCHAR(40) NULL,
   UNIQUE KEY uq_restore_source_target(assembly_id,expected_revision,target_revision),
   CONSTRAINT fk_restore_assembly FOREIGN KEY(assembly_id)
     REFERENCES story_assemblies(id) ON DELETE CASCADE,
   CONSTRAINT fk_restore_requester FOREIGN KEY(requested_by)
     REFERENCES users(id) ON DELETE RESTRICT,
   CONSTRAINT fk_restore_reviewer FOREIGN KEY(reviewed_by)
     REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_assembly_restore_get(int $editor,int $request):array{
 sf_assembly_editor($editor);sf_assembly_restore_schema();
 $q=sf_db()->prepare('SELECT id,assembly_id,expected_revision,target_revision,target_digest,
  status,reason,requested_by,requested_at,reviewed_by,reviewed_at,review_basis,
  applied_revision,applied_at FROM story_assembly_restore_proposals WHERE id=?');
 $q->execute([$request]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('Restoration proposal not found.');
 return $row;
}
function sf_assembly_restore_list(int $editor,int $assembly):array{
 sf_assembly_editor($editor);sf_assembly_restore_schema();
 $q=sf_db()->prepare('SELECT id,assembly_id,expected_revision,target_revision,status,reason,
  requested_by,requested_at,reviewed_by,reviewed_at,review_basis,applied_revision,applied_at
  FROM story_assembly_restore_proposals WHERE assembly_id=? ORDER BY id DESC LIMIT 30');
 $q->execute([$assembly]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
/** Independently revalidate current source editorial permissions without copying raw media. */
function sf_assembly_restore_verify(int $editor,array $p):array{
 $assembly=(int)$p['assembly_id'];$expected=(int)$p['expected_revision'];
 $saved=sf_assembly_read($editor,$assembly);
 if(!$saved||$saved['status']!=='draft'||(int)$saved['revision']!==$expected)
  throw new RuntimeException('This draft was revised after the restoration request.');
 if((int)$p['target_revision']>=$expected)
  throw new RuntimeException('Only earlier archived revisions can be restored.');
 $snapshot=sf_assembly_history_read($editor,$assembly,(int)$p['target_revision']);
 if(!hash_equals((string)$p['target_digest'],(string)$snapshot['digest']))
  throw new RuntimeException('Archived editorial evidence changed.');
 $validated=[];$total=0;
 foreach($snapshot['items'] as $item){
  $clip=sf_assembly_validate_item($item);
  $total+=(int)$clip['duration_ms'];
  if($total>3600000)throw new RuntimeException('Restored timeline exceeds runtime limit.');
  $validated[]=$clip;
 }
 return $validated;
}
function sf_assembly_restore_request(int $editor,int $assembly,int $revision,int $target,string $reason):array{
 sf_assembly_editor($editor);sf_assembly_restore_schema();
 $reason=sf_capture_clean($reason,750);
 if(strlen($reason)<25||$assembly<1||$revision<2||$target<1||$target>=$revision)
  throw new InvalidArgumentException('Restore request needs a prior revision and 25+ character reason.');
 $snapshot=sf_assembly_history_read($editor,$assembly,$target);
 $p=['assembly_id'=>$assembly,'expected_revision'=>$revision,
  'target_revision'=>$target,'target_digest'=>$snapshot['digest']];
 sf_assembly_restore_verify($editor,$p);
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested restoration request.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([$assembly]);
  if((int)$q->fetchColumn()!==$revision)throw new RuntimeException('Assembly changed before request creation.');
  $q=$db->prepare('SELECT id,status FROM story_assembly_restore_proposals
    WHERE assembly_id=? AND expected_revision=? AND target_revision=?');
  $q->execute([$assembly,$revision,$target]);$existing=$q->fetch(PDO::FETCH_ASSOC);
  if($existing){
   if($existing['status']!=='pending_review')
    throw new RuntimeException('A completed restoration decision already exists for these revisions.');
   $db->commit();return ['id'=>(int)$existing['id'],'status'=>'pending_review','existing'=>true];
  }
  $q=$db->prepare("INSERT INTO story_assembly_restore_proposals
   (assembly_id,expected_revision,target_revision,target_digest,status,reason,requested_by,requested_at,review_basis)
   VALUES(?,?,?,?,'pending_review',?,?,?,'')");
  $q->execute([$assembly,$revision,$target,$p['target_digest'],$reason,$editor,gmdate('c')]);
  $id=(int)$db->lastInsertId();
  sf_team_audit($editor,'capture.assembly_restore_requested','story_assembly',(string)$assembly,
    'Private revision '.$target.' restoration requested from revision '.$revision);
  $db->commit();return ['id'=>$id,'status'=>'pending_review','existing'=>false];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_assembly_restore_review(int $editor,int $id,string $decision,string $basis):bool{
 sf_assembly_editor($editor);sf_assembly_restore_schema();
 if(!in_array($decision,['approved','rejected'],true))
  throw new InvalidArgumentException('Unsupported restoration decision.');
 $basis=sf_capture_clean($basis,1000);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document a review basis of at least 25 characters.');
 $p=sf_assembly_restore_get($editor,$id);
 if($p['status']!=='pending_review')throw new RuntimeException('Restoration was already reviewed.');
 // A rejection must remain possible even after media-rights revocation.
 // Approvals, however, re-check every restored source and digest.
 if($decision==='approved')sf_assembly_restore_verify($editor,$p);
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested restoration review.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([(int)$p['assembly_id']]);
  if((int)$q->fetchColumn()!==(int)$p['expected_revision'])
   throw new RuntimeException('Restoration request became stale.');
  $q=$db->prepare("UPDATE story_assembly_restore_proposals SET status=?,reviewed_by=?,
   reviewed_at=?,review_basis=? WHERE id=? AND status='pending_review'");
  $q->execute([$decision,$editor,gmdate('c'),$basis,$id]);
  if($q->rowCount()!==1)throw new RuntimeException('Restoration approval was already consumed.');
  sf_team_audit($editor,'capture.assembly_restore_reviewed','story_assembly',(string)$p['assembly_id'],
   'Private restoration #'.$id.' '.$decision);
  $db->commit();return true;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_assembly_restore_apply(int $editor,int $id):array{
 sf_assembly_editor($editor);sf_assembly_restore_schema();
 $p=sf_assembly_restore_get($editor,$id);
 if($p['status']!=='approved'||(int)($p['reviewed_by']??0)<1)
  throw new RuntimeException('Explicit approved human restoration required.');
 $items=sf_assembly_restore_verify($editor,$p);
 $expected=(int)$p['expected_revision'];
 // sf_assembly_replace alone owns the transaction; the second callback runs
 // inside it. Failure to update approval ledger/audit rolls back the clip edit.
 return sf_assembly_replace($editor,(int)$p['assembly_id'],$expected,$items,
   static function(int $newRevision)use($editor,$id,$p):void{
    $q=sf_db()->prepare("UPDATE story_assembly_restore_proposals SET status='applied',
      applied_revision=?,applied_at=? WHERE id=? AND status='approved' AND
      assembly_id=? AND expected_revision=? AND target_revision=? AND target_digest=?");
    $q->execute([$newRevision,gmdate('c'),$id,(int)$p['assembly_id'],
     (int)$p['expected_revision'],(int)$p['target_revision'],(string)$p['target_digest']]);
    if($q->rowCount()!==1)throw new RuntimeException('Restoration approval changed during execution.');
    sf_team_audit($editor,'capture.assembly_restore_applied','story_assembly',(string)$p['assembly_id'],
      'Approved historical revision '.(int)$p['target_revision'].' restored as new private revision '.$newRevision);
   });
}
