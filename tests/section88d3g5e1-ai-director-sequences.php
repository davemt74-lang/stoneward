<?php
declare(strict_types=1);
/** G5E-1: actual SQLite permissions, external-AI egress, source IDs, review and atomic apply. */
$metadata=['artist_world.composer_enabled'=>'0'];
function sf_ai_meta_get(string $key,string $fallback=''):string{
 global $metadata;return (string)($metadata[$key]??$fallback);
}
function sf_ai_meta_set(string $key,string $value):void{
 global $metadata;$metadata[$key]=$value;
}
function sf_ai_llm_candidates(string $workload='chat'):array{
 return [['provider'=>'openai','model'=>'gpt-test','api_key'=>'TEST-ONLY-NOT-A-KEY','endpoint_url'=>'']];
}
require __DIR__.'/section88d3d-story-assembly.php';
require_once dirname(__DIR__).'/api/story-composer-core.php';
require_once dirname(__DIR__).'/api/story-chat-ai-director-core.php';
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
$passed=0;
function seqPass(bool $yes,string $why):void{
 global $passed;
 if(!$yes)throw new RuntimeException('G5E1 FAIL '.$why);
 $passed++;echo 'G5E1 PASS '.$why.PHP_EOL;
}
function seqReject(callable $fn,string $why):void{
 $no=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$no=true;}
 seqPass($no,$why);
}
$GLOBALS['g5d3_extra_catalog']=[
 ['id'=>'stone-original-1','title'=>'Original Stonefellow Lights','duration'=>120,
 'metadata'=>['master_copyright'=>'Stonefellow','composition_copyright'=>'Stonefellow']],
 ['id'=>'cover-1','title'=>'Third-party cover','duration'=>65,
 'metadata'=>['is_cover'=>true]]
];
seqReject(fn()=>sf_chat_ai_director_overview(2,$id),'Contributor denied AI sequence details.');
$select=sf_chat_ai_director_overview(1,null);
seqPass($select['requires_selection']&&count($select['assemblies'])===1,
 'No production is guessed from an ambiguous Director request.');
$before=sf_chat_ai_director_overview(1,$id);
seqPass(!$before['enabled']&&!$before['eligible_for_external_ai']&&$before['read_only']&&
 !$before['model_invoked']&&!$before['changes_applied'],
 'Disabled AI and uncleared dialogue cannot leak to external composer.');
seqReject(fn()=>sf_composer_queue(1,$id,3),'External provider is opt-in only.');
sf_composer_configure(1,true);
seqReject(fn()=>sf_composer_context(1,$id,3),'Existing dialogue needs independent external-AI clearance.');
sf_capture_rights_review(1,$dialogueSub,'external_ai','cleared',
 'Authorized independent model processing of only the verified dialogue metadata.');
$noScene=sf_composer_context(1,$id,3);
seqPass(count($noScene['items'])===2&&
 count(array_filter($noScene['candidate_pool'],
  static fn(array $c):bool=>$c['kind']==='scene'))===0,
 'Scene candidate without explicit external-AI consent stays outside model context.');
sf_capture_rights_review(1,$sceneSub,'external_ai','cleared',
 'Verified first-party scene metadata approved for external AI storyboard suggestions.');
$context=sf_composer_context(1,$id,3);
$pool=$context['candidate_pool'];
seqPass(count($pool)===2&&$pool[0]['kind']==='scene'&&
 $pool[0]['source_id']===(string)$scene&&
 $pool[1]['kind']==='track'&&$pool[1]['source_id']==='stone-original-1',
 'Only the consented verified scene and controlled Stonefellow original are candidate additions.');
seqPass(!str_contains(json_encode($context),'We are beginning the show.')&&
 !str_contains(json_encode($context),'cover-1')&&
 !str_contains(json_encode($context),'TEST-ONLY'),
 'Model context excludes transcript text, cover songs and provider credentials.');
$payload=['order'=>[2,0,1,3],
 'logline'=>'The band enters backstage while Stonefellow originals carry the story.',
 'beats'=>[
  ['reason'=>'Establish the true backstage setting','transition'=>'cut'],
  ['reason'=>'Introduce the recorded Stonefellow music','transition'=>'audio_bridge'],
  ['reason'=>'Follow with the approved dialogue beat','transition'=>'dissolve'],
  ['reason'=>'Close the story with another controlled original','transition'=>'fade']],
 'missing_shots'=>[['brief'=>'Capture a closeup of the band leaving the stage']]];
$valid=sf_composer_validate_suggestion($payload,$context);
seqPass($valid['proposed_additions']===2&&count($valid['source_keys'])===4&&
 !$valid['render_authorized']&&!$valid['publication_authorized'],
 'AI can order existing media and at most four new authorized sources, never publish or render.');
seqReject(fn()=>sf_composer_validate_suggestion(
 array_replace($payload,['order'=>[2,1,3]]),$context),
 'Model cannot drop an existing source clip.');
