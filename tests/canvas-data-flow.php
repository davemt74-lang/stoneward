<?php
declare(strict_types=1);

// Exercise named data bindings through proposal, generation, live reads and gallery publication.
$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/sf-data-flow-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isLink()||!$entry->isDir())unlink($entry->getPathname());else rmdir($entry->getPathname());
    }
    rmdir($fixture);
});
$config=getenv('SF_TEST_DATA_DB_CONFIG');
$config=$config?json_decode($config,true,8,JSON_THROW_ON_ERROR):['driver'=>'sqlite','path'=>$fixture.'/db.sqlite'];
file_put_contents($fixture.'/config/database.php','<?php return '.var_export($config,true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
foreach(['catalog','releases'] as $name)file_put_contents($fixture.'/data/'.$name.'.json','[]');
define('SF_ROOT',$fixture);

$shows=[
    ['id'=>'flow-venue','title'=>'Forecast show','venue'=>'Fixture Stage','city'=>'Tucson','region'=>'AZ','country'=>'US','date'=>'2099-01-01','status'=>'scheduled','public_visible'=>true],
    ['id'=>'flow-hidden','title'=>'PRIVATE_SHOW_CANARY','venue'=>'PRIVATE_VENUE_CANARY','city'=>'Phoenix','region'=>'AZ','country'=>'US','date'=>'2099-01-01','status'=>'hidden','public_visible'=>false],
];
function data_flow_save_shows(array $shows):void {
    file_put_contents(SF_ROOT.'/data/shows.json',json_encode($shows,JSON_THROW_ON_ERROR));
}
data_flow_save_shows($shows);

$calls=0;$prompt=[];$instructions='';$checks=0;$serial=0;
function sf_openai_call(array $provider,string $system,array $history,string $message,int $maxOutput,int $timeout=0):array {
    $GLOBALS['calls']++;
    $GLOBALS['instructions']=$system;
    $GLOBALS['prompt']=json_decode($message,true,16,JSON_THROW_ON_ERROR);
    $reply=str_contains($system,'creative project planner')
        ?['title'=>'Two weather views','concept'=>'Style a canvas using two selected data sources.']
        :['title'=>'Two weather views','html'=>'<section class="view"><div class="sf-dataset-my_preferences"><div class="sf-data-row"><span class="sf-field-locale"></span></div></div></section>',
            'css'=>'.view{display:grid}'];
    return ['text'=>json_encode($reply,JSON_THROW_ON_ERROR),'provider'=>'openai','model'=>'fixture','input_tokens'=>10,'output_tokens'=>20];
}
function data_flow_check(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function data_flow_reject(callable $run,string $type,string $label):void {
    try{$run();}catch(Throwable $error){if($error instanceof $type){data_flow_check(true,$label);return;}throw $error;}
    throw new RuntimeException('Expected rejection: '.$label);
}
function data_flow_request():string {return str_pad(dechex(++$GLOBALS['serial']),32,'0',STR_PAD_LEFT);}
function data_flow_weather():array {
    return ['status'=>'fresh','fetched_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+600),
        'source'=>'National Weather Service','timezone'=>'America/Phoenix',
        'rows'=>[['temperature_f'=>94,'condition'=>'LIVE_READING_CANARY','observed_at'=>gmdate('c')]]];
}

require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';
require_once $root.'/api/canvas-gallery-core.php';
require_once $root.'/api/canvas-project-ideas.php';
require_once $root.'/api/canvas-data-core.php';
$db=sf_db();$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$db->exec($mysql?'CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) UNIQUE,display_name VARCHAR(190),password_hash VARCHAR(255),role VARCHAR(16),status VARCHAR(16),created_at VARCHAR(40),updated_at VARCHAR(40),last_login_at VARCHAR(40) NULL) ENGINE=InnoDB':
    'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
foreach([1,2,9] as $uid)$q->execute([$uid,'data'.$uid.'@example.invalid','Fixture account',
    password_hash('fixture',PASSWORD_DEFAULT),$uid===9?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
sf_ai_save_provider('openai',['enabled'=>true,'api_key'=>'fixture-only','model'=>'fixture']);
sf_token_adjust(1,1000000,'admin_grant','data-flow');
$owner=['id'=>1,'role'=>'user'];$other=['id'=>2,'role'=>'user'];$admin=['id'=>9,'role'=>'admin'];
$permission='canvas.data.weather_nws';
$setPolicy=static function(bool $enabled)use($admin,$permission):void {
    sf_canvas_permission_save($admin,sf_canvas_permission_policy()['revision'],[$permission=>$enabled]);
};
$phoenix=['alias'=>'phoenix','product'=>'weather.current','location_id'=>'city-0455000'];
$venue=['alias'=>'show','product'=>'weather.hourly','location_id'=>'show-flow-venue'];
$bindings=[$phoenix,$venue];
$brief='Make a visual with the two selected weather readings {data:phoenix:weather.current:city-0455000} {data:show:weather.hourly:show-flow-venue} {me:my_preferences}';
$before=sf_token_balance(1);

data_flow_reject(fn()=>sf_canvas_data_sources([$phoenix+['lat'=>33.4]]),InvalidArgumentException::class,
    'The attachment shape rejects caller-supplied coordinates');
data_flow_reject(fn()=>sf_canvas_data_sources([array_replace($phoenix,['product'=>'internal.url'])]),InvalidArgumentException::class,
    'An unregistered product cannot become a saved source');
data_flow_reject(fn()=>sf_canvas_data_sources([$phoenix,array_replace($venue,['alias'=>'phoenix'])]),InvalidArgumentException::class,
    'One alias cannot refer to two independent sources');
data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>$brief.' {data-clear}']]),InvalidArgumentException::class,
    'Composer attachment and clear commands cannot conflict');
data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>$brief,'artist_inputs'=>['data_sources'=>[$venue]]]]),InvalidArgumentException::class,
    'Structured scope cannot contradict the composer binding');
