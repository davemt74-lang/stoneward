<?php
declare(strict_types=1);
/** Regression gate for chat-first production sections G5A through G5D. */
require __DIR__.'/section88d2c-scenes.php';
require_once dirname(__DIR__).'/api/story-director-scene-candidates.php';
$passed=0;
function g5pass(bool $condition,string $message):void{
 global $passed;
 if(!$condition)throw new RuntimeException('G5 AUDIT FAIL: '.$message);
 $passed++;echo 'G5 PASS '.$message.PHP_EOL;
}
function g5deny(callable $fn,string $message):void{
 $denied=false;
 try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
 g5pass($denied,$message);
}
g5deny(fn()=>sf_director_scene_candidates(2),'Contributors cannot retrieve editor scene candidates.');
g5pass(sf_director_scene_candidates(1)===[],
 'A verified annotation on a pending capture is not a usable editor clip.');
sf_capture_review(1,$v,'approved_for_edit');
$candidates=sf_director_scene_candidates(1);
g5pass(count($candidates)===1 && $candidates[0]['scene_id']===$scene,
 'Only independently verified scenes on approved submissions are returned.');
g5pass($candidates[0]['start_ms']===1500 && $candidates[0]['end_ms']===2900,
 'Source timecodes are preserved without fabricated frame analysis.');
g5pass($candidates[0]['publication_clearance']==='unreviewed',
 'Editorial approval cannot be misrepresented as publication clearance.');
g5pass(!str_contains(json_encode($candidates,JSON_THROW_ON_ERROR),'asset_id')
 && !str_contains(json_encode($candidates,JSON_THROW_ON_ERROR),'media_url'),
 'Scene candidate contracts exclude internal asset IDs and media URLs.');
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
$intents=[
 'review footage'=>'production_footage_review',
 'show director shot list'=>'production_director_coverage',
 'scene clips'=>'production_scene_candidates',
 'assembly #123 timeline'=>'production_timeline_inspect',
 'timeline for assembly #123'=>'production_timeline_inspect',
 'assembly timeline'=>'production_timeline_inspect',
 'editorial assembly #123'=>'production_editorial_status',
];
foreach($intents as $utterance=>$intent)
 g5pass(sf_admin_agent_plan($utterance)['type']===$intent,
   'Correct chat intent: '.$utterance);
$conversation=sf_admin_agent_thread(2);
sf_admin_agent_save_message(2,$conversation,'assistant','Sensitive footage submission title',
 ['type'=>'production_footage_review','footage_queue'=>[['title'=>'Sensitive footage submission title']]]);
$history=sf_admin_agent_thread_messages(2,$conversation);
g5pass(count($history)===1 &&
 !str_contains($history[0]['content'],'Sensitive') && $history[0]['action']===[],
 'Saved assistant production details are withheld when editor permission is missing.');
$editorConversation=sf_admin_agent_thread(1);
sf_admin_agent_save_message(1,$editorConversation,'assistant','Scoped internal review',
 ['type'=>'production_scene_candidates','scene_candidates'=>$candidates]);
$editorHistory=sf_admin_agent_thread_messages(1,$editorConversation);
g5pass($editorHistory[0]['action']['scene_candidates'][0]['scene_id']===$scene,
 'Authorized editor retains private production context in their own thread.');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ui=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
g5pass(str_contains($agent,"'publication_clearance'") &&
 str_contains($agent,"'external_ai_clearance'") &&
 str_contains($ui,"ctx()?.openView?.('team')"),
 'Footage metadata and actual Creative Director navigation are integrated.');
g5pass(str_contains($ui,'sceneCandidateHtml') && str_contains($ui,'directorHtml')
 && str_contains($ui,'timelineHtml') && str_contains($ui,'footageHtml'),
 'All four chat-first read models have presentation paths.');
echo "88D3G5: $passed focused integration checks passed".PHP_EOL;
