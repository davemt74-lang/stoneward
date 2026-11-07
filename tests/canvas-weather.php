<?php
declare(strict_types=1);

require __DIR__.'/../api/canvas-weather-core.php';

$root=sys_get_temp_dir().'/stonefellow-weather-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700))throw new RuntimeException('Could not create weather fixture storage.');
register_shutdown_function(static function()use($root):void {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){
        if($file->isDir())rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($root);
});

$checks=0;
function wx_check(bool $ok,string $message):void {
    global $checks;
    if(!$ok)throw new RuntimeException($message);
    $checks++;
    echo 'PASS '.$message.PHP_EOL;
}
function wx_ok(array $body,?int $age=null,array $extra=[]):array {
    return $extra+['code'=>200,'body'=>$body,'max_age'=>$age];
}
function wx_point(string $office='PSR',int $x=100,int $y=50):array {
    return wx_ok(['properties'=>['gridId'=>$office,'gridX'=>$x,'gridY'=>$y,'timeZone'=>'America/Phoenix',
        'forecast'=>'https://invalid.example/bad','observationStations'=>'https://invalid.example/bad']]);
}
function wx_stations(string ...$ids):array {
    return wx_ok(['features'=>array_map(static fn(string $id):array=>[
        'id'=>'https://invalid.example/'.$id,'properties'=>['stationIdentifier'=>$id]],$ids)]);
}
function wx_observation(int $when,?float $temperature=34.4,array $extra=[],array $headers=[]):array {
    return wx_ok(['properties'=>$extra+[
        'timestamp'=>gmdate('c',$when),'temperature'=>['value'=>$temperature,'unitCode'=>'wmoUnit:degC','qualityControl'=>'V'],
        'textDescription'=>'Clear','relativeHumidity'=>['value'=>24.8,'unitCode'=>'wmoUnit:percent'],
        'heatIndex'=>['value'=>35,'unitCode'=>'wmoUnit:degC'],
        'windSpeed'=>['value'=>16.09344,'unitCode'=>'wmoUnit:km_h-1'],
        'windDirection'=>['value'=>270,'unitCode'=>'wmoUnit:degree_(angle)'],
        'precipitationLastHour'=>['value'=>0.00254,'unitCode'=>'wmoUnit:m'],
    ]],30,$headers);
}
function wx_period(string $start,string $end,int|array $temperature=94):array {
    return ['name'=>'Today','startTime'=>$start,'endTime'=>$end,'temperature'=>$temperature,'temperatureUnit'=>'F','isDaytime'=>true,
        'shortForecast'=>'Sunny','detailedForecast'=>'Clear and sunny.','windSpeed'=>'5 to 10 mph','windDirection'=>'NW',
        'probabilityOfPrecipitation'=>['value'=>8,'unitCode'=>'wmoUnit:percent']];
}
function wx_alert(int $now,string $id='urn:oid:actual',string $status='Actual',string $type='Alert',?int $end=null):array {
    return ['properties'=>['id'=>$id,'status'=>$status,'messageType'=>$type,'event'=>'Heat Advisory','severity'=>'Moderate',
        'certainty'=>'Likely','urgency'=>'Expected','headline'=>'Heat advisory','description'=>'Very hot weather.',
        'instruction'=>'Drink plenty of water.','sent'=>gmdate('c',$now-600),'effective'=>gmdate('c',$now-300),
        'onset'=>gmdate('c',$now+300),'expires'=>gmdate('c',$now+600),'ends'=>$end===null?null:gmdate('c',$end)]];
}

