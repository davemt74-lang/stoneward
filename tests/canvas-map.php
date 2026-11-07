<?php
declare(strict_types=1);

require dirname(__DIR__).'/api/canvas-map-core.php';

function map_check(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    echo "PASS $label\n";
}

/** Reconstruct the exact ring keys and coordinates expected by authored canvases. */
function map_rings(array $rows): array {
    $rings=[];$nextChunk=[];$last=null;
    foreach($rows as $row){
        if(array_keys($row)!==['region','name','part','ring','chunk','coordinates']||
            preg_match('/\A[A-Z]{2}\z/',$row['region'])!==1||!is_string($row['name'])||$row['name']===''||
            !is_int($row['part'])||$row['part']<0||!is_int($row['ring'])||$row['ring']<0||
            !is_int($row['chunk'])||$row['chunk']<0||!is_string($row['coordinates'])||
            strlen($row['coordinates'])>4000)throw new RuntimeException('Unexpected map row fields.');
        $identity=[$row['region'],$row['part'],$row['ring'],$row['chunk']];
        if($last!==null&&$identity<=$last)throw new RuntimeException('Map chunks are out of order.');
        $last=$identity;
        $key=$row['region'].':'.$row['part'].':'.$row['ring'];
        if($row['chunk']!==($nextChunk[$key]??0))throw new RuntimeException('Map chunk missing.');
        $nextChunk[$key]=$row['chunk']+1;
        $points=json_decode($row['coordinates'],true,8,JSON_THROW_ON_ERROR);
        if(!array_is_list($points)||!$points)throw new RuntimeException('Missing map coordinates.');
        foreach($points as $point){
            if(!array_is_list($point)||count($point)!==2||
                !(is_int($point[0])||is_float($point[0]))||!is_finite($point[0])||abs($point[0])>180||
                !(is_int($point[1])||is_float($point[1]))||!is_finite($point[1])||abs($point[1])>90)
                throw new RuntimeException('Invalid WGS84 longitude or latitude.');
            $rings[$key][]=$point;
        }
    }
    foreach($rings as $points){
        if(count($points)<4||$points[0]!==$points[count($points)-1])
            throw new RuntimeException('Map ring failed to close.');
    }
    return $rings;
}

$arizona=sf_canvas_map_read(['id'=>'city-0455000','region'=>'AZ']);
map_check($arizona['status']==='fresh'&&$arizona['source']===SF_CANVAS_MAP_SOURCE&&$arizona['timezone']===null&&
    strtotime($arizona['expires_at'])-strtotime($arizona['fetched_at'])===86400&&
    array_unique(array_column($arizona['rows'],'region'))===['AZ']&&
    array_unique(array_column($arizona['rows'],'name'))===['Arizona'],
    'A selected city receives attributed boundaries for its region.');
$arizonaRings=map_rings($arizona['rows']);
$points=$arizonaRings['AZ:0:0'];
map_check(count($arizonaRings)===1&&min(array_column($points,0))< -114&&max(array_column($points,0))> -110&&
    min(array_column($points,1))<32&&max(array_column($points,1))>36,
    'Arizona rings reconstruct in longitude, latitude order.');

$overview=sf_canvas_map_read(['id'=>'us-regions']);
$regions=array_values(array_unique(array_column($overview['rows'],'region')));
$overviewRings=map_rings($overview['rows']);
map_check($overview['status']==='fresh'&&count($regions)===52&&
    !array_diff(['AK','DC','HI','PR'],$regions)&&count($overview['rows'])<=120&&
    strlen(json_encode($overview,JSON_THROW_ON_ERROR))<256*1024,
    'The national overview covers all states, DC and Puerto Rico within the SDK budget.');

$allRegionalRings=[];$counts=[];
foreach($regions as $region){
    $data=sf_canvas_map_read(['region'=>$region]);
    map_check($data['status']==='fresh'&&array_unique(array_column($data['rows'],'region'))===[$region]&&
        count($data['rows'])<=120&&strlen(json_encode($data,JSON_THROW_ON_ERROR))<256*1024,
        "The $region detail layer stays within its approved region and the SDK budget.");
    $rings=map_rings($data['rows']);
    $allRegionalRings+=$rings;
    $counts[$region]=count(array_unique(array_column($data['rows'],'part')));
}
map_check(array_sum($counts)===131&&$counts['AK']===47&&$counts['HI']===8&&$counts['PR']===4,
    'Regional detail retains every Census polygon part, including islands.');
map_check(count($overviewRings)>count($regions)&&count($overviewRings)<count($allRegionalRings)&&
    !array_diff_key($overviewRings,$allRegionalRings),'Overview preserves stable part keys and omits minor polygons.');

foreach([[],['region'=>'../AZ'],['region'=>['AZ']],['region'=>'ZZ'],['id'=>'unapproved-region']] as $place){
    $data=sf_canvas_map_read($place);
    if($data['status']!=='unavailable'||$data['rows']!==[])
        throw new RuntimeException('An invalid region unexpectedly returned boundaries.');
}
map_check(true,'Missing, malformed and unsupported map regions return unavailable.');
