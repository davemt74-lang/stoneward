<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$user=sf_current_user();$uid=(int)($user['id']??0);
if($method==='GET')sf_json_response(['ok'=>true,'summary'=>sf_seo_discovery_summary(max(1,min(365,(int)($_GET['days']??30))))]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_discovery_events']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=60)sf_json_response(['ok'=>false,'message'=>'Too many discovery events.'],429);$attempts[]=$now;$_SESSION['sf_discovery_events']=$attempts;
$b=sf_request_json();$event=(string)($b['event_type']??'landing');sf_seo_record_event($event,$b,$uid);sf_json_response(['ok'=>true]);
