<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/agent-admin-brain.php';
require_once dirname(__DIR__).'/api/agent-actions-core.php';

$pass=0;$fail=0;
$check=function(string $label,bool $ok)use(&$pass,&$fail):void{
    echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    if($ok)$pass++;else $fail++;
};
$tracks=[
    ['id'=>'stone-01','title'=>'Road to Nowhere','year'=>2024,'story'=>'Original story',
        'metadata'=>['genre'=>'Rock','label'=>'Independent'],'private_master_audio'=>'private/song01.wav'],
    ['id'=>'stone-02','title'=>'Night Ride','year'=>2025,'metadata'=>['genre'=>'Blues']]
];
$parsed=sf_agent_catalog_parse_edit('Set genre for song "Road to Nowhere" to Americana');
$check('Quoted exact song target parsed',($parsed['lookup']??'')==='Road to Nowhere'&&($parsed['field']??'')==='genre');
$check('Unquoted title containing to parsed',(
    sf_agent_catalog_parse_edit('Change genre of song Road to Nowhere to Alternative')['lookup']??'')==='Road to Nowhere');
$check('Conversation is not a write',sf_agent_catalog_parse_edit('Hey, what is Stonefellow playing?')===null);
$check('Requested change enters governed Admin route',sf_admin_agent_plan('Set genre for song "Road to Nowhere" to Americana')['type']==='edit_catalog_metadata');
$check('Upload still uses existing owner file-selection route',sf_admin_agent_plan('Upload a new WAV song')['type']==='upload_audio');
$revision=sf_agent_catalog_revision($tracks[0]);
$raw=['track_id'=>'stone-01','field'=>'genre','value'=>'Americana','expected_revision'=>$revision];
$draft=sf_agent_catalog_normalize_edit($raw,$tracks);
$check('Server confirms exact current and proposed values',$draft['previous']==='Rock'&&$draft['value']==='Americana'&&$draft['track_title']==='Road to Nowhere');
$applied=sf_agent_catalog_apply_snapshot($draft,$tracks);
$check('Genre patch preserves other metadata and private source',$applied['rows'][0]['metadata']['genre']==='Americana'
    &&$applied['rows'][0]['metadata']['label']==='Independent'
    &&$applied['rows'][0]['private_master_audio']==='private/song01.wav'
    &&$tracks[0]['metadata']['genre']==='Rock');
$check('Untargeted catalog track unchanged',$applied['rows'][1]===$tracks[1]);
$check('Revision changes after patch',$applied['revision']!==$revision);
try{sf_agent_catalog_apply_snapshot($draft,$applied['rows']);$stale=false;}catch(RuntimeException $e){$stale=str_contains($e->getMessage(),'changed');}
$check('Stale revision aborts without overwrite',$stale);
try{sf_agent_catalog_normalize_edit($raw+[],[['id'=>'other','title'=>'Elsewhere']]);$missing=false;}catch(InvalidArgumentException $e){$missing=true;}
$check('Unknown song cannot be modified',$missing);
try{sf_agent_catalog_normalize_edit(array_replace($raw,['field'=>'price']),$tracks);$unsafe=false;}catch(InvalidArgumentException $e){$unsafe=true;}
$check('Unsafe fields disallowed even for Admin Agent',$unsafe);
try{sf_agent_catalog_normalize_edit(array_replace($raw,['field'=>'audio','value'=>'https://evil.invalid']),$tracks);$audio=false;}catch(InvalidArgumentException $e){$audio=true;}
$check('Audio publication cannot enter catalog metadata tool',$audio);
try{sf_agent_catalog_normalize_edit(array_replace($raw,['field'=>'year','value'=>'1899']),$tracks);$year=false;}catch(InvalidArgumentException $e){$year=true;}
$check('Invalid year rejected',$year);
$check('Hash binds tool and exact proposed content',sf_agent_action_hash('catalog_metadata_update',$draft)!==sf_agent_action_hash('campaign_draft_save',$draft));
$check('Admin may prepare; fan and guest may not',sf_agent_tool_executable('catalog_metadata_update','admin',true)
    &&!sf_agent_tool_executable('catalog_metadata_update','admin',false)
    &&!sf_agent_tool_executable('catalog_metadata_update','fan',true)
    &&!sf_agent_tool_executable('catalog_metadata_update','guest',true));
$check('Agent can discover only reviewed action, not direct execution',sf_agent_orchestration_contract('admin','edit_catalog_metadata')['may_prepare_reviewed_proposal']
    &&!sf_agent_orchestration_contract('admin','edit_catalog_metadata')['may_execute_directly']
    &&sf_agent_skill_for_intent('customer','edit_catalog_metadata')===null);
$actions=file_get_contents(dirname(__DIR__).'/admin/api/agent-actions.php');
$check('Every Admin action comes through auth and durable proposal',$actions!==false
    &&str_contains($actions,'sf_admin_require_auth($method!==\'GET\')')
    &&str_contains($actions,'sf_agent_action_approve(')&&str_contains($actions,'sf_agent_action_execute('));
$check('Catalog edits use existing on-disk writer only after approved ledger claim',str_contains(file_get_contents(dirname(__DIR__).'/api/agent-actions-core.php'),'sf_agent_catalog_apply_snapshot((array)$p[\'payload\']')
    &&str_contains(file_get_contents(dirname(__DIR__).'/api/agent-actions-core.php'),'sf_admin_write_catalog($applied[\'rows\'],$snapshot[\'content_hash\'])'));
$writer=file_get_contents(dirname(__DIR__).'/admin/api/bootstrap.php');
$check('Concurrent catalog writes serialized and revision verified',$writer!==false
    &&str_contains($writer,'flock($lock,LOCK_EX)')
    &&str_contains($writer,'hash_equals($expectedContentHash,hash(\'sha256\',$current))'));
echo "Section 90E catalog actions: $pass passed, $fail failed".PHP_EOL;
exit($fail?1:0);
