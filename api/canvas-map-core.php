<?php
declare(strict_types=1);

const SF_CANVAS_MAP_SOURCE='U.S. Census Bureau, 2025 Cartographic Boundary Files (1:20m)';

/** Read bundled boundary geometry for a resolved public place or the U.S. overview. */
function sf_canvas_map_read(array $place): array {
    $unavailable=['status'=>'unavailable','source'=>SF_CANVAS_MAP_SOURCE,
        'fetched_at'=>null,'expires_at'=>null,'timezone'=>null,'rows'=>[]];
    $overview=($place['id']??null)==='us-regions';
    $region=$place['region']??null;
    if(!$overview&&(!is_string($region)||preg_match('/\A[A-Z]{2}\z/',$region)!==1))return $unavailable;

    $file=dirname(__DIR__).'/config/canvas-maps/'.($overview?'us-overview.json':'us-regions.json');
    $bytes=@file_get_contents($file,false,null,0,450001);
    if($bytes===false||strlen($bytes)>450000)return $unavailable;
    $layer=json_decode($bytes,true,8);
    $rows=$overview?$layer:($layer[$region]??null);
    if(!is_array($rows)||!array_is_list($rows)||!$rows||count($rows)>120)return $unavailable;

    $now=time();
    return ['status'=>'fresh','source'=>SF_CANVAS_MAP_SOURCE,'fetched_at'=>gmdate('c',$now),
        'expires_at'=>gmdate('c',$now+86400),'timezone'=>null,'rows'=>$rows];
}
