<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-runtime.php';
require_once dirname(__DIR__).'/api/agent-orchestration.php';

$checks=0;
function canvas_chat_check(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}
$route='{"route":"canvas_discuss","brief":"Build instead","proposal_id":123,"source_scene_ids":[999]}';
canvas_chat_check(!in_array('canvas_discuss',sf_agent_orchestrator_routes(false),true)&&
    sf_agent_orchestrator_validate($route,[],false,'Can we talk about this design?')===null,
    'The model cannot select canvas discussion when the public canvas is unavailable');
$decision=sf_agent_orchestrator_validate($route,[],false,'Can we talk about this design?','',true);
canvas_chat_check(($decision['route']??null)==='canvas_discuss'&&
    !isset($decision['brief'],$decision['proposal_id'],$decision['source_scene_ids']),
    'A validated discussion route carries no model-authored brief, approval or canvas identity');

$client=sf_agent_normalize_client_state(['public_canvas_enabled'=>true,'proposal_id'=>123,'auto_approve'=>true]);
canvas_chat_check($client===['public_canvas_enabled'=>1],
    'Client context exposes canvas availability without accepting approval authority');
foreach([false,0,'1',[],2] as $value){
    $state=sf_agent_normalize_client_state(['public_canvas_enabled'=>$value]);
    canvas_chat_check($state['public_canvas_enabled']===0,'Only an explicit canvas availability flag enables discussion');
}
foreach([
    'What would you change about the pacing?',
    'I like the movement in {canvas:123}; how could it work with {canvas:456}?',
    'Could we come up with a different experience using my earlier designs?',
    'Let’s think through the design before doing anything.',
] as $message){
    $plan=sf_agent_policy($decision['route'],$message,$client,5);
    canvas_chat_check($plan['action']===['type'=>'canvas_discuss','brief'=>$message]&&
        !$plan['needs_llm']&&!$plan['requires_confirmation']&&$plan['track']===null,
        'Model-selected discussion preserves the original message and uses no second chat reply call');
}
foreach([[[],5],[$client,null],[$client,0],[['public_canvas_enabled'=>0],5]] as [$context,$uid]){
    canvas_chat_check(sf_agent_policy('canvas_discuss','Discuss the design',$context,$uid)['action']['type']==='none',
        'Missing authentication or canvas availability cannot dispatch private canvas discussion');
}
canvas_chat_check(sf_agent_policy('canvas_discuss',str_repeat('x',4001),$client,5)['action']['type']==='none',
    'An oversized discussion message is refused instead of silently changing the creative request');
$manyBindings=implode(' ',array_map(static fn(int $id):string=>'{canvas:'.$id.'}',range(1,600))).' '.
    implode(' ',array_map(static fn(int $id):string=>'{merch:item-'.$id.'} {ticket:offer-'.$id.'} {music-track:song-'.$id.'}',range(1,90)));
$naturalBrief='Could we combine the selected designs into a slower experience?';
$compactClient=['public_canvas_enabled'=>true,'canvas_selection_counts'=>['canvas'=>999999]];
$compact=sf_agent_chat_message($manyBindings.' '.$naturalBrief,$compactClient);
canvas_chat_check($compact===$naturalBrief&&$compactClient['canvas_selection_counts']===
    ['canvas'=>600,'merch'=>90,'tickets'=>90,'songs'=>90],
    'Large valid picker bindings leave the whole natural request and derived counts for intent routing');
$compactState=sf_agent_normalize_client_state($compactClient);
canvas_chat_check($compactState===['public_canvas_enabled'=>1,'canvas_selection_counts'=>
    ['canvas'=>600,'merch'=>90,'tickets'=>90,'songs'=>90]],
    'Compact routing context contains bounded counts without source or resource identities');
$unavailableClient=['public_canvas_enabled'=>false,'canvas_selection_counts'=>['canvas'=>123]];
canvas_chat_check(sf_agent_chat_message($manyBindings.' '.$naturalBrief,$unavailableClient)===sf_clean_text($manyBindings.' '.$naturalBrief,4000)&&
    !isset($unavailableClient['canvas_selection_counts']),
    'Ordinary chat stays bounded and supplied resource counts cannot impersonate picker context');