$now=strtotime('2026-11-07T21:00:00+00:00');
$paths=[];
$mode='normal';
$sample=$now-1800;
$transport=static function(string $path)use(&$paths,&$mode,&$sample):array {
    $paths[]=$path;
    return match($path){
        '/points/33.4484,-112.0740'=>wx_point(),
        '/gridpoints/PSR/100,50/stations'=>wx_stations('unsafe/../station','KPHX','KDVT'),
        '/stations/KPHX/observations/latest?require_qc=true'=>match($mode){
            'failure'=>['code'=>503],
            'redirect'=>['code'=>302,'location'=>'https://invalid.example/bad'],
            'no_store'=>wx_observation($sample,34.4,[],['no_store'=>true]),
            'must_revalidate'=>wx_observation($sample,34.4,[],['no_stale'=>true]),
            'future'=>wx_observation($sample+1),
            'unknown_unit'=>wx_observation($sample,34.4,['temperature'=>['unitCode'=>'wmoUnit:K','value'=>307.5]]),
            default=>wx_observation($sample),
        },
        '/stations/KDVT/observations/latest?require_qc=true'=>['code'=>503],
        default=>throw new RuntimeException('Unexpected request path '.$path),
    };
};
$first=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/current');
wx_check($first['status']==='fresh'&&$first['source']==='National Weather Service'&&$first['timezone']==='America/Phoenix','Fresh observation exposes official attribution and point time zone');
wx_check($first['rows'][0]['temperature_f']===94&&$first['rows'][0]['temperature_c']===34.4&&$first['rows'][0]['observed_at']===gmdate('c',$sample),'Current temperature normalizes to a whole 94 °F, with Celsius and UTC observation time');
wx_check($first['rows'][0]['feels_like_f']===95&&$first['rows'][0]['wind_mph']===10.0&&$first['rows'][0]['precipitation_1h_inches']===0.1&&$first['rows'][0]['relative_humidity_percent']===24.8,'Supported observed quantities normalize with explicit units');
wx_check($first['expires_at']===gmdate('c',$now+30)&&count($paths)===3&&!str_contains(implode(' ',$paths),'invalid.example'),'Provider cache age bounds freshness and links never determine request destinations');
$hit=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+29,$root.'/current');
wx_check($hit['rows']==$first['rows']&&$hit['fetched_at']===$first['fetched_at']&&count($paths)===3,'Two reads of the same point/product share one cache');
$mode='failure';
$stale=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+31,$root.'/current');
wx_check($stale['status']==='stale'&&$stale['expires_at']===$first['expires_at']&&$stale['rows'][0]['temperature_f']===94,'Upstream failure can display an existing stale reading without authorizing fresh-temperature rules');
$requests=count($paths);
sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+35,$root.'/current');
wx_check(count($paths)===$requests,'Short retries after provider failure are suppressed');
$mode='normal';
$sample=$now+62;
$recovered=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+62,$root.'/current');
wx_check($recovered['status']==='fresh'&&$recovered['fetched_at']===gmdate('c',$now+62),'Valid observation recovers after negative backoff');
$mode='future';$sample=$now;
$future=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/future');
wx_check($future['status']==='unavailable'&&$future['rows']===[],'A future provider observation cannot become fresh');
$mode='unknown_unit';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/units')['status']==='unavailable','Unknown or malformed observed units fail closed');
$mode='normal';$sample=$now-7200;
$old=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/old');
wx_check($old['status']==='stale'&&$old['expires_at']===gmdate('c',$sample+5400),'Provider HTTP success does not make an old observation fresh');
$sample=$now-22000;
wx_check(sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/discard')['status']==='unavailable','An observation older than the display retention bound is discarded');
$sample=$now;$mode='redirect';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/redirect')['status']==='unavailable','Provider redirects are never followed');
$mode='no_store';$start=count($paths);
sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/no-store');
sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+1,$root.'/no-store');
wx_check(count($paths)===$start+4&&glob($root.'/no-store/result-*.json')===[],'No-store responses are read live and never persisted as stale bodies');
$mode='must_revalidate';
sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now,$root.'/no-stale');
$mode='failure';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+31,$root.'/no-stale')['status']==='unavailable','Provider must-revalidate response cannot replay after expiry');

$mode='normal';$sample=$now;
$heldPath=$root.'/current/result-'.substr(hash('sha256','current:33.4484,-112.0740'),0,2).'.lock';
$hold=fopen($heldPath,'c');
if($hold===false||!flock($hold,LOCK_EX))throw new RuntimeException('Could not lock weather fixture cache.');
$before=count($paths);
$locked=sf_canvas_weather_read(33.4484,-112.074,'current',$transport,$now+100,$root.'/current');
fclose($hold);
wx_check($locked['status']==='stale'&&count($paths)===$before,'Concurrent refresh uses a stale cache without duplicating upstream calls');

$fallback=static function(string $path)use($now):array {
    return match($path){
        '/points/33.4484,-112.0740'=>wx_point(),
        '/gridpoints/PSR/100,50/stations'=>wx_stations('KPHX','KDVT'),
        '/stations/KPHX/observations/latest?require_qc=true'=>wx_observation($now-7200),
        '/stations/KDVT/observations/latest?require_qc=true'=>wx_observation($now-900,33.1),
        default=>throw new RuntimeException('Unexpected fallback path '.$path),
    };
};
$alternative=sf_canvas_weather_read(33.4484,-112.074,'current',$fallback,$now,$root.'/station-fallback');
wx_check($alternative['status']==='fresh'&&$alternative['rows'][0]['station_id']==='KDVT'&&$alternative['rows'][0]['temperature_f']===92,'A nearby second station can replace stale first-station data');