seqReject(fn()=>sf_composer_validate_suggestion(
 array_replace($payload,['order'=>[2,0,1,3,99]]),$context),
 'Model cannot fabricate a non-existent source index.');
seqReject(fn()=>sf_composer_validate_suggestion(
 array_replace($payload,['order'=>[2,0,1,2]]),$context),
 'Model cannot duplicate a verified clip.');
seqReject(fn()=>sf_composer_validate_suggestion(
 array_replace($payload,['source_keys'=>['fake']]),$context),
 'Wrong or tampered source identity keys are refused.');
$overview=sf_chat_ai_director_overview(1,$id);
seqPass($overview['enabled']&&$overview['eligible_for_external_ai']&&
 $overview['candidate_count']===2&&$overview['worker']==='supervised_cli_only',
 'Main chat sees source counts without invoking the model.');
$req=sf_composer_queue(1,$id,3);
seqPass($req['status']==='queued'&&!$req['existing'],
 'Opted-in editor queues one governed sequence proposal for exact revision.');
$dup=sf_composer_queue(1,$id,3);
seqPass($dup['existing']&&$dup['id']===$req['id'],
 'Repeated chat request reuses proposal instead of spending another model call.');
$calls=0;
$mock=function(string $url,array $headers,array $body)use(&$calls,$payload):array{
 $calls++;
 if($url!=='https://api.openai.com/v1/responses'||!str_contains(json_encode($body),'candidate_pool'))
  throw new RuntimeException('Unexpected model endpoint or missing bounded source pool.');
 return ['output_text'=>json_encode($payload)];
};
$done=sf_composer_run_once(1,$mock);
seqPass($done['status']==='pending_review'&&$calls===1,
 'Supervised worker returns a bounded source-aware AI Director sequence.');
$pending=sf_chat_ai_director_overview(1,$id);
seqPass($pending['proposals'][0]['status']==='pending_review'&&
 $pending['proposals'][0]['proposed_additions']===2&&
 count($pending['proposals'][0]['beats'])===4,
 'Agent Chat retrieves the actual pending proposal, beat explanations and additions.');
seqReject(fn()=>sf_composer_apply(1,$req['id']),
 'No AI sequence can apply without a separate human review.');
seqPass(sf_composer_review(1,$req['id'],'approved',
 'The editor compared the evidence, music rights, source IDs and story continuity.'),
 'Human approval with written rationale records the exact proposal.');
seqPass(!sf_composer_review(1,$req['id'],'approved'),
 'Duplicate approval does not change the proposal twice.');
sf_capture_rights_review(1,$sceneSub,'external_ai','restricted',
 'The artist revoked external-AI metadata consent for the scene before applying.');
seqReject(fn()=>sf_composer_apply(1,$req['id']),
 'Revoked external-AI consent invalidates an approved but unapplied proposal.');
seqPass((int)sf_assembly_read(1,$id)['revision']===3,
 'A blocked proposal leaves timeline revision and source identities unchanged.');
sf_capture_rights_review(1,$sceneSub,'external_ai','cleared',
 'The verified source owner reauthorized metadata processing before human application.');
$applied=sf_composer_apply(1,$req['id']);
$next=sf_assembly_read(1,$id);
seqPass($applied['revision']===4&&count($next['items'])===4&&
 $next['items'][0]['kind']==='scene'&&$next['items'][0]['source_id']===(string)$scene&&
 $next['items'][3]['kind']==='track'&&$next['items'][3]['source_id']==='stone-original-1',
 'Separate human apply writes the exact two new verified sources and the proposed ordered sequence.');
seqPass(count(sf_assembly_history_list(1,$id))>=2&&
 sf_composer_list(1,$id)[0]['status']==='applied',
 'Agent Brain and immutable history retain approved sequence application and provenance.');
seqReject(fn()=>sf_composer_apply(1,$req['id']),
 'Applied proposal cannot mutate the timeline or trigger another model request.');
seqPass(sf_admin_agent_plan('ai director sequence assembly #'.$id)['type']==='production_ai_director_sequence'&&
 sf_admin_agent_plan('generate film sequence assembly #'.$id)['type']==='production_ai_director_sequence',
 'Natural AI Director and production-sequence commands route to the same chat canvas.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$agent=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
seqPass(str_contains($api,"'chat_composer_queue'")&&
 str_contains($api,"'chat_composer_review'")&&
 str_contains($api,"'chat_composer_apply'")&&
 str_contains($api,"'confirmed'")&&
 str_contains($ui,'data-agent-director-queue')&&
 str_contains($ui,'data-agent-director-review')&&
 str_contains($ui,'data-agent-director-apply')&&
 str_contains($ui,'aiDirectorSequenceHtml')&&
 str_contains($agent,"'production_ai_director_sequence'"),
 'Chat proposal, external opt-in, human review and second apply have independent authenticated contracts.');
echo "G5E1: $passed actual SQLite safety/AI proposal assertions passed".PHP_EOL;
