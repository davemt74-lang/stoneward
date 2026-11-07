<?php
declare(strict_types=1);

const SF_CANVAS_DATA_MAX_SOURCES=4;

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
            'parameters'=>['location_id'=>['type'=>'public_place','label'=>'City or venue']],
            'permission_id'=>'canvas.data.weather_nws','capability'=>'weather_nws','provider'=>'National Weather Service',
            'operation'=>$operation,'reader'=>'sf_canvas_data_read_weather'];
    }
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

function sf_canvas_data_source_context(array $sources): array {
    require_once __DIR__.'/canvas-data-places.php';
    $products=array_column(sf_canvas_data_inventory()['products'],null,'id');
    $out=[];
    foreach($sources as $source){
        $product=$products[$source['product']]??null;
        if($product===null)throw new InvalidArgumentException('A selected live data service is disabled. Update the /data selection.');
        $place=sf_canvas_data_place($source['location_id']);
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

/** Only the current saved scene or approved gallery can select a binding. */
function sf_canvas_data_read(array $viewer,array $context,string $alias,?callable $read=null): array {
    if(preg_match('/\A[a-z][a-z0-9-]{0,31}\z/',$alias)!==1)throw new InvalidArgumentException('Choose a valid live data name.');
    require_once __DIR__.'/canvas-data-places.php';
    $source=sf_canvas_data_binding($viewer,$context,$alias);
    $product=sf_canvas_data_products()[$source['product']];
    $unavailable=['status'=>'unavailable','source'=>$product['provider'],'fetched_at'=>null,'expires_at'=>null,'timezone'=>null,'rows'=>[]];
    if(!sf_canvas_permission_enabled($product['capability']))return $unavailable;
    $place=sf_canvas_data_place($source['location_id']);
    if($place===null)return $unavailable;
    $data=$product['reader']($place,$product['operation'],$read);
    // A slow provider response must not outlive a withdrawal or a relocated venue.
    if(sf_canvas_data_binding($viewer,$context,$alias)!==$source||!sf_canvas_permission_enabled($product['capability'])||
        sf_canvas_data_place($source['location_id'])!==$place)return $unavailable;
    return $data+['alias'=>$source['alias'],'product'=>$source['product'],'location'=>sf_canvas_data_public_place($place)];
}
