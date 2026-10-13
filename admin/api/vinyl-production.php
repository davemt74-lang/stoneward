<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$admin=sf_admin_require_auth($method!=='GET');
$body=$method==='POST'?sf_request_json():[];
$id=sf_clean_text($method==='GET'?($_GET['order_id']??''):($body['order_id']??''),100);
if($id==='' || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/',$id)) sf_json_response(['ok'=>false,'message'=>'Valid order ID required.'],422);
try {
  sf_fulfillment_ensure_schema();
  $snapshot=sf_fulfillment_order_snapshot($id);
  $order=(array)($snapshot['order']??[]);
  $quote=(array)($order['quote']??[]);
  $json=json_encode($quote,JSON_UNESCAPED_SLASHES);
  if(!is_string($json)||!str_contains($json,'"custom_media"')) sf_json_response(['ok'=>false,'message'=>'No custom vinyl product on this order.'],404);
  $dir=SF_ROOT.'/storage/fulfillment';
  if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir)) throw new RuntimeException('Production storage unavailable.');
  $path=$dir.'/vinyl-production.json';
  $lock=fopen($dir.'/vinyl-production.lock','c+');
  if(!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('Production lock unavailable.');
  try {
    $raw=is_file($path)?file_get_contents($path):'{}';
    $records=json_decode((string)$raw,true);
    if(!is_array($records)) throw new RuntimeException('Production record storage is corrupt.');
    $record=$records[$id]??['order_id'=>$id,'status'=>'awaiting_artwork_review','revision'=>0,'events'=>[],'updated_at'=>null];
    $stages=['awaiting_artwork_review','artwork_approved','in_production','quality_check','production_complete'];
    if($method==='POST'){
      if(($body['action']??'')!=='advance') sf_json_response(['ok'=>false,'message'=>'Unsupported production action.'],422);
      $expected=(int)($body['revision']??-1);
      if($expected!==(int)$record['revision']) sf_json_response(['ok'=>false,'message'=>'Production state has changed. Refresh before editing.'],409);
      if(!in_array((string)($order['status']??''),['paid','completed','confirmed','fulfilled'],true)) sf_json_response(['ok'=>false,'message'=>'Only confirmed paid orders may advance production.'],422);
      $next=sf_clean_text($body['status']??'',60);
      $currentIndex=array_search($record['status'],$stages,true);
      if($currentIndex===false||!isset($stages[$currentIndex+1])||$stages[$currentIndex+1]!==$next) sf_json_response(['ok'=>false,'message'=>'Choose the next production stage in sequence.'],422);
      if(empty($body['confirmed'])) sf_json_response(['ok'=>false,'message'=>'Explicit approval is required.'],422);
      $note=sf_clean_text($body['note']??'',500);
      $now=gmdate('c');
      $record['events'][]=['at'=>$now,'from'=>$record['status'],'to'=>$next,'admin_id'=>(int)$admin['id'],'note'=>$note];
      $record['status']=$next;
      $record['updated_at']=$now;
      $record['revision']++;
      $records[$id]=$record;
      $encoded=json_encode($records,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
      $tmp=tempnam($dir,'vinyl_');
      if(!$tmp||file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$path)) throw new RuntimeException('Unable to persist production status.');
      @chmod($path,0660);
      sf_log_admin_action((int)$admin['id'],'vinyl_production_stage','order',$id,['status'=>$next,'revision'=>$record['revision']]);
    } elseif($method!=='GET') sf_json_response(['ok'=>false,'message'=>'Method not allowed.'],405);
    sf_json_response(['ok'=>true,'record'=>$record,'stages'=>$stages,'csrf'=>sf_admin_csrf()]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