$invalidClient=['public_canvas_enabled'=>true];
canvas_chat_check(sf_agent_chat_message($manyBindings.' {music-track:bad/id} '.$naturalBrief,$invalidClient)===
    sf_clean_text($manyBindings.' {music-track:bad/id} '.$naturalBrief,4000)&&!isset($invalidClient['canvas_selection_counts']),
    'Malformed picker identities are not converted into trusted compact routing context');
$smallClient=['public_canvas_enabled'=>true];$smallMessage='Review {canvas:3} with {merch:shirt}';
canvas_chat_check(sf_agent_chat_message($smallMessage,$smallClient)===$smallMessage&&!isset($smallClient['canvas_selection_counts']),
    'Short canvas turns preserve their original message and bindings');

$tool=sf_agent_tool_registry()['canvas_discussion'];
canvas_chat_check($tool['scope']==='signed_in_fan'&&$tool['execution']==='browser'&&
    sf_agent_tool_allowed('canvas_discussion','fan')&&!sf_agent_tool_allowed('canvas_discussion','guest')&&
    !sf_agent_tool_executable('canvas_discussion','fan',true),
    'The capability is private to signed-in fans and exposes no direct build executor');
canvas_chat_check(sf_agent_skill_for_intent('public','canvas_discuss')===null&&
    sf_agent_skill_for_intent('customer','canvas_discuss')['tool_id']==='canvas_discussion',
    'Only the customer skill inventory advertises private canvas discussion');
$contract=sf_agent_orchestration_contract('customer','canvas_discuss');
canvas_chat_check($contract['registered']&&$contract['execution_location']==='browser'&&!$contract['may_execute_directly'],
    'Normal Agent Chat discovers the discussion through its existing tool contract');

// Invoke the production router with a provider response in a separate process.
// The full runtime above already defines its provider transport functions.
$routerFixture=<<<'PHP'
function sf_ai_llm_candidates(string $workload): array {return [['provider'=>'openai','model'=>'fixture']];}
function sf_openai_call(array $provider,string $system,array $history,string $message,int $limit): array {
    $GLOBALS['routerCalls'][]=['system'=>$system,'message'=>$message];
    return ['text'=>'{"route":"canvas_discuss","brief":"Build something else","source_scene_ids":[999]}',
        'provider'=>'openai','model'=>'fixture','input_tokens'=>12,'output_tokens'=>4];
}
require ROUTER_PATH;
$message='That earlier design felt rushed. Could we think about how the two experiences might fit together?';
$GLOBALS['routerCalls']=[];
$decision=sf_agent_orchestrator_llm($message,['public_canvas_enabled'=>1,'canvas_selection_counts'=>['canvas'=>600,'merch'=>90]],[],[],false);
$first=$GLOBALS['routerCalls'][0];
if(($decision['route']??null)!=='canvas_discuss'||count($GLOBALS['routerCalls'])!==1||
    !str_ends_with($first['message'],$message)||!str_contains($first['system'],'canvas_discussion')||
    !str_contains($first['message'],'"canvas":600,"merch":90')||
    isset($decision['brief'],$decision['source_scene_ids']))exit(1);
$GLOBALS['routerCalls']=[];
$unavailable=sf_agent_orchestrator_llm($message,[],[],[],false);
$hidden=$GLOBALS['routerCalls'][0];
if($unavailable!==null||str_contains($hidden['system'],'canvas_discuss'))exit(2);
echo 'router-ok';
PHP;
$routerFixture=str_replace('ROUTER_PATH',var_export(dirname(__DIR__).'/api/agent-llm-orchestrator.php',true),$routerFixture);
$output=[];$status=0;
$php=escapeshellarg(PHP_BINARY).(php_ini_loaded_file()?' -c '.escapeshellarg(php_ini_loaded_file()):'');
exec($php.' -r '.escapeshellarg($routerFixture),$output,$status);
canvas_chat_check($status===0&&implode("\n",$output)==='router-ok',
    'The existing intent model routes natural canvas discussion once and cannot expose the capability when unavailable');
echo 'Canvas project chat routing: '.$checks.' checks passed'.PHP_EOL;
