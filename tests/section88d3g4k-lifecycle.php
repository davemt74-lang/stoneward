<?php
declare(strict_types=1);
/** G4K certification on the original G4J proven rights/role/audit fixture. */
require __DIR__.'/section88d3g4j-production-tasks.php';
require_once dirname(__DIR__).'/api/story-production-task-lifecycle-core.php';
$g4k=0;
function assertK(bool $v,string $message):void{
 global $g4k;if(!$v)throw new RuntimeException('G4K FAIL '.$message);
 ++$g4k;echo 'PASS G4K '.$message.PHP_EOL;
}
function denyK(callable $fn,string $message):void{
 try{$fn();throw new LogicException('Unapproved task action: '.$message);}
 catch(RuntimeException|InvalidArgumentException $e){assertK(true,$message);}
}
$history=sf_production_task_timeline(1,$id);
assertK(count($history)>=4 && $history[0]['event_type']==='approved'
 && in_array('proposed',array_column($history,'event_type'),true)
 && in_array('submitted',array_column($history,'event_type'),true),
 'An evidence-linked immutable task timeline tracks creation through completion.');
assertK(count(sf_production_task_notifications(2))>=2 &&
 sf_production_task_notifications(2)[0]['event_type']==='approved',
 'Active assigned contributor receives private in-app review-status updates.');
denyK(fn()=>sf_production_task_timeline(4,$id),'Unassigned user denied private production history.');
denyK(fn()=>sf_production_task_event($id,1,2,'approved','submitted','approved'),
 'Task history cannot be written outside the active transaction.');
denyK(fn()=>sf_production_task_progress(2,$id,'Attempt to modify completed task.'),
 'Completed task cannot accept progress messages.');
denyK(fn()=>sf_production_task_reopen(1,$id,2,
 'Attempt to reopen previously approved and completed work.'),
 'Approved production work cannot be destructively reopened.');
$task=$second['id'];$roles[4]=['sound_crew'];
sf_production_task_assign(1,$task,4);
$auditFail=true;
denyK(fn()=>sf_production_task_progress(4,$task,
 'Recording the first protected microphone soundcheck.'),
 'Failed progress audit rolls back task state and event together.');
$auditFail=false;
assertK(sf_production_task_read(1,$task)['status']==='assigned'
 && !in_array('progress',array_column(sf_production_task_timeline(1,$task),'event_type'),true),
 'No phantom progress appears after transactional failure.');
sf_production_task_progress(4,$task,'Capturing clear ambient audio around the venue.');
assertK(sf_production_task_read(1,$task)['status']==='in_progress'
 && sf_production_task_timeline(4,$task)[0]['event_type']==='progress',
 'Contributor checkpoint changes state to in_progress and writes a real event.');
denyK(fn()=>sf_production_task_progress(3,$task,
 'Unassigned producer trying to add progress to another role.'),
 'Role-ineligible producer cannot update another team member task.');
$roles[2]=['videographer','sound_crew'];
sf_production_task_assign(1,$task,2);
assertK(count(sf_production_task_list(4))===0 && !sf_production_task_notifications(4),
 'Reassignment revokes prior assignee access to task and activity notifications.');
denyK(fn()=>sf_production_task_progress(4,$task,'Trying to update old assignment.'),
 'Former assignee cannot continue recording progress.');
sf_production_task_assign(1,$task,4);
assertK(sf_production_task_timeline(1,$task)[0]['event_type']==='reassigned',
 'Reassignment is a visible, editor-controlled history event.');
$roles[4]=[];
assertK(sf_production_task_notifications(4)===[],
 'Revoked contributor role immediately removes in-app private task activity.');
$roles[4]=['sound_crew'];
$request=(int)sf_production_task_read(1,$task)['source_id'];
sf_capture_publish_request(1,$request,true);
$evidence=sf_capture_submit(4,['request_id'=>$request,
 'submission_type'=>'ambient','title'=>'Nighttime room ambience',
 'description'=>'Recorded an unedited sound bed for independent review.',
 'rights_attested'=>true],4);
