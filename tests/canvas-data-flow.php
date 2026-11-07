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
    ['id'=>'flow-nearby','title'=>'Nearby show','venue'=>'Phoenix Stage','city'=>'Phoenix','region'=>'AZ','country'=>'US','date'=>'2099-01-02','status'=>'scheduled','public_visible'=>true,
        'archive_notes'=>'PRIVATE_SHOW_NOTE_CANARY','ticket_url'=>'https://example.invalid/private-note'],
    ['id'=>'flow-cancelled','title'=>'Cancelled show','venue'=>'Former Stage','city'=>'Phoenix','region'=>'AZ','country'=>'US','date'=>'2099-01-01','status'=>'cancelled','public_visible'=>true],
    ['id'=>'flow-past','title'=>'Earlier show','venue'=>'Past Stage','city'=>'Phoenix','region'=>'AZ','country'=>'US','date'=>'2000-01-01','status'=>'scheduled','public_visible'=>true],
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

$geoPermission='canvas.data.geography_public';
$composition=[
    ['alias'=>'anchor','product'=>'geography.place','location_id'=>'city-0455000'],
    ['alias'=>'shows','product'=>'geography.shows','location_id'=>'city-0455000'],
    ['alias'=>'forecast','product'=>'weather.hourly','location_id'=>'published-shows'],
    $phoenix,
];
data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>'Locate a place {data:anchor:geography.place:published-shows}']]),
    InvalidArgumentException::class,'A show-set weather scope cannot be attached as a single geography place');
$combined=sf_canvas_project_propose($owner,['project'=>['brief'=>'Build a tour display {data:anchor:geography.place:city-0455000} {data:shows:geography.shows:city-0455000} {data:forecast:weather.hourly:published-shows} {data:phoenix:weather.current:city-0455000}']]);
sf_canvas_project_approve(1,$combined['id']);
$combinedBuild=$combined['project']+['project_managed'=>true,'proposal_id'=>$combined['id'],'request_id'=>data_flow_request()];
$fixedOnly=$combinedBuild;
$fixedOnly['artist_inputs']['data_sources'][2]['location_id']='show-flow-nearby';
data_flow_reject(fn()=>sf_agent_scene_create($owner,$fixedOnly),SfCanvasProjectApprovalRequired::class,
    'An approved catalog scope cannot be silently replaced with a fixed show');
$combinedScene=sf_agent_scene_create($owner,$combinedBuild)['scene'];
$combinedContext=['scene_id'=>$combinedScene['id']];
$uses=array_column(sf_canvas_access_summary($owner,$combinedContext)['permissions'],null,'id');
data_flow_check($combinedScene['artist_inputs']['data_sources']===$composition&&
    isset($uses[$geoPermission],$uses[$permission])&&!$uses[$geoPermission]['personal']&&!$uses[$permission]['personal'],
    'Geography and weather save separate capabilities in one approved scene');
$anchor=sf_canvas_data_read($owner,$combinedContext,'anchor',$weather);
$locations=sf_canvas_data_read($owner,$combinedContext,'shows',$weather);
data_flow_check($anchor['status']==='fresh'&&count($anchor['rows'])===1&&
    abs($anchor['rows'][0]['lat']-33.5)<0.4&&abs($anchor['rows'][0]['lon']+112.0)<0.4&&count($requests)===5,
    'A selected city yields map coordinates without a weather provider request');
$rows=$locations['rows'];
data_flow_check($locations['status']==='fresh'&&array_column($rows,'location_id')===['show-flow-nearby','show-flow-venue']&&
    $rows[0]['distance_km']===0.0&&$rows[1]['distance_km']>100&&count($requests)===5&&
    !str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'PRIVATE_')&&!array_key_exists('ticket_url',$rows[0]),
    'Geography lists upcoming public shows nearest first with map fields, excluding private notes, past shows and cancelled shows');
data_flow_reject(fn()=>sf_canvas_data_read($other,$combinedContext,'shows',$weather),OutOfBoundsException::class,
    'Public map data does not expose another account’s private scene binding');
