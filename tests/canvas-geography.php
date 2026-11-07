<?php
declare(strict_types=1);

$fixture=sys_get_temp_dir().'/sf-canvas-geography-'.bin2hex(random_bytes(6));
mkdir($fixture.'/data',0700,true);
define('SF_ROOT',$fixture);
require dirname(__DIR__).'/api/bootstrap.php';
require dirname(__DIR__).'/api/canvas-geography-core.php';

function geography_check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}

function geography_save_shows(array $rows): void {
    file_put_contents(SF_ROOT.'/data/shows.json',json_encode($rows,JSON_THROW_ON_ERROR));
}

try{
    $phoenix=sf_canvas_data_place('city-0455000',true);
    $city=sf_canvas_geography_read($phoenix,'place');
    $point=$city['rows'][0];
    geography_check($point['location_id']==='city-0455000'&&$point['city_id']===$point['location_id']&&
        $point['title']==='Phoenix, AZ'&&$point['city']==='Phoenix'&&$point['name']==='Phoenix'&&$point['kind']==='city'&&
        $point['region']==='AZ'&&$point['country_code']==='US'&&$point['venue']===null&&$point['show_id']===null&&
        $point['lat']===33.5722&&$point['lon']===-112.0901&&$point['precision']==='census_place_representative_point'&&
        $city['source']===$point['coordinate_source']&&$city['status']==='fresh'&&$city['timezone']===null&&
        strtotime($city['expires_at'])-strtotime($city['fetched_at'])===60,
        'A selected city exposes stable Census geography and a fresh local snapshot');
    $sanJuan=sf_canvas_data_places('San Juan, PR')[0]['id'];
    geography_check(sf_canvas_geography_read(sf_canvas_data_place($sanJuan),'place')['rows'][0]['country_code']==='PR',
        'Puerto Rico keeps its territory code, including when resolving legacy public place metadata');

    $show=['id'=>'local-a','title'=>'Desert night','venue'=>'Fixture Stage','city'=>'Phoenix','region'=>'Arizona',
        'country'=>'United States','date'=>'2099-07-01T20:00','status'=>'scheduled','public_visible'=>true,
        'address'=>'PRIVATE_ADDRESS','archive_notes'=>'PRIVATE_NOTES','ticket_url'=>'https://example.invalid/PRIVATE_TICKET',
        'latitude'=>0,'longitude'=>0];
    $excluded=[
        'hidden'=>['public_visible'=>false],
        'visibility-string'=>['public_visible'=>'false'],
        'archived'=>['status'=>'archived'],
        'canceled'=>['status'=>'canceled'],
        'cancelled'=>['status'=>'cancelled'],
        'completed'=>['status'=>'completed'],
        'postponed'=>['status'=>'postponed'],
        'past'=>['date'=>'1900-07-01'],
        'invalid-date'=>['date'=>'2099-02-29'],
        'invalid-time'=>['date'=>'2099-07-01T24:00'],
        'foreign'=>['country'=>'Canada'],
        'ambiguous'=>['city'=>'Cottonwood'],
        'no-region'=>['region'=>''],
        'unknown-city'=>['city'=>'Unknown fixture place'],
    ];
    $shows=[];
    for($i=0;$i<51;$i++)$shows[]=array_replace($show,['id'=>'distant-'.$i,'city'=>'Denver','region'=>'CO','date'=>'2099-06-01']);
    foreach($excluded as $id=>$change)$shows[]=array_replace($show,['id'=>$id,'title'=>'PRIVATE_EXCLUDED_'.$id],$change);
    $shows[]=array_replace($show,['id'=>'local-b','title'=>'','venue'=>'Fixture Stage B']);
    $shows[]=array_replace($show,['id'=>'nearby','city'=>'Tucson','date'=>'2099-05-01']);
    $shows[]=$show;
    geography_save_shows($shows);

    $result=sf_canvas_geography_read($phoenix,'shows');
    $rows=$result['rows'];
    geography_check($result['status']==='fresh'&&$result['source']==='Stonefellow public shows'&&
        strtotime($result['expires_at'])<=sf_canvas_geography_next_utc_day(time())&&count($rows)===48&&
        array_column(array_slice($rows,0,3),'show_id')===['local-a','local-b','nearby']&&
        $rows[0]['distance_km']===0.0&&$rows[1]['distance_km']===0.0&&
        $rows[2]['distance_km']>130&&$rows[2]['distance_km']<200&&$rows[2]['distance_km']<$rows[3]['distance_km'],
        'Distance ranks the entire calendar before the output cap, with stable ordering for equal distances and dates');
    geography_check($rows[0]['title']==='Desert night'&&$rows[0]['venue']==='Fixture Stage'&&
        $rows[0]['date']==='2099-07-01T20:00'&&$rows[1]['title']==='Fixture Stage B'&&
        $rows[0]['city_id']===$point['city_id']&&$rows[0]['lat']===$point['lat']&&$rows[0]['lon']===$point['lon']&&
        $rows[0]['precision']===$point['precision']&&!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'PRIVATE_'),
        'Results preserve published text and Census point provenance without leaking private fields or excluded records');
    foreach(array_keys($excluded) as $id)geography_check(sf_canvas_geography_upcoming_venue('show-'.$id)===null,
        'The upcoming venue scope excludes '.$id);
    $location=sf_canvas_geography_upcoming_venue($rows[0]['location_id']);
    $venue=sf_canvas_geography_read($location,'place')['rows'][0];
    geography_check($location['scheduled_date']===$rows[0]['date']&&$location['id']==='show-local-a'&&
        $venue['kind']==='venue'&&$venue['title']==='Fixture Stage · Phoenix, AZ'&&$venue['name']==='Fixture Stage'&&
        $venue['show_id']==='local-a'&&$venue['city_id']==='city-0455000'&&$venue['lat']===$point['lat'],
        'A show location resolves through the approved venue scope and exposes city-level geography');

    geography_save_shows([array_replace($show,['date'=>'2099-07-01T21:00'])]);
    geography_check(sf_canvas_geography_upcoming_venue('show-local-a')['scheduled_date']==='2099-07-01T21:00',
        'The same venue ID observes a same-day schedule change');
    geography_save_shows([array_replace($show,['city'=>'Tucson'])]);
    geography_check(sf_canvas_geography_upcoming_venue('show-local-a')['city_id']==='city-0477000',
        'The same venue ID observes a relocated show');
    geography_save_shows([array_replace($show,['public_visible'=>false])]);
    geography_check(sf_canvas_geography_upcoming_venue('show-local-a')===null&&
        sf_canvas_geography_read($phoenix,'shows')['rows']===[],
        'Withdrawing a show removes its row and its scoped venue binding immediately');

    geography_check(sf_canvas_geography_upcoming_show(array_replace($show,['date'=>'2026-11-07T00:00']),
        '2026-11-07')!==null&&sf_canvas_geography_upcoming_show(array_replace($show,['date'=>'2026-11-06T23:59']),
        '2026-11-07')===null,'Upcoming selection follows the public calendar UTC date boundary');
    date_default_timezone_set('Pacific/Honolulu');
    geography_check(sf_canvas_geography_next_utc_day(strtotime('2026-11-08T00:00:00Z'))===strtotime('2026-11-09T00:00:00Z'),
        'UTC snapshot expiry is independent of the server timezone');
}finally{
    if(is_file($fixture.'/data/shows.json'))unlink($fixture.'/data/shows.json');
    rmdir($fixture.'/data');
    rmdir($fixture);
}