$forecastCalls=[];
$forecast=static function(string $path)use(&$forecastCalls,$now):array {
    $forecastCalls[]=$path;
    if($path==='/points/33.4484,-112.0740')return wx_point();
    if($path==='/points/32.2226,-110.9747')return wx_point('TWC',41,61);
    $temperature=match($path){
        '/gridpoints/PSR/100,50/forecast'=>94,
        '/gridpoints/PSR/100,50/forecast/hourly'=>['value'=>34.4,'unitCode'=>'wmoUnit:degC'],
        '/gridpoints/TWC/41,61/forecast'=>88,
        default=>throw new RuntimeException('Unexpected forecast path '.$path),
    };
    return wx_ok(['properties'=>['updateTime'=>gmdate('c',$now-1800),'periods'=>[
        wx_period(gmdate('c',$now-7200),gmdate('c',$now-3600),60),
        wx_period('2026-11-07T13:30:00-07:00','2026-11-07T14:30:00-07:00',$temperature),
    ]]],90);
};
$phoenix=sf_canvas_weather_read(33.4484,-112.074,'daily',$forecast,$now,$root.'/forecasts');
$tucson=sf_canvas_weather_read(32.2226,-110.9747,'daily',$forecast,$now,$root.'/forecasts');
$hourly=sf_canvas_weather_read(33.4484,-112.074,'hourly',$forecast,$now,$root.'/forecasts');
wx_check($phoenix['status']==='fresh'&&$tucson['status']==='fresh'&&$hourly['status']==='fresh'&&
    $phoenix['rows'][0]['temperature_f']===94&&$tucson['rows'][0]['temperature_f']===88&&$hourly['rows'][0]['temperature_f']===94,
    'Independent places and products return their own normalized forecast rows');
wx_check(count($forecastCalls)===5&&count($phoenix['rows'])===1&&$hourly['rows'][0]['start_at']==='2026-11-07T20:30:00+00:00'&&
    $hourly['rows'][0]['is_daytime']===true&&$hourly['rows'][0]['precipitation_probability_percent']===8.0,
    'Forecast products share point metadata and expose valid-time intervals and precipitation');
