<?php
declare(strict_types=1);
/** G4I integrates private Agent Brain with verified G4H production fixtures. */
require __DIR__.'/section88d3g4h-governed-restore.php';
require dirname(__DIR__).'/api/story-agent-brain-bridge.php';
$nG4I=0;
function verifyG4I(bool $v,string $label):void{
 global $nG4I;if(!$v)throw new RuntimeException('G4I FAIL '.$label);
 $nG4I++;echo 'G4I PASS '.$label.PHP_EOL;
}
function rejectG4I(callable $f,string $label):void{
 try{$f();throw new LogicException('Unexpected access: '.$label);}
 catch(RuntimeException|InvalidArgumentException $e){verifyG4I(true,$label);}
}
sf_composer_schema();
sf_render_jobs_schema();
$db->prepare("INSERT INTO story_composer_proposals
 (assembly_id,revision,status,provider,model,suggestion_json,created_by,created_at)
 VALUES(?,?,'pending_review','anthropic','private-model','{}',?,?)")
 ->execute([$id,6,1,gmdate('c')]);
$db->prepare("INSERT INTO story_render_jobs(assembly_id,revision,status,created_by,created_at,updated_at)
 VALUES(?,?,'private_review',?,?,?)")->execute([$id,6,1,gmdate('c'),gmdate('c')]);
$before=(int)$db->query('SELECT COUNT(*) FROM story_assembly_items')->fetchColumn();
$snapshot=sf_story_agent_brain_summary(1);
verifyG4I($snapshot['scope']==='admin_editor_only'&&$snapshot['available'], 'Admin-only summary is read-only and bounded.');
verifyG4I($snapshot['attention_total']===2&&$snapshot['total_shown']===1,
 'Pending model review and private video review counted once each.');
verifyG4I($snapshot['assemblies'][0]['composer_statuses']['pending_review']===1
 &&$snapshot['assemblies'][0]['render_statuses']['private_review']===1,
 'Shared real proposal/render statuses appear without a second ledger.');
$detail=sf_story_agent_brain_inspect(1,$id);
verifyG4I($detail['revision']===6&&$detail['clip_count']===2&&
 $detail['private_only']&&!$detail['authorizes_edits']&&!$detail['authorizes_render']&&
 !$detail['authorizes_publication']&&!$detail['external_model_access'],
 'Focused Agent Brain never grants execution, media, model or publishing rights.');
verifyG4I(count($detail['review_queue'])===2
 &&in_array('ai_director_proposal',array_column($detail['review_queue'],'kind'),true)
 &&in_array('private_video',array_column($detail['review_queue'],'kind'),true),
 'Single read model shows real pending AI and private video decisions.');
verifyG4I($detail['source_certification']['eligible']===false
 &&$detail['render_ready']===false,'No falsely verified source masters or render-ready claim.');
$json=json_encode($detail,JSON_THROW_ON_ERROR);
verifyG4I(!str_contains($json,'suggestion_json')&&!str_contains($json,'target_digest')
 &&!str_contains($json,'output_name')&&!str_contains($json,'source_id')
 &&!str_contains($json,'review_basis')&&!str_contains($json,'path'),
 'No raw media, private source IDs, hashes or review essays cross Agent Brain boundary.');
verifyG4I(str_contains(sf_story_agent_brain_brief(1,$id),'Private editorial assembly #')
 &&str_contains(sf_story_agent_brain_brief(1),'2 recorded human review items'),
 'Admin chat uses deterministic real data and human review guidance.');
rejectG4I(fn()=>sf_story_agent_brain_summary(2),'Unprivileged contributor denied aggregate production status.');
rejectG4I(fn()=>sf_story_agent_brain_inspect(2,$id),'Unprivileged contributor denied private assembly drilldown.');
rejectG4I(fn()=>sf_story_agent_brain_inspect(1,9999999),'Unknown assembly is not discoverable.');
$after=(int)$db->query('SELECT COUNT(*) FROM story_assembly_items')->fetchColumn();
verifyG4I($before===$after,'No production clips, approvals, jobs or assets modified by status reads.');
$agent=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
verifyG4I(str_contains($agent,"'production_editorial_status'") &&
 str_contains($agent,'sf_story_agent_brain_brief(') &&
 str_contains($agent,'sf_story_agent_brain_summary(') &&
 str_contains($ui,'brainProductionOpen') &&
 str_contains($ui,'production-review'),
 'Existing Admin Agent chat, Brain drawer and private editor linked.');
verifyG4I(str_contains($agent,'sf_admin_agent_external_facts(')&&
 !str_contains(substr($agent,strpos($agent,'function sf_admin_agent_external_facts('),
 strpos($agent,'function sf_admin_agent_research_reply(')-strpos($agent,'function sf_admin_agent_external_facts(')),
 'editorial_production'),'External LLM fact allowlist does not reveal private editorial evidence.');
echo "88D3G4I: $nG4I editorial integration assertions passed".PHP_EOL;
