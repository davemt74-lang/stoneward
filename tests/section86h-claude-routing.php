<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-claude-'.bin2hex(random_bytes(7));
mkdir($root.'/config',0700,true);
define('SF_ROOT',$root);
function sf_clean_text(mixed $value,int $max):string {return substr(trim((string)$value),0,$max);}
function sf_db_config():array {return ['driver'=>'sqlite'];}
function sf_db():PDO {
    static $db=null;
    if(!$db){
        $db=new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE app_meta (meta_key TEXT PRIMARY KEY,meta_value TEXT NOT NULL,updated_at TEXT NOT NULL)');
    }
    return $db;
}
require dirname(__DIR__).'/api/ai.php';
$checks=0;
function verify(bool $pass,string $label):void {
    global $checks;
    if(!$pass)throw new RuntimeException('86H FAIL '.$label);
    $checks++;
    echo "PASS ".$label.PHP_EOL;
}
function invalid(callable $fn):bool {
    try{$fn();return false;}catch(InvalidArgumentException){return true;}
}
verify(sf_claude_model_choice('claude-haiku-5-5')==='claude-haiku-5-5','Haiku 5.5 official Claude API ID');
verify(sf_claude_model_choice('claude-sonnet-5-5')==='claude-sonnet-5-5','Sonnet 5.5 official Claude API ID');
verify(invalid(fn()=>sf_claude_model_choice('unexpected-model')),'Unknown auto-selected models fail closed');
verify(sf_claude_effective_mode('','claude-sonnet-4-6')==='manual','Legacy configured Claude model is preserved');
verify(sf_claude_effective_mode('','')==='auto','Fresh configuration uses automatic model routing');
verify(sf_ai_state()['routing']['coding_models']===['openai'=>['gpt-6-luna'],
    'anthropic'=>[SF_CLAUDE_FAST_MODEL,SF_CLAUDE_REASONING_MODEL]]&&sf_ai_canvas_model_options()===[],
    'Initial coding choices derive from the existing defaults and require configured providers to appear');
verify(sf_claude_workload_for_plan(['route'=>'general_conversation','context_profile'=>'catalog'])==='chat','Everyday conversation selects fast tier');
verify(sf_claude_workload_for_plan(['route'=>'knowledge_question','context_profile'=>'knowledge'])==='reasoning','Knowledge answer selects reasoning tier');
verify(sf_claude_workload_for_plan(['route'=>'general_conversation','context_profile'=>'account'])==='reasoning','Account-relevant context selects reasoning tier');
$selected=sf_claude_choose_model(['provider'=>'anthropic','model'=>''],['claude_mode'=>'auto'],'chat');
verify($selected['model']==='claude-haiku-5-5'&&$selected['model_tier']==='chat','Haiku 5.5 auto-selected for chat');
$selected=sf_claude_choose_model(['provider'=>'anthropic','model'=>''],['claude_mode'=>'auto'],'agent_brain');
verify($selected['model']==='claude-sonnet-5-5'&&$selected['model_tier']==='reasoning','Sonnet 5.5 auto-selected for Admin Agent Brain');
$selected=sf_claude_choose_model(['provider'=>'anthropic','model'=>'claude-sonnet-4-6'],['claude_mode'=>'manual'],'agent_brain');
verify($selected['model']==='claude-sonnet-4-6'&&$selected['model_tier']==='manual','Manual model respects existing configured model');
$other=sf_claude_choose_model(['provider'=>'openai','model'=>'gpt-6'],['claude_mode'=>'auto'],'agent_brain');
verify($other['model']==='gpt-6','Claude routing never changes OpenAI model');
sf_ai_save_provider('anthropic',['enabled'=>true,'api_key'=>'sk-ant-test-only-not-real-key','model'=>'']);
verify(sf_ai_state()['providers']['anthropic']['key_configured'],'Existing encrypted Anthropic settings reused');
verify(!isset(sf_ai_state()['providers']['anthropic']['api_key'])&&
    !str_contains(json_encode(sf_ai_state()),'sk-ant-test-only-not-real-key'),'Client-facing state never exposes provider key');
