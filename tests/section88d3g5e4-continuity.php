<?php
declare(strict_types=1);
/**
 * G5E4 end-to-end: revised Composer source context + deterministic source-
 * proven continuity, inferred review prompts, and approved-plan invalidation.
 */
require __DIR__.'/section88d3g5e3-scene-understanding.php';
require_once dirname(__DIR__).'/api/story-director-continuity-core.php';
$g5e4=0;
function continuityPass(bool $ok,string $label):void{
 global $g5e4;
 if(!$ok)throw new RuntimeException('G5E4 FAIL: '.$label);
 $g5e4++;echo 'G5E4 PASS '.$label.PHP_EOL;
}
function continuityDeny(callable $fn,string $label):void{
 $blocked=false;try{$fn();}catch(InvalidArgumentException|RuntimeException $e){$blocked=true;}
 continuityPass($blocked,$label);
}
$ctx=sf_composer_context(1,$id,4);
$report=$ctx['continuity']??null;
continuityPass(is_array($report)&&$report['source_count']===4&&
 $report['read_only']&&!$report['publication_approved']&&!$report['render_started'],
 'Current composer context includes bounded read-only, source-derived continuity.');
continuityPass(count($report['character_arcs'])===1&&
 $report['character_arcs'][0]['arc_status']==='single_verified_appearance',
 'A verified fictional character link is reported without inventing a personal arc.');
continuityPass($report['music_pacing']['cue_count']===2&&
 !$report['music_pacing']['tempo_or_audio_beats_analyzed']&&
 $report['music_pacing']['estimated_music_fraction']>0,
 'Stonefellow music pacing uses cue duration only, not fictitious audio beat analysis.');
continuityPass(!str_contains(json_encode($report),'We are beginning the show.')&&
 !$report['visual_identity_inferred']&&!$report['video_playback_analyzed'],
 'Continuity contains no transcript text, assumed real identity or fake full-video viewing.');
$fixture=[
 ['kind'=>'scene','source_id'=>'101','duration_ms'=>4000],
 ['kind'=>'track','source_id'=>'original-one','duration_ms'=>6000],
 ['kind'=>'transcript','source_id'=>'201','duration_ms'=>2000],
 ['kind'=>'scene','source_id'=>'102','duration_ms'=>4000],
 ['kind'=>'scene','source_id'=>'103','duration_ms'=>4000]
];
$evidence=[
 'scene:101'=>['story_connections'=>[
  ['entity_id'=>4,'entity_type'=>'character','label'=>'Fictional guitarist',
   'origin'=>'editor_verified_story_graph'],
  ['entity_id'=>8,'entity_type'=>'location','label'=>'Backstage',
   'origin'=>'editor_verified_story_graph']]],
 'scene:102'=>['story_connections'=>[
  ['entity_id'=>4,'entity_type'=>'character','label'=>'Fictional guitarist',
   'origin'=>'editor_verified_story_graph'],
  ['entity_id'=>9,'entity_type'=>'location','label'=>'Concert stage',
   'origin'=>'editor_verified_story_graph']]],
 'scene:103'=>['story_connections'=>[
  ['entity_id'=>9,'entity_type'=>'location','label'=>'Concert stage',
   'origin'=>'editor_verified_story_graph']]],
 'track:original-one'=>['music_status'=>'original_stonefellow_standing_authorization']
];
$crafted=sf_director_continuity_report($fixture,$evidence);
continuityPass($crafted['source_count']===5&&
 count($crafted['character_arcs'])===1&&
 $crafted['character_arcs'][0]['return_gaps'][0]['from_clip']===1&&
 $crafted['character_arcs'][0]['return_gaps'][0]['to_clip']===4,
 'Repeated fictional story entity links surface an exact 1-to-4 narrative gap.');
$codes=array_column($crafted['issues'],'code');
continuityPass(in_array('character_return_gap',$codes,true)&&
 in_array('location_handoff',$codes,true),
 'Verified differing location concepts and character return produce human review prompts.');
continuityPass($crafted['music_pacing']['cue_duration_ms']===6000&&
 $crafted['music_pacing']['dialogue_adjacent_to_music']===1&&
 $crafted['music_pacing']['estimated_music_fraction']===0.3,
 'Music/dialogue adjacency and duration-based share are calculated deterministically.');
$missing=sf_director_continuity_report([
 ['kind'=>'transcript','source_id'=>'300','duration_ms'=>2000],
 ['kind'=>'track','source_id'=>'original-two','duration_ms'=>15000]
],[]);
continuityPass(in_array('establishing_visual_coverage',array_column($missing['coverage_gaps'],'code'),true)&&
 $missing['music_pacing']['tempo_or_audio_beats_analyzed']===false,
 'Missing visual coverage is identified without invented visual content.');
continuityDeny(fn()=>sf_director_continuity_report([
 ['kind'=>'unknown','source_id'=>'1','duration_ms'=>10]],[]),
 'Unsupported fake source types cannot be analyzed.');
continuityDeny(fn()=>sf_director_continuity_for_order([99],$ctx),
 'Out-of-range model-suggested source index is refused in continuity analysis.');
$sourceCount=count($ctx['items']);
$validOrder=range(0,$sourceCount-1);
$payload=['order'=>$validOrder,
 'logline'=>'Music and scene cues establish the band story and its setting.',
 'beats'=>array_map(static fn()=>[
  'reason'=>'Preserve source-backed performance narrative continuity.',
  'transition'=>'cut'
 ],$validOrder),
 'missing_shots'=>[['brief'=>'Capture one new exterior establishment.']]];
$approved=sf_composer_validate_suggestion($payload,$ctx);
continuityPass(isset($approved['continuity_preview']['issue_count'])&&
 preg_match('/^[a-f0-9]{64}$/',$approved['continuity_evidence_sha256'])===1&&
 !$approved['publication_authorized']&&!$approved['render_authorized'],
 'Validated AI Director proposal is bound to exact reviewed continuity evidence.');
$ctxChanged=$ctx;
$ctxChanged['source_understanding']['scene:'.$scene]['story_connections']=[];
continuityDeny(fn()=>sf_composer_validate_suggestion($approved,$ctxChanged),
 'Changed verified story links invalidate a previously reviewed AI proposal.');
$ctxChanged=$ctx;
$ctxChanged['source_understanding']['track:stone-original-1']['music_status']=
 'requires_individual_rights_review';
continuityDeny(fn()=>sf_composer_validate_suggestion($approved,$ctxChanged),
 'Changing original music rights state invalidates old storyboard approval.');
continuityPass(sf_composer_validate_suggestion($approved,$ctx)
 ['continuity_evidence_sha256']===$approved['continuity_evidence_sha256'],
 'Unchanged reviewed source story and music evidence gives stable approval digest.');
$chat=sf_chat_ai_director_overview(1,$id);
continuityPass(isset($chat['continuity_current']['character_arcs'])&&
 $chat['continuity_current']['source_count']===4&&
 !$chat['model_invoked']&&!$chat['changes_applied'],
 'Existing Agent Chat displays live continuity without new model invocation or editing.');
$js=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/story-agent-brain-bridge.php');
continuityPass(str_contains($js,'directorContinuityHtml')&&
 str_contains($js,'Current story and soundtrack continuity')&&
 str_contains($js,'Proposed sequence continuity')&&
 str_contains($brain,"'continuity_handoffs'")&&
 str_contains($brain,"'review_director_story_continuity'"),
 'Agent Chat and Agent Brain both surface grounded continuity and human review needs.');
echo "G5E4: $g5e4 continuity and stale-review checks passed".PHP_EOL;
