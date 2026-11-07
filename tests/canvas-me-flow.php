<?php
declare(strict_types=1);

// Exercise account-source selection, approval, generation and sharing with real storage.
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-me-flow-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_ME_DB_CONFIG');
$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['catalog','releases','shows'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);

$calls=0;$prompt=[];$system='';$checks=0;$serial=0;$modelTools=['my_preferences'];
function sf_openai_call(array $provider,string $instructions,array $history,string $message,int $maxOutput,int $timeout=0):array {
    $GLOBALS['calls']++;
    $GLOBALS['system']=$instructions;
    $GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    $html='<div class="account-lens">';
    foreach($GLOBALS['modelTools'] as $tool){
        $html.='<section class="sf-dataset-'.$tool.'"><article class="sf-data-row"><p class="sf-field-timezone"></p></article></section>';
    }
    $html.='</div>';
    $reply=str_contains($instructions,'creative project planner')
        ?['title'=>'Account lens','concept'=>'A clear view of the selected account source.']
        :['title'=>'Account lens','html'=>$html,'css'=>'.account-lens{display:grid}'];
    return ['text'=>json_encode($reply,JSON_THROW_ON_ERROR),'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
function me_flow_check(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}
function me_flow_reject(callable $run,string $message,string $class=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){if($error instanceof $class){me_flow_check(true,$message);return;}throw $error;}
    throw new RuntimeException('Expected rejection: '.$message);
}
function me_flow_request():string {return str_pad(dechex(++$GLOBALS['serial']),32,'0',STR_PAD_LEFT);}
function me_flow_rows(array $scene):array {return $scene['datasets']['my_preferences']['rows']??[];}
function me_flow_saved(array $user,int $id):array {
    return sf_canvas_build_workflow_scene($user,['candidate_id'=>$id])??throw new RuntimeException('The saved canvas is unavailable.');
}
function me_flow_private_tool_ids(array $tools):array {
    return array_values(array_column(array_filter($tools,static fn(array $tool):bool=>($tool['scope']??'')==='signed_in_fan'),'id'));
}
function me_flow_no_values(mixed $value):bool {
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    foreach(['Pacific/Tahiti','Asia/Tokyo','PRIVATE_ME_EMAIL','PRIVATE_ME_NAME'] as $sentinel){
        if(str_contains($json,$sentinel))return false;
    }
    return true;
}

require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';
require_once $root.'/api/canvas-gallery-core.php';
require_once $root.'/api/canvas-project-ideas.php';
$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(16),status VARCHAR(16),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB':
    'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,2,9] as $uid)$q->execute([$uid,'PRIVATE_ME_EMAIL'.$uid.'@example.invalid','PRIVATE_ME_NAME'.$uid,
    password_hash('fixture',PASSWORD_DEFAULT),$uid===9?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);
sf_token_adjust(1,1000000,'admin_grant','me-flow');
$user=['id'=>1,'role'=>'user'];$viewer=['id'=>2,'role'=>'user'];$admin=['id'=>9,'role'=>'admin'];
$prefFields=['locale'=>'fr-PF','country_code'=>'PF','listening_history_enabled'=>true,'personalization_enabled'=>false];
sf_privacy_save_profile(1,$prefFields+['timezone'=>'Pacific/Tahiti']);
sf_privacy_save_profile(2,array_replace($prefFields,['locale'=>'ja-JP','country_code'=>'JP','timezone'=>'Asia/Tokyo']));
$preference='my_preferences';$ticket='my_tickets';$permission='canvas.data.'.$preference;
$expected=['my_listening_history','my_listening_summary','my_preferences','my_purchases','my_tickets','personal_listening_stats'];

$before=sf_token_balance(1);
$inventory=sf_canvas_resource_inventory(1,'me');
$ids=array_column($inventory,'id');sort($ids);
me_flow_check($ids===$expected&&array_column($inventory,null,'id')[$preference]['permission_id']===$permission&&
    array_key_exists('timezone',array_column($inventory,null,'id')[$preference]['fields'])&&
    me_flow_no_values($inventory)&&!isset($inventory[0]['rows'])&&$calls===0&&sf_token_balance(1)===$before&&
    sf_canvas_access_export(1)===[],
    'Account picker shows all six field inventories without values, consent, inference, or token changes');
