<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/vinyl-production-gates.php';
require_once SF_ROOT.'/api/kunaki-bridge.php';
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
  $isVinyl=false;
  foreach((array)($quote['items']??[]) as $item){
    if(is_array($item)&&($item['type']??'')==='custom_media'&&($item['format']??'vinyl')==='vinyl'){$isVinyl=true;break;}
  }
  if(!$isVinyl) sf_json_response(['ok'=>false,'message'=>'No custom vinyl product on this order.'],404);
  $proof=sf_vinyl_production_proof($order,SF_ROOT.'/storage');
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
      $action=(string)($body['action']??'');
      if(!in_array($action,['advance','verify_existing_proof','link_kunaki_product'],true))
          sf_json_response(['ok'=>false,'message'=>'Unsupported production action.'],422);
      $expected=(int)($body['revision']??-1);
      if($expected!==(int)$record['revision']) sf_json_response(['ok'=>false,'message'=>'Production state has changed. Refresh before editing.'],409);
      if($action==='link_kunaki_product'){
        $itemIndex=(int)($body['item_index']??-1);
        $productId=(string)($body['product_id']??'');
        if(!hash_equals((string)($proof['fingerprint']??''),(string)($body['proof_sha256']??'')))
          throw new RuntimeException('Artwork proof has changed; refresh the order.');
        $mapping=sf_kunaki_mapping($order,$proof,$record,$itemIndex,$productId,!empty($body['confirmed']));
        // A single published product must never represent different customer-specific
        // vinyl designs. Prevent accidental reuse across orders or proof revisions.
        foreach($records as $key=>$past){
          if(!is_array($past))continue;
          foreach((array)($past['kunaki_products']??[]) as $pastItem){
            if(!is_array($pastItem)||($pastItem['product_id']??'')!==$mapping['product_id'])continue;
            if(($pastItem['proof_sha256']??'')!==$mapping['proof_sha256'])
              throw new RuntimeException('That Kunaki product ID is already bound to a different vinyl design.');
          }
        }
        $existing=(array)($record['kunaki_products']??[]);
        $same=$existing[(string)$itemIndex]??null;
        if(is_array($same)&&($same['product_id']??'')!==$mapping['product_id'])
          throw new RuntimeException('Existing Kunaki mapping cannot be replaced without a separate explicit remapping review.');
        $mapping['linked_at']=gmdate('c');
        $mapping['linked_by']=(int)$admin['id'];
        $record['kunaki_products'][(string)$itemIndex]=$mapping;
        $record['revision']++;
        $record['updated_at']=gmdate('c');
        $records[$id]=$record;
        $encoded=json_encode($records,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $tmp=tempnam($dir,'vinyl_');
        if(!$tmp||file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$path))
          throw new RuntimeException('Unable to save Kunaki product mapping.');
        @chmod($path,0660);
        sf_log_admin_action((int)$admin['id'],'kunaki_product_linked','order',$id,
          ['revision'=>$record['revision'],'item_index'=>$itemIndex,'product_id'=>$mapping['product_id'],'proof_sha256'=>$mapping['proof_sha256']]);
        sf_json_response(['ok'=>true,'record'=>$record,'proof'=>$proof,'stages'=>$stages,
           'kunaki_mode'=>'product_mapping_only','provider_submissions_enabled'=>false,'csrf'=>sf_admin_csrf()]);
      }
      if($action==='verify_existing_proof'){
        if((string)($record['status']??'')==='awaiting_artwork_review'
          ||!empty($record['proof_sha256']))
            throw new RuntimeException('Only a previously advanced legacy order with no recorded proof can be verified here.');
        $evidence=sf_vinyl_production_approval(
          ['status'=>'awaiting_artwork_review'],$proof,$body,'artwork_approved');
        $record['proof_sha256']=$evidence['proof_sha256'];
        $record['proof_reviewed_at']=$evidence['proof_reviewed_at'];
        $record['proof_reviewed_by']=(int)$admin['id'];
        $record['updated_at']=gmdate('c');
        $record['revision']++;
        $records[$id]=$record;
        $encoded=json_encode($records,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $tmp=tempnam($dir,'vinyl_');
        if(!$tmp||file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$path))
          throw new RuntimeException('Unable to persist artwork proof verification.');
        @chmod($path,0660);
        sf_log_admin_action((int)$admin['id'],'vinyl_legacy_proof_verified','order',$id,
          ['revision'=>$record['revision'],'proof_sha256'=>$record['proof_sha256']]);
        sf_json_response(['ok'=>true,'record'=>$record,'proof'=>$proof,'stages'=>$stages,'csrf'=>sf_admin_csrf()]);
      }
      $orderStatus=(string)($order['status']??'');$paymentStatus=(string)($order['payment']['status']??'');
      $isPaid=str_starts_with($orderStatus,'paid_')||in_array($orderStatus,['paid','completed','fulfilled'],true)||in_array($paymentStatus,['paid','test_paid','simulated_paid','succeeded'],true);
      if(!$isPaid || in_array($orderStatus,['refunded','partially_refunded','canceled','cancelled'],true)) sf_json_response(['ok'=>false,'message'=>'Only confirmed paid orders may advance production.'],422);
      $next=sf_clean_text($body['status']??'',60);
      $currentIndex=array_search($record['status'],$stages,true);
      if($currentIndex===false||!isset($stages[$currentIndex+1])||$stages[$currentIndex+1]!==$next) sf_json_response(['ok'=>false,'message'=>'Choose the next production stage in sequence.'],422);
      if(empty($body['confirmed'])) sf_json_response(['ok'=>false,'message'=>'Explicit approval is required.'],422);
      $note=sf_clean_text($body['note']??'',500);
      $evidence=sf_vinyl_production_approval($record,$proof,$body,$next);
      if($next==='artwork_approved'){
        $record['proof_sha256']=$evidence['proof_sha256'];
        $record['proof_reviewed_at']=$evidence['proof_reviewed_at'];
        $record['proof_reviewed_by']=(int)$admin['id'];
      }
      if($next==='production_complete')$record['qc_completed_at']=gmdate('c');
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
    sf_json_response(['ok'=>true,'record'=>$record,'proof'=>$proof,'stages'=>$stages,'csrf'=>sf_admin_csrf()]);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