foreach([['forecast',null],['forecast','city-0455000'],['phoenix','show-flow-venue'],['anchor','show-flow-venue']] as [$alias,$location]){
    data_flow_reject(fn()=>sf_canvas_data_read($owner,$combinedContext,$alias,$weather,$location),InvalidArgumentException::class,
        'A scope requires a show ID and a fixed binding rejects location overrides');
}
foreach(['show-flow-hidden','show-flow-cancelled','show-flow-past','show-flow-missing'] as $location){
    $absent=sf_canvas_data_read($owner,$combinedContext,'forecast',$weather,$location);
    data_flow_check($absent['status']==='unavailable'&&$absent['rows']===[]&&count($requests)===5,
        'Ineligible show ID cannot trigger weather or expose data');
}
$composed=sf_canvas_data_read($owner,$combinedContext,'forecast',$weather,$rows[1]['location_id']);
data_flow_check($composed['status']==='fresh'&&$composed['location']['id']==='show-flow-venue'&&count($requests)===6&&
    abs($requests[5][0]-32.2)<0.4&&$requests[5][2]==='hourly',
    'A canonical location from geography resolves through the separately attached weather binding');

$setGeography=static function(bool $enabled)use($admin,$geoPermission):void {
    sf_canvas_permission_save($admin,sf_canvas_permission_policy()['revision'],[$geoPermission=>$enabled]);
};
$setGeography(false);
$products=array_column(sf_canvas_data_inventory()['products'],'id');
$disabledMap=sf_canvas_data_read($owner,$combinedContext,'shows',$weather);
data_flow_check(!in_array('geography.shows',$products,true)&&in_array('weather.hourly',$products,true)&&
    $disabledMap['status']==='unavailable'&&$disabledMap['rows']===[]&&
    sf_canvas_data_read($owner,$combinedContext,'forecast',$weather,$rows[0]['location_id'])['status']==='fresh'&&count($requests)===7,
    'Geography can be disabled independently of an authorized weather scope');
$setGeography(true);
$setPolicy(false);
$disabledForecast=sf_canvas_data_read($owner,$combinedContext,'forecast',$weather,$rows[1]['location_id']);
data_flow_check($disabledForecast['status']==='unavailable'&&$disabledForecast['rows']===[]&&count($requests)===7&&
    sf_canvas_data_read($owner,$combinedContext,'shows',$weather)['rows']===$rows,
    'Disabling weather leaves the map and published show locations usable');
$setPolicy(true);
$cancelledDuringRead=sf_canvas_data_read($owner,$combinedContext,'forecast',static function()use($shows):array {
    $shows[0]['status']='cancelled';
    data_flow_save_shows($shows);
    return data_flow_weather();
},$rows[1]['location_id']);
data_flow_check($cancelledDuringRead['status']==='unavailable'&&$cancelledDuringRead['rows']===[],
    'Cancellation during scoped weather fetch prevents delivery of its stale authorization');
data_flow_save_shows($shows);

$composedShareable=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Shareable tour map',
    'html'=>'<p class="map">Fixture map</p>','css'=>'','artist_inputs'=>['data_sources'=>$composition]]);
$composedGallery=sf_canvas_gallery_submit($owner,['scene_id'=>$composedShareable['id'],'request_id'=>data_flow_request(),
    'title'=>'Shared map','description'=>'Map and forecast','creator_label'=>'Fixture creator','publication_consent'=>true]);
sf_canvas_gallery_moderate($admin,$composedGallery['id'],'approve',$composedGallery['snapshot_hash']);
$composedOpen=sf_canvas_gallery_open($composedGallery['id']);
$composedPublic=['gallery_id'=>$composedGallery['id']];
$publicRows=sf_canvas_data_read([],$composedPublic,'shows',$weather)['rows'];
data_flow_check($composedOpen['scene']['artist_inputs']['data_sources']===$composition&&
    !array_diff([$geoPermission,$permission],array_column($composedOpen['item']['uses'],'id'))&&$publicRows===$rows&&
    sf_canvas_data_read([],$composedPublic,'forecast',$weather,$publicRows[0]['location_id'])['status']==='fresh',
    'An approved gallery lets a guest compose map and scoped weather from its reviewed manifest');
data_flow_check(count($requests)===8&&!$db->inTransaction(),'Mixed-service runtime and rejection paths leave transactions and provider counts consistent');