me_flow_reject(fn()=>sf_canvas_resource_inventory(0,'me'),'Guest inventory requires an account');
me_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Show account details {me:published_catalog}']]),
    'Public tool IDs are rejected as account attachments');
me_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Show account details {me:customer_order_status}']]),
    'A registered non-canvas account tool cannot be attached');
me_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Show account details {me:my_preferences} {me-clear}']]),
    'Conflicting category selections and an explicit clear are rejected');
me_flow_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Show account details {me:my_preferences}',
    'artist_inputs'=>['me_tools'=>['my_tickets']]]]),'Conflicting composer tokens and structured selections are rejected');

$project=['brief'=>'Create an abstract view of the profile settings {me:my_preferences} {me:my_preferences}'];
$proposal=sf_canvas_project_propose($user,['project'=>$project]);
me_flow_check($proposal['project']['artist_inputs']['me_tools']===[$preference]&&
    $proposal['project']['track_id']===''&&$proposal['project']['context_track_id']===''&&$calls===0&&
    sf_token_balance(1)===$before&&sf_canvas_access_export(1)===[]&&
    !str_contains($proposal['project']['brief'],'{me:'),
    'Attaching a deduplicated scope strips binding tokens and costs nothing with an empty player');

sf_canvas_project_approve(1,$proposal['id']);
$approved=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>me_flow_request()];
$changed=$approved;$changed['artist_inputs']['me_tools']=[$preference,$ticket];
me_flow_reject(fn()=>sf_agent_scene_create($user,$changed),
    'An approved project cannot silently add a different private scope',SfCanvasProjectApprovalRequired::class);
me_flow_check($calls===0&&sf_token_balance(1)===$before,'Approval scope mismatch is rejected before generation or token usage');

$built=sf_agent_scene_create($user,$approved)['scene'];
me_flow_check($built['artist_inputs']['me_tools']===[$preference]&&
    me_flow_private_tool_ids($prompt['canvas_data_tools'])===[$preference]&&
    isset(array_column($prompt['canvas_data_tools'],null,'id')[$preference]['fields']['timezone'])&&
    me_flow_no_values([$prompt,$system,$built]),
    'Generation receives the selected account schema and saves the scope without including account values');

$owned=me_flow_saved($user,$built['id']);
$context=['scene_id'=>$built['id']];
me_flow_check($owned['canvas_access']['requires_consent']&&
    array_column($owned['canvas_access']['permissions'],'id')===[$permission]&&
    me_flow_rows($owned)===[]&&sf_canvas_access_export(1)===[],
    'Project approval does not grant private read access');
sf_canvas_access_change($user,$context,'grant',$owned['canvas_access']['fingerprint'],[$permission]);
$owned=me_flow_saved($user,$built['id']);
me_flow_check(me_flow_rows($owned)[0]['timezone']==='Pacific/Tahiti'&&
    me_flow_rows($owned)[0]['locale']==='fr-PF'&&!isset(me_flow_rows($owned)[0]['user_id']),
    'Owner sees their live preference fields after explicit consent');
me_flow_reject(fn()=>sf_canvas_access_summary($viewer,$context),'A second account cannot inspect or use a private scene grant',OutOfBoundsException::class);

$planned=sf_canvas_project_ideas_plan($user,['project'=>['action'=>'revise','brief'=>'Rebalance the composition',
    'previous_id'=>$built['id'],'artist_inputs'=>['merch_ids'=>[]]],'request_id'=>me_flow_request()]);
me_flow_check($planned['proposal']['project']['artist_inputs']['me_tools']===[$preference]&&
    me_flow_private_tool_ids($prompt['references']['available_data_tools'])===[$preference]&&me_flow_no_values([$prompt,$system]),
    'Planning an unrelated resource edit preserves account scope and passes metadata only');
