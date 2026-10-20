<?php
declare(strict_types=1);
/** G5D-3 — real SQLite assembly, transcript, catalog and storyboard gates. */
require __DIR__.'/section88d3d-story-assembly.php';
require_once dirname(__DIR__).'/api/story-stonefellow-music-auto-core.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-media-core.php';
require_once dirname(__DIR__).'/api/storyboard-preview-core.php';
$g5d3=0;
function cuePass(bool $ok,string $why):void{
 global $g5d3;if(!$ok)throw new RuntimeException('G5D3 FAIL '.$why);
 $g5d3++;echo 'G5D3 PASS '.$why.PHP_EOL;
}
function cueReject(callable $fn,string $why):void{
 $denied=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
 cuePass($denied,$why);
}
$GLOBALS['g5d3_extra_catalog']=[
 ['id'=>'stone-original-1','title'=>'Stonefellow Stage Lights','duration'=>120,
  'metadata'=>['composition_copyright'=>'Stonefellow','master_copyright'=>'Stonefellow']],
 ['id'=>'cover-track-1','title'=>'Third party cover recording','duration'=>75,
  'metadata'=>['is_cover'=>true,'copyright_text'=>'Third-party cover license pending']],
 ['id'=>'sample-track-1','title'=>'Restricted sample version','duration'=>65,
  'metadata'=>['sample_clearance_required'=>true]]
];
cuePass(!sf_stonefellow_music_auto_status('stone-song-1')['eligible'],
 'Explicit unverified sync license blocks even a purported original track.');
cuePass(!sf_stonefellow_music_auto_status('cover-track-1')['eligible'] &&
 !sf_stonefellow_music_auto_status('sample-track-1')['eligible'] &&
 !sf_stonefellow_music_auto_status('outside-music')['eligible'],
 'Cover/sample/unknown catalog music is never automatically cleared.');
cuePass(sf_stonefellow_music_auto_status('stone-original-1')['eligible'],
 'Existing controlled Stonefellow original qualifies for standing approval.');
$intent=sf_assembly_chat_media_intent('add original Stonefellow Stage Lights music to assembly #'.$id.' after clip #1');
cuePass($intent['assembly_id']===$id&&$intent['kind']==='track'&&
 $intent['slot']===1&&str_contains($intent['media_topic'],'Stage Lights'),
 'Conversational original music placement preserves song search and clip position.');
cuePass(sf_assembly_chat_media_intent('insert verified dialogue to assembly #'.$id.' at end')['kind']==='transcript',
 'Natural language correctly distinguishes independently verified dialogue.');
$select=sf_assembly_chat_media_preview(1,null,'track','Stage Lights');
cuePass($select['requires_selection']&&count($select['assemblies'])===1&&
 !$select['executed_changes'],'No implied assembly selection or hidden change.');
$preview=sf_assembly_chat_media_preview(1,$id,'track','Stage Lights',1,'after_clip');
cuePass(!$preview['executed_changes']&&count($preview['matches'])===1&&
 $preview['matches'][0]['source_id']==='stone-original-1'&&
 $preview['matches'][0]['music_approval']==='automatic_stonefellow_original',
 'Private chat preview presents only eligible first-party catalog music.');
cueReject(fn()=>sf_assembly_chat_media_preview(2,$id,'track','Stage Lights'),
 'Non-editor cannot inspect private music placement.');
cueReject(fn()=>sf_assembly_chat_media_insert(2,$id,3,1,'track','stone-original-1',
 'Non-editor is forbidden from music placement.'),'Contributor cannot add music.');
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,3,1,'track','cover-track-1',
 'A cover song may not use the standing original music policy.'),
 'Cover song cannot bypass independent use rights.');
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,3,1,'track','stone-song-1',
 'Unverified sync rights must not become automatically approved.'),
 'Explicit unverified rights block automatic approval.');
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,3,1,'track','stone-original-1','short'),
 'Human placement reason still required for automatic artist music authorization.');
$failAudit=true;
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,3,1,'track','stone-original-1',
 'The Stonefellow song is an original and its entrance supports the narrative.'),
 'Audit failure rolls back music timeline placement.');
