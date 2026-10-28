<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
require_once dirname(__DIR__).'/api/agent-actions-core.php';
require_once dirname(__DIR__).'/api/agent-integration-coverage.php';
$passed=0;$failed=0;
$check=static function(bool $ok,string $label)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if($ok)$passed++;else $failed++;
};
$reject=static function(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
$message='Set note for knowledge file "Artist Biography.md" to Updated artist timeline.';
$parsed=sf_agent_knowledge_parse_note($message);
$check(($parsed['name']??'')==='Artist Biography.md'&&($parsed['value']??'')==='Updated artist timeline.','Exact quoted filename and new note parsed');
$check(sf_agent_knowledge_parse_note('List Knowledge Base documents')===null,'Read intent is never parsed as write');
$check(sf_agent_knowledge_parse_note('Publish knowledge file "Artist Biography.md"')===null,'Publishing is not a supported note operation');
$check(sf_agent_knowledge_parse_note('Delete knowledge file "Artist Biography.md"')===null,'Deletion is not a supported note operation');
$check(sf_admin_agent_plan($message)['type']==='edit_knowledge_note','Conversation selects reviewed Knowledge Base note intent');
$check(sf_admin_agent_plan('List Knowledge Base documents')['type']==='knowledge_inventory_read','Read intent preserved');
$check(sf_admin_agent_plan('Find the knowledge base')['type']==='knowledge_manager','Existing navigation preserved');
$index=['files'=>[
    ['id'=>'K-ONE','name'=>'Artist Biography.md','notes'=>'Original description','updated_at'=>'2026-10-01T00:00:00Z','public_agent'=>false,'text'=>'PRIVATE DOCUMENT BODY','stored_path'=>'storage/knowledge/secret.md','folder_id'=>'A'],
    ['id'=>'K-TWO','name'=>'Another Reference.pdf','notes'=>'Other note','updated_at'=>'2026-10-02T00:00:00Z','public_agent'=>true,'text'=>'OTHER PRIVATE BODY'],
]];
$indexHash=hash('sha256','fixture-knowledge-index');$revision=sf_agent_knowledge_revision($index['files'][0]);
$raw=['file_id'=>'K-ONE','field'=>'notes','value'=>'Updated artist timeline.','expected_revision'=>$revision,'expected_index_hash'=>$indexHash];
$draft=sf_agent_knowledge_normalize_note($raw,$index,$indexHash);
$check($draft['previous']==='Original description'&&$draft['value']==='Updated artist timeline.'&&$draft['file_name']==='Artist Biography.md','Server-normalized snapshot shows exact old and new note');
$check($draft['expected_revision']===$revision&&$draft['expected_index_hash']===$indexHash,'Proposal binds document revision and whole-index hash');
$after=sf_agent_knowledge_apply_note($draft,$index);
$check($after['files'][0]['notes']==='Updated artist timeline.','Approved note patch applies to exact file');
$check($after['files'][0]['public_agent']===false&&$after['files'][0]['text']==='PRIVATE DOCUMENT BODY'&&$after['files'][0]['stored_path']==='storage/knowledge/secret.md','Document content, storage and visibility unchanged');
$check($after['files'][1]===$index['files'][1]&&$index['files'][0]['notes']==='Original description','Other documents and original fixture unchanged');
$check($reject(fn()=>sf_agent_knowledge_normalize_note($raw,$index,hash('sha256','new-index'))),'Stale index hash rejects proposal');
$check($reject(fn()=>sf_agent_knowledge_normalize_note(array_replace($raw,['expected_revision'=>str_repeat('0',64)]),$index,$indexHash)),'Stale document revision rejected');
$check($reject(fn()=>sf_agent_knowledge_normalize_note(array_replace($raw,['file_id'=>'MISSING']),$index,$indexHash)),'Unknown document rejected');
$duplicate=['files'=>[$index['files'][0],$index['files'][0]]];
$check($reject(fn()=>sf_agent_knowledge_normalize_note($raw,$duplicate,$indexHash)),'Duplicate document identity rejected');
$check($reject(fn()=>sf_agent_knowledge_normalize_note(array_replace($raw,['field'=>'public_agent']),$index,$indexHash)),'Agent sharing flag cannot be changed');
$publicIndex=$index;$publicIndex['files'][0]['public_agent']=true;
$check($reject(fn()=>sf_agent_knowledge_normalize_note($raw,$publicIndex,$indexHash)),'Public-Agent-visible document notes cannot be altered by private-note action');
$check($reject(fn()=>sf_agent_knowledge_normalize_note($raw+['stored_path'=>'attacker.php'],$index,$indexHash)),'Unexpected writable fields rejected');
$check($reject(fn()=>sf_agent_knowledge_normalize_note(array_replace($raw,['value'=>str_repeat('A',501)]),$index,$indexHash)),'Oversized note rejected');
$check($reject(fn()=>sf_agent_knowledge_apply_note($draft,$after)),'Old approved draft cannot overwrite newer note');
$check(sf_agent_action_hash('knowledge_note_update',$draft)!==sf_agent_action_hash('catalog_metadata_update',$draft),'Payload hash binds tool identity');
$check(sf_agent_tool_executable('knowledge_note_update','admin',true)
    &&!sf_agent_tool_executable('knowledge_note_update','admin',false)
    &&!sf_agent_tool_executable('knowledge_note_update','fan',true)
    &&!sf_agent_tool_executable('knowledge_note_update','guest',true),'Only confirmed Admin may use existing proposal executor');
$contract=sf_agent_orchestration_contract('admin','edit_knowledge_note');
$check($contract['tool_id']==='knowledge_note_update'&&$contract['may_prepare_reviewed_proposal']
    &&!$contract['may_execute_directly']&&$contract['requires_exact_approval'],'Registered as proposal-only, never direct execution');
$check(!sf_agent_orchestration_contract('customer','edit_knowledge_note')['registered']
    &&!sf_agent_orchestration_contract('public','edit_knowledge_note')['registered'],'Fan and public discovery denied');
$coverage=sf_agent_integration_coverage(sf_admin_agent_module_routes());
$check($coverage['checks_passed']&&count($coverage['reviewed_action_eligible'])===7,'Existing audited allowlist contains exactly seven reviewed executors');
$ledger=(string)file_get_contents(dirname(__DIR__).'/api/agent-actions-core.php');
$check(str_contains($ledger,"'knowledge_note_update'=>sf_agent_action_normalize_knowledge_note")
    &&str_contains($ledger,'sf_agent_knowledge_save_approved_note((array)$p[\'payload\'])'),'Ledger validates input and saves only after approval claim');
$writer=(string)file_get_contents(dirname(__DIR__).'/admin/api/bootstrap.php');
$check(str_contains($writer,'flock($lock,LOCK_EX)')&&str_contains($writer,'hash_equals($expectedContentHash,hash(\'sha256\',$raw))'),'Knowledge writer checks approved hash inside lock');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/agent-actions.php');
$check(str_contains($api,'sf_admin_require_auth($method!==\'GET\')')&&str_contains($api,"'agent_knowledge_note_updated'")
    &&str_contains($api,'sf_agent_action_approve('),'Existing authenticated API owns audit, approval and execution');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(!str_contains((string)file_get_contents(dirname(__DIR__).'/api/agent-knowledge-actions.php'),'sf_ai_llm_candidates')
    &&!str_contains($brain,'sf_agent_action_execute('),'No autonomous model execution or direct Agent write');
echo "Section 91B3A: $passed passed, $failed failed".PHP_EOL;
exit($failed?1:0);
