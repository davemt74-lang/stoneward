<?php
declare(strict_types=1);
/** G4M real G4J–G4L fixture + source-evidence/concurrent date review. */
require __DIR__.'/section88d3g4l-scheduling.php';
require_once dirname(__DIR__).'/api/story-production-deadline-core.php';
$g4m=0;
function passM(bool $v,string $why):void{
 global $g4m;if(!$v)throw new RuntimeException('G4M FAIL '.$why);
 $g4m++;echo "PASS G4M $why\n";
}
function rejectM(callable $fn,string $why):void{
 $rejected=false;
 try{$fn();}catch(InvalidArgumentException|RuntimeException $e){$rejected=true;}
 passM($rejected,$why);
}
$startDue='2031-01-12T12:00:00Z';$extended='2031-01-15T12:00:00Z';
$original=(int)sf_production_task_read(1,$work)['assigned_to'];
passM($original===4,'Verified existing G4L band task remains assigned to crew #4.');
rejectM(fn()=>sf_production_deadline_request(2,$work,$extended,
 'An unrelated contributor must not create deadline extensions.'),
 'Another contributor cannot request an extension.');
rejectM(fn()=>sf_production_deadline_request(4,$work,$startDue,
 'Crew attempted to extend date without changing the deadline.'),
 'Extension must strictly increase the existing deadline.');
rejectM(fn()=>sf_production_deadline_request(4,$work,'2032-12-01T12:00:00Z',
 'Crew requested a distant extension without scheduling justification.'),
 'Unreasonably far due dates are rejected.');
$auditFail=true;
rejectM(fn()=>sf_production_deadline_request(4,$work,$extended,
 'Crew must complete a new recording session before the review.'),
 'Failed request audit atomically blocks the extension.');
$auditFail=false;
passM(sf_production_deadline_requests(1)===[],'Failed request does not leave a review record.');
$req=sf_production_deadline_request(4,$work,$extended,
 'The assigned member needs to record the live session after rehearsal.');
passM($req['status']==='pending_review'&&!$req['existing'],
 'Contributor creates pending approval, not a modified schedule.');
$dup=sf_production_deadline_request(4,$work,$extended,
 'The original request is awaiting a human scheduling decision.');
passM($dup['existing']&&$dup['id']===$req['id'],
 'Exact duplicate returns original approval request.');
$board=sf_production_schedule_board(4,'2031-01-11T12:00:00Z');
$task=array_values(array_filter($board['tasks'],fn($t)=>$t['task_id']===$work))[0];
passM($task['due_at']===$startDue,'Pending extension does not alter the real due date.');
rejectM(fn()=>sf_production_deadline_review(4,$req['id'],'approved',
 'Contributor tried to approve their own request.'),
 'Contributor cannot consume human approval.');
rejectM(fn()=>sf_production_deadline_review(1,$req['id'],'approved','short'),
 'Editor approval requires documented independent review basis.');
$auditFail=true;
rejectM(fn()=>sf_production_deadline_review(1,$req['id'],'approved',
 'Verified role eligibility, documented scheduling need and revised timeline.'),
 'Failed approval audit rolls back the date and decision together.');
$auditFail=false;
passM(sf_production_deadline_requests(1)[0]['status']==='pending_review',
 'Failed approval leaves request pending.');
passM(array_values(array_filter(sf_production_schedule_board(1,'2031-01-11T12:00:00Z')['tasks'],
 fn($t)=>$t['task_id']===$work))[0]['due_at']===$startDue,
 'Failed approval does not produce a phantom new due date.');
$result=sf_production_deadline_review(1,$req['id'],'approved',
 'Verified contributor scheduling conflict and accepted revised shooting window.');
passM($result['status']==='approved'&&$result['current_due_at']===$extended,
 'Explicit Admin approval applies exact future deadline.');
passM(sf_production_task_timeline(1,$work)[0]['event_type']==='deadline_approved',
 'Approved deadline includes evidence-linked event history.');
rejectM(fn()=>sf_production_deadline_review(1,$req['id'],'approved',
 'Replaying same approval must never change date again.'),
 'Approved request cannot be consumed twice.');
$again=sf_production_deadline_request(4,$work,'2031-01-20T12:00:00Z',
 'The updated concert itinerary needs another crew review session.');
passM(count(array_filter(sf_production_deadline_requests(1),fn($p)=>$p['status']==='pending_review'))===1,
 'Pending request visible to editor and not automatically approved.');
$denied=sf_production_deadline_review(1,$again['id'],'rejected',
 'The latest production schedule provides sufficient time for the current task.');
passM($denied['status']==='rejected'&&$denied['current_due_at']===$extended,
 'Rejection preserves previously approved schedule.');
$stale=sf_production_deadline_request(4,$work,'2031-01-21T12:00:00Z',
 'A third change is proposed before the editor updates the existing deadline.');
sf_production_schedule_set(1,$work,'2031-01-17T12:00:00Z','critical',
 'The editor updated the due date before reviewing the crew request.');
rejectM(fn()=>sf_production_deadline_review(1,$stale['id'],'approved',
 'Old snapshot must not override independently updated human schedule.'),
 'Optimistic date and priority matching blocks stale approvals.');
passM(sf_production_deadline_requests(1)[0]['status']==='pending_review',
 'Stale review does not silently consume the open request.');
sf_production_deadline_review(1,$stale['id'],'rejected',
 'Prior crew proposal is stale after separately documented editor changes.');
$roleSnapshot=$roles[4];$roles[4]=[];
passM(sf_production_deadline_requests(4)===[],
 'Role revocation hides a contributor’s previous deadline requests.');
rejectM(fn()=>sf_production_deadline_request(4,$work,'2031-01-22T12:00:00Z',
 'Revoked contributor cannot ask for another date.'),
 'Revoked role cannot create extension requests.');
$roles[4]=$roleSnapshot;
rejectM(fn()=>sf_production_calendar_chat(2),'Non-editor cannot inspect private global calendar.');
$chat=sf_production_calendar_chat(1,'2031-01-14T12:00:00Z');
passM($chat['human_approval_required']&&!$chat['auto_reschedule']&&
 !$chat['automatic_notifications_sent']&&$chat['external_provider_data']===false,
 'Chat cards are local evidence and never autonomous/external data.');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$canvas=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$crewApi=(string)file_get_contents(dirname(__DIR__).'/api/story-production-tasks.php');
$adminApi=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-production-tasks.php');
passM(str_contains($agent,"'production_schedule_calendar'")&&
 str_contains($agent,'sf_production_calendar_chat(')&&
 str_contains($canvas,'data-agent-deadline-review')&&
 str_contains($canvas,'data-agent-task-schedule')&&
 str_contains($crewApi,"'request_deadline'")&&str_contains($adminApi,"'review_deadline'"),
 'Agent Chat handles schedule cards, independent review and contributor request without extra dashboards.');
echo "88D3G4M: $g4m private deadline/chat/approval assertions passed\n";
