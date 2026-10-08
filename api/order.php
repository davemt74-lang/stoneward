<?php
declare(strict_types=1); require __DIR__.'/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
sf_require_csrf();
try{
  $user=sf_current_user();$body=sf_request_json();$requestKey=sf_clean_text($body['request_id']??'',128);$cart=is_array($body['cart']??null)?$body['cart']:[];$quote=sf_quote($cart);if(!$quote['ok'])sf_json_response($quote,422);
  $cust=sf_customer(is_array($body['customer']??null)?$body['customer']:[],$quote['physical']);if(!$cust['ok'])sf_json_response(['ok'=>false,'errors'=>$cust['errors']],422);
  $cfg=sf_store_config();$id=sf_order_id();$token=sf_token();$test=$cfg['mode']==='test';
  $pay=sf_resolve_payment((string)($body['payment_method']??''),$cfg);
  if(!$pay['ok']){
    $messages=['cash_simulation_unavailable'=>'Cash simulation is only available in test mode.','test_payment_unavailable'=>'Test payment is only available in test mode.','payment_provider_not_configured'=>'Production checkout is disabled until a real payment adapter is configured.'];
    sf_json_response(['ok'=>false,'error'=>$pay['error'],'message'=>$messages[$pay['error']]??'Payment method is unavailable.'],$pay['error']==='payment_provider_not_configured'?503:422);
  }
  $method=$pay['method'];$orderStatus=$pay['order_status'];$paymentProvider=$pay['provider'];$paymentStatus=$pay['payment_status'];
  if($requestKey!==''){$claim=sf_order_request_claim($requestKey,$user?(int)$user['id']:null);if($claim['state']==='existing'){$existingId=(string)$claim['order_id'];$existing=sf_read_order($existingId);if($existing)sf_json_response(['ok'=>true,'idempotent_replay'=>true,'order_id'=>$existingId,'status'=>$existing['status']??'','payment_method'=>$existing['payment']['method']??'','payment_provider'=>$existing['payment']['provider']??'','confirmation_token'=>'','mode'=>($cfg['mode']??'test'),'total_cents'=>$existing['quote']['total_cents']??0,'currency'=>$existing['quote']['currency']??'USD','pod'=>$existing['pod']??[]]);}if($claim['state']==='in_progress')sf_json_response(['ok'=>false,'error'=>'order_in_progress','message'=>'This checkout request is already being processed.'],409);}
  $order=['schema'=>'stonefellow.order.v1','id'=>$id,'user_id'=>$user?(int)$user['id']:null,'status'=>$orderStatus,'created_at'=>gmdate('c'),'customer'=>$cust['customer'],'quote'=>$quote,'payment'=>['method'=>$method,'provider'=>$paymentProvider,'status'=>$paymentStatus,'amount_cents'=>$quote['total_cents'],'currency'=>$quote['currency']],'fulfillment'=>['status'=>$quote['physical']?'pending':'not_required','carrier'=>'','tracking_number'=>'','tracking_url'=>'','note'=>'','updated_at'=>gmdate('c')],'timeline'=>[['type'=>'order_created','status'=>$orderStatus,'label'=>'Order received','created_at'=>gmdate('c')]],'pod'=>[],'confirmation_token_hash'=>hash('sha256',$token)];
  if($test) $order['pod']=sf_build_pod_handoffs($id,$quote,$cust['customer']);
  sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);
  if($requestKey!=='')sf_order_request_complete($requestKey,$id);
  if($user){
    sf_account_library_add_order((int)$user['id'],$order);
    sf_log_user_activity((int)$user['id'],'purchase','Created order '.$id,'order',$id,['total_cents'=>$quote['total_cents'],'physical'=>$quote['physical']]);
    sf_notify_user((int)$user['id'],'order','Order '.$id.' received','Your Stonefellow order has been recorded.','?view=order&id='.rawurlencode($id));
  }
  try{$items=array_map(fn($x)=>(string)($x['label']??$x['type']??'Item'),(array)$quote['items']);$bodyText="Stonefellow order {$id}\n\n".implode("\n",array_map(fn($x)=>'- '.$x,$items))."\n\nTotal: ".sf_money((int)$quote['total_cents']).' '.(string)$quote['currency']."\nStatus: ".$orderStatus."\n\nThank you for supporting Stonefellow.";sf_transactional_email($user?(int)$user['id']:0,(string)$cust['customer']['email'],'Stonefellow order '.$id,$bodyText,'order_confirmation');}catch(Throwable $mailError){}
  sf_json_response(['ok'=>true,'order_id'=>$id,'status'=>$order['status'],'payment_method'=>$method,'payment_provider'=>$paymentProvider,'confirmation_token'=>$token,'mode'=>$cfg['mode'],'total_cents'=>$quote['total_cents'],'currency'=>$quote['currency'],'pod'=>$order['pod']]);
}catch(Throwable $e){sf_json_response(['ok'=>false,'error'=>'server_error'],500);}
