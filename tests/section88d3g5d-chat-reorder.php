<?php
declare(strict_types=1);
require __DIR__.'/section88d3d-story-assembly.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-core.php';
$g5dChecks=0;
function g5dPass(bool $ok,string $message):void{
 global $g5dChecks;if(!$ok)throw new RuntimeException('G5D FAIL: '.$message);
 $g5dChecks++;echo 'G5D PASS '.$message.PHP_EOL;
}
function g5dReject(callable $call,string $message):void{
 $rejected=false;try{$call();}catch(RuntimeException|InvalidArgumentException $e){$rejected=true;}
 g5dPass($rejected,$message);
}
$initial=sf_assembly_read(1,$id);
g5dPass($initial['revision']==3&&count($initial['items'])===2,
 'Existing production assembly and verified sources are reusable.');
g5dReject(fn()=>sf_assembly_chat_move(2,$id,3,1,'up',
 'Contributor should not be able to reorder this assembly.'),
 'Unprivileged contributor cannot alter the private timeline.');
g5dReject(fn()=>sf_assembly_chat_move(1,$id,3,1,'up','short'),
 'Unreviewed move without human reason rejected.');
g5dReject(fn()=>sf_assembly_chat_move(1,$id,3,0,'up',
 'Moving first clip above the top is not a valid timeline edit.'),
 'Out-of-bounds adjacent move rejected.');
$move=sf_assembly_chat_move(1,$id,3,1,'up',
 'Human editor verified the dialogue belongs before the music entrance.');
$after=sf_assembly_read(1,$id);
g5dPass($move['revision']===4&&$after['revision']==4&&
 $after['items'][0]['kind']==='transcript'&&$after['items'][1]['kind']==='track',
 'Exact adjacent reorder commits one new revision while preserving source identities.');
g5dPass($after['items'][0]['in_ms']===$initial['items'][1]['in_ms'] &&
 $after['items'][1]['out_ms']===$initial['items'][0]['out_ms'],
 'Chat reordering cannot silently change source trims or durations.');
g5dReject(fn()=>sf_assembly_chat_move(1,$id,3,0,'down',
 'Replaying an older snapshot must never reorder the newer revision.'),
 'Stale chat approval is blocked by independent optimistic revision check.');
$failAudit=true;
g5dReject(fn()=>sf_assembly_chat_move(1,$id,4,0,'down',
 'Reordering should roll back if the activity audit cannot be persisted.'),
 'Audit-write failure prevents a phantom committed revision.');
$failAudit=false;
$afterFailure=sf_assembly_read(1,$id);
g5dPass($afterFailure['revision']==4&&$afterFailure['items'][0]['kind']==='transcript',
 'Failed audit rolls back both revision and clip sequence.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
g5dPass(str_contains($api,"'chat_move'")&&str_contains($api,"'confirmed'")&&
 str_contains($api,'sf_assembly_chat_move(')&&str_contains($ui,'data-agent-timeline-move')&&
 str_contains($ui,"send('assembly #'+id+' timeline')"),
 'Authenticated exact-review API and chat timeline actions are wired together.');
g5dPass(str_contains($ui,"closest('.admin-agent-calendar')") &&
 str_contains($ui,"closest('.admin-agent-calendar-task')"),
 'Repeated chat snapshots use review justifications from the exact clicked card.');
echo "G5D: $g5dChecks certified reorder assertions passed".PHP_EOL;
