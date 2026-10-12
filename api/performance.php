<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_web_vitals']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=40)sf_json_response(['ok'=>false,'message'=>'Too many performance events.'],429);$attempts[]=$now;$_SESSION['sf_web_vitals']=$attempts;
try{$e=sf_performance_record(sf_request_json());sf_json_response(['ok'=>true,'metric'=>$e['metric']]);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
