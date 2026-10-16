<?php
declare(strict_types=1);
/** 87A1 discover within the existing public music catalog. */
function sf_fan_discovery_paths():array {
 return [
  'start_here'=>['title'=>'Start here','moods'=>[],'themes'=>[],'energy'=>null],
  'after_dark'=>['title'=>'After dark','moods'=>['desert night','reflective','dark'],'themes'=>['night','distance'],'energy'=>2],
  'open_road'=>['title'=>'Open road','moods'=>['driving','restless'],'themes'=>['road','motion'],'energy'=>4],
  'slow_down'=>['title'=>'Slow down','moods'=>['quiet','acoustic','intimate'],'themes'=>['home','memory'],'energy'=>1],
  'deep_cuts'=>['title'=>'Dig deeper','moods'=>[],'themes'=>[],'energy'=>null],
 ];
}
function sf_fan_discovery_public(array $catalog):array {
 $rows=[];
 foreach($catalog as $track){
  $id=(string)($track['id']??'');
  if($id===''||($track['public_visible']??true)===false
   ||in_array((string)($track['state']??''),['draft','private','archived'],true))continue;
  if(function_exists('sf_seo_public_track')&&!sf_seo_public_track($id))continue;
  $rows[]=$track;
 }
 return $rows;
}