data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>'Use the hidden event {data:hidden:weather.current:show-flow-hidden}']]),InvalidArgumentException::class,
    'A hand-written token cannot resolve a hidden show');
data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>'Use a forged city {data:fake:weather.current:city-9999999}']]),InvalidArgumentException::class,
    'A well-formed but unknown place cannot enter project approval');

$proposal=sf_canvas_project_propose($owner,['project'=>['brief'=>$brief]]);
data_flow_check($proposal['project']['artist_inputs']['data_sources']===$bindings&&
    $proposal['project']['artist_inputs']['me_tools']===['my_preferences']&&$proposal['project']['track_id']===''&&
    !str_contains($proposal['project']['brief'],'{data:')&&$calls===0&&sf_token_balance(1)===$before&&sf_canvas_access_export(1)===[],
    'Two named sources attach alongside /me with no player song, inference, token spending or access grant');
sf_canvas_project_approve(1,$proposal['id']);
$build=$proposal['project']+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>data_flow_request()];
$changed=$build;
$changed['artist_inputs']['data_sources'][0]['location_id']='city-0477000';
data_flow_reject(fn()=>sf_agent_scene_create($owner,$changed),SfCanvasProjectApprovalRequired::class,
    'A different public place cannot enter an already approved build');
$changed=$build;
$changed['artist_inputs']['data_sources'][1]['product']='weather.daily';
data_flow_reject(fn()=>sf_agent_scene_create($owner,$changed),SfCanvasProjectApprovalRequired::class,
    'A product change cannot reuse approval for a different named source');
data_flow_check($calls===0&&sf_token_balance(1)===$before,'Scope mismatches stop before contacting a model or charging tokens');

$built=sf_agent_scene_create($owner,$build)['scene'];
$context=['scene_id'=>$built['id']];
$encoded=json_encode([$prompt,$built],JSON_THROW_ON_ERROR);
data_flow_check($built['artist_inputs']['data_sources']===$bindings&&str_contains($encoded,'weather.hourly')&&
    !str_contains($encoded,'PRIVATE_SHOW_CANARY')&&!str_contains($encoded,'LIVE_READING_CANARY')&&
    str_contains($instructions,'StonefellowData'),
    'Generation retains the approved data scope and receives the sandbox contract rather than weather or private venue rows');