$nullScope=sf_canvas_project_propose($user,['project'=>['action'=>'revise','brief'=>'Adjust the view',
    'previous_id'=>$built['id'],'artist_inputs'=>null]]);
sf_canvas_project_approve(1,$nullScope['id']);
$reset=sf_agent_scene_create($user,$nullScope['project']+['project_managed'=>true,'proposal_id'=>$nullScope['id'],
    'request_id'=>me_flow_request()])['scene'];
me_flow_check($nullScope['project']['artist_inputs']===null&&$reset['artist_inputs']['me_tools']===[$preference]&&
    me_flow_private_tool_ids($prompt['canvas_data_tools'])===[$preference],
    'An explicit legacy artist-resource reset cannot widen a previously scoped account attachment');
$edited=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],'brief'=>'Reposition the panels',
    'request_id'=>me_flow_request()])['scene'];
me_flow_check($edited['artist_inputs']['me_tools']===[$preference]&&
    me_flow_rows(me_flow_saved($user,$edited['id']))[0]['timezone']==='Pacific/Tahiti'&&me_flow_no_values($prompt),
    'A saved revision inherits the scope and reuses its valid owner grant without forwarding account values');

$modelTools=[$preference,$ticket];
$expanded=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],
    'brief'=>'Include the selected reservation category {me:my_preferences} {me:my_tickets}',
    'request_id'=>me_flow_request()])['scene'];
$permissions=array_column(me_flow_saved($user,$expanded['id'])['canvas_access']['permissions'],null,'id');
me_flow_check($permissions[$permission]['granted']&&!$permissions['canvas.data.'.$ticket]['granted']&&
    count($permissions)===2&&me_flow_no_values($prompt),
    'A deliberate expansion asks for consent only for the newly declared scope');

$before=sf_token_balance(1);$savedCount=count(sf_agent_scene_history_export(1));$priorCalls=$calls;
me_flow_reject(fn()=>sf_agent_scene_create($user,$proposal['project']+['request_id'=>me_flow_request()]),
    'An out-of-scope private binding produced by the model is rejected',SfAgentSceneInvalidOutput::class);
me_flow_check($calls>$priorCalls&&sf_token_balance(1)===$before&&count(sf_agent_scene_history_export(1))===$savedCount,
    'A rejected out-of-scope output releases its reservation and saves no new scene');

$modelTools=[];
$clearProposal=sf_canvas_project_propose($user,['project'=>['action'=>'revise','previous_id'=>$built['id'],
    'brief'=>'Use an abstract background {me-clear}']]);
sf_canvas_project_approve(1,$clearProposal['id']);
$cleared=sf_agent_scene_create($user,$clearProposal['project']+['project_managed'=>true,'proposal_id'=>$clearProposal['id'],
    'request_id'=>me_flow_request()])['scene'];
$clearView=me_flow_saved($user,$cleared['id']);
me_flow_check($cleared['artist_inputs']['me_tools']===[]&&me_flow_private_tool_ids($prompt['canvas_data_tools'])===[]&&
    $clearView['canvas_access']['permissions']===[]&&me_flow_rows($clearView)===[],
    'An approved explicit clear preserves an empty scope through saving and reopening');
$directClear=sf_agent_scene_create($user,['canvas_kind'=>'custom','previous_id'=>$built['id'],
    'brief'=>'Use an abstract background {me-clear}','request_id'=>me_flow_request()])['scene'];
me_flow_check($directClear['artist_inputs']['me_tools']===[]&&me_flow_private_tool_ids($prompt['canvas_data_tools'])===[]&&
    me_flow_rows(me_flow_saved($user,$directClear['id']))===[],
    'An explicit clear also works on a direct canvas revision');

