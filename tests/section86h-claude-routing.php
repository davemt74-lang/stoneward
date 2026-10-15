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
sf_ai_save_provider('anthropic',['enabled'=>false,'model'=>'claude-sonnet-4-6']);
verify(count(sf_ai_llm_candidates('agent_brain'))===1,'Disabled Anthropic cannot silently consume API tokens');
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