sf_production_task_progress(4,$task,'Captured environment tone and documented placement.');
sf_production_task_submit(4,$task,$evidence,'Linked first ambient audio recording to the shot request.');
sf_production_task_review(1,$task,'rejected',
 'Recording contains unwanted speech. Please provide a cleaner sound bed.');
assertK(sf_production_task_read(1,$task)['status']==='rejected'
 && sf_production_task_timeline(1,$task)[0]['evidence_id']==$evidence,
 'Rejected completion retains original contributor evidence identifier for audit.');
denyK(fn()=>sf_production_task_reopen(2,$task,2,
 'Contributor attempted to bypass human recovery authorization.'),
 'Crew cannot reopen rejected production work.');
denyK(fn()=>sf_production_task_reopen(1,$task,4,'too short'),
 'Recovery requires a documented human corrective basis.');
$auditFail=true;
denyK(fn()=>sf_production_task_reopen(1,$task,4,
 'Request a clean ambience capture without identifiable voices.'),
 'Failure to record recovery activity reverses the reopened task update.');
$auditFail=false;
assertK(sf_production_task_read(1,$task)['status']==='rejected',
 'Audit failure cannot silently clear rejection.');
sf_production_task_reopen(1,$task,4,
 'Request a clean ambience capture without identifiable voices.');
$reopened=sf_production_task_read(1,$task);
assertK($reopened['status']==='assigned' && $reopened['evidence_id']===null
 && $reopened['reviewed_by']===null,
 'Reopen resets active evidence and reviewer while preserving old immutable events.');
denyK(fn()=>sf_production_task_reopen(1,$task,4,
 'Try to consume the same rejection recovery a second time.'),
 'Reopen is single-consumption for one rejected state.');
assertK(sf_production_task_timeline(1,$task)[0]['event_type']==='reopened'
 && in_array('rejected',array_column(sf_production_task_timeline(1,$task),'event_type'),true),
 'Rejection and recovery both survive as auditable events.');
$replacement=sf_capture_submit(4,['request_id'=>$request,
 'submission_type'=>'ambient','title'=>'Clean second ambient recording',
 'description'=>'Second clean room tone submitted for approval.',
 'rights_attested'=>true],4);
sf_production_task_submit(4,$task,$replacement,
 'Clean replacement environmental sound now submitted for independent verification.');
denyK(fn()=>sf_production_task_review(1,$task,'approved',
 'Approved based on prior evidence alone without independently reviewing new upload.'),
 'Prior rejection approval never transfers to a new capture.');
sf_capture_review(1,$replacement,'approved_for_edit');
sf_production_task_review(1,$task,'approved',
 'Independently checked replacement sound and its editing clearance.');
assertK(sf_production_task_read(1,$task)['status']==='approved'
 && sf_production_task_timeline(1,$task)[0]['evidence_id']==$replacement,
 'Corrected replacement evidence receives a new explicit human completion decision.');
$notifications=sf_production_task_notifications(4);
assertK($notifications&&$notifications[0]['event_type']==='approved'
 && $notifications[0]['status_to']==='approved',
 'Assigned contributor sees approval in their private in-app activity stream.');
$admin=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-production-tasks.php');
$user=(string)file_get_contents(dirname(__DIR__).'/api/story-production-tasks.php');
$studio=(string)file_get_contents(dirname(__DIR__).'/admin/assets/director-capture.js');
$capture=(string)file_get_contents(dirname(__DIR__).'/assets/js/app.js');
assertK(str_contains($admin,"'reopen'")&&str_contains($user,"'progress'")
 &&str_contains($studio,'data-task-reopen')&&str_contains($capture,'data-assigned-progress'),
 'Admin recovery controls and authenticated crew progress UI are wired to existing screens.');
echo "88D3G4K: $g4k lifecycle/evidence/access assertions passed".PHP_EOL;
