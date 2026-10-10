<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$u=sf_require_user();$id=sf_clean_text($_GET['id']??'',100);$order=sf_read_order($id);if(!$order)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
$orderUserId=(int)($order['user_id']??0);$email=strtolower((string)($order['customer']['email']??''));if($orderUserId!==(int)$u['id']&&!($orderUserId===0&&$email===strtolower((string)$u['email'])))sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
unset($order['confirmation_token_hash']);
$ops=['shipments'=>[],'refunds'=>[],'cases'=>[]];if(function_exists('sf_fulfillment_order_snapshot')){try{$snap=sf_fulfillment_order_snapshot($id);$ops=['shipments'=>$snap['shipments']??[],'refunds'=>$snap['refunds']??[],'cases'=>array_values(array_filter($snap['cases']??[],fn($c)=>(int)($c['user_id']??0)===(int)$u['id']))];}catch(Throwable $e){}}
sf_json_response(['ok'=>true,'order'=>$order,'operations'=>$ops]);
