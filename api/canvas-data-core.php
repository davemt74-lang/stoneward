<?php
declare(strict_types=1);
require_once __DIR__.'/canvas-map-core.php';

const SF_CANVAS_DATA_MAX_SOURCES=4;
const SF_CANVAS_DATA_SHOW_SCOPE='published-shows';
const SF_CANVAS_DATA_REGION_SCOPE='us-regions';
const SF_CANVAS_DATA_MIN_RADIUS_M=10;
const SF_CANVAS_DATA_MAX_RADIUS_M=10000;

/** Catalog entries describe data, parameters and host permissions. */
function sf_canvas_data_products(): array {
    $forecast=['name'=>'text','start_at'=>'datetime','end_at'=>'datetime','is_daytime'=>'boolean',
        'temperature_f'=>'number','temperature_c'=>'number','condition'=>'text','detailed_forecast'=>'text',
        'wind_speed'=>'text','wind_direction'=>'text','precipitation_probability_percent'=>'number'];
    $products=[
        'current'=>['Current weather','Latest station observation. temperature_f is the rounded whole Fahrenheit reading.',
            ['station_id'=>'text','observed_at'=>'datetime','temperature_f'=>'number','temperature_c'=>'number',
                'condition'=>'text','feels_like_f'=>'number','relative_humidity_percent'=>'number','wind_mph'=>'number',
                'wind_direction_degrees'=>'number','precipitation_1h_inches'=>'number']],
        'hourly'=>['Hourly forecast','Forecast periods with their own valid start and end times.',$forecast],
        'daily'=>['Day and night forecast','Forecast periods organized into daytime and nighttime.', $forecast],
        'alerts'=>['Official weather alerts','Active NWS alerts for the selected point.',
            ['id'=>'text','event'=>'text','severity'=>'text','certainty'=>'text','urgency'=>'text','headline'=>'text',
                'description'=>'text','instruction'=>'text','sent_at'=>'datetime','effective_at'=>'datetime',
                'onset_at'=>'datetime','expires_at'=>'datetime','ends_at'=>'datetime']],
    ];
    $catalog=[];
    foreach($products as $operation=>[$title,$description,$fields]){
        $id='weather.'.$operation;
        $catalog[$id]=['id'=>$id,'title'=>$title,'description'=>$description,'fields'=>$fields,
            'parameters'=>['location_id'=>['type'=>'public_place','label'=>'City or venue','scope_options'=>[
                ['id'=>SF_CANVAS_DATA_SHOW_SCOPE,'title'=>'Any upcoming published venue','kind'=>'scope'],
            ]]],
            'permission_id'=>'canvas.data.weather_nws','capability'=>'weather_nws','provider'=>'National Weather Service',
            'operation'=>$operation,'reader'=>'sf_canvas_data_read_weather'];
    }
    $placeFields=['location_id'=>'text','city_id'=>'text','title'=>'text','city'=>'text','region'=>'text',
        'country_code'=>'text','lat'=>'number','lon'=>'number','precision'=>'text','coordinate_source'=>'text',
        'venue'=>'text','show_id'=>'text'];
    $geography=[
        'place'=>['Public place details','Public city or venue details with coordinates at city-level precision.',
            $placeFields+['name'=>'text','kind'=>'text'],'U.S. Census Bureau Gazetteer Files (2026)'],
        'shows'=>['Upcoming shows by distance','Up to 48 geocoded public shows, nearest first by straight-line distance from the selected place. Use location_id for an authorized venue-scoped lookup.',
            $placeFields+['date'=>'text','distance_km'=>'number'],'Stonefellow public shows'],
    ];
    foreach($geography as $operation=>[$title,$description,$fields,$provider]){
        $id='geography.'.$operation;
        $catalog[$id]=['id'=>$id,'title'=>$title,'description'=>$description,'fields'=>$fields,
            'parameters'=>['location_id'=>['type'=>'public_place','label'=>'City or venue']],
            'permission_id'=>'canvas.data.geography_public','capability'=>'geography_public','provider'=>$provider,
            'operation'=>$operation,'reader'=>'sf_canvas_data_read_geography'];
    }
    $catalog['map.boundaries']=[
        'id'=>'map.boundaries','title'=>'U.S. map boundaries',
        'description'=>'Simplified state and territory outlines for a public place or a U.S. overview. Group rows by region, part and ring, then append coordinates in chunk order. Each coordinates string is JSON containing longitude/latitude pairs. Ring zero is the exterior. Draw and style the geometry to suit the requested design.',
        'fields'=>['region'=>'text','name'=>'text','part'=>'number','ring'=>'number','chunk'=>'number','coordinates'=>'text'],
        'parameters'=>['location_id'=>['type'=>'public_place','label'=>'City or venue','scope_options'=>[
            ['id'=>SF_CANVAS_DATA_REGION_SCOPE,'title'=>'U.S. overview','kind'=>'scope'],
        ]]],
        'permission_id'=>'canvas.data.map_public','capability'=>'map_public','provider'=>SF_CANVAS_MAP_SOURCE,
        'operation'=>'boundaries','reader'=>'sf_canvas_map_read',
    ];
    $catalog['location.proximity']=[
        'id'=>'location.proximity','title'=>'Device proximity to a public point',
        'description'=>'An explicitly selected public target point and radius. A separate, viewer-initiated check returns only a proximity status. Device coordinates stay in the trusted site page.',
        'fields'=>['lat'=>'number','lon'=>'number','radius_m'=>'number'],
        'parameters'=>['location_id'=>['type'=>'public_point','label'=>'Public target point',
            'min_radius_m'=>SF_CANVAS_DATA_MIN_RADIUS_M,'max_radius_m'=>SF_CANVAS_DATA_MAX_RADIUS_M]],
        'permission_id'=>'canvas.data.location_proximity','capability'=>'location_proximity','provider'=>'Stonefellow public target',
        'operation'=>'proximity','reader'=>'sf_canvas_data_read_proximity',
    ];
    return $catalog;
}

