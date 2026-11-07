<?php
declare(strict_types=1);

require __DIR__.'/../api/canvas-earthquakes-core.php';

$directory=sys_get_temp_dir().'/stonefellow-earthquakes-'.bin2hex(random_bytes(8));
if(!mkdir($directory,0700))throw new RuntimeException('Could not create earthquake fixture storage.');
register_shutdown_function(static function()use($directory):void {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){
        if($file->isDir())rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($directory);
});

$checks=0;
function quake_check(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
}

function quake_feature(string $id,int $occurred,int $updated,array $properties=[],array $point=[-112.074,33.4484,8.25]):array {
    return ['type'=>'Feature','id'=>$id,'geometry'=>['type'=>'Point','coordinates'=>$point],
        'properties'=>$properties+['time'=>$occurred,'updated'=>$updated,'mag'=>4.8,'magType'=>'mww',
            'place'=>"  Cañón de\tArizona\0 ",'status'=>'automatic','type'=>'earthquake',
            'url'=>'https://example.invalid/ignore','detail'=>'https://example.invalid/ignore-detail']];
}

function quake_feed(int $time,array $features=[],array $headers=[]):array {
    return $headers+['code'=>200,'body'=>['type'=>'FeatureCollection',
        'metadata'=>['generated'=>$time*1000,'status'=>200,'count'=>count($features)],'features'=>$features]];
}

date_default_timezone_set('America/Phoenix');
$now=strtotime('2026-11-07T17:30:00Z');
$ms=$now*1000;
$old=quake_feature('us123',$ms-10000,$ms-9000,['mag'=>4.3]);
$corrected=quake_feature('us123',$ms-10001,$ms-8888,['mag'=>4.7,'status'=>'reviewed']);
$second=quake_feature('ak987',$ms-10001,$ms-7000,[],[-149.3,61.2,-1.8]);
$later=quake_feature('nc789',$ms-10000,$ms-5000,['magType'=>null,'place'=>null]);
$nonEarthquake=quake_feature('nnblast',$ms-500,$ms-400,['type'=>'quarry blast']);
$retracted=quake_feature('uswithdrawn',$ms-1000,$ms-100,['status'=>'deleted']);
$samples=[$old,$corrected,$later,$second,$nonEarthquake,$retracted,quake_feature('uswithdrawn',$ms-1000,$ms-500),
    quake_feature('../unsafe',$ms-9000,$ms-7000),
    quake_feature('badlon',$ms-9000,$ms-7000,[],[181,0,10]),
    quake_feature('badlat',$ms-9000,$ms-7000,[],[0,-91,10]),
    quake_feature('baddepth',$ms-9000,$ms-7000,[],[0,0,INF]),
    quake_feature('badmag',$ms-9000,$ms-7000,['mag'=>'4.8']),
    quake_feature('badtime',$ms-9000,$ms-7000,['time'=>'1794072000000']),
    quake_feature('future',$ms+1000,$ms+1000),
    quake_feature('badupdate',$ms-9000,$ms-10000),
    'bad feature'];
$urls=[];
$transport=static function(string $url)use(&$urls,$now,$samples):array {
    $urls[]=$url;
    return quake_feed($now,$samples);
};
$result=sf_canvas_earthquakes_read('m25-day',$transport,$now,$directory.'/primary');
quake_check($result['status']==='fresh'&&$result['source']===SF_CANVAS_EARTHQUAKES_SOURCE&&$result['timezone']==='UTC'&&
    $result['fetched_at']==='2026-11-07T17:30:00+00:00'&&$result['expires_at']==='2026-11-07T17:31:00+00:00',
    'A valid feed has a shared fresh envelope and UTC times');
quake_check(array_column($result['rows'],'event_id')===['nc789','ak987','us123'],
    'Newest events sort by UTC milliseconds and stable ID after excluding invalid, retracted and non-earthquake records');
