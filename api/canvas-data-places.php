<?php
declare(strict_types=1);

/** Normalize names for matching while keeping the source spelling in labels. */
function sf_canvas_data_place_key(string $value): string {
    if(function_exists('iconv')&&preg_match('/[^\x00-\x7F]/',$value)){
        $ascii=@iconv('UTF-8','ASCII//TRANSLIT',$value);
        if(is_string($ascii)&&!str_contains($ascii,'?'))$value=$ascii;
    }
    return sf_search_normalize($value);
}

function sf_canvas_data_place_states(): array {
    static $aliases=null;
    if($aliases!==null)return $aliases;
    $states=[
        'AL'=>'alabama','AK'=>'alaska','AZ'=>'arizona','AR'=>'arkansas','CA'=>'california',
        'CO'=>'colorado','CT'=>'connecticut','DE'=>'delaware','DC'=>'district of columbia','FL'=>'florida',
        'GA'=>'georgia','HI'=>'hawaii','ID'=>'idaho','IL'=>'illinois','IN'=>'indiana','IA'=>'iowa','KS'=>'kansas',
        'KY'=>'kentucky','LA'=>'louisiana','ME'=>'maine','MD'=>'maryland','MA'=>'massachusetts','MI'=>'michigan',
        'MN'=>'minnesota','MS'=>'mississippi','MO'=>'missouri','MT'=>'montana','NE'=>'nebraska','NV'=>'nevada',
        'NH'=>'new hampshire','NJ'=>'new jersey','NM'=>'new mexico','NY'=>'new york','NC'=>'north carolina',
        'ND'=>'north dakota','OH'=>'ohio','OK'=>'oklahoma','OR'=>'oregon','PA'=>'pennsylvania','PR'=>'puerto rico',
        'RI'=>'rhode island','SC'=>'south carolina','SD'=>'south dakota','TN'=>'tennessee','TX'=>'texas',
        'UT'=>'utah','VT'=>'vermont','VA'=>'virginia','WA'=>'washington','WV'=>'west virginia','WI'=>'wisconsin',
        'WY'=>'wyoming',
    ];
    $aliases=['d c'=>'DC'];
    foreach($states as $code=>$name){$aliases[$name]=$code;$aliases[strtolower($code)]=$code;}
    uksort($aliases,static fn(string $left,string $right):int=>strlen($right)<=>strlen($left));
    return $aliases;
}

/** Stream deployment-owned Census shards, with no external or per-user lookup. */
function sf_canvas_data_place_cities(?string $state=null,?string $code=null): Generator {
    foreach(glob(dirname(__DIR__).'/config/canvas-data-places/us-places-*.tsv')?:[] as $file){
        $stream=fopen($file,'rb');
        if($stream===false)continue;
        try{
            while(($line=fgets($stream))!==false){
                if($state!==null&&substr($line,8,2)!==$state)continue;
                if($code!==null&&substr($line,0,7)!==$code)continue;
                [$geoid,$region,$name,$alternate,$lat,$lon,$label]=array_pad(explode("\t",rtrim($line,"\r\n"),7),7,'');
                if(!preg_match('/\A[0-9]{7}\z/',$geoid)||!preg_match('/\A[A-Z]{2}\z/',$region)||$name===''||
                    !is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)continue;
                yield [
                    'id'=>'city-'.$geoid,'title'=>($label?:$name).', '.$region,'kind'=>'city',
                    'lat'=>(float)$lat,'lon'=>(float)$lon,'region'=>$region,'name'=>$name,'alternate'=>$alternate,
                ];
            }
        }finally{fclose($stream);}
    }
}

function sf_canvas_data_place_result(array $place): array {
    return array_intersect_key($place,array_flip(['id','title','kind','lat','lon']));
}

/** A published show uses the centroid for its unambiguously identified city. */
function sf_canvas_data_place_show_city(array $show): ?array {
    $id=(string)($show['id']??'');
    if(!preg_match('/\A[a-z0-9][a-z0-9-]{0,79}\z/',$id)||($show['public_visible']??true)!==true||
        !in_array((string)($show['status']??'scheduled'),['scheduled','completed','cancelled','postponed'],true))return null;
    $city=sf_canvas_data_place_key((string)($show['city']??''));
    $region=sf_canvas_data_place_states()[sf_canvas_data_place_key((string)($show['region']??''))]??null;
    $country=sf_canvas_data_place_key((string)($show['country']??''));
    if($city===''||$region===null||(!in_array($country,['','us','u s','usa','u s a','united states','united states of america'],true)&&
        !($region==='PR'&&in_array($country,['pr','puerto rico'],true))))return null;
    $venue=sf_clean_text($show['venue']??'',80);
    $name=sf_clean_text($show['title']??'',80);
    if($venue==='')$venue=$name;
    if($venue==='')return null;
    $date=(string)($show['date']??'');
    $date=preg_match('/\A\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?\z/',$date)?substr($date,0,10):'';
    return ['id'=>'show-'.$id,'city'=>$city,'region'=>$region,'venue'=>$venue,'name'=>$name,'date'=>$date];
}

