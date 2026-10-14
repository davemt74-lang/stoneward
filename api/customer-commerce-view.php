<?php
declare(strict_types=1);
require_once __DIR__.'/store-order-visibility.php';
/**
 * Section 78: an explicit customer-facing read model. Internal order JSON,
 * payment provider references, fulfillment staff data and private notes never
 * leave this boundary. Keep the current order-detail canvas contract stable.
 */
function sf_customer_commerce_owns(array $order,array $viewer):bool {
    if(!sf_store_customer_order_visible($order))return false;
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$id))return false;
    $viewerId=(int)($viewer['id']??0);
    if($viewerId<1)return false;
    $ownerId=(int)($order['user_id']??0);
    if($ownerId>0)return $ownerId===$viewerId;
    $email=strtolower(trim((string)($viewer['email']??'')));
    $buyer=strtolower(trim((string)($order['customer']['email']??'')));
    return $email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)!==false
        &&$buyer!==''&&hash_equals($buyer,$email);
}
function sf_customer_commerce_tracking_url(mixed $raw):string {
    if(!is_string($raw)||strlen($raw)>700||!filter_var($raw,FILTER_VALIDATE_URL))return '';
    $parts=parse_url($raw);
    if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['https'],true)
        ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
        ||isset($parts['fragment']))return '';
    return $raw;
}
function sf_customer_commerce_text(array $from,string $key,int $max=250):string {
    $v=$from[$key]??'';
    return is_scalar($v)?substr((string)$v,0,$max):'';
}
function sf_customer_commerce_order(array $order,array $viewer):array {
    if(!sf_customer_commerce_owns($order,$viewer))
        throw new RuntimeException('Order not found.');
    $quote=(array)($order['quote']??[]);
    $payment=(array)($order['payment']??[]);
    $ful=(array)($order['fulfillment']??[]);
    $customer=(array)($order['customer']??[]);
    $ship=(array)($customer['shipping']??[]);
    $items=[];
    foreach(array_slice((array)($quote['items']??[]),0,100) as $item){
        if(!is_array($item))continue;
        $items[]=['label'=>sf_customer_commerce_text($item,'label',180),
            'type'=>sf_customer_commerce_text($item,'type',80),
            'price_cents'=>max(0,(int)($item['price_cents']??0))];
    }
    $timeline=[];
    foreach(array_slice((array)($order['timeline']??[]),-100) as $event){
        if(!is_array($event))continue;
        $timeline[]=['type'=>sf_customer_commerce_text($event,'type',60),
            'status'=>sf_customer_commerce_text($event,'status',60),
            'label'=>sf_customer_commerce_text($event,'label',250),
            'created_at'=>sf_customer_commerce_text($event,'created_at',40)];
    }
    $pod=[];
    foreach(array_slice((array)($order['pod']??[]),0,30) as $unit){
        if(!is_array($unit))continue;
        $pod[]=['status'=>sf_customer_commerce_text($unit,'status',80)];
    }
    return [
        'id'=>(string)$order['id'],
        'status'=>sf_customer_commerce_text($order,'status',60),
        'created_at'=>sf_customer_commerce_text($order,'created_at',40),
        'customer'=>['shipping'=>[
            'line1'=>sf_customer_commerce_text($ship,'line1',200),
            'line2'=>sf_customer_commerce_text($ship,'line2',200),
            'city'=>sf_customer_commerce_text($ship,'city',120),
            'region'=>sf_customer_commerce_text($ship,'region',120),
            'postal'=>sf_customer_commerce_text($ship,'postal',32),
            'country'=>sf_customer_commerce_text($ship,'country',80)
        ]],
        'quote'=>['items'=>$items,'total_cents'=>max(0,(int)($quote['total_cents']??0)),
            'currency'=>sf_customer_commerce_text($quote,'currency',8),'physical'=>!empty($quote['physical'])],
        'payment'=>['method'=>sf_customer_commerce_text($payment,'method',40),
            'provider'=>sf_customer_commerce_text($payment,'provider',40),
            'status'=>sf_customer_commerce_text($payment,'status',40)],
        'fulfillment'=>['status'=>sf_customer_commerce_text($ful,'status',60),
            'carrier'=>sf_customer_commerce_text($ful,'carrier',100),
            'tracking_number'=>sf_customer_commerce_text($ful,'tracking_number',160),
            'tracking_url'=>sf_customer_commerce_tracking_url($ful['tracking_url']??''),
            'updated_at'=>sf_customer_commerce_text($ful,'updated_at',40)],
        'timeline'=>$timeline,'pod'=>$pod
    ];
}
function sf_customer_commerce_operations(array $snapshot,array $viewer,string $orderId):array {
    $uid=(int)($viewer['id']??0);
    if($uid<1)return ['shipments'=>[],'refunds'=>[],'cases'=>[]];
    $ships=[];
    foreach(array_slice((array)($snapshot['shipments']??[]),0,50) as $s){
        if(!is_array($s)||(string)($s['order_id']??'')!==$orderId)continue;
        $ships[]=['status'=>sf_customer_commerce_text($s,'status',40),
            'carrier'=>sf_customer_commerce_text($s,'carrier',100),
            'service'=>sf_customer_commerce_text($s,'service',100),
            'tracking_number'=>sf_customer_commerce_text($s,'tracking_number',160),
            'tracking_url'=>sf_customer_commerce_tracking_url($s['tracking_url']??''),
            'estimated_delivery_at'=>sf_customer_commerce_text($s,'estimated_delivery_at',40),
            'updated_at'=>sf_customer_commerce_text($s,'updated_at',40)];
    }
    $refunds=[];
    foreach(array_slice((array)($snapshot['refunds']??[]),0,50) as $r){
        if(!is_array($r)||(string)($r['order_id']??'')!==$orderId)continue;
        $refunds[]=['amount_cents'=>max(0,(int)($r['amount_cents']??0)),
            'status'=>sf_customer_commerce_text($r,'status',40),
            'reason'=>sf_customer_commerce_text($r,'reason',350),
            'restock_status'=>sf_customer_commerce_text($r,'restock_status',40),
            'requested_at'=>sf_customer_commerce_text($r,'requested_at',40)];
    }
    $cases=[];
    foreach(array_slice((array)($snapshot['cases']??[]),0,50) as $c){
        if(!is_array($c)||(string)($c['order_id']??'')!==$orderId
            ||(int)($c['user_id']??0)!==$uid)continue;
        $messages=[];
        foreach(array_slice((array)($c['messages']??[]),-100) as $m){
            if(!is_array($m)||!in_array((string)($m['actor_type']??''),['fan','admin','system','agent'],true))continue;
            $messages[]=['actor_type'=>sf_customer_commerce_text($m,'actor_type',20),
                'message'=>sf_customer_commerce_text($m,'message',8000),
                'created_at'=>sf_customer_commerce_text($m,'created_at',40)];
        }
        $cases[]=['id'=>(int)($c['id']??0),
            'case_key'=>sf_customer_commerce_text($c,'case_key',64),
            'subject'=>sf_customer_commerce_text($c,'subject',255),
            'status'=>sf_customer_commerce_text($c,'status',40),
            'messages'=>$messages];
    }
    return ['shipments'=>$ships,'refunds'=>$refunds,'cases'=>$cases];
}
/** Projection for the existing Account → Purchases list, never raw order JSON. */
function sf_customer_commerce_summary(array $order,array $viewer):array {
    $safe=sf_customer_commerce_order($order,$viewer);
    $f=$safe['fulfillment'];
    return [
        'id'=>$safe['id'],'status'=>$safe['status'],'created_at'=>$safe['created_at'],
        'total_cents'=>$safe['quote']['total_cents'],'currency'=>$safe['quote']['currency'],
        'items'=>array_map(static fn(array $i):array=>['type'=>$i['type'],'label'=>$i['label']],$safe['quote']['items']),
        'fulfillment'=>['status'=>$f['status'],'carrier'=>$f['carrier'],
            'tracking_number'=>$f['tracking_number'],'tracking_url'=>$f['tracking_url']]
    ];
}
