<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/customer-commerce-view.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$viewer=$method==='POST'?sf_require_user(false,true):sf_require_user();
sf_fulfillment_ensure_schema();
function sf_customer_care_visible_case(array $case,array $viewer):array {
    $projected=sf_customer_commerce_operations(['cases'=>[$case]],$viewer,(string)($case['order_id']??''));
    if(empty($projected['cases']))throw new RuntimeException('Support case not found.');
    return $projected['cases'][0];
}
if($method==='GET'){
    $orderId=sf_clean_text($_GET['order_id']??'',100);
    if($orderId===''){
        $rows=sf_fulfillment_cases('',(int)$viewer['id'],100);
        $safe=[];
        foreach($rows as $row){
            try{$safe[]=sf_customer_care_visible_case($row,$viewer);}
            catch(Throwable $e){}
        }
        sf_json_response(['ok'=>true,'cases'=>$safe]);
    }
    $order=sf_read_order($orderId);
    if(!is_array($order)||!sf_customer_commerce_owns($order,$viewer))
        sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
    try{
        $ops=sf_customer_commerce_operations(sf_fulfillment_order_snapshot($orderId),$viewer,$orderId);
        sf_json_response(['ok'=>true,'order'=>sf_customer_commerce_order($order,$viewer)]+$ops);
    }catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>'Order support unavailable.'],503);}
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$body=sf_request_json();
$action=(string)($body['action']??'');
try{
    if($action==='open_case'){
        $case=sf_fulfillment_open_case($viewer,(array)($body['case']??[]),'fan');
        sf_log_user_activity((int)$viewer['id'],'support_case','Opened support case '.$case['case_key'],
            'support_case',(string)$case['id'],['order_id'=>$case['order_id'],'issue_type'=>$case['issue_type']]);
        sf_json_response(['ok'=>true,'case'=>sf_customer_care_visible_case($case,$viewer)]);
    }
    if($action==='reply_case'||$action==='close_case'){
        $case=sf_fulfillment_case((int)($body['case_id']??0));
        if(!$case||(int)($case['user_id']??0)!==(int)$viewer['id'])
            throw new RuntimeException('Support case not found.');
        if($action==='reply_case'){
            if(in_array((string)$case['status'],['resolved','closed'],true))
                throw new RuntimeException('This case is closed.');
            $case=sf_fulfillment_add_case_message((int)$case['id'],'fan',(int)$viewer['id'],
                (string)($body['message']??''),sf_clean_text($body['request_key']??'',190));
            sf_log_user_activity((int)$viewer['id'],'support_reply','Replied to support case '.$case['case_key'],
                'support_case',(string)$case['id'],['order_id'=>$case['order_id']]);
        }else{
            $case=sf_fulfillment_update_case((int)$case['id'],['status'=>'closed',
                'priority'=>$case['priority']],(int)$viewer['id']);
            sf_log_user_activity((int)$viewer['id'],'support_case_closed','Closed support case '.$case['case_key'],
                'support_case',(string)$case['id'],['order_id'=>$case['order_id']]);
        }
        sf_json_response(['ok'=>true,'case'=>sf_customer_care_visible_case($case,$viewer)]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported support action.'],422);
}catch(Throwable $e){
    sf_json_response(['ok'=>false,'message'=>$e instanceof InvalidArgumentException?$e->getMessage():'Support request could not be completed.'],422);
}