$mapPermission='canvas.data.map_public';
$locationPermission='canvas.data.location_proximity';
$target='point-33448400_-112074000_500';
$spatial=[
    ['alias'=>'city-outline','product'=>'map.boundaries','location_id'=>'city-0455000'],
    ['alias'=>'venue-outline','product'=>'map.boundaries','location_id'=>'show-flow-venue'],
    ['alias'=>'regions','product'=>'map.boundaries','location_id'=>'us-regions'],
    ['alias'=>'entrance','product'=>'location.proximity','location_id'=>$target],
];
foreach([
    ['location.proximity','city-0455000'],
    ['location.proximity','point-90000001_0_500'],
    ['location.proximity','point-0_-180000001_500'],
    ['location.proximity','point-33448400_-112074000_9'],
    ['location.proximity','point-33448400_-112074000_10001'],
    ['location.proximity','point-033448400_-112074000_500'],
    ['location.proximity','point--0_0_500'],
    ['location.proximity','point-33448400__500'],
    ['map.boundaries',$target],
    ['map.boundaries','published-shows'],
    ['map.boundaries','show-flow-hidden'],
] as [$product,$location]){
    data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>'Use a spatial source',
        'artist_inputs'=>['data_sources'=>[['alias'=>'spatial','product'=>$product,'location_id'=>$location]]]]]),
        InvalidArgumentException::class,'Malformed, out-of-range and unapproved spatial targets are rejected');
}
$spatialBrief='Combine regional outlines and a public proximity target';
foreach($spatial as $source)$spatialBrief.=' {data:'.$source['alias'].':'.$source['product'].':'.$source['location_id'].'}';
$spatialProposal=sf_canvas_project_propose($owner,['project'=>['brief'=>$spatialBrief]]);
sf_canvas_project_approve(1,$spatialProposal['id']);
$spatialBuild=$spatialProposal['project']+['project_managed'=>true,'proposal_id'=>$spatialProposal['id'],'request_id'=>data_flow_request()];
$changedRadius=$spatialBuild;
$changedRadius['artist_inputs']['data_sources'][3]['location_id']='point-33448400_-112074000_501';
data_flow_reject(fn()=>sf_agent_scene_create($owner,$changedRadius),SfCanvasProjectApprovalRequired::class,
    'An approved proximity target cannot silently grow or change coordinates');
$spatialScene=sf_agent_scene_create($owner,$spatialBuild)['scene'];
$spatialContext=['scene_id'=>$spatialScene['id']];
$spatialUses=array_column(sf_canvas_access_summary($owner,$spatialContext)['permissions'],null,'id');
data_flow_check($spatialScene['artist_inputs']['data_sources']===$spatial&&
    isset($spatialUses[$mapPermission],$spatialUses[$locationPermission])&&
    empty($spatialUses[$mapPermission]['device_consent'])&&($spatialUses[$locationPermission]['device_consent']??false)===true,
    'Map and proximity sources save separately and disclose the device permission boundary');

$cityBoundary=sf_canvas_data_read($owner,$spatialContext,'city-outline',$weather);
$venueBoundary=sf_canvas_data_read($owner,$spatialContext,'venue-outline',$weather);
$firstChunk=$cityBoundary['rows'][0]??[];
$pairs=json_decode($firstChunk['coordinates']??'[]',true,5,JSON_THROW_ON_ERROR);
data_flow_check($cityBoundary['status']==='fresh'&&$venueBoundary['status']==='fresh'&&$cityBoundary['rows']!==[]&&
    array_values(array_unique(array_column($cityBoundary['rows'],'region')))===['AZ']&&
    $cityBoundary['rows']===$venueBoundary['rows']&&
    isset($firstChunk['name'],$firstChunk['part'],$firstChunk['ring'],$firstChunk['chunk'],$firstChunk['coordinates'])&&
    is_array($pairs)&&count($pairs)>=4&&is_array($pairs[0])&&count($pairs[0])===2&&
    is_numeric($pairs[0][0])&&is_numeric($pairs[0][1]),
    'A city and a public venue resolve to the same selected regional outline and geometry chunks');
$regions=sf_canvas_data_read($owner,$spatialContext,'regions',$weather);
$regionCodes=array_unique(array_column($regions['rows'],'region'));
data_flow_check($regions['status']==='fresh'&&count($regionCodes)>=52&&in_array('AZ',$regionCodes,true)&&
    in_array('AK',$regionCodes,true)&&in_array('HI',$regionCodes,true),
    'The explicitly selected regional overview includes the full national outline set');
