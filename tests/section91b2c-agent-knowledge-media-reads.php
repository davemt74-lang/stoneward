<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-admin-brain.php';
require dirname(__DIR__).'/api/agent-integration-coverage.php';
$fails=0;$checks=0;
$check=static function(bool $ok,string $label)use(&$fails,&$checks):void{
    $checks++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$fails++;
};
$index=['files'=>[
    ['id'=>'K1','name'=>'Artist Biography','type'=>'md','public_agent'=>true,'text'=>'PRIVATE BIO BODY','notes'=>'SECRET NOTE','stored_path'=>'storage/knowledge/private.md','folder_id'=>'F1'],
    ['id'=>'K2','name'=>'Unreleased Plan','type'=>'pdf','public_agent'=>false,'text'=>'PRIVATE PLAN BODY','stored_path'=>'storage/private.pdf'],
    ['id'=>'K3','name'=>'Release History','type'=>'txt','public_agent'=>true],
    ['id'=>'','name'=>'Invalid Document','type'=>'txt'],
]];
$all=sf_agent_admin_knowledge_read($index,'all');
$check($all['matched']===3&&$all['read_only']&&$all['source']==='stonefellow.admin.knowledge','Grounded bounded Knowledge Base inventory');
$check(sf_agent_admin_knowledge_read($index,'private')['rows'][0]['id']==='K2','Private knowledge filter');
$check(sf_agent_admin_knowledge_read($index,'agent_enabled')['matched']===2,'Agent-enabled knowledge filter');
$check(sf_agent_admin_knowledge_read($index,'all',1)['truncated'],'Knowledge result truncation is explicit');
$check(!preg_match('/PRIVATE BIO BODY|SECRET NOTE|storage\/knowledge|storage\/private/',json_encode($all)?:''),'No document body, notes or stored paths escape');
$check(sf_agent_admin_knowledge_filter('Which knowledge documents are private?')==='private','Natural private knowledge filter');
$check(sf_agent_admin_knowledge_filter('List documents available to the Agent')==='agent_enabled','Natural agent-visible knowledge filter');
$assets=[
    ['id'=>10,'title'=>'Master Recording','category'=>'audio','link_count'=>2,'stored_path'=>'storage/private.wav','sha256'=>'SENSITIVE_HASH','technical'=>['comment'=>'SECRET']],
    ['id'=>11,'title'=>'Show Poster','category'=>'image','link_count'=>0,'variants'=>['original'=>'storage/hidden.jpg']],
    ['id'=>12,'title'=>'Promo Video','category'=>'video','link_count'=>1],
];
$media=sf_agent_admin_media_read($assets,'all');
$check($media['matched']===3&&$media['rows'][0]['attachment_count']===2,'Real asset category and usage projection');
$check(sf_agent_admin_media_read($assets,'unattached')['rows'][0]['id']===11,'Unattached media filter');
$check(sf_agent_admin_media_read($assets,'image')['matched']===1,'Image category filter');
$check(sf_agent_admin_media_read($assets,'all',1)['truncated'],'Media limit/truncation explicit');
$check(!preg_match('/private.wav|SENSITIVE_HASH|SECRET|hidden.jpg/',json_encode($media)?:''),'No stored path, hash, tags or variants escape');
$check(sf_agent_admin_media_filter('List unused media files')==='unattached','Natural unused media filter');
$check(sf_agent_admin_media_filter('Show uploaded images')==='image','Natural image media filter');
$links=[
    ['id'=>1,'asset_id'=>10,'title'=>'Master Recording','category'=>'audio','entity_type'=>'track','entity_id'=>'T1','role'=>'master_audio','public_visible'=>0,'link_json'=>'PRIVATE NOTES'],
    ['id'=>2,'asset_id'=>11,'title'=>'Show Poster','category'=>'image','entity_type'=>'show','entity_id'=>'S1','role'=>'poster','public_visible'=>1,'stored_path'=>'PRIVATE PATH'],
];
$relations=sf_agent_admin_attachment_read($links,'all');
$check($relations['matched']===2&&$relations['read_only']&&$relations['source']==='stonefellow.admin.media_links','Media attachments grounded in relationships');
$check(sf_agent_admin_attachment_read($links,'track')['rows'][0]['role']==='master_audio','Track attachment filter');
$check(sf_agent_admin_attachment_read($links,'all',1,5)['truncated']&&sf_agent_admin_attachment_read($links,'all',1,5)['matched']===5,'Attachment count and truncation honest');
$check(!preg_match('/PRIVATE NOTES|PRIVATE PATH/',json_encode($relations)?:''),'Attachment internals excluded');
$check(sf_agent_admin_attachment_filter('Which attachments are on releases?')==='release','Natural relation filter');
$check(str_contains(sf_agent_admin_read_brief($all),'Artist Biography')&&str_contains(sf_agent_admin_read_brief($media),'Show Poster')&&str_contains(sf_agent_admin_read_brief($relations),'master_audio'),'Evidence-backed summaries across domains');
$check(str_contains(sf_agent_admin_read_brief($all),'Read only; no changes made.'),'Read-only status explicit');
foreach([
    'List Knowledge Base documents'=>'knowledge_inventory_read',
    'Which documents are private?'=>'knowledge_inventory_read',
    'Show Media Library assets'=>'media_inventory_read',
    'List uploaded images'=>'media_inventory_read',
    'Which attachments are on tracks?'=>'media_attachment_read',
    'Show media attachments'=>'media_attachment_read'
] as $input=>$intent)$check(sf_admin_agent_plan($input)['type']===$intent,'Admin chat routes '.$input);
$check(sf_admin_agent_plan('Open Knowledge Manager')['type']==='knowledge_manager','Knowledge Manager navigation unchanged');
$check(sf_admin_agent_plan('Open Media Library')['type']==='media_library','Media Library navigation unchanged');
$check(sf_admin_agent_plan('Show upcoming concerts')['type']==='shows_inventory_read','Prior show-calendar read unchanged');
foreach(['knowledge_inventory_read'=>'admin_knowledge_inventory','media_inventory_read'=>'admin_media_inventory','media_attachment_read'=>'admin_media_attachment_inventory'] as $intent=>$tool){
    $contract=sf_agent_orchestration_contract('admin',$intent);
    $check($contract['tool_id']===$tool&&$contract['may_read_or_navigate']&&!$contract['may_execute_directly']&&!$contract['requires_exact_approval'],'Admin read contract '.$tool);
    $check(!sf_agent_orchestration_contract('customer',$intent)['registered']&&!sf_agent_orchestration_contract('public',$intent)['registered'],'Public/customer cannot discover '.$tool);
}
$coverage=sf_agent_integration_coverage(sf_admin_agent_module_routes());
$check($coverage['checks_passed']&&in_array('admin_media_attachment_inventory',array_column($coverage['tools'],'id'),true),'Integration coverage includes attachment reader');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(str_contains($brain,'sf_admin_knowledge_index()')&&str_contains($brain,'sf_agent_admin_media_source(')&&str_contains($brain,'sf_agent_admin_attachment_source('),'Runtime uses the real Admin data sources');
$check(!str_contains((string)file_get_contents(dirname(__DIR__).'/api/agent-admin-reads.php'),'sf_ai_llm_candidates'),'No external model invoked for private inventory');
echo 'Section 91B2C: '.($checks-$fails).'/'.$checks.' checks'.PHP_EOL;
exit($fails?1:0);
