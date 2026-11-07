<?php
declare(strict_types=1);

$fixture=sys_get_temp_dir().'/sf-canvas-places-'.bin2hex(random_bytes(6));
mkdir($fixture.'/data',0700,true);
define('SF_ROOT',$fixture);
require dirname(__DIR__).'/api/bootstrap.php';
require dirname(__DIR__).'/api/canvas-data-places.php';

function place_check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}
function place_write_shows(array $shows): void {
    $next=SF_ROOT.'/data/shows-next.json';
    file_put_contents($next,json_encode($shows,JSON_THROW_ON_ERROR));
    rename($next,SF_ROOT.'/data/shows.json');
}
try{
    $catalog=iterator_count(sf_canvas_data_place_cities());
    place_check($catalog===32363,'Packaged catalog contains all 32,363 places in the 2026 national file');
    $phoenix=sf_canvas_data_place('city-0455000');
    place_check($phoenix===['id'=>'city-0455000','title'=>'Phoenix, AZ','kind'=>'city','lat'=>33.5722,'lon'=>-112.0901],
        'The Phoenix city ID resolves to its authoritative Census representative point');
    place_check((sf_canvas_data_places('  PHOENIX, Arizona, USA ')[0]??null)===$phoenix,
        'State names, country suffixes and case resolve consistently');
    place_check((sf_canvas_data_places('phoenix az')[0]??null)===$phoenix,
        'The same city can be selected with a state abbreviation');
    $springfield=sf_canvas_data_places('Springfield');
    place_check(count(array_unique(array_column($springfield,'title')))>=2,
        'Cross-state city ambiguity is shown as distinct choices');
    $cottonwoods=array_values(array_filter(sf_canvas_data_places('Cottonwood, AZ'),
        static fn(array $row):bool=>str_starts_with($row['title'],'Cottonwood ')));
    place_check(count($cottonwoods)===2&&count(array_unique(array_column($cottonwoods,'title')))===2,
        'Same-state city and CDP names retain their legal designations');
    place_check((sf_canvas_data_places('New York City, NY')[0]['title']??null)==='New York, NY',
        'Official and common city names both resolve');
    place_check((sf_canvas_data_places('Nashville, Tennessee')[0]['title']??null)==='Nashville, TN',
        'Common labels resolve to the corresponding consolidated Census government');
    place_check(count(sf_canvas_data_places('an'))===18,'A broad search is bounded');
    place_check(sf_canvas_data_places('P')===[]&&sf_canvas_data_places('')===[],'Short and empty searches avoid a broad city listing');
    place_check(sf_canvas_data_place('city-0455000/../../secret')===null&&
        sf_canvas_data_place('show-/../../secret')===null&&sf_canvas_data_place('city-0000000')===null,
        'Malformed and unknown IDs cannot resolve');
    try{sf_canvas_data_places(str_repeat('a',101));throw new LogicException('Oversize input accepted');}
    catch(InvalidArgumentException){echo "PASS Oversize searches are rejected\n";}

    $show=['id'=>'fixture-show','venue'=>'Crescent Ballroom','title'=>'Fixture performance','city'=>'Phoenix',
        'region'=>'Arizona','country'=>'U.S.','date'=>'2027-01-02T20:00','status'=>'scheduled','public_visible'=>true,
        'archive_notes'=>'PRIVATE-NOTE','address'=>'PRIVATE-ADDRESS','latitude'=>0,'longitude'=>0];
    place_write_shows([$show,
        array_replace($show,['id'=>'hidden-show','public_visible'=>false]),
        array_replace($show,['id'=>'archived-show','status'=>'archived']),
        array_replace($show,['id'=>'foreign-show','country'=>'Canada']),
        array_replace($show,['id'=>'ambiguous-show','city'=>'Cottonwood']),
        array_replace($show,['id'=>'no-state','region'=>'']),
    ]);
    $venue=sf_canvas_data_place('show-fixture-show');
    place_check($venue!==null&&$venue['kind']==='venue'&&$venue['lat']===$phoenix['lat']&&$venue['lon']===$phoenix['lon']&&
        str_contains($venue['title'],'city weather')&&!str_contains(json_encode($venue),'PRIVATE-'),
        'Show weather uses the public city centroid without leaking or using private address fields');
    place_check(sf_canvas_data_places('Crescent Ballroom')===[$venue]&&sf_canvas_data_places('')===[$venue],
        'Public venues resolve by name and appear as suggestions');
    foreach(['hidden-show','archived-show','foreign-show','ambiguous-show','no-state'] as $id)
        place_check(sf_canvas_data_place('show-'.$id)===null,"Unavailable show {$id} never resolves");
    place_write_shows([array_replace($show,['city'=>'Tucson'])]);
    place_check(sf_canvas_data_place('show-fixture-show')['lat']===sf_canvas_data_place('city-0477000')['lat'],
        'City changes are observed for the same show ID within one request');
    place_write_shows([array_replace($show,['public_visible'=>false])]);
    place_check(sf_canvas_data_place('show-fixture-show')===null&&sf_canvas_data_places('Crescent Ballroom')===[],
        'Withdrawing publication invalidates previously visible show selections within one request');
}finally{
    if(is_file($fixture.'/data/shows.json'))unlink($fixture.'/data/shows.json');
    if(is_file($fixture.'/data/shows-next.json'))unlink($fixture.'/data/shows-next.json');
    rmdir($fixture.'/data');rmdir($fixture);
}