$access=sf_canvas_access_summary($owner,$context);
$permissions=array_column($access['permissions'],null,'id');
data_flow_check(isset($permissions[$permission],$permissions['canvas.data.my_preferences'])&&
    $permissions[$permission]['enabled']&&$permissions[$permission]['granted']&&!$permissions[$permission]['personal']&&
    $access['requires_consent']&&sf_canvas_access_export(1)===[],
    'What this canvas uses declares public weather without granting private account fields');
data_flow_reject(fn()=>sf_canvas_access_change($owner,$context,'grant',$access['fingerprint'],[$permission]),InvalidArgumentException::class,
    'Public weather cannot masquerade as a personal consent grant');

$plan=sf_canvas_project_propose($owner,['project'=>['brief'=>'Add a caption','previous_id'=>$built['id'],'artist_inputs'=>['merch_ids'=>[]]]]);
data_flow_check($plan['project']['artist_inputs']['data_sources']===$bindings&&$plan['project']['artist_inputs']['me_tools']===['my_preferences'],
    'A revision editing another resource type inherits all saved weather aliases');
$clear=sf_canvas_project_propose($owner,['project'=>['brief'=>'Remove the weather dependency {data-clear}','previous_id'=>$built['id']]]);
data_flow_check($clear['project']['artist_inputs']['data_sources']===[]&&$clear['project']['artist_inputs']['me_tools']===['my_preferences'],
    'An explicit clear removes only external data bindings in the proposal');
sf_canvas_project_approve(1,$clear['id']);
$cleared=sf_agent_scene_create($owner,$clear['project']+['project_managed'=>true,'proposal_id'=>$clear['id'],'request_id'=>data_flow_request()])['scene'];
data_flow_check($cleared['artist_inputs']['data_sources']===[]&&
    !in_array($permission,array_column(sf_canvas_access_summary($owner,['scene_id'=>$cleared['id']])['permissions'],'id'),true),
    'A cleared revision removes the capability from its effective runtime scope');

$requests=[];
$balanceBeforeRead=sf_token_balance(1);
$modelCallsBeforeRead=$calls;
$weather=static function(float $lat,float $lon,string $product)use(&$requests):array {
    $requests[]=[$lat,$lon,$product];
    return data_flow_weather();
};
foreach([[$other,$context,'phoenix'],[[],$context,'phoenix'],[$owner,$context,'unknown'],[$owner,['scene_id'=>$cleared['id']],'phoenix']] as [$viewer,$scope,$alias]){
    data_flow_reject(fn()=>sf_canvas_data_read($viewer,$scope,$alias,$weather),OutOfBoundsException::class,
        'Runtime rejects a private or absent binding before fetching');
}
data_flow_check($requests===[],'Unauthorized identities, unbound names and clears never reach the provider');
$current=sf_canvas_data_read($owner,$context,'phoenix',$weather);
$forecast=sf_canvas_data_read($owner,$context,'show',$weather);
data_flow_check($current['status']==='fresh'&&$forecast['status']==='fresh'&&$current['rows'][0]['temperature_f']===94&&
    count($requests)===2&&abs($requests[0][0]-33.5)<0.4&&abs($requests[1][0]-32.2)<0.4&&
    $requests[0][2]==='current'&&$requests[1][2]==='hourly',
    'Two saved aliases fetch their independent approved products at their distinct canonical locations');
data_flow_check(sf_token_balance(1)===$balanceBeforeRead&&$calls===$modelCallsBeforeRead&&sf_canvas_access_export(1)===[],
    'Reading weather neither creates consent grants nor invokes the model again');

$setPolicy(false);
$disabled=sf_canvas_data_read($owner,$context,'phoenix',$weather);
data_flow_check($disabled['status']==='unavailable'&&$disabled['rows']===[]&&count($requests)===2&&
    array_column(sf_canvas_access_summary($owner,$context)['permissions'],'enabled','id')[$permission]===false,
    'A disabled provider remains disclosed but delivers no rows and calls no upstream');
