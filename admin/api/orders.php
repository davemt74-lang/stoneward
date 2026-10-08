<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$me=sf_admin_require_auth($write);

function sf_admin_order_public(array $o): array {
    $f=(array)($o['fulfillment']??[]);
    return [
        'id'=>$o['id']??'',
        'status'=>$o['status']??'',
        'created_at'=>$o['created_at']??'',
        'customer'=>['name'=>$o['customer']['name']??'','email'=>$o['customer']['email']??''],
        'total_cents'=>$o['quote']['total_cents']??0,
        'currency'=>$o['quote']['currency']??'USD',
        'items'=>count($o['quote']['items']??[]),
        'payment_method'=>$o['payment']['method']??'',
        'payment_provider'=>$o['payment']['provider']??'',
        'payment_status'=>$o['payment']['status']??'',
        'physical'=>!empty($o['quote']['physical']),
        'fulfillment'=>[
            'status'=>(string)($f['status']??(!empty($o['quote']['physical'])?'pending':'not_required')),
            'carrier'=>(string)($f['carrier']??''),
            'tracking_number'=>(string)($f['tracking_number']??''),
            'tracking_url'=>(string)($f['tracking_url']??''),
            'updated_at'=>$f['updated_at']??null,
        ],
    ];
}
function sf_admin_order_write(array $order): void {
    $id=(string)($order['id']??'');
    if(!preg_match('/^SF-[A-Z0-9-]+$/',$id))throw new InvalidArgumentException('Invalid order.');
    sf_write_json(SF_ROOT.'/storage/orders/'.$id.'.json',$order);
}
function sf_admin_order_sync_pod(string $orderId,string $status): void {
    foreach(glob(SF_ROOT.'/storage/pod/'.$orderId.'-*.json')?:[] as $path){
        $h=json_decode((string)file_get_contents($path),true);if(!is_array($h))continue;
        $h['status']=$status;$h['updated_at']=gmdate('c');sf_write_json($path,$h);
    }
}
if(!$write){
    $id=sf_clean_text($_GET['id']??'',100);
    if($id!==''){
        $o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
        unset($o['confirmation_token_hash']);
        sf_json_response(['ok'=>true,'order'=>$o,'summary'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
    }
    $rows=[];foreach(sf_admin_orders() as $o)$rows[]=sf_admin_order_public($o);
    sf_json_response(['ok'=>true,'orders'=>$rows,'csrf'=>sf_admin_csrf()]);
}

$b=sf_request_json();$action=(string)($b['action']??'');
if($action==='update_fulfillment'){
    $id=sf_clean_text($b['id']??'',100);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);
    if(empty($o['quote']['physical']))sf_json_response(['ok'=>false,'message'=>'This order does not require physical fulfillment.'],422);
    $status=(string)($b['status']??'pending');
    $allowed=['pending','preparing','in_production','shipped','delivered','canceled'];
    if(!in_array($status,$allowed,true))sf_json_response(['ok'=>false,'message'=>'Invalid fulfillment status.'],422);
    $carrier=sf_clean_text($b['carrier']??'',100);
    $tracking=sf_clean_text($b['tracking_number']??'',120);
    $trackingUrl=trim((string)($b['tracking_url']??''));
    if($trackingUrl!==''&&!filter_var($trackingUrl,FILTER_VALIDATE_URL))sf_json_response(['ok'=>false,'message'=>'Tracking URL is invalid.'],422);
    $note=sf_clean_text($b['note']??'',1000);$now=gmdate('c');$previous=(string)($o['fulfillment']['status']??'pending');
    $o['fulfillment']=['status'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking,'tracking_url'=>$trackingUrl,'note'=>$note,'updated_at'=>$now,'updated_by'=>(int)$me['id']];
    $o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'fulfillment','status'=>$status,'label'=>'Fulfillment '.ucwords(str_replace('_',' ',$status)),'created_at'=>$now];
    sf_admin_order_write($o);
    sf_admin_order_sync_pod($id,$status);
    $uid=(int)($o['user_id']??0);
    if($uid>0){
        $message='Order '.$id.' is now '.ucwords(str_replace('_',' ',$status)).'.';
        if($tracking!=='')$message.=' Tracking: '.$tracking.'.';
        sf_notify_user($uid,'fulfillment','Order '.$id.' updated',$message,'?view=order&id='.rawurlencode($id));
        sf_log_user_activity($uid,'order_fulfillment',$message,'order',$id,['status'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking]);
    }
    sf_log_admin_action((int)$me['id'],'fulfillment_updated','order',$id,['from'=>$previous,'to'=>$status,'carrier'=>$carrier,'tracking_number'=>$tracking]);
    if($status!==$previous&&in_array($status,['shipped','delivered','canceled'],true)){
        $email=(string)($o['customer']['email']??'');$uid=(int)($o['user_id']??0);
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            $subject=$status==='shipped'?'Stonefellow order shipped':($status==='delivered'?'Stonefellow order delivered':'Stonefellow order update');
            $body="Order {$id}\n\nStatus: ".ucwords(str_replace('_',' ',$status));
            if($status==='shipped'&&$tracking!=='')$body.="\nTracking: ".$tracking;
            if($status==='shipped'&&$trackingUrl!=='')$body.="\n".$trackingUrl;
            if($note!=='')$body.="\n\n".$note;
            try{sf_transactional_email($uid,$email,$subject,$body,'order_fulfillment');}catch(Throwable $e){}
        }
    }
    sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
}
if($action==='update_order_status'){
    $id=sf_clean_text($b['id']??'',100);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);$target=(string)($b['status']??'');$allowed=['canceled','refund_requested','refunded'];if(!in_array($target,$allowed,true))sf_json_response(['ok'=>false,'message'=>'Invalid order state.'],422);
    $provider=(string)($o['payment']['provider']??'');$method=(string)($o['payment']['method']??'');if($target==='refunded'&&!in_array($provider,['test','cash_simulation'],true)&&!in_array($method,['test','cash'],true))sf_json_response(['ok'=>false,'message'=>'This payment requires a provider-side refund. Mark it refund requested until the payment provider confirms the refund.'],422);
    $previous=(string)($o['status']??'');$now=gmdate('c');$o['status']=$target;if($target==='refunded')$o['payment']['status']='refunded';if($target==='canceled'&&!empty($o['quote']['physical'])){$o['fulfillment']['status']='canceled';$o['fulfillment']['updated_at']=$now;sf_admin_order_sync_pod($id,'canceled');}$o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'order_status','status'=>$target,'label'=>ucwords(str_replace('_',' ',$target)),'created_at'=>$now];sf_admin_order_write($o);
    $uid=(int)($o['user_id']??0);if($uid>0){$label='Order '.$id.' is now '.ucwords(str_replace('_',' ',$target)).'.';sf_notify_user($uid,'order',$label,'','?view=order&id='.rawurlencode($id));sf_log_user_activity($uid,'order_status',$label,'order',$id,['from'=>$previous,'to'=>$target]);}sf_log_admin_action((int)$me['id'],'order_status_updated','order',$id,['from'=>$previous,'to'=>$target]);sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'csrf'=>sf_admin_csrf()]);
}
if($action==='retry_pod'){
    $id=sf_clean_text($b['id']??'',100);$o=sf_read_order($id);if(!$o)sf_json_response(['ok'=>false,'message'=>'Order not found.'],404);if(empty($o['quote']['physical']))sf_json_response(['ok'=>false,'message'=>'This order has no physical handoff.'],422);$o['pod']=sf_build_pod_handoffs($id,(array)$o['quote'],(array)$o['customer']);$o['timeline']=is_array($o['timeline']??null)?$o['timeline']:[];$o['timeline'][]=['type'=>'pod_retry','status'=>'ready_for_provider','label'=>'POD handoff regenerated','created_at'=>gmdate('c')];sf_admin_order_write($o);sf_log_admin_action((int)$me['id'],'pod_handoff_retried','order',$id);sf_json_response(['ok'=>true,'order'=>sf_admin_order_public($o),'pod'=>$o['pod'],'csrf'=>sf_admin_csrf()]);
}
sf_json_response(['ok'=>false,'message'=>'Unsupported order action.'],422);
