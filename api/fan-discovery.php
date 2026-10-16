<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-discovery-paths.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
 sf_json_response(['ok'=>false,'message'=>'Discovery is read only.'],405);
$user=sf_current_user();
$uid=($user&&isset($user['id']))?(int)$user['id']:null;
try{
 $path=(string)($_GET['path']??'start_here');
 $limit=max(1,min(12,(int)($_GET['limit']??8)));
 sf_json_response(['ok'=>true,'discovery'=>sf_fan_discovery_state($uid,$path,$limit)]);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}
