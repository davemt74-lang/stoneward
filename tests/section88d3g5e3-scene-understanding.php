<?php
declare(strict_types=1);
/**
 * G5E-3: actual SQLite/Composer/Agent Chat integration, source provenance,
 * independently reviewed character-story links and sparse sampled frames.
 */
require __DIR__.'/section88d3g5e1-ai-director-sequences.php';
require_once dirname(__DIR__).'/api/story-director-understanding-core.php';
$g5e3=0;
function understandingPass(bool $condition,string $label):void{
 global $g5e3;
 if(!$condition)throw new RuntimeException('G5E3 FAIL: '.$label);
 $g5e3++;echo 'G5E3 PASS '.$label.PHP_EOL;
}
function understandingReject(callable $fn,string $label):void{
 $blocked=false;try{$fn();}catch(Throwable $e){$blocked=true;}
 understandingPass($blocked,$label);
}
understandingReject(fn()=>sf_director_scene_understanding(2,
 [['kind'=>'scene','source_id'=>(string)$scene,'submission_id'=>$sceneSub]]),
 'Contributor cannot retrieve private storyline or visual observation evidence.');
$initial=sf_composer_context(1,$id,4);
understandingPass(isset($initial['source_understanding']['track:stone-original-1'])&&
 $initial['source_understanding']['track:stone-original-1']['music_status']===
 'original_stonefellow_standing_authorization',
 'Original Stonefellow track retains automatic eligible music authorization in AI Director.');
understandingPass($initial['source_understanding']['scene:'.$scene]['visual_observations']===[],
 'Composer cannot claim an analyzed visual moment without a reviewed result.');
$entity=sf_story_graph_create_entity(1,[
 'entity_type'=>'character','label'=>'The fictional stage guitarist',
 'description'=>'Story role for the private performance',
 'origin'=>'human']);
sf_story_graph_add_link(1,$entity,$sceneSub,'scene',$scene,'appears_in');
$draft=sf_composer_context(1,$id,4);
understandingPass($draft['source_understanding']['scene:'.$scene]['story_connections']===[],
 'Unreviewed or machine-suggested character-story concepts are excluded.');
sf_story_graph_review_entity(1,$entity,'verified');
$linked=sf_composer_context(1,$id,4);
$con=$linked['source_understanding']['scene:'.$scene]['story_connections'];
understandingPass(count($con)===1&&$con[0]['entity_type']==='character'&&
 $con[0]['label']==='The fictional stage guitarist'&&
 $con[0]['origin']==='editor_verified_story_graph',
 'Only editor-verified, source-linked fictional character concepts inform scene selection.');
// Use production's immutable source-byte contract for a separately reviewed
// three-frame analysis; no actual provider keys, media transfers or fake IDs.
foreach([
 'ALTER TABLE media_assets ADD COLUMN sha256 TEXT',
 'ALTER TABLE media_assets ADD COLUMN size_bytes INTEGER',
 'ALTER TABLE media_assets ADD COLUMN stored_path TEXT',
 'ALTER TABLE media_assets ADD COLUMN duration_seconds REAL'
] as $sql)$db->exec($sql);
$hash=hash('sha256','stonefellow-private-source-test-4');
$size=824;
$db->prepare('UPDATE media_assets SET sha256=?,size_bytes=?,duration_seconds=? WHERE id=4')
 ->execute([$hash,$size,4.2]);
sf_evidence_record($sceneSub,4,[
 'sha256'=>$hash,'byte_size'=>$size,'mime_type'=>'video/mp4',
 'image_width'=>null,'image_height'=>null]);
$source=sf_inference_source($sceneSub,'scene');
understandingPass($source['source_sha256']===$hash,
 'Visual source evidence remains bound to the original capture fingerprint.');
