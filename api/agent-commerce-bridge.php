<?php
declare(strict_types=1);
require_once __DIR__.'/commerce-operations.php';

/**
 * Section 76B: one existing commerce source, two strictly isolated agent views.
 * Read-only snapshots: no Stripe network calls and no writes on Chat/Brain GET.
 */
function sf_agent_commerce_admin_snapshot():array {
    $orders=[];
    $available=false;
    if(function_exists('sf_admin_orders')){
        $orders=sf_admin_orders();
        $available=true;
    }elseif(function_exists('sf_fulfillment_recent_orders')&&function_exists('sf_read_order')){
        foreach(sf_fulfillment_recent_orders(120) as $r){
            $id=(string)($r['id']??'');
            if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id))continue;
            $item=sf_read_order($id);
            if($item&&sf_store_customer_order_visible($item))$orders[]=$item;
        }
        $available=true;
    }
    if(!$available)return [
        'available'=>false,'summary'=>[],'total_attention'=>null,
        'attention_orders'=>[],'source'=>'unavailable','reads_only'=>true
    ];
    $snapshot=sf_commerce_ops_snapshot($orders);
    $safe=[];
    foreach(array_slice((array)($snapshot['items']??[]),0,8) as $row){
        $safe[]=[
            'id'=>(string)($row['id']??''),
            'status'=>(string)($row['status']??''),
            'payment_status'=>(string)($row['payment_status']??''),
            'fulfillment_status'=>(string)($row['fulfillment_status']??''),
            'flags'=>array_values(array_slice((array)($row['flags']??[]),0,6)),
            'open_followups'=>(int)($row['open_followups']??0)
        ];
    }
    return [
        'available'=>true,'summary'=>(array)($snapshot['summary']??[]),
        'total_attention'=>(int)($snapshot['total_attention']??0),
        'attention_orders'=>$safe,
        'source'=>'commerce_operations',
        'reads_only'=>true
    ];
}

/** Owner binding is server-sourced; never select by customer-provided order ID. */
function sf_agent_commerce_customer_snapshot(int $userId):array {
    if($userId<1)return ['orders'=>[],'count'=>0,'scope'=>'customer'];
    if(!function_exists('sf_fulfillment_recent_orders'))return ['orders'=>[],'count'=>0,'scope'=>'customer'];
    $items=[];
    foreach(sf_fulfillment_recent_orders(120) as $summary){
        $id=(string)($summary['id']??'');
        if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id))continue;
        $order=sf_read_order($id);
        if(!$order||!sf_store_customer_order_visible($order)
           ||(int)($order['user_id']??0)!==$userId)continue;
        $items[]=[
            'id'=>$id,
            'status'=>(string)($order['status']??''),
            'payment_status'=>(string)($order['payment']['status']??''),
            'fulfillment_status'=>(string)($order['fulfillment']['status']??''),
            'tracking_number'=>(string)($order['fulfillment']['tracking_number']??''),
            'carrier'=>(string)($order['fulfillment']['carrier']??''),
            'refund_status'=>(string)($order['payment']['refund_status']??''),
            'created_at'=>(string)($order['created_at']??'')
        ];
        if(count($items)>=8)break;
    }
    return ['orders'=>$items,'count'=>count($items),'scope'=>'customer','reads_only'=>true];
}
function sf_agent_commerce_customer_context(int $userId):string {
    $s=sf_agent_commerce_customer_snapshot($userId);
    if(empty($s['orders']))return '';
    $lines=[];
    foreach($s['orders'] as $o){
        $lines[]='Order '.$o['id'].': payment '.$o['payment_status']
           .'; order '.$o['status'].'; fulfillment '.$o['fulfillment_status']
           .($o['tracking_number']!==''?'; tracking '.$o['carrier'].' '.$o['tracking_number']:'');
    }
    return "YOUR OWN VERIFIED STONEFELLOW ORDERS (read-only)\n".implode("\n",$lines);
}

/** A deterministic parser, not model-supplied authority to create a follow-up. */
function sf_agent_commerce_parse_followup(string $message):?array {
    if(!preg_match('/\b(?:follow[\s-]?up|note)\s+(?:for\s+)?(?:order\s+)?(SF-[A-Z0-9-]+)\s*[:：]\s*(.{1,600})$/isu',trim($message),$m))
        return null;
    $note=trim($m[2]);
    if($note===''||strlen($note)>600)return null;
    return ['order_id'=>strtoupper($m[1]),'note'=>$note,'priority'=>'normal','due_date'=>''];
}
