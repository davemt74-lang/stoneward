<?php
declare(strict_types=1);
// Reuse 88D3D's full verified editorial source fixtures and regression gates.
$metadata=['artist_world.composer_enabled'=>'0'];
function sf_ai_meta_get(string $key,string $fallback=''):string{
 global $metadata;return (string)($metadata[$key]??$fallback);
}
function sf_ai_meta_set(string $key,string $value):void{
 global $metadata;$metadata[$key]=$value;
}
$providerMode='openai';
function sf_ai_llm_candidates(string $workload='chat'):array{
 global $providerMode;
 return [['provider'=>$providerMode,'api_key'=>'TEST-ONLY-NOT-A-REAL-KEY',
   'model'=>'claude-test-stub','endpoint_url'=>'']];
}
require __DIR__.'/section88d3d-story-assembly.php';
require dirname(__DIR__).'/api/story-composer-core.php';
$count=0;
function verify88e(bool $value,string $message):void{
 global $count;
 if(!$value)throw new RuntimeException('FAIL '.$message);
 echo 'PASS '.$message.PHP_EOL;$count++;
}
function reject88e(callable $fn,string $message):void{
 try{$fn();throw new LogicException('Unexpected success');}
 catch(InvalidArgumentException|RuntimeException $e){verify88e(true,$message);}
}
reject88e(fn()=>sf_composer_queue(1,$id,3),'Owner opt-in required before queue');
reject88e(fn()=>sf_composer_configure(2,true),'Contributor cannot turn on external composer');
sf_composer_configure(1,true);
verify88e(sf_composer_enabled(),'Explicit editor opt-in enabled composer');
reject88e(fn()=>sf_composer_queue(2,$id,3),'Contributor cannot send private composition');
reject88e(fn()=>sf_composer_queue(1,$id,2),'Stale revision cannot queue provider work');
reject88e(fn()=>sf_composer_queue(1,$id,3),'Missing independent external-AI rights stops egress');
sf_capture_rights_review(1,$dialogueSub,'external_ai','cleared',
 'Independently verified artist consent for AI analysis of private story metadata.');
$context=sf_composer_context(1,$id,3);
verify88e(count($context['items'])===2&&$context['items'][0]['kind']==='track',
 'Provider only receives known Stonefellow music and verified editorial items');
$contextString=json_encode($context);
verify88e(!str_contains($contextString,'We are beginning the show.')
  &&!str_contains($contextString,'TEST-ONLY-NOT-A-REAL-KEY')
  &&!str_contains($contextString,'contributor_id'),
 'Context excludes transcript dialogue, contributor identities and provider secrets');
$p=sf_composer_queue(1,$id,3);
verify88e($p['status']==='queued'&&!$p['existing'],'Durable composition reserved for current revision');
$dup=sf_composer_queue(1,$id,3);
verify88e($dup['existing']&&$dup['id']===$p['id'],'Same revision cannot reserve duplicate provider charge');
verify88e(count(sf_composer_list(1,$id))===1,'Review queue is private and source-bound');
reject88e(fn()=>sf_composer_list(2,$id),'Contributor cannot read composition proposals');
$called=0;
$mock=function(string $url,array $headers,array $request)use(&$called):array{
 $called++;$serialized=json_encode($request);
 if($url!=='https://api.openai.com/v1/responses'||!str_contains($serialized,'story_premise')
   ||str_contains($serialized,'We are beginning the show.'))
  throw new RuntimeException('Unexpected outbound payload or provider URL');
 $answer=['order'=>[1,0],'logline'=>'The band enters as music sets the mood.',
  'beats'=>[['reason'=>'Begin with the firsthand story','transition'=>'cut'],
    ['reason'=>'Bring in the track as an ending cue','transition'=>'audio_bridge']],
  'missing_shots'=>[['brief'=>'Capture a venue entrance establishing shot']]];
 return ['output_text'=>json_encode($answer)];
};
$done=sf_composer_run_once(1,$mock);
verify88e($done['status']==='pending_review'&&$called===1,
 'Configured OpenAI response is parsed as constrained metadata-only draft');
