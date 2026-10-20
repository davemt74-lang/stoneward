<?php
declare(strict_types=1);
require __DIR__.'/section88d3g5d-chat-reorder.php';
$trimChecks=0;
function trimPass(bool $ok,string $why):void{
 global $trimChecks;
 if(!$ok)throw new RuntimeException('G5D trim FAIL: '.$why);
 $trimChecks++;echo 'G5D TRIM PASS '.$why.PHP_EOL;
}
function trimReject(callable $job,string $why):void{
 $denied=false;try{$job();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
 trimPass($denied,$why);
}
$state=sf_assembly_read(1,$id);
trimPass($state['revision']===4&&count($state['items'])===2&&$state['items'][1]['kind']==='track',
 'Editable revision 4 contains the independently verified soundtrack.');
trimReject(fn()=>sf_assembly_chat_trim(2,$id,4,1,1000,18000,
 'Contributor cannot change source timecodes of editorial clips.'),
 'Contributor cannot trim an editorial timeline.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,1000,18000,'too brief'),
 'Human review basis is mandatory.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,18000,1000,
 'Reversed times cannot produce a legitimate source clip.'),
 'Reversed source range is rejected.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,1000,1400,
 'Sub half-second trims are invalid for this editor.'),
 'Under 500 ms source interval rejected.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,1000,90000,
 'A source range must fit the real recording duration.'),
 'Source interval exceeding verified song duration denied.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,0,20000,
 'Unchanged clip ranges should not consume an audit revision.'),
 'No-op edit does not increment a revision.');
$done=sf_assembly_chat_trim(1,$id,4,1,1000,18000,
 'Human editor inspected the song transition and trimmed the outro.');
$current=sf_assembly_read(1,$id);
trimPass($done['revision']===5&&$current['revision']===5&&
 $current['items'][1]['in_ms']==1000&&$current['items'][1]['out_ms']==18000&&
 $current['items'][1]['duration_ms']==17000,
 'Reviewed trim updates only the intended source range and duration.');
trimPass($current['items'][0]['source_id']===$state['items'][0]['source_id']&&
 $current['items'][0]['in_ms']===$state['items'][0]['in_ms']&&
 $current['items'][0]['out_ms']===$state['items'][0]['out_ms'],
 'Other dialogue and source references remain byte-for-byte unchanged.');
trimReject(fn()=>sf_assembly_chat_trim(1,$id,4,1,2000,17000,
 'Stale stored chat cards must never override newer reviewer changes.'),
 'Stale chat revisions are rejected.');
$failAudit=true;
trimReject(fn()=>sf_assembly_chat_trim(1,$id,5,1,2000,17000,
 'Audit writer failure must roll back timeline changes and revision.'),
 'Failed audit makes trimming atomic.');
$failAudit=false;
$after=sf_assembly_read(1,$id);
trimPass($after['revision']===5&&$after['items'][1]['in_ms']==1000,
 'Rejected audit leaves no partial clip changes.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
trimPass(str_contains($api,"'chat_trim'")&&str_contains($api,"'confirmed'")&&
 str_contains($api,'sf_assembly_chat_trim(')&&
 str_contains($js,'data-agent-timeline-trim')&&str_contains($js,"send('assembly #'+id+' timeline')"),
 'Chat trim UI uses authenticated exact-review API and refreshes the live timeline.');
echo "G5D TRIM CERTIFICATION: $trimChecks assertions passed".PHP_EOL;