function sf_canvas_data_inventory(): array {
    require_once __DIR__.'/canvas-capabilities.php';
    $policy=sf_canvas_permission_policy();
    $products=[];
    foreach(sf_canvas_data_products() as $product){
        if(!sf_canvas_permission_enabled($product['capability'],$policy))continue;
        unset($product['operation'],$product['capability'],$product['reader']);
        $products[]=$product;
    }
    return ['max_sources'=>SF_CANVAS_DATA_MAX_SOURCES,'products'=>$products];
}

/** Persist aliases and canonical public location IDs, without fetched values. */
function sf_canvas_data_sources(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>SF_CANVAS_DATA_MAX_SOURCES)
        throw new InvalidArgumentException('Choose up to '.SF_CANVAS_DATA_MAX_SOURCES.' live data sources.');
    $products=sf_canvas_data_products();$sources=[];
    foreach($raw as $item){
        if(!is_array($item)||count($item)!==3||!is_string($alias=$item['alias']??null)||
            preg_match('/\A[a-z][a-z0-9-]{0,31}\z/',$alias)!==1||
            !is_string($id=$item['product']??null)||!isset($products[$id])||
            !is_string($location=$item['location_id']??null)||
            preg_match('/\A[A-Za-z0-9_-]{1,100}\z/',$location)!==1)
            throw new InvalidArgumentException('Choose valid live data sources and public locations.');
        $binding=['alias'=>$alias,'product'=>$id,'location_id'=>$location];
        if(isset($sources[$alias])&&$sources[$alias]!==$binding)
            throw new InvalidArgumentException('Give each live data source a distinct name.');
        $sources[$alias]=$binding;
    }
    return array_values($sources);
}

function sf_canvas_data_source_token(string $value): array {
    $parts=explode(':',$value);
    if(count($parts)!==3)throw new InvalidArgumentException('Choose live data sources from /data.');
    return ['alias'=>strtolower($parts[0]),'product'=>strtolower($parts[1]),'location_id'=>$parts[2]];
}

function sf_canvas_data_declared_capabilities(array $sources): array {
    $products=sf_canvas_data_products();$ids=[];
    foreach($sources as $source)if(isset($products[$source['product']??'']))$ids[$products[$source['product']]['capability']]=true;
    return $ids;
}

/** Public metadata for discovery, model instructions and saved bindings. */
function sf_canvas_data_public_place(array $place): array {
    return array_intersect_key($place,['id'=>true,'title'=>true,'kind'=>true]);
}

function sf_canvas_data_location_scope(array $product,string $location): ?array {
    foreach($product['parameters']['location_id']['scope_options']??[] as $scope){
        if($scope['id']===$location)return $scope;
    }
    return null;
}

/** The attachment contains public target coordinates, never a viewer's device location. */
function sf_canvas_data_point(string $id): ?array {
    if(preg_match('/\Apoint-(0|-?[1-9][0-9]{0,8})_(0|-?[1-9][0-9]{0,8})_([1-9][0-9]{0,4})\z/',$id,$values)!==1)return null;
    $lat=(int)$values[1];$lon=(int)$values[2];$radius=(int)$values[3];
    if(abs($lat)>90000000||abs($lon)>180000000||$radius<SF_CANVAS_DATA_MIN_RADIUS_M||$radius>SF_CANVAS_DATA_MAX_RADIUS_M)return null;
    $lat=$lat/1000000;$lon=$lon/1000000;
    return ['id'=>$id,'title'=>sprintf('%.6F°, %.6F° · %d m',$lat,$lon,$radius),'kind'=>'point',
        'lat'=>$lat,'lon'=>$lon,'radius_m'=>$radius];
}

function sf_canvas_data_saved_place(array $product,string $location): ?array {
    if(($product['parameters']['location_id']['type']??null)==='public_point')return sf_canvas_data_point($location);
    return sf_canvas_data_location_scope($product,$location)??sf_canvas_data_place($location,true);
}

