<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/vp3-music-core.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||array_diff(array_keys($_GET),['track_id']))
    sf_json_response(['ok'=>false,'code'=>'method_not_allowed','message'=>'Use a public track identifier.'],405);
$id=$_GET['track_id']??null;
if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',$id))sf_json_response(['ok'=>false,'code'=>'source_unavailable','message'=>'This song is unavailable.'],404);
try{
    $track=function_exists('sf_seo_public_track')?sf_seo_public_track($id):(sf_track_map()[$id]??null);
    $source=is_array($track)?sf_vp3_music_source_for_track($track):null;
    if(!$source)sf_json_response(['ok'=>false,'code'=>'source_unavailable','message'=>'This VP3 song is unavailable.'],404);
    sf_json_response(['ok'=>true,'protocol'=>SF_VP3_MUSIC_PROTOCOL,'source'=>$source]);
}catch(Throwable){sf_json_response(['ok'=>false,'code'=>'source_unavailable','message'=>'This VP3 song is unavailable.'],404);}