$publicTarget=sf_canvas_data_read($owner+['lat'=>12.34,'lon'=>56.78],$spatialContext,'entrance',$weather);
$targetRow=$publicTarget['rows'][0]??[];
data_flow_check($publicTarget['status']==='fresh'&&count($publicTarget['rows'])===1&&
    abs(($targetRow['lat']??0)-33.4484)<0.000001&&abs(($targetRow['lon']??0)+112.074)<0.000001&&
    ($targetRow['radius_m']??null)===500&&count($requests)===8,
    'The proximity endpoint returns the saved public target and radius without using viewer coordinates or fetching weather');
foreach(['city-outline','regions','entrance'] as $alias){
    data_flow_reject(fn()=>sf_canvas_data_read($owner,$spatialContext,$alias,$weather,'city-0477000'),
        InvalidArgumentException::class,'Spatial runtime aliases reject a caller-supplied location override');
}
data_flow_reject(fn()=>sf_canvas_data_read($other,$spatialContext,'entrance',$weather),OutOfBoundsException::class,
    'A viewer cannot inspect a private spatial binding owned by another account');

$spatialShareable=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Shareable spatial canvas',
    'html'=>'<p class="map">Fixture map</p>','css'=>'','artist_inputs'=>['data_sources'=>$spatial]]);
$spatialGallery=sf_canvas_gallery_submit($owner,['scene_id'=>$spatialShareable['id'],'request_id'=>data_flow_request(),
    'title'=>'Shared spatial canvas','description'=>'Map and proximity target','creator_label'=>'Fixture creator','publication_consent'=>true]);
$spatialPublic=['gallery_id'=>$spatialGallery['id']];
data_flow_reject(fn()=>sf_canvas_data_read([],$spatialPublic,'entrance',$weather),OutOfBoundsException::class,
    'Pending gallery entries cannot expose spatial bindings');
sf_canvas_gallery_moderate($admin,$spatialGallery['id'],'approve',$spatialGallery['snapshot_hash']);
$spatialOpen=sf_canvas_gallery_open($spatialGallery['id']);
data_flow_check($spatialOpen['scene']['artist_inputs']['data_sources']===$spatial&&
    !array_diff([$mapPermission,$locationPermission],array_column($spatialOpen['item']['uses'],'id'))&&
    sf_canvas_data_read([],$spatialPublic,'venue-outline',$weather)['rows']===$venueBoundary['rows']&&
    sf_canvas_data_read([],$spatialPublic,'entrance',$weather)['rows']===$publicTarget['rows'],
    'Approved gallery guests can read the same public outline and target without access to a viewer location');
$spatialPolicy=static function(string $permission,bool $enabled)use($admin):void {
    sf_canvas_permission_save($admin,sf_canvas_permission_policy()['revision'],[$permission=>$enabled]);
};
$spatialPolicy($mapPermission,false);
$mapOff=sf_canvas_data_read($owner,$spatialContext,'regions',$weather);
$products=array_column(sf_canvas_data_inventory()['products'],'id');
data_flow_check($mapOff['status']==='unavailable'&&$mapOff['rows']===[]&&!in_array('map.boundaries',$products,true)&&
    in_array('location.proximity',$products,true)&&in_array('geography.shows',$products,true)&&
    sf_canvas_data_read([],$spatialPublic,'entrance',$weather)['rows']===$publicTarget['rows'],
    'Map boundaries can be disabled independently of public geography and proximity');
$spatialPolicy($mapPermission,true);
$spatialPolicy($locationPermission,false);
$locationOff=sf_canvas_data_read([],$spatialPublic,'entrance',$weather);
data_flow_check($locationOff['status']==='unavailable'&&$locationOff['rows']===[]&&
    sf_canvas_data_read([],$spatialPublic,'venue-outline',$weather)['rows']===$venueBoundary['rows'],
    'Disabling proximity withholds the target and preserves separate map bindings');
$spatialPolicy($locationPermission,true);
data_flow_check(count($requests)===8&&!$db->inTransaction(),'All spatial paths are local and leave no open transaction');

$earthquakePermission='canvas.data.earthquakes_usgs';
$earthquakeBindings=[
    ['alias'=>'recent-quakes','product'=>'earthquakes.recent','location_id'=>'m45-day'],
    ['alias'=>'week-quakes','product'=>'earthquakes.recent','location_id'=>'m25-week'],
    $spatial[2],
];
$quakeBrief='Plot two earthquake feeds over regional boundaries {data:recent-quakes:earthquakes.recent:m45-day} '.
    '{data:week-quakes:earthquakes.recent:m25-week} {data:regions:map.boundaries:us-regions}';
