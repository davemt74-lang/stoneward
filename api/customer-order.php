<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-commerce-view.php';
$viewer=sf_require_user();
$id=sf_clean_text($_GET['id']??'',100);
if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id))
    sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
$order=sf_read_order($id);
if(!is_array($order)||!sf_customer_commerce_owns($order,$viewer))
    sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
$ops=['shipments'=>[],'refunds'=>[],'cases'=>[]];
if(function_exists('sf_fulfillment_order_snapshot')){
    try{$ops=sf_customer_commerce_operations(sf_fulfillment_order_snapshot($id),$viewer,$id);}
    catch(Throwable $e){error_log('Customer order operations unavailable: '.get_class($e));}
}
require_once __DIR__.'/vinyl-customer-status.php';
$vinyl=null;
foreach(sf_vinyl_customer_status((int)$viewer['id'],(string)$viewer['email'],1,$id) as $item){
    if((string)($item['order_id']??'')===$id){$vinyl=$item;break;}
}
sf_json_response(['ok'=>true,'order'=>sf_customer_commerce_order($order,$viewer),
    'operations'=>$ops,'vinyl_production'=>$vinyl]);