$chat=sf_ai_llm_candidates('chat');
$brain=sf_ai_llm_candidates('agent_brain');
verify(count($chat)===1&&$chat[0]['model']==='claude-haiku-5-5','Actual candidate resolver uses Haiku for chat');
verify($brain[0]['model']==='claude-sonnet-5-5','Actual candidate resolver uses Sonnet for Agent Brain');
verify(sf_ai_resolve_llm()['model']==='claude-haiku-5-5','Legacy resolve caller stays compatible');
$set=sf_ai_save_routing(['preferred_llm'=>'anthropic','fallback_enabled'=>true,
    'voice_provider'=>'none','decision_provider'=>'none','jev_response_eval'=>false,
    'claude_mode'=>'auto','claude_fast_model'=>'claude-sonnet-5-5',
    'claude_reasoning_model'=>'claude-haiku-5-5']);
verify($set['claude_fast_model']==='claude-sonnet-5-5'&&
    $set['claude_reasoning_model']==='claude-haiku-5-5','Admin routing controls persist per-tier presets');
verify(sf_ai_llm_candidates('chat')[0]['model']==='claude-sonnet-5-5'&&
    sf_ai_llm_candidates('agent_brain')[0]['model']==='claude-haiku-5-5','Per-tier choices reach actual candidate resolver');
$before=sf_ai_state()['routing'];
verify(invalid(fn()=>sf_ai_save_routing(['preferred_llm'=>'openai','claude_mode'=>'auto',
    'claude_fast_model'=>'hacked-model'])),'Invalid automatic model rejected');
verify(sf_ai_state()['routing']===$before,'Invalid routing request does not partially change existing settings');
sf_ai_save_provider('anthropic',['enabled'=>true,'model'=>'claude-sonnet-4-6']);
sf_ai_save_routing(['preferred_llm'=>'anthropic','voice_provider'=>'none','decision_provider'=>'none',
    'claude_mode'=>'manual','claude_fast_model'=>'claude-haiku-5-5',
    'claude_reasoning_model'=>'claude-sonnet-5-5']);
verify(sf_ai_llm_candidates('chat')[0]['model']==='claude-sonnet-4-6','Manual mode preserves owner-selected existing Sonnet');
sf_ai_save_routing(['preferred_llm'=>'anthropic','voice_provider'=>'none','decision_provider'=>'none',
    'claude_mode'=>'auto','claude_fast_model'=>'claude-haiku-5-5',
    'claude_reasoning_model'=>'claude-sonnet-5-5']);
verify(sf_ai_llm_candidates('chat')[0]['model']==='claude-haiku-5-5','Opting in to auto overrides manual ID only at selection');
verify(sf_ai_provider_row('anthropic')['model']==='claude-sonnet-4-6','Automatic mode does not erase manual model preference');
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'sk-openai-test-only-not-real-key','model'=>'gpt-6']);
sf_ai_save_routing(['preferred_llm'=>'openai','fallback_enabled'=>true,'voice_provider'=>'none',
    'decision_provider'=>'none','claude_mode'=>'auto','claude_fast_model'=>'claude-haiku-5-5',
    'claude_reasoning_model'=>'claude-sonnet-5-5']);
$ordered=sf_ai_llm_candidates('agent_brain');
verify($ordered[0]['provider']==='openai'&&$ordered[0]['model']==='gpt-6'&&
    $ordered[1]['provider']==='anthropic'&&$ordered[1]['model']==='claude-sonnet-5-5',
    'OpenAI preference and Claude fallback remain intact');
verify(sf_ai_canvas_llm_candidates()===sf_ai_llm_candidates('chat')&&
    array_column(sf_ai_canvas_llm_candidates(),'provider')===['openai','anthropic'],
    'Default canvas generation keeps existing chat model order and fallback');
verify(sf_ai_state()['routing']['coding_models']===['openai'=>['gpt-6'],
    'anthropic'=>['claude-sonnet-4-6',SF_CLAUDE_FAST_MODEL,SF_CLAUDE_REASONING_MODEL]],
    'Unedited coding lists inherit current provider settings and Claude tier choices');

$codingRoute=['preferred_llm'=>'openai','fallback_enabled'=>false,'voice_provider'=>'none','decision_provider'=>'none'];
$fineTune='ft:gpt-custom:organization:coding-id';
$codingModel='claude-coding-custom-20261031';
$openaiOption=['provider'=>'openai','model'=>$fineTune];
$claudeOption=['provider'=>'anthropic','model'=>$codingModel];
$set=sf_ai_save_routing($codingRoute+['coding_models'=>['openai'=>[$fineTune,$fineTune],'anthropic'=>[$codingModel]]]);
verify($set['coding_models']===['openai'=>[$fineTune],'anthropic'=>[$codingModel]],
    'Admin saves custom exact coding model IDs in order and deduplicates each list');