$row=$result['rows'][2];
quake_check($row===['event_id'=>'us123','place'=>'Cañón de Arizona','magnitude'=>4.7,'magnitude_type'=>'mww','lat'=>33.4484,
    'lon'=>-112.074,'depth_km'=>8.25,'occurred_at'=>'2026-11-07T17:29:49.999Z','updated_at'=>'2026-11-07T17:29:51.112Z',
    'review_status'=>'reviewed','source_url'=>'https://earthquake.usgs.gov/earthquakes/eventpage/us123'],
    'The latest correction wins and projects only typed fields and a canonical USGS event link');
quake_check($result['rows'][1]['depth_km']===-1.8&&$result['rows'][0]['place']===null&&$result['rows'][0]['magnitude_type']===null,
    'Finite negative depths and optional null source fields remain usable');
$cached=sf_canvas_earthquakes_read('m25-day',$transport,$now+1,$directory.'/primary');
quake_check($cached===$result&&$urls===['https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/2.5_day.geojson'],
    'Fresh reads coalesce across callers using the same allowlisted slot');
foreach(['m25-week','m45-day','m45-week'] as $feed)sf_canvas_earthquakes_read($feed,$transport,$now,$directory.'/primary');
quake_check(array_map('basename',$urls)===['2.5_day.geojson','2.5_week.geojson','4.5_day.geojson','4.5_week.geojson'],
    'The four scopes select their own exact fixed USGS summary URLs');
try{
    sf_canvas_earthquakes_read('https://example.invalid/forged',$transport,$now,$directory.'/primary');
    throw new RuntimeException('Expected invalid feed rejection.');
}catch(InvalidArgumentException){}
quake_check(count($urls)===4,'An unregistered feed never reaches the upstream transport');

$failed=0;
$outage=static function(string $url)use(&$failed):array {$failed++;return ['code'=>503,'retry_after'=>60];};
$stale=sf_canvas_earthquakes_read('m25-day',$outage,$now+61,$directory.'/primary');
quake_check($stale['status']==='stale'&&$stale['expires_at']===$result['expires_at']&&$stale['rows']===$result['rows'],
    'An outage returns bounded stale data with the original expiry');
sf_canvas_earthquakes_read('m25-day',$outage,$now+90,$directory.'/primary');
quake_check($failed===1,'Retry-After cools down the shared feed slot');
$expired=sf_canvas_earthquakes_read('m25-day',$outage,$now+960,$directory.'/primary');
quake_check($expired['status']==='unavailable'&&$expired['rows']===[]&&$expired['expires_at']===null,
    'Expired stale data becomes unavailable and is not replayed');
$freshEmpty=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>quake_feed($now),$now,$directory.'/empty');
quake_check($freshEmpty['status']==='fresh'&&$freshEmpty['rows']===[],'A valid empty USGS feed is a fresh empty response');
$onlyBlasts=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>quake_feed($now,[$nonEarthquake]),$now,$directory.'/blast');
quake_check($onlyBlasts['status']==='fresh'&&$onlyBlasts['rows']===[],'Valid non-earthquake records do not masquerade as earthquake results');
$newer=quake_feature('uswithdrawn',$ms-1000,$ms-50);
$reissued=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>quake_feed($now,[$newer,$retracted]),$now,$directory.'/reissued');
quake_check(array_column($reissued['rows'],'event_id')===['uswithdrawn'],'An older retraction does not override a newer event revision');

$broken=quake_feed($now,[$later]);$broken['body']['metadata']['count']=0;
foreach([
    'wrong-count'=>$broken,
    'redirect'=>['code'=>302,'location'=>'https://example.invalid'],
    'non-geojson'=>['code'=>200,'body'=>['features'=>[]]],
    'unrecognizable-features'=>quake_feed($now,[null,[]]),
    'unusable-earthquake'=>quake_feed($now,[quake_feature('badlat',$ms-9000,$ms-7000,[],[0,91,8])]),
    'old-generated'=>quake_feed($now-961),
    'future-generated'=>quake_feed($now+121),
] as $id=>$response){
    $data=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>$response,$now,$directory.'/'.$id);
    quake_check($data['status']==='unavailable'&&$data['rows']===[],"Rejected provider response: $id");
}
$aged=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>quake_feed($now-121,[$old]),$now,$directory.'/aged');
quake_check($aged['status']==='unavailable','An event newer than its purported feed generation is rejected');
$agedEmpty=sf_canvas_earthquakes_read('m45-day',static fn(string $url):array=>quake_feed($now-121),$now,$directory.'/aged-empty');
quake_check($agedEmpty['status']==='stale'&&strtotime($agedEmpty['expires_at'])===$now-61,
    'Retrieving an older feed does not extend its freshness');

