<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
sf_inquiry_ensure_schema();$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='GET')sf_json_response(['ok'=>true,'types'=>array_map(fn($x)=>['value'=>$x,'label'=>sf_inquiry_type_label($x)],sf_inquiry_types()),'references'=>sf_inquiry_public_references()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();
if(trim((string)($b['company_fax']??''))!=='')sf_json_response(['ok'=>true,'submitted'=>true,'reference'=>'']);
$now=time();$attempts=array_values(array_filter((array)($_SESSION['sf_professional_inquiries']??[]),fn($t)=>(int)$t>$now-600));if(count($attempts)>=5)sf_json_response(['ok'=>false,'message'=>'Too many inquiry submissions. Please try again later.'],429);$attempts[]=$now;$_SESSION['sf_professional_inquiries']=$attempts;
try{$r=sf_inquiry_submit($b);sf_json_response(['ok'=>true,'submitted'=>true,'reference'=>$r['reference'],'duplicate'=>!empty($r['duplicate'])]);}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
