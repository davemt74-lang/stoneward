<?php
declare(strict_types=1);
/** End-to-end governed restoration exercising real verified sources and transactions. */
require __DIR__.'/section88d3d-story-assembly.php';
require_once dirname(__DIR__).'/api/story-assembly-history-core.php';
require_once dirname(__DIR__).'/api/story-assembly-restore-core.php';
$tested=0;
function okayH(bool $ok,string $label):void{
 global $tested;
 if(!$ok)throw new RuntimeException('FAIL G4H: '.$label);
 ++$tested;echo 'PASS G4H: '.$label.PHP_EOL;
}
function denyH(callable $task,string $label):void{
 try{$task();throw new LogicException('Unsafe restoration unexpectedly permitted: '.$label);}
 catch(RuntimeException|InvalidArgumentException $e){okayH(true,$label);}
}
sf_assembly_restore_schema();
$old=sf_assembly_read(1,$id);
okayH((int)$old['revision']===3,'Existing versioned private assembly is the test fixture.');
sf_assembly_history_archive(1,$id,1,[]); // Actual empty first revision in fixture.
sf_assembly_history_archive(1,$id,2,[$sceneItem,$transItem,$musicItem,$stillItem]);
sf_assembly_history_archive(1,$id,3,$old['items']);
$next=sf_assembly_replace(1,$id,3,[$sceneItem,$transItem,$musicItem]);
okayH($next['revision']===4,'Current production edit creates version four with verified sources.');
okayH(count(sf_assembly_history_list(1,$id))===4,'History ledger contains only actual and newly saved versions.');
denyH(fn()=>sf_assembly_restore_request(2,$id,4,3,str_repeat('Invalid contributor request.',2)),
 'Contributor cannot queue restoration review.');
denyH(fn()=>sf_assembly_restore_request(1,$id,4,4,str_repeat('Cannot restore present revision.',2)),
 'Current revision cannot be a historical restore target.');
denyH(fn()=>sf_assembly_restore_request(1,$id,4,3,'Too short'),
 'Restore reason requires explicit justification.');
denyH(fn()=>sf_assembly_restore_request(1,$id,4,2,str_repeat('Uncleared scene restoration request.',2)),
 'Revoked source in an old snapshot blocks proposal creation.');
$reason='Return to the independently verified dialogue and music sequence.';
$req=sf_assembly_restore_request(1,$id,4,3,$reason);
okayH($req['status']==='pending_review'&&!$req['existing'],'Restoration request does not modify clips.');
$duplicate=sf_assembly_restore_request(1,$id,4,3,$reason);
okayH($duplicate['existing']&&$duplicate['id']===$req['id'],
 'Duplicate request returns existing proposal without duplicate side effects.');
okayH(sf_assembly_read(1,$id)['revision']==4&&count(sf_assembly_read(1,$id)['items'])===3,
 'Requesting a restoration never changes current editorial material.');
denyH(fn()=>sf_assembly_restore_apply(1,$req['id']),
 'Unapproved restoration cannot be applied.');
denyH(fn()=>sf_assembly_restore_review(2,$req['id'],'approved',str_repeat('Not an editor. ',3)),
 'Contributor cannot authorize production restoration.');
denyH(fn()=>sf_assembly_restore_review(1,$req['id'],'approved','Too short'),
 'Reviewer must document a meaningful basis.');
$basis='Verified original capture rights, source boundaries and historical cut.';
$failAudit=true;
denyH(fn()=>sf_assembly_restore_review(1,$req['id'],'approved',$basis),
 'Failure to write human approval audit rolls back decision.');
$failAudit=false;
okayH(sf_assembly_restore_get(1,$req['id'])['status']==='pending_review',
 'Failed review audit leaves proposal pending.');
okayH(sf_assembly_restore_review(1,$req['id'],'approved',$basis),
 'Human-reviewed decision independently authorizes the request.');
denyH(fn()=>sf_assembly_restore_review(1,$req['id'],'approved',$basis),
 'Human approval is single-consumption.');
$failAudit=true;
denyH(fn()=>sf_assembly_restore_apply(1,$req['id']),
 'Failed apply audit atomically rolls back both revision and approval ledger.');
$failAudit=false;
okayH(sf_assembly_read(1,$id)['revision']==4&&
 sf_assembly_restore_get(1,$req['id'])['status']==='approved',
 'Failed restoration remains approved but unapplied with no phantom revision.');
$res=sf_assembly_restore_apply(1,$req['id']);
okayH($res['revision']==5&&count(sf_assembly_read(1,$id)['items'])===2,
 'Applying approved restore makes a NEW revision with verified archived items.');
okayH(sf_assembly_restore_get(1,$req['id'])['status']==='applied'&&
 (int)sf_assembly_restore_get(1,$req['id'])['applied_revision']===5,
 'Approval ledger is atomically consumed by the exact new revision.');
denyH(fn()=>sf_assembly_restore_apply(1,$req['id']),
 'Consumed restoration cannot replay.');
okayH(sf_assembly_history_compare(1,$id,3,5)['changes']===[],
 'Restored history matches original metadata without mutating archived revision.');
$reject=sf_assembly_restore_request(1,$id,5,4,
 'Review the prior scene cut before deciding whether to restore it.');
okayH(sf_assembly_restore_review(1,$reject['id'],'rejected',
 'This prior story sequence does not match the approved creative brief.'),
 'Editor can reject a restoration proposal.');
denyH(fn()=>sf_assembly_restore_apply(1,$reject['id']),
 'Rejected proposal never restores a timeline.');
$pending=sf_assembly_restore_request(1,$id,5,3,
 'Potential alternative restoration; must not survive source changes.');
$stillVerified=$db->prepare("UPDATE story_transcripts SET review_status='rejected' WHERE id=?");
$stillVerified->execute([$trans]);
denyH(fn()=>sf_assembly_restore_review(1,$pending['id'],'approved',$basis),
 'Revoked evidence blocks approval of old timeline.');
okayH(sf_assembly_restore_review(1,$pending['id'],'rejected',
 'The prior dialogue source was revoked; reject this unsafe change.'),
 'Revoked sources still allow explicit rejection and an auditable decision.');
$stillVerified=$db->prepare("UPDATE story_transcripts SET review_status='verified' WHERE id=?");
$stillVerified->execute([$trans]);
$stale=sf_assembly_restore_request(1,$id,5,1,
 'Another approved source-preserving version should be considered.');
$more=sf_assembly_replace(1,$id,5,[$musicItem,$transItem]);
okayH($more['revision']==6,'Independent editorial edit advances the optimistic revision.');
denyH(fn()=>sf_assembly_restore_review(1,$stale['id'],'approved',$basis),
 'Request becomes stale after editor changes timeline.');
denyH(fn()=>sf_assembly_restore_apply(1,$stale['id']),
 'Unreviewed stale restoration never overwrites new changes.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/story-assembly.js');
okayH(str_contains($api,"sf_admin_require_auth(")&&str_contains($api,"'request_restore'")&&
 str_contains($api,"'review_restore'")&&str_contains($api,"'apply_restore'"),
 'Existing CSRF-protected Admin API enforces distinct request/review/apply actions.');
okayH(str_contains($ui,'data-restore-review')&&str_contains($ui,'data-restore-apply')&&
 str_contains($ui,'restoreRequests=')&&str_contains($ui,'window.confirm('),
 'Studio keeps human approvals, confirmations and applied results visible.');
echo "88D3G4H: $tested restore/ACL/source/atomicity assertions passed".PHP_EOL;