$shared=$root.'/parallel';
mkdir($shared,0700);
$peerCode=<<<'PHP_PEER'
require $argv[1];
$dir=$argv[2];
$now=(int)$argv[3];
$reply=sf_canvas_weather_read(33.4484,-112.074,'daily',static function(string $path)use($dir,$now):array {
    if($path==='/points/33.4484,-112.0740'){
        touch($dir.'/point-fetching');
        usleep(500_000);
        $body=['properties'=>['gridId'=>'PSR','gridX'=>100,'gridY'=>50,'timeZone'=>'America/Phoenix']];
    }elseif($path==='/gridpoints/PSR/100,50/forecast'){
        $body=['properties'=>['updateTime'=>gmdate('c',$now-600),'periods'=>[[
            'startTime'=>gmdate('c',$now),'endTime'=>gmdate('c',$now+3600),'temperature'=>93,'temperatureUnit'=>'F',
        ]]]];
    }else throw new RuntimeException('Unexpected peer request: '.$path);
    return ['code'=>200,'body'=>$body];
},$now,$dir);
echo json_encode($reply,JSON_THROW_ON_ERROR);
PHP_PEER;
$peer=proc_open([PHP_BINARY,'-r',$peerCode,realpath(__DIR__.'/../api/canvas-weather-core.php'),$shared,(string)$now],
    [1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($peer))throw new RuntimeException('Could not launch weather concurrency fixture.');
try{
    $deadline=microtime(true)+5;
    while(!file_exists($shared.'/point-fetching')&&microtime(true)<$deadline)usleep(10_000);
    wx_check(file_exists($shared.'/point-fetching'),'Concurrent fixture starts the first cold point lookup');
    $hourlyPaths=[];
    $concurrent=sf_canvas_weather_read(33.4484,-112.074,'hourly',static function(string $path)use(&$hourlyPaths,$now):array {
        $hourlyPaths[]=$path;
        return wx_ok(['properties'=>['updateTime'=>gmdate('c',$now-600),'periods'=>[
            wx_period(gmdate('c',$now),gmdate('c',$now+3600)),
        ]]]);
    },$now,$shared);
    $dailyPeer=json_decode(stream_get_contents($pipes[1]),true,16,JSON_THROW_ON_ERROR);
    wx_check($concurrent['status']==='fresh'&&$concurrent['rows'][0]['temperature_f']===94&&
        $dailyPeer['status']==='fresh'&&$dailyPeer['rows'][0]['temperature_f']===93&&
        $hourlyPaths===['/gridpoints/PSR/100,50/forecast/hourly'],
        'Concurrent cold products reuse one point lookup and deliver both independent forecasts');
}finally{
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($peer);
}

$staleForecast=static fn(string $path):array=>$path[1]==='p'?wx_point():wx_ok(['properties'=>[
    'updateTime'=>gmdate('c',$now-90000),'periods'=>[wx_period(gmdate('c',$now),gmdate('c',$now+3600))],
]]);
wx_check(sf_canvas_weather_read(33.4484,-112.074,'daily',$staleForecast,$now,$root.'/old-forecast')['status']==='unavailable','Old forecast issuance cannot become fresh just because its periods are in the future');
$badPoint=static function(string $path):array {
    if(!str_starts_with($path,'/points/'))throw new RuntimeException('An untrusted grid caused a network request.');
    return wx_ok(['properties'=>['gridId'=>'https://invalid.example','gridX'=>1,'gridY'=>2]]);
};
wx_check(sf_canvas_weather_read(33.4484,-112.074,'daily',$badPoint,$now,$root.'/bad-point')['status']==='unavailable','Untrusted grid IDs fail closed before any derived request');

$alertsMode='active';
$alertTransport=static function(string $path)use(&$alertsMode,$now):array {
    if($path==='/points/33.4484,-112.0740')return wx_point();
    if($path!=='/alerts?active=true&point=33.4484,-112.0740')throw new RuntimeException('Unexpected alerts path '.$path);
    return match($alertsMode){
        'active'=>wx_ok(['features'=>[wx_alert($now),wx_alert($now,'test','Test'),wx_alert($now,'cancel','Actual','Cancel'),wx_alert($now,'expired','Actual','Alert',$now-60)]]),
        'empty'=>wx_ok(['features'=>[]]),
        'invalid'=>wx_ok(['features'=>[['properties'=>['event'=>'Unknown input']]]]),
        default=>['code'=>503],
    };
};
$alerts=sf_canvas_weather_read(33.4484,-112.074,'alerts',$alertTransport,$now,$root.'/alerts');
wx_check($alerts['status']==='fresh'&&count($alerts['rows'])===1&&$alerts['rows'][0]['id']==='urn:oid:actual'&&$alerts['rows'][0]['onset_at']===gmdate('c',$now+300),
    'Alert rows cover active official point alerts and omit exercises, cancellations and ended alerts');
$alertsMode='failure';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'alerts',$alertTransport,$now+61,$root.'/alerts')['status']==='unavailable','Expired alert cache does not replay during an outage');
$alertsMode='empty';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'alerts',$alertTransport,$now,$root.'/clear-alerts')['status']==='fresh','A valid empty alert feed means no active alerts for the point');
$alertsMode='invalid';
wx_check(sf_canvas_weather_read(33.4484,-112.074,'alerts',$alertTransport,$now,$root.'/invalid-alerts')['status']==='unavailable','Malformed alert rows do not become an apparently clear feed');

$calls=0;
$limited=static function(string $path)use(&$calls):array {$calls++;return ['code'=>429,'retry_after'=>60];};
sf_canvas_weather_read(33.4484,-112.074,'current',$limited,$now,$root.'/rate');
sf_canvas_weather_read(32.2226,-110.9747,'hourly',$limited,$now+40,$root.'/rate');
wx_check($calls===1,'Upstream 429 Retry-After blocks other products and locations on the host');
sf_canvas_weather_read(35.1,-111.1,'daily',$limited,$now+61,$root.'/rate');
wx_check($calls===2,'The upstream cooldown releases after its retry time');

$budget=0;
$noCoverage=static function(string $path)use(&$budget):array {$budget++;return ['code'=>404];};
for($index=0;$index<56;$index++)sf_canvas_weather_read(33.01+$index/10000,-112.07,'current',$noCoverage,$now,$root.'/budget');
wx_check($budget===48,'Changing location keys cannot bypass the shared minute request quota');
sf_canvas_weather_read(34.1,-111.1,'current',$noCoverage,$now+61,$root.'/budget');
wx_check($budget===49,'The minute budget recovers without manual cleanup');

$rejected=0;
foreach([[NAN,-112.074,'current'],[33.4484,181,'daily'],[33.4484,-112.074,'https://invalid.example']] as [$lat,$lon,$product]){
    try{sf_canvas_weather_read($lat,$lon,$product,$noCoverage,$now,$root.'/invalid');}
    catch(InvalidArgumentException){$rejected++;}
}
wx_check($rejected===3&&$budget===49,'Invalid coordinates and products never touch the provider');

echo "Weather provider: $checks checks passed".PHP_EOL;