function sf_canvas_data_source_context(array $sources): array {
    require_once __DIR__.'/canvas-data-places.php';
    $products=array_column(sf_canvas_data_inventory()['products'],null,'id');
    $out=[];
    foreach($sources as $source){
        $product=$products[$source['product']]??null;
        if($product===null)throw new InvalidArgumentException('A selected live data service is disabled. Update the /data selection.');
        $place=sf_canvas_data_saved_place($product,$source['location_id']);
        if($place===null)throw new InvalidArgumentException('A selected live data location is unavailable. Update the /data selection.');
        $out[]=$source+['location'=>sf_canvas_data_public_place($place),'schema'=>$product];
    }
    return $out;
}

function sf_canvas_data_binding(array $viewer,array $context,string $alias): array {
    require_once __DIR__.'/canvas-access-core.php';
    if(isset($context['scene_id'])){
        $q=sf_db()->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");
        $q->execute([(int)($viewer['id']??0)]);$active=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$active)throw new OutOfBoundsException('Canvas data source unavailable.');
    }
    $resolved=sf_canvas_access_resolve($viewer,$context);
    foreach($resolved['scene']['artist_inputs']['data_sources']??[] as $source)if($source['alias']===$alias)return $source;
    throw new OutOfBoundsException('Canvas data source unavailable.');
}

/** This adapter resolves the public-place parameter into the provider's point input. */
function sf_canvas_data_read_weather(array $place,string $operation,?callable $read=null): array {
    require_once __DIR__.'/canvas-weather-core.php';
    return ($read??sf_canvas_weather_read(...))((float)$place['lat'],(float)$place['lon'],$operation);
}

function sf_canvas_data_read_geography(array $place,string $operation): array {
    require_once __DIR__.'/canvas-geography-core.php';
    return sf_canvas_geography_read($place,$operation);
}

function sf_canvas_data_read_proximity(array $place): array {
    $now=time();
    return ['status'=>'fresh','source'=>'Stonefellow public target','fetched_at'=>gmdate('c',$now),
        'expires_at'=>gmdate('c',$now+60),'timezone'=>null,
        'rows'=>[['lat'=>$place['lat'],'lon'=>$place['lon'],'radius_m'=>$place['radius_m']]]];
}

/** Resolve an exact saved place or a runtime venue within an explicitly selected scope. */
function sf_canvas_data_read_place(array $product,string $location,?string $requestedLocation): ?array {
    if($location===SF_CANVAS_DATA_SHOW_SCOPE&&sf_canvas_data_location_scope($product,$location)!==null){
        if($requestedLocation===null||preg_match('/\Ashow-[a-z0-9][a-z0-9-]{0,79}\z/',$requestedLocation)!==1)
            throw new InvalidArgumentException('Choose a published venue for this live data source.');
        require_once __DIR__.'/canvas-geography-core.php';
        return sf_canvas_geography_upcoming_venue($requestedLocation);
    }
    if($requestedLocation!==null)throw new InvalidArgumentException('This live data source has a fixed location.');
    return sf_canvas_data_saved_place($product,$location);
}

/** Only the current saved scene or approved gallery can select a binding. */
function sf_canvas_data_read(array $viewer,array $context,string $alias,?callable $read=null,?string $requestedLocation=null): array {
    if(preg_match('/\A[a-z][a-z0-9-]{0,31}\z/',$alias)!==1)throw new InvalidArgumentException('Choose a valid live data name.');
    require_once __DIR__.'/canvas-data-places.php';
    $source=sf_canvas_data_binding($viewer,$context,$alias);
    $product=sf_canvas_data_products()[$source['product']];
    $unavailable=['status'=>'unavailable','source'=>$product['provider'],'fetched_at'=>null,'expires_at'=>null,'timezone'=>null,'rows'=>[]];
    if(!sf_canvas_permission_enabled($product['capability']))return $unavailable;
    $place=sf_canvas_data_read_place($product,$source['location_id'],$requestedLocation);
    if($place===null)return $unavailable;
    $data=$product['reader']($place,$product['operation'],$read);
    // A slow provider response must not outlive a withdrawal or a relocated venue.
    if(sf_canvas_data_binding($viewer,$context,$alias)!==$source||!sf_canvas_permission_enabled($product['capability'])||
        sf_canvas_data_read_place($product,$source['location_id'],$requestedLocation)!==$place)return $unavailable;
    if($requestedLocation!==null&&isset($data['expires_at'])){
        $expiry=strtotime($data['expires_at']);
        $nextDay=sf_canvas_geography_next_utc_day(time());
        if($expiry!==false&&$expiry>$nextDay)$data['expires_at']=gmdate('c',$nextDay);
    }
    return $data+['alias'=>$source['alias'],'product'=>$source['product'],'location'=>sf_canvas_data_public_place($place)];
}
