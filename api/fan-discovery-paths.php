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

/** Pure ranker: existing personalization signals, never opaque LLM claims. */
function sf_fan_discovery_rank(array $catalog,string $path,?array $profile=null,int $limit=8):array {
 $paths=sf_fan_discovery_paths();
 if(!isset($paths[$path]))throw new InvalidArgumentException('Unknown discovery path.');
 $rules=$paths[$path];$personal=!empty($profile['personalized']);$limit=max(1,min(12,$limit));
 $base=[];
 if($personal&&function_exists('sf_personalization_rank_catalog'))
  foreach(sf_personalization_rank_catalog($catalog,$profile,30) as $i=>$r)
   $base[(string)$r['track_id']]=max(0,10-$i);
 $rows=[];
 foreach(sf_fan_discovery_public($catalog) as $index=>$t){
  $id=(string)$t['id'];
  if($profile!==null&&($profile['feedback'][$id]['sentiment']??'')==='dislike')continue;
  $moods=array_map('strtolower',(array)($t['mood']??[]));
  $themes=array_map('strtolower',(array)($t['themes']??[]));
  $mh=array_values(array_intersect($moods,$rules['moods']));
  $th=array_values(array_intersect($themes,$rules['themes']));
  $score=count($mh)*12+count($th)*8;
  if($rules['energy']!==null)$score+=max(0,6-abs((int)($t['energy']??3)-$rules['energy'])*3);
  if($personal){
   $score+=$base[$id]??0;
   $seen=max(0,(int)($profile['seen_counts'][$id]??0));
   if($path==='deep_cuts')$score+=max(0,32-$seen*9);
   elseif($path==='start_here')$score+=max(0,10-$seen*2);
  }elseif($path==='deep_cuts')$score+=max(0,6-$index);
  $reason=$mh?'Fits the '.$mh[0].' mood.':($th?'Explores '.$th[0].'.':
   ($path==='deep_cuts'&&$personal?'A less familiar Stonefellow song.':'Explore the Stonefellow catalog.'));
  $rows[]=['score'=>$score,'index'=>$index,'item'=>[
   'track_id'=>$id,'title'=>(string)($t['title']??$id),
   'release'=>(string)($t['release']??''),'artwork'=>(string)($t['artwork']??''),
   'duration'=>max(0,(int)($t['duration']??0)),'reason'=>$reason]];
 }
 usort($rows,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:($a['index']<=>$b['index']));
 return array_map(static fn(array $r):array=>$r['item'],array_slice($rows,0,$limit));
}

/** Resolve a fan only from the authenticated session, never a URL user_id. */
function sf_fan_discovery_state(?int $userId,string $path='start_here',int $limit=8):array {
 $paths=sf_fan_discovery_paths();
 if(!isset($paths[$path]))throw new InvalidArgumentException('Unknown discovery path.');
 $allowed=$userId!==null&&$userId>0
  &&function_exists('sf_privacy_personalization_enabled')
  &&sf_privacy_personalization_enabled($userId);
 $profile=$allowed&&function_exists('sf_personalization_recommendation_profile')
  ?sf_personalization_recommendation_profile($userId):null;
 $tabs=[];foreach($paths as $key=>$spec)$tabs[]=['id'=>$key,'title'=>$spec['title']];
 return [
  'path'=>$path,'paths'=>$tabs,'allow_feedback'=>$allowed,'personalized'=>$allowed&&!empty($profile['personalized']),
  'summary'=>$allowed&&!empty($profile['personalized'])
   ?'Guided by your saved songs and listening preferences.'
   :'Explore the Stonefellow catalog without personal listening history.',
  'items'=>sf_fan_discovery_rank(sf_catalog(),$path,$profile,$limit),
  'privacy'=>'Listening history is not used without the existing permission.',
 ];
}
