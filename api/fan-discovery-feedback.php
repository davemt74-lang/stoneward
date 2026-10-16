<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-discovery-paths.php';
header('Cache-Control: private, no-store, max-age=0');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')
 sf_json_response(['ok'=>false,'message'=>'POST required.'],405);
$u=sf_require_user(false,true);$uid=(int)$u['id'];
try{
 if(!sf_privacy_personalization_enabled($uid))
  throw new InvalidArgumentException('Enable personal music recommendations in Privacy Settings first.');
 $now=time();
 $events=array_values(array_filter((array)($_SESSION['sf_discovery_feedback_rate']??[]),
  static fn($stamp)=>(int)$stamp>$now-600));
 if(count($events)>=30)sf_json_response(['ok'=>false,'message'=>'Too many feedback changes.'],429);
 $request=sf_request_json();
 $id=(string)($request['track_id']??'');
 $sentiment=(string)($request['sentiment']??'');
 if(!in_array($sentiment,['like','dislike','neutral'],true)||
    !sf_seo_public_track($id))throw new InvalidArgumentException('Unknown public track or feedback value.');
 $events[]=$now;$_SESSION['sf_discovery_feedback_rate']=$events;
 $value=sf_track_feedback_set($uid,$id,$sentiment,'fan_discovery');
 $path=(string)($request['path']??'start_here');
 sf_json_response(['ok'=>true,'feedback'=>$value,
  'discovery'=>sf_fan_discovery_state($uid,$path,8),'csrf'=>sf_user_csrf()]);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}
