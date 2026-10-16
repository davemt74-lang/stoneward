<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fan-purchase-journey-core.php';
require_once __DIR__.'/library-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')
 sf_json_response(['ok'=>false,'message'=>'Purchase journey is read-only.'],405);
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
 $user=sf_current_user();
 $uid=$user&&isset($user['id'])?(int)$user['id']:null;
 $path=(string)($_GET['path']??'start_here');
 $limit=max(1,min(12,(int)($_GET['limit']??8)));
 sf_json_response(['ok'=>true,'journey'=>sf_fan_purchase_paths($uid,$path,$limit)]);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
 error_log('87B fan journey: '.get_class($e).': '.$e->getMessage());
 sf_json_response(['ok'=>false,'message'=>'Purchase journey temporarily unavailable.'],503);
}