$failAudit=false;
cuePass(sf_assembly_read(1,$id)['revision']==3&&count(sf_assembly_read(1,$id)['items'])===2,
 'No phantom music track persisted after audit failure.');
$music=sf_assembly_chat_media_insert(1,$id,3,1,'track','stone-original-1',
 'The original Stonefellow soundtrack establishes the stage and mood.');
cuePass($music['revision']==4&&$music['music_approval']==='automatic_original_stonefellow'&&
 sf_assembly_read(1,$id)['items'][1]['source_id']==='stone-original-1',
 'Eligible music is placed in the requested slot with no separate licensing form.');
$rights=sf_storyboard_track_rights(1,$id,4);
cuePass(($rights['stone-original-1']['sync']??'')==='cleared'&&
 ($rights['stone-original-1']['master']??'')==='cleared'&&
 ($rights['stone-original-1']['authorization_origin']??'')==='stonefellow_original_standing_authorization',
 'Storyboard sync and master gates clear automatically for an eligible original.');
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,4,2,'track','stone-original-1',
 'Accidental duplicate cue must require a separate future proposal.'),
 'Duplicate original music cue is rejected.');
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,3,2,'track','stone-original-1',
 'Stale Agent Chat revision must not overwrite an updated film.'),
 'Outdated music review card cannot override latest timeline.');
$audioSub=sf_capture_submit(2,['title'=>'Verified stage narration','submission_type'=>'audio',
 'description'=>'A second editor-reviewed voice beat','rights_attested'=>true,
 'people_release_status'=>'requires_review'],5);
sf_capture_review(1,$audioSub,'approved_for_edit');
$narration=sf_transcript_create(1,$audioSub,['origin'=>'human','language'=>'en',
 'segments'=>[['start_ms'=>500,'end_ms'=>2800,'text'=>'Welcome to the stage.',
 'speaker_label'=>'Band']]]);
cueReject(fn()=>sf_assembly_chat_media_insert(1,$id,4,3,'transcript',(string)$narration,
 'Unverified dialogue must not enter a governed assembly.'),
 'Unverified dialogue is not insertable even with editor rights.');
sf_transcript_review(1,$narration,'verified');
$dialogue=sf_assembly_chat_media_preview(1,$id,'transcript','');
cuePass(count($dialogue['matches'])===1&&$dialogue['matches'][0]['source_id']===(string)$narration,
 'Chat lists only new human-verified dialogue that is absent from current timeline.');
$dialogueResult=sf_assembly_chat_media_insert(1,$id,4,3,'transcript',(string)$narration,
 'The verified narration creates a necessary closing storytelling beat.');
cuePass($dialogueResult['revision']==5&&
 sf_assembly_read(1,$id)['items'][3]['source_id']===(string)$narration,
 'Verified dialogue is placed with its immutable source and timecodes.');
cuePass(($rights['stone-original-1']['sync']??'')==='cleared'&&
 (sf_storyboard_track_rights(1,$id,5)['stone-original-1']['master']??'')==='cleared',
 'Original music standing authorization remains current through new revisions.');
sf_storyboard_license_review(1,$id,'stone-original-1','master','restricted',
 'Master-use permission temporarily restricted by a specific rights conflict.',5);
$restricted=sf_storyboard_track_rights(1,$id,5);
cuePass(($restricted['stone-original-1']['master']??'')==='restricted'&&
 ($restricted['stone-original-1']['sync']??'')==='cleared',
 'Explicit rights restriction overrides auto-clearance for its specific scope.');
cuePass(($restricted['stone-original-1']['master']??'')!=='cleared',
 'Owner standing policy never erases a manual restriction.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$agent=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
cuePass(sf_admin_agent_plan('add Stonefellow music to assembly #'.$id)['type']==='production_timeline_media'&&
 sf_admin_agent_plan('add verified dialogue to assembly #'.$id)['type']==='production_timeline_media'&&
 str_contains($api,"'chat_add_media'")&&str_contains($ui,'data-agent-media-source')&&
 str_contains($agent,"'production_timeline_media'"),
 'Admin Chat planner, minimal placement UI, and authenticated action share one workflow.');
echo "G5D3: $g5d3 focused assertions passed".PHP_EOL;