data_flow_reject(fn()=>sf_canvas_project_approve(1,$plan['id']),InvalidArgumentException::class,
    'Pending approvals revalidate the provider policy');
$setPolicy(true);
$during=sf_canvas_data_read($owner,$context,'phoenix',static function()use($setPolicy):array {
    $setPolicy(false);
    return data_flow_weather();
});
data_flow_check($during['status']==='unavailable'&&$during['rows']===[],'An administrator disabling weather during a read blocks delivery of the fetched rows');
$setPolicy(true);

$moved=sf_canvas_data_read($owner,$context,'show',static function()use($shows):array {
    $shows[0]['city']='Phoenix';
    data_flow_save_shows($shows);
    return data_flow_weather();
});
data_flow_check($moved['status']==='unavailable'&&$moved['rows']===[],'A venue changing location during a read cannot deliver data for the old point');
data_flow_check(sf_canvas_data_read($owner,$context,'show',$weather)['status']==='fresh'&&abs($requests[2][0]-33.5)<0.4,
    'The next request resolves the current public venue city from storage');
$hiddenShows=$shows;$hiddenShows[0]['public_visible']=false;data_flow_save_shows($hiddenShows);
$hidden=sf_canvas_data_read($owner,$context,'show',$weather);
data_flow_check($hidden['status']==='unavailable'&&$hidden['rows']===[]&&count($requests)===3,
    'A withdrawn venue becomes unavailable without fetching its location');
data_flow_save_shows($shows);

$shareable=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Shareable weather',
    'html'=>'<p class="forecast">Fixture weather</p>','css'=>'','artist_inputs'=>['data_sources'=>$bindings]]);
$gallery=sf_canvas_gallery_submit($owner,['scene_id'=>$shareable['id'],'request_id'=>data_flow_request(),'title'=>'Shared weather',
    'description'=>'Two public locations','creator_label'=>'Fixture creator','publication_consent'=>true]);
$publicContext=['gallery_id'=>$gallery['id']];
data_flow_reject(fn()=>sf_canvas_data_read([],$publicContext,'phoenix',$weather),OutOfBoundsException::class,
    'Pending gallery entries do not expose a runtime binding');
sf_canvas_gallery_moderate($admin,$gallery['id'],'approve',$gallery['snapshot_hash']);
$public=sf_canvas_gallery_open($gallery['id']);
data_flow_check($public['scene']['artist_inputs']['data_sources']===$bindings&&
    in_array($permission,array_column($public['item']['uses'],'id'),true)&&
    !str_contains(sf_canvas_gallery_row($gallery['id'])['snapshot_json'],'LIVE_READING_CANARY'),
    'The public gallery snapshot preserves named scope and discloses weather without storing fetched rows');
data_flow_check(sf_canvas_data_read([],$publicContext,'phoenix',$weather)['rows'][0]['temperature_f']===94&&count($requests)===4,
    'An approved gallery exposes the same public binding to an anonymous viewer');
data_flow_reject(fn()=>sf_canvas_data_read([],$publicContext,'backstage',$weather),OutOfBoundsException::class,
    'Public gallery use never grants unlisted aliases');
$remix=sf_canvas_gallery_remix($other,$gallery['id'],data_flow_request());
data_flow_check($remix['scene']['artist_inputs']['data_sources']===$bindings&&
    sf_canvas_data_read($other,['scene_id'=>$remix['scene']['id']],'show',$weather)['status']==='fresh',
    'A remix retains the reviewed named scope on its own saved canvas');
data_flow_reject(fn()=>sf_canvas_data_read($owner,['scene_id'=>$remix['scene']['id']],'show',$weather),OutOfBoundsException::class,
    'A creator cannot read another account’s private remix');
sf_canvas_gallery_withdraw($owner,$gallery['id']);
data_flow_reject(fn()=>sf_canvas_data_read([],$publicContext,'phoenix',$weather),OutOfBoundsException::class,
    'A withdrawn gallery loses its runtime weather route');
data_flow_check(count($requests)===5&&!$db->inTransaction(),'Authorized and rejected flows leave no open transaction or stray provider calls');
echo 'Canvas external data flow: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