$now=gmdate('c');
$db->prepare("INSERT INTO story_inference_jobs
 (submission_id,asset_id,task_type,source_sha256,source_size_bytes,
 status,attempt_count,created_by,created_at,updated_at)
 VALUES(?,4,'scene',?,?,'completed',1,1,?,?)")
 ->execute([$sceneSub,$hash,$size,$now,$now]);
$job=(int)$db->lastInsertId();
$visualDraft=[
 'description'=>'Three sparse samples show a guitar and venue equipment.',
 'timing'=>'three_sampled_frames_only',
 'frame_observations'=>[
  ['index'=>1,'sampled_frame_ms'=>800,'description'=>'Microphone and guitar on stage.',
   'usable_suggestion'=>true],
  ['index'=>2,'sampled_frame_ms'=>2100,'description'=>'Guitar under stage lights.',
   'usable_suggestion'=>true],
  ['index'=>3,'sampled_frame_ms'=>3400,'description'=>'A soft-focused stage detail.',
   'usable_suggestion'=>false]],
 'shot_recommendations'=>['Capture a steady stage-wide cutaway.']
];
$db->prepare("INSERT INTO story_inference_results
 (job_id,provider,model,result_json,status,reviewed_by,reviewed_at,created_at)
 VALUES(?,'openai','fixture',?,'pending_review',NULL,NULL,?)")
 ->execute([$job,json_encode($visualDraft,JSON_THROW_ON_ERROR),$now]);
$pending=sf_composer_context(1,$id,4);
understandingPass($pending['source_understanding']['scene:'.$scene]['visual_observations']===[],
 'Unreviewed model observations cannot influence the Director even with valid source bytes.');
sf_inference_review(1,$job,'approved');
$grounded=sf_composer_context(1,$id,4);
$understanding=$grounded['source_understanding']['scene:'.$scene];
understandingPass(count($understanding['visual_observations'])===3&&
 $understanding['visual_observations'][1]['sampled_frame_ms']===2100&&
 $understanding['visual_observations'][2]['usable_suggestion']===false,
 'Three separately approved visual observations are source-timecoded and retain uncertainty.');
understandingPass(!$understanding['independent_publication_clearance']&&
 !$understanding['audio_analyzed']&&
 !str_contains(json_encode($grounded),'We are beginning the show.'),
 'No implied identity, audio understanding, publication right or full private dialogue enters model context.');
$chat=sf_chat_ai_director_overview(1,$id);
$kinds=array_column($chat['scene_recommendations'],'kind');
understandingPass(in_array('visual_moment',$kinds,true)&&
 in_array('story_connection',$kinds,true)&&
 in_array('retake',$kinds,true)&&
 in_array('original_music',$kinds,true)&&
 !$chat['model_invoked']&&!$chat['changes_applied'],
 'Existing Agent Chat receives source-grounded scene, story, retake and music suggestions without a model call.');
sf_capture_rights_review(1,$sceneSub,'external_ai','restricted',
 'The capture owner has withdrawn external analysis rights for all scene context.');
$revoked=sf_director_scene_understanding(1,[
 ['kind'=>'scene','source_id'=>(string)$scene,'submission_id'=>$sceneSub]
]);
understandingPass(!isset($revoked['scene:'.$scene]),
 'Revoking external-AI rights suppresses previously approved visual and story-graph context immediately.');
understandingReject(fn()=>sf_composer_context(1,$id,4),
 'Director does not send a revised prompt when an existing production source loses consent.');
sf_capture_rights_review(1,$sceneSub,'external_ai','cleared',
 'The source owner separately restored verified AI metadata authorization.');
$db->prepare('UPDATE media_assets SET sha256=? WHERE id=4')
 ->execute([str_repeat('0',64)]);
$invalid=sf_director_scene_understanding(1,[
 ['kind'=>'scene','source_id'=>(string)$scene,'submission_id'=>$sceneSub]
]);
understandingPass($invalid['scene:'.$scene]['visual_observations']===[],
 'A changed media fingerprint invalidates the previous model observation.');
$db->prepare('UPDATE media_assets SET sha256=? WHERE id=4')->execute([$hash]);
$db->prepare("UPDATE story_scene_annotations SET review_status='rejected' WHERE id=?")
 ->execute([$scene]);
$unverified=sf_director_scene_understanding(1,[
 ['kind'=>'scene','source_id'=>(string)$scene,'submission_id'=>$sceneSub]
]);
understandingPass(!isset($unverified['scene:'.$scene]),
 'Revoked canonical footage verification blocks linked graph and visual intelligence together.');
$db->prepare("UPDATE story_scene_annotations SET review_status='verified' WHERE id=?")
 ->execute([$scene]);
understandingPass(count(sf_composer_context(1,$id,4)
 ['source_understanding']['scene:'.$scene]['visual_observations'])===3,
 'Reverified byte-identical consented footage can be reconsidered without duplicating model work.');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$composer=(string)file_get_contents(dirname(__DIR__).'/api/story-composer-core.php');
understandingPass(str_contains($ui,'source-grounded editorial suggestions')&&
 str_contains($composer,'source_understanding')&&
 str_contains($composer,'sf_director_scene_understanding('),
 'Agent Chat and supervised Director share the same source-provenance understanding contract.');
echo "G5E3: $g5e3 integrated assertions passed".PHP_EOL;