$short=static fn(string $url):array=>quake_feed($now,[$old],['max_age'=>15,'no_stale'=>true]);
sf_canvas_earthquakes_read('m45-day',$short,$now,$directory.'/no-stale');
quake_check(sf_canvas_earthquakes_read('m45-day',$outage,$now+15,$directory.'/no-stale')['status']==='unavailable',
    'Shared cache honors a shorter upstream max-age and must-revalidate');
$noStore=static fn(string $url):array=>quake_feed($now,[$old],['no_store'=>true]);
$transient=sf_canvas_earthquakes_read('m45-day',$noStore,$now,$directory.'/no-store');
quake_check($transient['status']==='fresh'&&sf_canvas_earthquakes_read('m45-day',$outage,$now+1,$directory.'/no-store')['status']==='unavailable',
    'A no-store feed can be used once without leaving reusable cached rows');

$many=[];
for($index=0;$index<147;$index++)$many[]=quake_feature('us'.$index,$ms-1000+$index,$ms-100);
$capped=sf_canvas_earthquakes_read('m45-week',static fn(string $url):array=>quake_feed($now,$many),$now,$directory.'/capped');
$reversed=sf_canvas_earthquakes_read('m45-week',static fn(string $url):array=>quake_feed($now,array_reverse($many)),$now,$directory.'/reversed');
quake_check(count($capped['rows'])===120&&$capped['rows'][0]['event_id']==='us146'&&end($capped['rows'])['event_id']==='us27'&&
    $capped===$reversed,'The response returns the same 120 newest events regardless of source ordering');

$shared=$directory.'/shared';mkdir($shared,0700);
$workerCode=<<<'PHP_WORKER'
require $argv[1];
$directory=$argv[2];$now=(int)$argv[3];
$result=sf_canvas_earthquakes_read('m45-week',static function(string $url)use($directory,$now):array {
    file_put_contents($directory.'/started','yes');
    usleep(450000);
    return ['code'=>200,'body'=>['type'=>'FeatureCollection','features'=>[],
        'metadata'=>['generated'=>$now*1000,'count'=>0,'status'=>200]]];
},$now,$directory);
echo json_encode($result,JSON_THROW_ON_ERROR);
PHP_WORKER;
$worker=proc_open([PHP_BINARY,'-r',$workerCode,realpath(__DIR__.'/../api/canvas-earthquakes-core.php'),$shared,(string)$now],
    [1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($worker))throw new RuntimeException('Could not launch earthquake concurrency fixture.');
try{
    $deadline=microtime(true)+5;
    while(!file_exists($shared.'/started')&&microtime(true)<$deadline)usleep(10000);
    quake_check(file_exists($shared.'/started'),'The first cold worker acquires its feed slot');
    $extraCalls=0;
    $waiting=sf_canvas_earthquakes_read('m45-week',static function(string $url)use(&$extraCalls):array {
        $extraCalls++;
        return ['code'=>500];
    },$now,$shared);
    $peer=json_decode(stream_get_contents($pipes[1]),true,16,JSON_THROW_ON_ERROR);
    quake_check($extraCalls===0&&$waiting['status']==='fresh'&&$waiting===$peer,
        'Independent cold workers reuse one successful upstream request and atomic cache write');
}finally{
    fclose($pipes[1]);fclose($pipes[2]);proc_close($worker);
}

echo "USGS earthquake provider: $checks checks passed\n";