verify(sf_ai_canvas_model_options()===[$openaiOption,$claudeOption],
    'Public coding options expose only provider and model for every enabled keyed provider regardless of routing preference');
$selected=sf_ai_canvas_llm_candidates($claudeOption);
verify(count($selected)===1&&$selected[0]['provider']==='anthropic'&&$selected[0]['model']===$codingModel&&
    $selected[0]['api_key']==='sk-ant-test-only-not-real-key'&&
    array_column(sf_ai_canvas_llm_candidates(),'model')===['gpt-6'],
    'Explicit coding selection uses the exact configured provider credentials with no fallback and keeps chat separate');
verify(invalid(fn()=>sf_ai_canvas_llm_candidates(['provider'=>'anthropic','model'=>strtoupper($codingModel)])),
    'Explicit coding selection requires an exact case-sensitive model match');

$before=sf_ai_state()['routing'];
foreach([null,['jev'=>['some-id']],['openai'=>['wrong id']],['openai'=>['slot'=>'gpt-6']],
    ['openai'=>array_fill(0,SF_AI_CODING_MODEL_LIMIT+1,'gpt-6')]] as $invalidModels){
    verify(invalid(fn()=>sf_ai_save_routing(['preferred_llm'=>'anthropic','coding_models'=>$invalidModels]))&&
        sf_ai_state()['routing']===$before,'Invalid coding lists cannot partially change routing settings');
}
$partial=sf_ai_save_routing($codingRoute+['coding_models'=>['anthropic'=>[]]]);
verify($partial['coding_models']===['openai'=>[$fineTune],'anthropic'=>[]]&&sf_ai_canvas_model_options()===[$openaiOption]&&
    invalid(fn()=>sf_ai_canvas_llm_candidates($claudeOption)),
    'Explicit empty provider list removes its choices and rejects a stale public selection');
verify(sf_ai_save_routing($codingRoute)['coding_models']===$partial['coding_models'],
    'Saving existing routing controls preserves omitted coding model lists');
verify(sf_ai_save_routing($codingRoute+['coding_models'=>[]])['coding_models']===['openai'=>[],'anthropic'=>[]]&&
    sf_ai_canvas_model_options()===[]&&array_column(sf_ai_canvas_llm_candidates(),'model')===['gpt-6'],
    'An explicit empty map clears both public lists and keeps default generation available');
sf_ai_save_routing($codingRoute+['coding_models'=>['openai'=>[$fineTune],'anthropic'=>[$codingModel]]]);
sf_ai_save_provider('anthropic',['enabled'=>false,'model'=>'claude-sonnet-4-6']);
verify(count(sf_ai_llm_candidates('agent_brain'))===1,'Disabled Anthropic cannot silently consume API tokens');
verify(sf_ai_canvas_model_options()===[$openaiOption]&&invalid(fn()=>sf_ai_canvas_llm_candidates($claudeOption)),
    'A disabled provider disappears from the picker and rejects saved selections even with a stored key');
sf_db()->exec("UPDATE ai_provider_settings SET enabled=1,secret_payload=NULL WHERE provider='anthropic'");
verify(sf_ai_canvas_model_options()===[$openaiOption]&&invalid(fn()=>sf_ai_canvas_llm_candidates($claudeOption)),
    'An enabled but keyless provider also remains unavailable');
$runtime=file_get_contents(dirname(__DIR__).'/api/agent-runtime.php');
$admin=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/admin.js');
verify(str_contains($runtime,'sf_ai_llm_candidates(sf_claude_workload_for_plan($plan))'),
    'Customer Agent runtime uses server-generated route and workload');
verify(str_contains($admin,"sf_ai_llm_candidates('agent_brain')"),
    'Admin Agent Brain uses reasoning tier');
verify(str_contains($ui,'name="claude_mode"')&&str_contains($ui,"claude_fast_model:f.get('claude_fast_model')")&&
    str_contains($ui,"claude_reasoning_model:f.get('claude_reasoning_model')"),
    'Admin provider page includes persisted Claude model controls');
verify(!str_contains($ui,'sk-ant-test-only-not-real-key'),
    'No API secrets embedded in client bundle');
echo "Claude routing acceptance: $checks checks passed\n";