$quakeReadings=[['event_id'=>'fixture-quake','place'=>'USGS_LIVE_FIXTURE','magnitude'=>4.8,'magnitude_type'=>'mw',
    'lat'=>34.1,'lon'=>-117.4,'depth_km'=>8.4,'occurred_at'=>'2026-11-06T17:00:00+00:00',
    'updated_at'=>'2026-11-06T17:04:00+00:00','review_status'=>'reviewed',
    'source_url'=>'https://earthquake.usgs.gov/earthquakes/eventpage/fixture-quake']];
$quakeRequests=[];
$quakeRead=static function(string $feed)use(&$quakeRequests,$quakeReadings):array {
    $quakeRequests[]=$feed;
    $stale=$feed==='m25-week';
    return ['status'=>$stale?'stale':'fresh','source'=>SF_CANVAS_EARTHQUAKES_SOURCE,'timezone'=>'UTC',
        'fetched_at'=>gmdate('c',time()-($stale?600:10)),'expires_at'=>gmdate('c',time()+($stale?-540:50)),
        'rows'=>$quakeReadings];
};
$quakeProduct=array_column(sf_canvas_data_inventory()['products'],null,'id')['earthquakes.recent'];
$quakeScopes=array_column($quakeProduct['parameters']['location_id']['scope_options'],'id');
sort($quakeScopes);
data_flow_check($quakeScopes===['m25-day','m25-week','m45-day','m45-week']&&$quakeProduct['permission_id']===$earthquakePermission,
    'Earthquake discovery declares four fixed public USGS feeds and its own permission');
foreach(['city-0455000','show-flow-venue','published-shows','m99-week','https://example.invalid/feeds'] as $forged){
    data_flow_reject(fn()=>sf_canvas_project_propose($owner,['project'=>['brief'=>'Use a quake feed',
        'artist_inputs'=>['data_sources'=>[['alias'=>'quakes','product'=>'earthquakes.recent','location_id'=>$forged]]]]]),
        InvalidArgumentException::class,'An earthquake attachment rejects arbitrary feeds, venues and coordinates');
}
$quakePlan=sf_canvas_project_propose($owner,['project'=>['brief'=>$quakeBrief]]);
sf_canvas_project_approve(1,$quakePlan['id']);
$quakeBuild=$quakePlan['project']+['project_managed'=>true,'proposal_id'=>$quakePlan['id'],'request_id'=>data_flow_request()];
$changedFeed=$quakeBuild;
$changedFeed['artist_inputs']['data_sources'][0]['location_id']='m45-week';
data_flow_reject(fn()=>sf_agent_scene_create($owner,$changedFeed),SfCanvasProjectApprovalRequired::class,
    'A different feed cannot reuse approval for a saved alias');
$quakeScene=sf_agent_scene_create($owner,$quakeBuild)['scene'];
$quakeContext=['scene_id'=>$quakeScene['id']];
$quakeUses=array_column(sf_canvas_access_summary($owner,$quakeContext)['permissions'],null,'id');
data_flow_check($quakeScene['artist_inputs']['data_sources']===$earthquakeBindings&&isset($quakeUses[$earthquakePermission],$quakeUses[$mapPermission])&&
    $quakeUses[$earthquakePermission]['granted']&&!$quakeUses[$earthquakePermission]['personal']&&$quakeRequests===[]&&
    !str_contains(json_encode([$prompt,$quakeScene],JSON_THROW_ON_ERROR),'USGS_LIVE_FIXTURE'),
    'A generated canvas can combine earthquake feeds and map geometry without fetching or persisting quake readings');
data_flow_reject(fn()=>sf_canvas_data_read($other,$quakeContext,'recent-quakes',$quakeRead),OutOfBoundsException::class,
    'A viewer cannot read another account’s private earthquake binding');
data_flow_reject(fn()=>sf_canvas_data_read($owner,$quakeContext,'recent-quakes',$quakeRead,'m45-week'),InvalidArgumentException::class,
    'A runtime caller cannot override a fixed earthquake feed');
