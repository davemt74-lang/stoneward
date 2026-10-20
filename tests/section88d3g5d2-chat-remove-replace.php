<?php
declare(strict_types=1);
/** G5D-2 full SQLite acceptance: editor authority, exact source, rights and atomicity. */
require __DIR__.'/section88d3g5d1-chat-insert.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-edit-core.php';
$g5d2=0;
function editPass(bool $value,string $label):void{
 global $g5d2;
 if(!$value)throw new RuntimeException('G5D-2 FAIL: '.$label);
 $g5d2++;echo 'G5D-2 PASS '.$label.PHP_EOL;
}
function editDeny(callable $action,string $label):void{
 $rejected=false;
 try{$action();}catch(RuntimeException|InvalidArgumentException $e){$rejected=true;}
 editPass($rejected,$label);
}
$removeIntent=sf_assembly_chat_edit_intent('remove clip #3 from assembly #'.$id);
editPass($removeIntent['operation']==='remove'&&$removeIntent['index']===2&&
 $removeIntent['assembly_id']===$id,'Exact natural-language clip removal parses correctly.');
$replaceIntent=sf_assembly_chat_edit_intent('replace clip #2 in assembly #'.$id.' with backstage guitar footage');
editPass($replaceIntent['operation']==='replace'&&$replaceIntent['index']===1&&
 $replaceIntent['topic']==='backstage guitar',
 'Replacement topic and clip position preserve the user request.');
$choose=sf_assembly_chat_edit_preview(1,null,2,'remove');
editPass($choose['requires_selection']&&count($choose['assemblies'])===1&&
 !$choose['executed_changes'],'Ambiguous assembly requires a read-only project selection.');
$before=sf_assembly_read(1,$id);
editPass($before['revision']==5&&count($before['items'])===4&&
 $before['items'][2]['kind']==='transcript',
 'Fixture contains two verified scenes, music and dialogue in revision five.');
$preview=sf_assembly_chat_edit_preview(1,$id,2,'remove');
editPass($preview['assembly']['revision']===5&&$preview['items'][2]['source_id']===(string)$trans&&
 !$preview['executed_changes'],'Read-only removal previews the exact old source and revision.');
editDeny(fn()=>sf_assembly_chat_edit_preview(2,$id,2,'remove'),
 'Contributor cannot view editor-only timeline mutation proposals.');
editDeny(fn()=>sf_assembly_chat_remove(2,$id,5,2,(string)$trans,
 'Contributors must never remove an editor-reviewed clip.'),
 'Contributor cannot remove any clip.');
editDeny(fn()=>sf_assembly_chat_remove(1,$id,5,2,'999999',
 'The expected source ID must match the actual timeline source.'),
 'Mismatch between clicked clip and current source is rejected.');
editDeny(fn()=>sf_assembly_chat_remove(1,$id,5,2,(string)$trans,'short'),
 'Removal without a sufficient review basis is rejected.');
editDeny(fn()=>sf_assembly_chat_remove(1,$id,5,40,(string)$trans,
 'Out-of-bounds positions cannot be removed.'),
 'Invalid clip index is rejected without mutation.');
$failAudit=true;
editDeny(fn()=>sf_assembly_chat_remove(1,$id,5,2,(string)$trans,
 'The dialogue clip breaks the story rhythm and needs to be removed.'),
 'Audit failure prevents unrecorded removal.');
$failAudit=false;
editPass(sf_assembly_read(1,$id)['revision']==5&&count(sf_assembly_read(1,$id)['items'])===4,
 'Failed audit rolls back removed clip and revision.');
$removed=sf_assembly_chat_remove(1,$id,5,2,(string)$trans,
 'The dialogue clip breaks narrative pacing and has to be removed.');
$after=sf_assembly_read(1,$id);
editPass($removed['revision']==6&&$removed['removed_source_id']===(string)$trans&&
 count($after['items'])===3&&$after['items'][0]['source_id']===$before['items'][0]['source_id']&&
 $after['items'][1]['source_id']===$before['items'][1]['source_id']&&
 $after['items'][2]['source_id']===$before['items'][3]['source_id'],
 'Confirmed removal advances revision exactly once and preserves other clips in order.');
editPass(!$removed['media_asset_deleted']&&sf_media_asset(4)!==null,
 'Removing an assembly reference never deletes original footage.');
