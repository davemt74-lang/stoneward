<?php
declare(strict_types=1);
/**
 * G5D-5 cross-feature certification: one actual private artist production,
 * revisited across music, dialogue, edit operations and record integrity.
 * This supplements (does not replace) G5A–G5D4 focused and FFmpeg suites.
 */
require __DIR__.'/section88d3g5d3-music-dialogue.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-edit-core.php';
require_once dirname(__DIR__).'/api/story-assembly-chat-core.php';
require_once dirname(__DIR__).'/api/story-chat-production-certification-core.php';
$g5d5=0;
function fullPass(bool $ok,string $label):void{
 global $g5d5;
 if(!$ok)throw new RuntimeException('G5D5 FAIL: '.$label);
 $g5d5++;echo 'G5D5 PASS '.$label.PHP_EOL;
}
function fullDeny(callable $fn,string $label):void{
 $denied=false;try{$fn();}catch(Throwable $e){$denied=true;}
 fullPass($denied,$label);
}
function fullGate(array $audit,string $name):array{
 foreach($audit['gates'] as $gate)if($gate['key']===$name)return $gate;
 throw new RuntimeException('Missing required production certification gate: '.$name);
}
$choices=sf_chat_production_certification(1,null);
fullPass($choices['requires_selection']&&count($choices['assemblies'])>=1&&
 $choices['read_only']&&!$choices['publish_allowed'],
 'Unspecified production cannot be guessed or silently edited.');
fullDeny(fn()=>sf_chat_production_certification(2,$id),
 'Contributor denied complete production evidence and playback metadata.');
$before=sf_assembly_read(1,$id);
fullPass((int)$before['revision']===5&&count($before['items'])===4,
 'Cross-feature fixture includes existing music and verified dialogue edits.');
$report=sf_chat_production_certification(1,$id);
fullPass($report['assembly']['revision']===5&&
 count($report['gates'])===6&&$report['total']===6,
 'Production certification inspects exactly six live safety and evidence checks.');
fullPass($report['read_only']&&$report['private_only']&&!$report['publish_allowed']&&
 !$report['render_started']&&!$report['media_deleted'],
 'Certification does not grant export, publication, rendering, or destructive actions.');
fullPass(!fullGate($report,'music')['passed']&&
 str_contains(fullGate($report,'music')['detail'],'standing-original authorizations'),
 'Automatic Stonefellow originals are distinguished from unresolved or restricted music rights.');
fullPass(!fullGate($report,'evidence')['passed']&&
 count($report['blockers'])>0,
 'Independent capture and master-use restrictions fail closed in readiness.');
fullPass(!fullGate($report,'transitions')['passed']&&
 !fullGate($report,'playback')['passed']&&$report['metadata_only'],
 'No configured crossfade or certified private render is invented.');
fullPass($report['history_count']>=1&&fullGate($report,'history')['passed']&&
 count($report['verified_history'])>=1,
 'Saved editorial history is actually checked for digest integrity.');
fullPass(sf_assembly_read(1,$id)['revision']===5,
 'Read-only certification did not mutate the assembly revision.');
require_once dirname(__DIR__).'/api/story-assembly-history-core.php';
$history=sf_assembly_history_list(1,$id);
$recent=(int)$history[0]['revision'];
$q=$db->prepare('SELECT digest FROM story_assembly_revisions WHERE assembly_id=? AND revision=?');
$q->execute([$id,$recent]);$digest=(string)$q->fetchColumn();
$db->prepare('UPDATE story_assembly_revisions SET digest=? WHERE assembly_id=? AND revision=?')
 ->execute([str_repeat('0',64),$id,$recent]);
$corrupt=sf_chat_production_certification(1,$id);
fullPass(!fullGate($corrupt,'history')['passed']&&
 !$corrupt['all_readiness_gates_passed'],
 'Tampered revision digest is reported as failed evidence, never hidden.');
$db->prepare('UPDATE story_assembly_revisions SET digest=? WHERE assembly_id=? AND revision=?')
 ->execute([$digest,$id,$recent]);
fullPass(fullGate(sf_chat_production_certification(1,$id),'history')['passed'],
 'Integrity gate recovers after the exact archived digest is restored.');
$db->prepare("UPDATE story_transcripts SET review_status='rejected' WHERE id=?")
 ->execute([$narration]);
$revoked=sf_chat_production_certification(1,$id);
fullPass(!fullGate($revoked,'source')['passed']&&
 !$revoked['all_readiness_gates_passed'],
 'A revoked verified dialogue source immediately invalidates production certification.');