$quakeModelCalls=$calls;$quakeTokenBalance=sf_token_balance(1);
$recent=sf_canvas_data_read($owner,$quakeContext,'recent-quakes',$quakeRead);
$stale=sf_canvas_data_read($owner,$quakeContext,'week-quakes',$quakeRead);
data_flow_check($recent['status']==='fresh'&&$stale['status']==='stale'&&$recent['rows']===$quakeReadings&&$stale['rows']===$quakeReadings&&
    $stale['location']['id']==='m25-week'&&$stale['fetched_at']<$recent['fetched_at']&&$stale['expires_at']<gmdate('c')&&
    $quakeRequests===['m45-day','m25-week']&&
    sf_canvas_data_read($owner,$quakeContext,'regions',$quakeRead)['rows']===$regions['rows']&&
    $calls===$quakeModelCalls&&sf_token_balance(1)===$quakeTokenBalance,
    'Saved aliases select distinct feeds, preserve stale readings and compose with the local map without AI charges');

$quakeShareable=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Shareable quake map',
    'html'=>'<p class="map">Fixture earthquake map</p>','css'=>'','artist_inputs'=>['data_sources'=>$earthquakeBindings]]);
$quakeGallery=sf_canvas_gallery_submit($owner,['scene_id'=>$quakeShareable['id'],'request_id'=>data_flow_request(),
    'title'=>'Shared quake map','description'=>'Approved feeds and public map','creator_label'=>'Fixture creator','publication_consent'=>true]);
$quakePublic=['gallery_id'=>$quakeGallery['id']];
data_flow_reject(fn()=>sf_canvas_data_read([],$quakePublic,'recent-quakes',$quakeRead),OutOfBoundsException::class,
    'A pending gallery cannot read earthquake feeds');
sf_canvas_gallery_moderate($admin,$quakeGallery['id'],'approve',$quakeGallery['snapshot_hash']);
$quakeOpen=sf_canvas_gallery_open($quakeGallery['id']);
data_flow_check($quakeOpen['scene']['artist_inputs']['data_sources']===$earthquakeBindings&&
    in_array($earthquakePermission,array_column($quakeOpen['item']['uses'],'id'),true)&&
    !str_contains(sf_canvas_gallery_row($quakeGallery['id'])['snapshot_json'],'USGS_LIVE_FIXTURE')&&
    sf_canvas_data_read([],$quakePublic,'recent-quakes',$quakeRead)['rows']===$quakeReadings&&
    $quakeRequests===['m45-day','m25-week','m45-day'],
    'Publication discloses the approved earthquake source without storing live readings');
$quakeRevision=sf_canvas_project_propose($owner,['project'=>['brief'=>'Improve marker contrast','previous_id'=>$quakeScene['id']]]);
$quakeDuring=sf_canvas_data_read([],$quakePublic,'week-quakes',static function(string $feed)use($quakeRead,$spatialPolicy,$earthquakePermission):array {
    $reading=$quakeRead($feed);
    $spatialPolicy($earthquakePermission,false);
    return $reading;
});
$quakeOff=sf_canvas_data_read($owner,$quakeContext,'recent-quakes',$quakeRead);
data_flow_check($quakeDuring['status']==='unavailable'&&$quakeDuring['rows']===[]&&$quakeOff['status']==='unavailable'&&$quakeOff['rows']===[]&&
    !in_array('earthquakes.recent',array_column(sf_canvas_data_inventory()['products'],'id'),true)&&
    sf_canvas_data_read([],$quakePublic,'regions',$quakeRead)['rows']===$regions['rows']&&
    $quakeRequests===['m45-day','m25-week','m45-day','m25-week'],
    'Disabling USGS prevents in-flight stale rows and later fetches while keeping the shared map usable');
data_flow_reject(fn()=>sf_canvas_project_approve(1,$quakeRevision['id']),InvalidArgumentException::class,
    'An unapproved revision revalidates the inherited USGS policy');
$spatialPolicy($earthquakePermission,true);
sf_canvas_gallery_withdraw($owner,$quakeGallery['id']);
data_flow_reject(fn()=>sf_canvas_data_read([],$quakePublic,'week-quakes',$quakeRead),OutOfBoundsException::class,
    'Withdrawing the quake map revokes guest access to its saved feed aliases');
data_flow_check($quakeRequests===['m45-day','m25-week','m45-day','m25-week']&&count($requests)===8&&!$db->inTransaction(),
    'Quake requests are isolated from other providers and leave no open transaction');
echo 'Canvas external data flow: '.$checks.' checks passed ('.($mysql?'mysql':'sqlite').')'.PHP_EOL;