editDeny(fn()=>sf_assembly_chat_remove(1,$id,5,2,(string)$trans,
 'Old chat cards must not repeat the previous timeline change.'),
 'Stale removal is rejected.');
$incoming=sf_capture_submit(2,['title'=>'Replacement backstage guitar wide',
 'submission_type'=>'video','description'=>'Replacement approved stage source',
 'rights_attested'=>true,'people_release_status'=>'unconfirmed'],4);
sf_capture_review(1,$incoming,'approved_for_edit');
$newScene=sf_scene_create(1,$incoming,['origin'=>'human','start_ms'=>800,'end_ms'=>4800,
 'description'=>'Backstage guitar wide stage moment','tags'=>['guitar','backstage']]);
sf_scene_review(1,$newScene,'verified');
$replacePreview=sf_assembly_chat_edit_preview(1,$id,1,'replace','backstage guitar');
editPass($replacePreview['matches'][0]['source_id']===(string)$newScene&&
 $replacePreview['matches'][0]['publication_clearance']==='unreviewed'&&
 !$replacePreview['executed_changes'],
 'Replacement preview shows only separately verified footage and its actual rights status.');
editDeny(fn()=>sf_assembly_chat_replace(2,$id,6,1,(string)$scene,(string)$newScene,
 'Contributor must not swap private footage.'),
 'Contributor cannot replace verified scenes.');
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,1,'wrong',(string)$newScene,
 'The original selected source must match before a replacement.'),
 'Source mismatch rejects replacement without modification.');
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,1,(string)$scene,(string)$unreleased,
 'Existing footage in another slot cannot be duplicated.'),
 'A verified scene already elsewhere in the timeline cannot be reused.');
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,1,(string)$scene,'999999',
 'Unknown source is not a verified replacement scene.'),
 'Unverified or nonexistent source is rejected.');
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,0,
 (string)$after['items'][0]['source_id'],(string)$newScene,
 'Music and dialogue require their own editor source workflows.'),
 'Non-scene original cannot silently become a different media kind.');
$failAudit=true;
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,1,(string)$scene,(string)$newScene,
 'Human director inspected the new backstage shot and approved its framing.'),
 'Failed audit rolls back replacement and sequence revision.');
$failAudit=false;
editPass(sf_assembly_read(1,$id)['revision']==6&&
 sf_assembly_read(1,$id)['items'][1]['source_id']===(string)$scene,
 'Audit failure preserves the original verified clip and revision.');
$replaced=sf_assembly_chat_replace(1,$id,6,1,(string)$scene,(string)$newScene,
 'Human director inspected the new backstage shot and approved its framing.');
$final=sf_assembly_read(1,$id);
editPass($replaced['revision']==7&&$replaced['previous_source_id']===(string)$scene&&
 $replaced['new_source_id']===(string)$newScene&&
 !$replaced['publication_approved']&&$final['revision']==7&&
 $final['items'][1]['source_id']===(string)$newScene,
 'Confirmed replacement preserves source provenance and writes one new private revision.');
editPass($final['items'][0]['source_id']===$after['items'][0]['source_id']&&
 $final['items'][2]['source_id']===$after['items'][2]['source_id']&&
 $final['director']['publication_rights_pending']>=1,
 'Other clips remain unchanged and unreviewed publication rights stay restricted.');
editDeny(fn()=>sf_assembly_chat_replace(1,$id,6,1,(string)$scene,(string)$newScene,
 'Stale chat card is not allowed to overwrite latest revision.'),
 'Stale replacement is rejected.');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
editPass(sf_admin_agent_plan('remove clip #2 from assembly #'.$id)['type']==='production_timeline_edit'&&
 sf_admin_agent_plan('replace clip #2 in assembly #'.$id.' with guitar footage')['type']==='production_timeline_edit'&&
 str_contains($agent,"'production_timeline_edit'")&&
 str_contains($api,"'chat_remove'")&&str_contains($api,"'chat_replace'")&&
 str_contains($ui,'data-agent-edit-confirm')&&str_contains($ui,"send('assembly #'+id+' timeline')"),
 'Natural-language chat intent, read-only card, authenticated action and revision refresh are connected.');
echo "G5D-2: $g5d2 functional integration checks passed".PHP_EOL;
