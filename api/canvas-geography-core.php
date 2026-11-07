<?php
declare(strict_types=1);

require_once __DIR__.'/canvas-data-places.php';

const SF_CANVAS_GEOGRAPHY_COORDINATES='U.S. Census Bureau Gazetteer Files (2026)';
const SF_CANVAS_GEOGRAPHY_SHOW_SOURCE='Stonefellow public shows';
const SF_CANVAS_GEOGRAPHY_SHOW_LIMIT=48;

function sf_canvas_geography_next_utc_day(int $timestamp): int {
    return strtotime(gmdate('Y-m-d',$timestamp).' +1 day UTC');
}

/** The live calendar uses UTC dates. Preserve the published time as local text. */
function sf_canvas_geography_upcoming_show(array $publicShow,string $today): ?array {
    if(($publicShow['status']??'scheduled')!=='scheduled')return null;
    $date=$publicShow['date']??'';
    if(!is_string($date)||!preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:T(?:[01]\d|2[0-3]):[0-5]\d)?\z/',$date,$parts)||
        substr($date,0,10)<$today||!checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]))return null;
    $show=sf_canvas_data_place_show_city($publicShow);
    return $show===null?null:$show+['scheduled_date'=>$date];
}

/** Resolve only a currently published, scheduled, upcoming and geocoded venue. */
function sf_canvas_geography_upcoming_venue(string $id): ?array {
    if(!preg_match('/\Ashow-([a-z0-9][a-z0-9-]{0,79})\z/',$id,$match))return null;
    $publicShow=sf_live_show_by_id($match[1]);
    $show=$publicShow===null?null:sf_canvas_geography_upcoming_show($publicShow,gmdate('Y-m-d'));
    if($show===null)return null;
    $venue=sf_canvas_data_place_show($show,true);
    return $venue===null?null:$venue+['scheduled_date'=>$show['scheduled_date']];
}

function sf_canvas_geography_point(array $place): array {
    return ['city_id'=>$place['city_id'],'city'=>$place['city'],'region'=>$place['region'],
        'country_code'=>$place['country_code'],'lat'=>$place['lat'],'lon'=>$place['lon'],
        'precision'=>'census_place_representative_point','coordinate_source'=>SF_CANVAS_GEOGRAPHY_COORDINATES];
}

function sf_canvas_geography_distance_km(array $from,array $to): float {
    $fromLat=deg2rad($from['lat']);$toLat=deg2rad($to['lat']);
    $longitudeDifference=deg2rad($to['lon']-$from['lon']);
    $halfChord=sin(($toLat-$fromLat)/2)**2+cos($fromLat)*cos($toLat)*sin($longitudeDifference/2)**2;
    return 2*6371.0088*asin(sqrt(min(1.0,$halfChord)));
}

function sf_canvas_geography_shows(array $anchor,array $publishedShows,string $today): array {
    $shows=[];
    foreach($publishedShows as $publicShow){
        $show=sf_canvas_geography_upcoming_show($publicShow,$today);
        if($show!==null)$shows[]=$show;
    }
    $cities=sf_canvas_data_place_show_cities($shows);
    $rows=[];
    foreach($shows as $show){
        $city=$cities[$show['region'].':'.$show['city']];
        if(!is_array($city))continue;
        $place=sf_canvas_data_place_venue_result($show,$city,true);
        $rows[]=['show_id'=>$place['show_id'],'location_id'=>$place['id'],
            'title'=>$show['name']!==''?$show['name']:$show['venue'],
            'venue'=>$show['venue'],'date'=>$show['scheduled_date'],
            'distance_km'=>sf_canvas_geography_distance_km($anchor,$place)]+sf_canvas_geography_point($place);
    }
    usort($rows,static fn(array $a,array $b):int=>$a['distance_km']<=>$b['distance_km']?:
        strcmp($a['date'],$b['date'])?:strcmp($a['show_id'],$b['show_id']));
    $rows=array_slice($rows,0,SF_CANVAS_GEOGRAPHY_SHOW_LIMIT);
    foreach($rows as &$row)$row['distance_km']=round($row['distance_km'],1);
    unset($row);
    return $rows;
}

/** A fresh response describes the local snapshot time, independent of the Gazetteer vintage. */
function sf_canvas_geography_read(array $place,string $operation,?callable $read=null): array {
    $now=time();$expiresAt=$now+60;
    if($operation==='place'){
        if(!isset($place['city_id']))$place=sf_canvas_data_place((string)($place['id']??''),true)??
            throw new InvalidArgumentException('Choose a public geography location.');
        $source=SF_CANVAS_GEOGRAPHY_COORDINATES;
        $rows=[['location_id'=>$place['id'],'title'=>$place['display_title']??$place['title'],
            'name'=>$place['name'],'kind'=>$place['kind'],'venue'=>$place['venue']??null,
            'show_id'=>$place['show_id']??null]+sf_canvas_geography_point($place)];
    }elseif($operation==='shows'){
        $source=SF_CANVAS_GEOGRAPHY_SHOW_SOURCE;
        $rows=sf_canvas_geography_shows($place,($read??sf_live_public_shows(...))(),gmdate('Y-m-d',$now));
        $expiresAt=min($expiresAt,sf_canvas_geography_next_utc_day($now));
    }else{
        throw new InvalidArgumentException('Choose a public geography product.');
    }
    return ['status'=>'fresh','source'=>$source,'fetched_at'=>gmdate('c',$now),
        'expires_at'=>gmdate('c',$expiresAt),'timezone'=>null,'rows'=>$rows];
}