$modelTools=[$preference];
$shareable=sf_agent_scene_create($user,$proposal['project']+['request_id'=>me_flow_request()])['scene'];
$shareableContext=['scene_id'=>$shareable['id']];
$shareableAccess=sf_canvas_access_summary($user,$shareableContext);
sf_canvas_access_change($user,$shareableContext,'grant',$shareableAccess['fingerprint'],[$permission]);
$share=sf_canvas_gallery_submit($user,['scene_id'=>$shareable['id'],'title'=>'Account Lens',
    'description'=>'Shows settings when the viewer allows it.','creator_label'=>'Fixture maker',
    'publication_consent'=>true,'request_id'=>me_flow_request()]);
sf_canvas_gallery_moderate($admin,$share['id'],'approve',$share['snapshot_hash']);
$snapshot=json_decode(sf_canvas_gallery_row($share['id'])['snapshot_json'],true,16,JSON_THROW_ON_ERROR);
$guest=sf_canvas_gallery_open($share['id']);
$visitor=sf_canvas_gallery_open($share['id'],$viewer);
$creator=sf_canvas_gallery_open($share['id'],$user);
me_flow_check($snapshot['artist_inputs']['me_tools']===[$preference]&&me_flow_no_values([$snapshot,$guest,$visitor,$creator])&&
    !isset($snapshot['datasets'],$snapshot['canvas_access'])&&me_flow_rows($guest['scene'])===[]&&
    me_flow_rows($visitor['scene'])===[]&&me_flow_rows($creator['scene'])===[]&&
    array_column($guest['item']['uses'],'id')===[$permission],
    'Gallery snapshots contain scope and code, and private grants do not cross into a gallery visit');

$galleryContext=['gallery_id'=>$share['id']];
sf_canvas_access_change($viewer,$galleryContext,'grant',$visitor['scene']['canvas_access']['fingerprint'],[$permission]);
$viewerRows=me_flow_rows(sf_canvas_gallery_open($share['id'],$viewer)['scene']);
me_flow_check(count($viewerRows)===1&&$viewerRows[0]['timezone']==='Asia/Tokyo'&&$viewerRows[0]['locale']==='ja-JP'&&
    me_flow_rows(sf_canvas_gallery_open($share['id'],$user)['scene'])===[]&&
    me_flow_rows(sf_canvas_gallery_open($share['id'])['scene'])===[],
    'Gallery consent loads only the granting viewer’s live account data');
$remix=sf_canvas_gallery_remix($viewer,$share['id'],me_flow_request());
$remixed=me_flow_saved($viewer,$remix['scene']['id']);
me_flow_check($remixed['artist_inputs']['me_tools']===[$preference]&&$remixed['canvas_access']['requires_consent']&&
    me_flow_rows($remixed)===[]&&me_flow_no_values($remix),
    'Remixing retains declared scope and requires its own grant');
$pending=sf_canvas_project_propose($user,['project'=>$project]);
$before=sf_token_balance(1);$priorCalls=$calls;
$policy=sf_canvas_permission_save($admin,sf_canvas_permission_policy()['revision'],[$permission=>false]);
me_flow_check(!in_array($preference,array_column(sf_canvas_resource_inventory(1,'me'),'id'),true)&&
    me_flow_rows(me_flow_saved($user,$built['id']))===[]&&
    me_flow_rows(sf_canvas_gallery_open($share['id'],$viewer)['scene'])===[],
    'Disabling a source removes it from discovery and live delivery while saved canvases remain openable');
me_flow_reject(fn()=>sf_canvas_project_approve(1,$pending['id']),
    'New approval rejects an account source disabled after attachment');
me_flow_check($calls===$priorCalls&&sf_token_balance(1)===$before,
    'Policy rejection changes neither inference calls nor token balance');
sf_canvas_permission_save($admin,$policy['policy']['revision'],[$permission=>true]);
me_flow_check(me_flow_rows(sf_canvas_gallery_open($share['id'],$viewer)['scene'])===$viewerRows,
    'Re-enabling policy respects the viewer’s existing independent grant');
me_flow_check(!$db->inTransaction(),'All accepted and rejected account-data workflows close their transactions');
echo 'Canvas me flow: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