$entry=sf_composer_list(1,$id)[0];
verify88e($entry['suggestion']['order']===[1,0]&&$entry['status']==='pending_review',
 'Proposal stores only a permutation of permitted timeline positions');
reject88e(fn()=>sf_composer_apply(1,$p['id']),'AI output cannot apply without human approval');
verify88e(sf_composer_review(1,$p['id'],'approved'),'Human editor reviews proposal first');
verify88e(!sf_composer_review(1,$p['id'],'approved'),'Duplicate approval is denied');
$result=sf_composer_apply(1,$p['id']);
verify88e($result['revision']===4&&sf_assembly_read(1,$id)['items'][0]['kind']==='transcript',
 'Explicit second action applies only approved ordering via optimistic revision');
reject88e(fn()=>sf_composer_apply(1,$p['id']),'Applied proposal cannot execute again');
verify88e(sf_composer_list(1,$id)[0]['status']==='applied',
 'Proposal displays the applied editorial revision');
$providerMode='anthropic';
$p2=sf_composer_queue(1,$id,4);
$invalidResponses=0;
$anthropicMock=function(string $url,array $headers,array $body)use(&$invalidResponses):array{
 $invalidResponses++;
 if($url!=='https://api.anthropic.com/v1/messages')
  throw new RuntimeException('Anthropic endpoint invalid');
 return ['content'=>[['type'=>'text','text'=>json_encode([
  'order'=>[1,1],'logline'=>'Invented repeated evidence',
  'beats'=>[['reason'=>'Duplicate source','transition'=>'cut'],['reason'=>'Duplicate source','transition'=>'cut']]
 ])]]]];
};
$res=sf_composer_run_once(1,$anthropicMock);
verify88e($res['status']==='interrupted'&&$invalidResponses===1,
 'Invalid Anthropic source permutation fails closed');
verify88e(sf_composer_list(1,$id)[0]['status']==='interrupted',
 'Uncertain/invalid provider result never silently retries');
verify88e(sf_composer_run_once(1,$anthropicMock)['status']==='empty',
 'Interrupted provider call is not replayed');
$timeline=sf_assembly_read(1,$id);
sf_assembly_replace(1,$id,4,array_map(fn($v)=>[
 'kind'=>$v['kind'],'source_id'=>(string)$v['source_id'],
 'in_ms'=>(int)$v['in_ms'],'out_ms'=>(int)$v['out_ms'],
 'duration_ms'=>(int)$v['duration_ms'],'notes'=>$v['notes']
],$timeline['items']));
$p3=sf_composer_queue(1,$id,5);
sf_capture_rights_review(1,$dialogueSub,'external_ai','restricted',
 'AI processing permission for this contributor is now revoked.');
$attempts=0;
$shouldNotRun=function()use(&$attempts):array{$attempts++;return [];};
$res=sf_composer_run_once(1,$shouldNotRun);
verify88e($res['status']==='interrupted'&&$attempts===0,
 'Revoked rights stop queued composition before external transfer');
reject88e(fn()=>sf_composer_queue(1,$id,5),'Same revision may not authorize revoked evidence');
$timeline=sf_assembly_read(1,$id);
sf_assembly_replace(1,$id,5,array_map(fn($v)=>[
 'kind'=>$v['kind'],'source_id'=>(string)$v['source_id'],
 'in_ms'=>(int)$v['in_ms'],'out_ms'=>(int)$v['out_ms'],
 'duration_ms'=>(int)$v['duration_ms'],'notes'=>$v['notes']
],$timeline['items']));
sf_capture_rights_review(1,$dialogueSub,'external_ai','cleared',
 'Editor independently reviewed a new authorized use of creative synopsis metadata.');
reject88e(fn()=>sf_composer_queue(1,$id,6),'Daily three-proposal budget enforced');
sf_composer_configure(1,false);
verify88e(!sf_composer_enabled(),'Global composer switch can be disabled');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
verify88e(str_contains($api,'sf_admin_require_auth($method!==\'GET\')')
 &&!str_contains($api,'sf_composer_run_once('),
 'HTTP API has CSRF write gate but no model-execution action');
echo "88D3E: $count functional assertions passed".PHP_EOL;
