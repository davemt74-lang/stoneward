<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/agent-admin-brain.php';
require dirname(__DIR__).'/api/agent-integration-coverage.php';
$failed=0;
$check=static function(bool $ok,string $label)use(&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;if(!$ok)$failed++;
};
$catalog=[
    ['id'=>'public-one','title'=>'Published Single','state'=>'published','public_visible'=>true,'metadata'=>['isrc'=>'USAAA1234567','words_by'=>'Alex'],'lyrics'=>'NEVER SHOW THESE PRIVATE LYRICS','private_master_audio'=>'storage/private.wav'],
    ['id'=>'draft-one','title'=>'Early Demo','state'=>'draft','public_visible'=>false,'metadata'=>['isrc'=>'','words_by'=>'','music_by'=>'']],
    ['id'=>'private-one','title'=>'Hidden Track','state'=>'private','metadata'=>['isrc'=>'USBBB1234567','music_by'=>'Taylor']],
    ['id'=>'limited-one','title'=>'Not Visible','state'=>'published','public_visible'=>false,'metadata'=>['isrc'=>'USCCC1234567','words_by'=>'Casey']],
];
$releases=[
    ['id'=>'live','title'=>'Out Now','state'=>'published','public_visible'=>true,'track_ids'=>['public-one']],
    ['id'=>'scheduled','title'=>'Coming Soon','state'=>'scheduled','release_date'=>'2026-12-01','track_ids'=>['draft-one','private-one'],'notes'=>'PRIVATE MARKETING NOTES'],
    ['id'=>'review','title'=>'Waiting Approval','state'=>'review','track_ids'=>[]],
    ['id'=>'archived','title'=>'Old Box Set','state'=>'archived','track_ids'=>[]]
];
$draft=sf_agent_admin_catalog_read($catalog,'unpublished');
$check($draft['matched']===3 && $draft['shown']===3 && $draft['read_only'],'Catalog lists only unpublished/private records');
$check(array_column($draft['rows'],'id')===['draft-one','private-one','limited-one'],'Visibility checks combine state and public flag');
$missing=sf_agent_admin_catalog_read($catalog,'missing_metadata');
$check($missing['matched']===1 && $missing['rows'][0]['missing_metadata']===['ISRC','writer/composer'],'Missing metadata is grounded in actual fields');
$check(!str_contains(json_encode($draft),'NEVER SHOW') && !str_contains(json_encode($draft),'private.wav'),'Private lyrics and media paths are excluded from bounded results');
$check(sf_agent_admin_catalog_read($catalog,'unpublished',1)['truncated'],'Read result limit exposes honest truncation');
$check(sf_agent_admin_catalog_filter("Show songs that haven't been published")==='unpublished','Natural unpublished catalog wording recognized');
$check(sf_agent_admin_catalog_filter('Which tracks are missing ISRC?')==='missing_metadata','Natural incomplete catalog wording recognized');
$pending=sf_agent_admin_release_read($releases,'pending');
$check(array_column($pending['rows'],'id')===['scheduled','review'],'Pending releases exclude public and archived');
$check(sf_agent_admin_release_read($releases,'scheduled')['matched']===1,'Scheduled release read is scoped');
$check(!str_contains(json_encode($pending),'PRIVATE MARKETING NOTES'),'Internal release notes are excluded');
$check(str_contains(sf_agent_admin_read_brief($pending),'Coming Soon') && str_contains(sf_agent_admin_read_brief($pending),'Read only; no changes made'),'Evidence-backed text communicates read-only outcome');
$check(sf_admin_agent_plan('Show me unpublished songs')['type']==='catalog_inventory_read','Conversation routes unpublished songs to real data read');
$check(sf_admin_agent_plan('Which tracks are missing metadata?')['type']==='catalog_inventory_read','Conversation routes incomplete metadata to real data read');
$check(sf_admin_agent_plan('Show pending releases')['type']==='release_inventory_read','Conversation routes pending releases to real data read');
$check(sf_admin_agent_plan('Show my releases')['type']==='release_manager' && sf_admin_agent_plan('Open catalog')['type']==='catalog_manager','Existing ordinary navigation preserved');
foreach(['catalog_inventory_read'=>'admin_catalog_inventory','release_inventory_read'=>'admin_release_inventory'] as $intent=>$tool){
    $contract=sf_agent_orchestration_contract('admin',$intent);
    $check($contract['tool_id']===$tool && $contract['may_read_or_navigate'] && !$contract['may_execute_directly'] && !$contract['requires_exact_approval'],'Admin-only read contract '.$tool);
    $check(!sf_agent_orchestration_contract('customer',$intent)['registered'] && !sf_agent_orchestration_contract('public',$intent)['registered'],'Customer and guest cannot discover '.$tool);
}
$coverage=sf_agent_integration_coverage(sf_admin_agent_module_routes());
$check($coverage['checks_passed'] && $coverage['registered_tools']===count(sf_agent_tool_registry()),'91B1 coverage remains consistent after two new read contracts');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$check(str_contains($brain,'sf_agent_admin_catalog_read(sf_catalog()') && str_contains($brain,'sf_agent_admin_release_read(sf_admin_releases()'),'Admin Agent uses actual Stonefellow source functions');
$check(!str_contains((string)file_get_contents(dirname(__DIR__).'/api/agent-admin-reads.php'),'sf_ai_llm_candidates'),'Private read projection cannot call external LLM');
echo 'Section 91B2A: '.(22-$failed).'/22 checks'.PHP_EOL;
exit($failed?1:0);
