<?php
declare(strict_types=1);
/** G5D-1 integration: actual SQL fixtures, protected media sources and revisions. */
require __DIR__.'/section88d3d-story-assembly.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-insert-core.php';
$g5insert=0;
function insertPass(bool $yes,string $label):void{
 global $g5insert;
 if(!$yes)throw new RuntimeException('G5D-1 FAIL: '.$label);
 $g5insert++;echo 'G5D-1 PASS '.$label.PHP_EOL;
}
function insertReject(callable $fn,string $label):void{
 $denied=false;
 try{$fn();}catch(InvalidArgumentException|RuntimeException $e){$denied=true;}
 insertPass($denied,$label);
}
$parsed=sf_assembly_chat_insert_intent('add verified backstage guitar shot after opening scene');
insertPass($parsed['topic']==='backstage guitar'&&$parsed['assembly_id']===null&&
 $parsed['slot']===1&&$parsed['placement']==='after_opening',
 'Natural footage request preserves topic and opening-scene placement without guessing an assembly.');
insertPass(sf_assembly_chat_insert_intent('insert verified drums clip to assembly #'.$id.
 ' after clip #2')['slot']===2,
 'Explicit assembly and numbered insertion position are parsed correctly.');
$selection=sf_assembly_chat_insert_preview(1,null,$parsed['topic'],$parsed['slot'],$parsed['placement']);
insertPass($selection['requires_selection']&&count($selection['assemblies'])===1&&
 $selection['assemblies'][0]['id']===$id&&!$selection['executed_changes'],
 'Unknown project requires a selection card rather than silent assembly mutation.');
$preview=sf_assembly_chat_insert_preview(1,$id,'backstage',1,'after_opening');
insertPass(!$preview['requires_selection']&&$preview['assembly']['revision']===3&&
 $preview['slot']===null&&$preview['position_warning']!==''&&
 count($preview['matches'])===1&&$preview['matches'][0]['source_id']===(string)$scene,
 'Missing opening scene prompts a position choice; verified metadata still provides a matching source.');
insertPass($preview['matches'][0]['publication_clearance']==='cleared'&&
 !$preview['executed_changes']&&$preview['private_only'],
 'Read-only candidate preview labels publication clearance and cannot authorize release.');
insertReject(fn()=>sf_assembly_chat_insert_preview(2,$id,'backstage',1),
 'Contributor denied private search and editorial assembly selection.');
insertReject(fn()=>sf_assembly_chat_insert(2,$id,3,1,(string)$scene,
 'An unauthorized contributor must not alter this production.'),'Contributor cannot insert.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,10,(string)$scene,
 'An out-of-bounds timeline slot cannot be saved.'),'Out-of-range position rejected.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,1,'0',
 'User-provided source identifiers must refer to verified scenes.'),
 'Nonpositive source ID rejected.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,1,(string)$still,
 'Rejected scene verification cannot enter the assembly.'),
 'Revoked editorial scene cannot be inserted.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,1,(string)$scene,'Short basis'),
 'Undocumented editing changes cannot bypass human approval.');
$before=sf_assembly_read(1,$id);
$failAudit=true;
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,1,(string)$scene,
 'The source was verified and its placement improves the narrative continuity.'),
 'Failed audit prevents a phantom inserted scene.');
$failAudit=false;
insertPass(sf_assembly_read(1,$id)['revision']===3&&
 count(sf_assembly_read(1,$id)['items'])===2,
 'Audit rejection atomically restores original revision and timeline.');
$result=sf_assembly_chat_insert(1,$id,3,1,(string)$scene,
 'The verified backstage shot provides context between opening song and dialogue.');
$updated=sf_assembly_read(1,$id);
insertPass($result['revision']===4&&$result['inserted_index']===1&&
 !$result['publication_approved']&&$updated['revision']==4&&
 count($updated['items'])===3&&$updated['items'][1]['source_id']===(string)$scene,
 'Confirmed insert adds exactly one approved source at the requested private position.');
insertPass($updated['items'][0]['source_id']===$before['items'][0]['source_id']&&
 $updated['items'][0]['in_ms']===$before['items'][0]['in_ms']&&
 $updated['items'][2]['source_id']===$before['items'][1]['source_id']&&
 $updated['items'][2]['out_ms']===$before['items'][1]['out_ms'],
 'Existing clips retain identity, trims and relative order.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,4,2,(string)$scene,
 'The same reviewed source should not be automatically repeated.'),
 'Duplicate source rejected even in the newest revision.');
insertReject(fn()=>sf_assembly_chat_insert(1,$id,3,0,(string)$scene,
 'Stale chat approvals cannot override later editorial changes.'),
 'Replay with older revision denied.');
$raw=sf_capture_submit(2,['title'=>'Unreleased rehearsal video','submission_type'=>'video',
 'description'=>'New private stage scene','rights_attested'=>true,'people_release_status'=>'unconfirmed'],4);
sf_capture_review(1,$raw,'approved_for_edit');
$unreleased=sf_scene_create(1,$raw,['origin'=>'human','start_ms'=>1000,'end_ms'=>3300,
 'description'=>'Verified rehearsal stage close-up','tags'=>['rehearsal']]);
sf_scene_review(1,$unreleased,'verified');
$rights=sf_assembly_chat_insert_preview(1,$id,'rehearsal',null,'choose_position');
insertPass(count($rights['matches'])===1&&
 $rights['matches'][0]['publication_clearance']==='unreviewed',
 'Missing distribution rights are shown explicitly on the proposed private source.');
$private=sf_assembly_chat_insert(1,$id,4,3,(string)$unreleased,
 'This clip is for private editorial assembly only; publication is not permitted.');
insertPass($private['private_only']&&!$private['publication_approved']&&
 sf_assembly_read(1,$id)['director']['publication_rights_pending']>=1,
 'Unreleased footage may only join a private editorial draft, never gain publication permission.');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$chat=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
insertPass(str_contains($api,"'chat_insert'")&&str_contains($api,"'confirmed'")&&
 str_contains($api,'sf_assembly_chat_insert(')&&
 str_contains($chat,'data-agent-insert-source')&&
 str_contains($chat,"send('assembly #'+id+' timeline')")&&
 str_contains($agent,'sf_assembly_chat_insert_preview('),
 'Authenticated chat preview, exact-confirmation endpoint and final timeline refresh are connected.');
echo "G5D-1: $g5insert verified integration assertions passed".PHP_EOL;