$db->prepare("UPDATE story_transcripts SET review_status='verified' WHERE id=?")
 ->execute([$narration]);
fullPass(fullGate(sf_chat_production_certification(1,$id),'source')['passed'],
 'Revalidated verified dialogue restores the source-integrity gate.');
// A single production now exercises the same server-authorized routines that
// chat UI controls use, preserving the original review and publication limits.
$moved=sf_assembly_chat_move(1,$id,5,3,'up',
 'Human director wants the closing narration ahead of the earlier dialogue.');
fullPass($moved['revision']===6&&
 sf_assembly_read(1,$id)['items'][2]['source_id']===(string)$narration,
 'Clip reorder advances the private production once and preserves source identity.');
fullDeny(fn()=>sf_assembly_chat_move(1,$id,5,2,'down',
 'A stale historical chat message cannot reorder newly saved revisions.'),
 'Replayed original chat reorder rejected by optimistic version check.');
$trimmed=sf_assembly_chat_trim(1,$id,6,1,0,15000,
 'Shorten the original Stonefellow soundtrack cue to preserve pacing.');
fullPass($trimmed['revision']===7&&
 sf_assembly_read(1,$id)['items'][1]['duration_ms']==15000,
 'Original Stonefellow music trims without repetitive music permission prompts.');
$removed=sf_assembly_chat_remove(1,$id,7,0,'stone-song-1',
 'Remove the legacy song with unresolved synchronization rights from this assembly.');
fullPass($removed['revision']===8&&
 count(sf_assembly_read(1,$id)['items'])===3&&!$removed['media_asset_deleted'],
 'Legacy music reference removed; physical media is untouched.');
$inserted=sf_assembly_chat_insert(1,$id,8,0,(string)$scene,
 'Insert the independently verified opening shot to establish the story context.');
fullPass($inserted['revision']===9&&
 sf_assembly_read(1,$id)['items'][0]['source_id']===(string)$scene,
 'Verified original footage added as opening without bypassing source review.');
$replaced=sf_assembly_chat_replace(1,$id,9,0,(string)$scene,(string)$still,
 'Use the independently verified static detail shot as the new opening.');
fullPass($replaced['revision']===10&&
 sf_assembly_read(1,$id)['items'][0]['source_id']===(string)$still,
 'Verified scene replacement applies one deterministic, audited revision.');
$after=sf_chat_production_certification(1,$id);
fullPass($after['assembly']['revision']===10&&$after['history_count']>=5&&
 fullGate($after,'history')['passed']&&fullGate($after,'source')['passed'],
 'All five successive private edits retain verified sources and a recoverable history.');
fullPass(!fullGate($after,'music')['passed']&&
 !$after['publish_allowed'],
 'Explicit master-use restriction survives every unrelated clip change.');
$intents=[
 'audit assembly #12'=>'production_timeline_certification',
 'certify production assembly #12'=>'production_timeline_certification',
 'review transitions for assembly #12'=>'production_timeline_transitions',
 'add backstage guitar shot to assembly #12'=>'production_timeline_insert',
 'remove clip #2 from assembly #12'=>'production_timeline_edit',
 'replace clip #2 in assembly #12 with verified scene'=>'production_timeline_edit',
 'add Stonefellow song to assembly #12'=>'production_timeline_media',
 'assembly #12 timeline'=>'production_timeline_inspect',
 'review footage'=>'production_footage_review',
];
foreach($intents as $utterance=>$type)
 fullPass(sf_admin_agent_plan($utterance)['type']===$type,
   'Single Agent Chat routes correctly: '.$utterance);
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
fullPass(str_contains($ui,'productionQualityHtml')&&
 str_contains($ui,'data-agent-quality-open')&&
 str_contains($ui,'data-agent-insert-source')&&
 str_contains($ui,'data-agent-edit-confirm')&&
 str_contains($ui,'data-agent-media-source')&&
 str_contains($ui,'data-agent-transition-global')&&
 str_contains($ui,'story-render-review.php?action=stream')&&
 str_contains($api,"'chat_insert'")&&
 str_contains($api,"'chat_remove'")&&
 str_contains($api,"'chat_replace'")&&
 str_contains($api,"'chat_add_media'")&&
 str_contains($api,"'chat_cuts'")&&
 str_contains($agent,'sf_chat_production_certification('),
 'All conversation actions and private review integrations share the current authenticated editor.');
echo "G5D-5 END-TO-END: $g5d5 acceptance assertions passed".PHP_EOL;