function sf_canvas_data_place_venue_result(array $show,array $city): array {
    $label=$show['venue'].($show['date']!==''?' · '.$show['date']:'').' · '.$city['title'].' (city weather)';
    return ['id'=>$show['id'],'title'=>$label,'kind'=>'venue','lat'=>$city['lat'],'lon'=>$city['lon']];
}

function sf_canvas_data_place(string $id): ?array {
    if(preg_match('/\Acity-([0-9]{7})\z/',$id,$match)){
        foreach(sf_canvas_data_place_cities(null,$match[1]) as $city)return sf_canvas_data_place_result($city);
        return null;
    }
    if(!preg_match('/\Ashow-([a-z0-9][a-z0-9-]{0,79})\z/',$id,$match))return null;
    $publicShow=sf_live_show_by_id($match[1]);
    $show=$publicShow===null?null:sf_canvas_data_place_show_city($publicShow);
    if($show===null)return null;
    $exact=null;
    foreach(sf_canvas_data_place_cities($show['region']) as $city){
        if($show['city']!==sf_canvas_data_place_key($city['name'])&&
            $show['city']!==sf_canvas_data_place_key($city['alternate']))continue;
        if($exact!==null)return null;
        $exact=$city;
    }
    return $exact===null?null:sf_canvas_data_place_venue_result($show,$exact);
}

/** Search by city or venue, optionally ending with a state name or abbreviation. */
function sf_canvas_data_places(string $query): array {
    $query=trim($query);
    if(strlen($query)>100||!preg_match('/\A[^\x00-\x1F\x7F]*\z/u',$query))
        throw new InvalidArgumentException('Enter a city or venue name up to 100 bytes.');
    $needle=sf_canvas_data_place_key($query);
    $needle=preg_replace('/ (?:united states(?: of america)?|u s(?: a)?|usa?)\z/','',$needle)??$needle;
    if($query!==''&&preg_match('/\A.{2}/us',$needle)!==1)return [];
    $region=null;
    foreach(sf_canvas_data_place_states() as $suffix=>$state){
        if(!str_ends_with($needle,' '.$suffix))continue;
        $candidate=substr($needle,0,-strlen($suffix)-1);
        if(preg_match('/\A.{2}/us',$candidate)!==1)continue;
        $needle=$candidate;$region=$state;break;
    }
    $venues=[];$venueCities=[];
    foreach(sf_live_public_shows() as $publicShow){
        $show=sf_canvas_data_place_show_city($publicShow);
        if($show===null||($region!==null&&$show['region']!==$region))continue;
        if($needle!==''&&!str_contains(sf_canvas_data_place_key($show['venue'].' '.$show['name'].' '.$show['city']),$needle))continue;
        $cityKey=$show['region'].':'.$show['city'];
        $venues[]=$show+['city_key'=>$cityKey];
        $venueCities[$cityKey]=null;
    }
    if($needle===''&&!$venues)return [];

    $exact=[];$prefix=[];$partial=[];
    foreach(sf_canvas_data_place_cities($region) as $city){
        $name=sf_canvas_data_place_key($city['name']);
        $alternate=sf_canvas_data_place_key($city['alternate']);
        if($venues){
            foreach(array_unique([$name,$alternate]) as $key){
                $lookup=$city['region'].':'.$key;
                if(array_key_exists($lookup,$venueCities))$venueCities[$lookup]=$venueCities[$lookup]===null?$city:false;
            }
        }
        if($needle==='')continue;
        if($name===$needle||$alternate===$needle){
            if(count($exact)<18)$exact[]=sf_canvas_data_place_result($city);
        }elseif(str_starts_with($name,$needle)||str_starts_with($alternate,$needle)){
            if(count($prefix)<18)$prefix[]=sf_canvas_data_place_result($city);
        }elseif(str_contains($name,$needle)||str_contains($alternate,$needle)){
            if(count($partial)<18)$partial[]=sf_canvas_data_place_result($city);
        }
    }
    $matches=$exact;
    foreach($venues as $show){
        if(count($matches)>=18)break;
        $city=$venueCities[$show['city_key']];
        if(is_array($city))$matches[]=sf_canvas_data_place_venue_result($show,$city);
    }
    return array_slice(array_merge($matches,$prefix,$partial),0,18);
}
