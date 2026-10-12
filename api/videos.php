<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_video_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();$uid=(int)($user['id']??0);
if($method==='GET'){$slug=sf_clean_text($_GET['slug']??'',160);if($slug==='')sf_json_response(['ok'=>true,'videos'=>sf_video_public_list(),'featured'=>sf_video_featured()]);$video=sf_video_public($slug);if(!$video)sf_json_response(['ok'=>false,'message'=>'Video not found.'],404);sf_json_response(['ok'=>true,'video'=>$video]);}
if($method==='POST'){$now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_video_events']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=90)sf_json_response(['ok'=>false,'message'=>'Too many video events.'],429);$attempts[]=$now;$_SESSION['sf_video_events']=$attempts;$b=sf_request_json();$id=(int)($b['video_id']??0);if(!sf_video_public($id))sf_json_response(['ok'=>false,'message'=>'Video not found.'],404);sf_video_event($id,(string)($b['event_type']??''),$uid,(array)($b['detail']??[]));sf_json_response(['ok'=>true]);}
sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
