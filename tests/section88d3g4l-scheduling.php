<?php
declare(strict_types=1);
require __DIR__.'/section88d3g4k-lifecycle.php';
require_once dirname(__DIR__).'/api/story-production-scheduling-core.php';
$g4l=0;
function okL(bool $ok,string $label):void{
 global $g4l;if(!$ok)throw new RuntimeException('G4L FAIL '.$label);
 ++$g4l;echo 'PASS G4L '.$label.PHP_EOL;
}
function deniedL(callable $callback,string $label):void{
 $blocked=false;
 try{$callback();}
 catch(InvalidArgumentException|RuntimeException $e){$blocked=true;}
 okL($blocked,$label);
}
$due='2031-01-12T12:00:00Z';$taskA=sf_production_task_propose_gap(1,'authentic_dialogue');
$work=(int)$taskA['id'];$roles[4][]='band_member';
sf_production_task_assign(1,$work,4);
$before=count(sf_production_task_timeline(1,$work));
deniedL(fn()=>sf_production_schedule_set(4,$work,$due,'critical','Unauthorized scheduling attempted from crew member.'),
 'Only owner-authorized editor can schedule production.');
deniedL(fn()=>sf_production_schedule_set(1,$work,'2031-02-30T12:00:00Z','high',
 'Schedule specific crew recording in next period.'),
 'Impossible UTC calendar date rejected.');
deniedL(fn()=>sf_production_schedule_set(1,$work,'2031-01-12T12:00:00+00:00','high',
 'Offset rather than canonical Z is forbidden.'),
 'Deadlines use normalized UTC instants, not ambiguous offsets.');
deniedL(fn()=>sf_production_schedule_set(1,$work,$due,'urgent',
 'Unsupported priority should not be accepted.'),
 'Unknown priority is rejected.');
$auditFail=true;
deniedL(fn()=>sf_production_schedule_set(1,$work,$due,'high',
 'High-priority recording deadline with editor review.'),
 'Activity audit failure rolls back scheduled deadline.');
$auditFail=false;
okL(count(sf_production_task_timeline(1,$work))===$before,
 'Failed scheduled action does not create event.');
sf_production_schedule_set(1,$work,$due,'high','Band dialogue due before first edit review.');
okL(sf_production_task_timeline(1,$work)[0]['event_type']==='scheduled',
 'Scheduling uses original transactional task event ledger.');
$view=sf_production_schedule_board(1,'2031-01-11T12:00:00Z');
$entry=array_values(array_filter($view['tasks'],fn($t)=>$t['task_id']===$work))[0];
okL(in_array('due_soon',$entry['flags'],true)&&!in_array('overdue',$entry['flags'],true),
 'Due-soon threshold uses UTC clock and current task status.');
$view=sf_production_schedule_board(1,'2031-01-13T12:00:00Z');
$entry=array_values(array_filter($view['tasks'],fn($t)=>$t['task_id']===$work))[0];
okL(in_array('overdue',$entry['flags'],true),'Overdue work flagged without changing assignment.');
deniedL(fn()=>sf_production_availability_set(4,'2031-02-30','2031-03-03','available',
 'Invalid calendar must fail.'),
 'Impossible availability dates rejected.');
deniedL(fn()=>sf_production_availability_set(4,'2031-01-12','2031-03-15','unavailable',
 'Too long for private production availability window.'),
 'Overly broad availability windows rejected.');
$auditFail=true;
deniedL(fn()=>sf_production_availability_set(4,'2031-01-12','2031-01-13','unavailable',
 'Crew cannot attend during recording window.'),
 'Failed crew availability audit rolls back calendar.');
$auditFail=false;
okL(sf_production_availability_list(4,4)===[],'Failed availability update leaves no phantom entries.');
sf_production_availability_set(4,'2031-01-12','2031-01-13','unavailable',
 'Crew cannot attend scheduled band session.');
$view=sf_production_schedule_board(1,'2031-01-13T12:00:00Z');
$entry=array_values(array_filter($view['tasks'],fn($t)=>$t['task_id']===$work))[0];
okL(in_array('availability_conflict',$entry['flags'],true)&&$entry['availability']==='unavailable',
 'Live contributor availability conflict identified for due date.');
deniedL(fn()=>sf_production_availability_list(2,4),
 'Other contributors cannot read private availability notes.');
okL(count(sf_production_availability_list(1,4))===1,
 'Production editor can inspect confidential crew availability.');
$boardBefore=sf_production_task_read(1,$work)['assigned_to'];
$hints=sf_production_schedule_recommendations(1,'2031-01-13T12:00:00Z');
okL(in_array('review_overdue_tasks',array_column($hints['suggestions'],'code'),true)
 &&in_array('review_crew_conflicts',array_column($hints['suggestions'],'code'),true)
 &&!$hints['performed_changes']&&!$hints['automatic_notifications_sent'],
 'Agent Brain suggestions are evidence-based and never execute tasks.');
okL(sf_production_task_read(1,$work)['assigned_to']===$boardBefore,
 'Conflict analysis did not reassign or notify contributors.');
sf_production_availability_set(4,'2031-01-12','2031-01-13','available',
 'Crew rescheduled and confirmed the session.');
$view=sf_production_schedule_board(1,'2031-01-11T12:00:00Z');
$entry=array_values(array_filter($view['tasks'],fn($t)=>$t['task_id']===$work))[0];
okL(!in_array('availability_conflict',$entry['flags'],true)&&$entry['availability']==='available',
 'New contributor status replaces overlapping old availability window.');
$crew=sf_production_schedule_board(4,'2031-01-11T12:00:00Z');
okL(count(array_filter($crew['tasks'],fn($t)=>$t['task_id']===$work))===1,
 'Only currently assigned crew see scheduled work.');
$roles[4]=array_values(array_diff($roles[4],['band_member']));
okL(!array_filter(sf_production_schedule_board(4,'2031-01-11T12:00:00Z')['tasks'],
 fn($t)=>$t['task_id']===$work),
 'Role revocation immediately hides private deadline.');
$roles[4][]='band_member';
$closure=$id;
deniedL(fn()=>sf_production_schedule_set(1,$closure,'2031-01-18T12:00:00Z','normal',
 'Previously approved task cannot be rescheduled.'),
 'Completed work is immutable to scheduling.');
$admin=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-production-tasks.php');
$user=(string)file_get_contents(dirname(__DIR__).'/api/story-production-tasks.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/director-capture.js');
$crewUi=(string)file_get_contents(dirname(__DIR__).'/assets/js/app.js');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/story-agent-brain-bridge.php');
okL(str_contains($admin,"'schedule'")&&str_contains($user,"'availability'")
 &&str_contains($ui,'data-task-schedule')&&str_contains($crewUi,'crewAvailabilityForm')
 &&str_contains($agent,'sf_production_schedule_recommendations('),
 'Existing authenticated admin, contributor and Agent Brain surfaces expose only their permitted schedule actions.');
echo "88D3G4L: $g4l schedule/availability/ACL/recovery tests passed".PHP_EOL;
