<?php
declare(strict_types=1);
require_once __DIR__.'/store-order-visibility.php';
/** Section 76 — bounded, non-charging customer commerce operations. */
function sf_commerce_ops_flags(array $order,int $now):array {
    if(!sf_store_customer_order_visible($order))return [];
    $stripe=(string)($order['payment']['provider']??'')==='stripe';
    $pay=(string)($order['payment']['status']??'');
    $status=(string)($order['status']??'');
    $ful=(string)($order['fulfillment']['status']??'');
    $created=strtotime((string)($order['created_at']??''))?:$now;
    $flags=[];
    if($stripe&&$pay==='pending'&&$status==='payment_pending'&&$now-$created>=900)
        $flags[]='payment_pending_review';
    if($stripe&&in_array($status,['paid','partially_refunded','refunded'],true)) {
        foreach(['library','crm','campaign','pod','notification'] as $task)
            if(empty($order['payment']['postpayment_done'][$task])){$flags[]='postpayment_incomplete';break;}
    }
    foreach((array)($order['payment']['refund_requests']??[]) as $request)
        if(is_array($request)&&in_array((string)($request['status']??''),['requested','pending','requires_action'],true)){
            $flags[]='refund_pending';break;
        }
    if(!empty($order['quote']['physical'])&&in_array($pay,['paid','simulated_paid','test_paid'],true)
       &&!in_array($ful,['shipped','delivered','canceled','not_required'],true))
        $flags[]='fulfillment_pending';
    if(in_array($status,['refunded','partially_refunded'],true))$flags[]='refund_completed';
    return $flags;
}
function sf_commerce_ops_followup_path(string $id):string {
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id)||str_starts_with($id,'SF-CERT-'))
        throw new InvalidArgumentException('Invalid customer order.');
    return SF_ROOT.'/storage/commerce-ops/'.$id.'.json';
}
function sf_commerce_ops_followups(string $orderId):array {
    $file=sf_commerce_ops_followup_path($orderId);
    if(!is_file($file))return [];
    $data=json_decode((string)file_get_contents($file),true);
    return is_array($data)&&is_array($data['followups']??null)?$data['followups']:[];
}
function sf_commerce_ops_update(string $orderId,int $adminId,string $action,array $input):array {
    if($adminId<1)throw new InvalidArgumentException('Administrator access required.');
    $order=sf_read_order($orderId);
    if(!$order||!sf_store_customer_order_visible($order))
        throw new RuntimeException('Customer order not found.');
    $file=sf_commerce_ops_followup_path($orderId);
    $dir=dirname($file);
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))
        throw new RuntimeException('Follow-up storage is unavailable.');
    $fp=@fopen($file.'.lock','c');
    if(!$fp)throw new RuntimeException('Follow-up lock is unavailable.');
    try{
        if(!flock($fp,LOCK_EX))throw new RuntimeException('Could not lock customer follow-ups.');
        $items=sf_commerce_ops_followups($orderId);
        $now=gmdate('c');
        if($action==='add_followup'){
            $key=trim((string)($input['request_key']??''));
            $note=trim((string)($input['note']??''));
            $priority=(string)($input['priority']??'normal');
            $due=(string)($input['due_date']??'');
            if(!preg_match('/^[A-Za-z0-9._:-]{16,96}$/D',$key))
                throw new InvalidArgumentException('A unique follow-up request ID is required.');
            if($note===''||strlen($note)>600)
                throw new InvalidArgumentException('Follow-up note must be 1–600 characters.');
            if(!in_array($priority,['normal','high'],true))
                throw new InvalidArgumentException('Invalid follow-up priority.');
            if($due!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$due)
               ||date('Y-m-d',strtotime($due)?:0)!==$due))
                throw new InvalidArgumentException('Due date must be a valid YYYY-MM-DD.');
            $digest=hash('sha256',$orderId.'|'.$key);
            foreach($items as $item)if(($item['request_hash']??'')===$digest){
                if((string)($item['note']??'')!==$note||(string)($item['priority']??'')!==$priority
                   ||(string)($item['due_date']??'')!==$due)
                    throw new RuntimeException('Follow-up request ID reused with changed details.');
                return $items;
            }
            if(count($items)>=100)throw new RuntimeException('Follow-up history limit reached.');
            $items[]=['id'=>'fu_'.bin2hex(random_bytes(8)),'request_hash'=>$digest,
                'note'=>$note,'priority'=>$priority,'due_date'=>$due,
                'status'=>'open','created_at'=>$now,'created_by'=>$adminId,'resolved_at'=>null];
        }elseif($action==='resolve_followup'){
            $id=(string)($input['followup_id']??'');
            if(!preg_match('/^fu_[a-f0-9]{16}$/D',$id))
                throw new InvalidArgumentException('Invalid follow-up ID.');
            $found=false;
            foreach($items as &$item)if(($item['id']??'')===$id){
                $found=true;
                if(($item['status']??'')!=='done'){
                    $item['status']='done';$item['resolved_at']=$now;$item['resolved_by']=$adminId;
                }
                break;
            }
            unset($item);
            if(!$found)throw new RuntimeException('Follow-up not found.');
        }else throw new InvalidArgumentException('Unsupported follow-up action.');
        sf_write_json($file,['schema'=>'sf.commerce.followups.v1','order_id'=>$orderId,
            'followups'=>$items,'updated_at'=>$now]);
        return $items;
    }finally{flock($fp,LOCK_UN);fclose($fp);}
}
function sf_commerce_ops_snapshot(array $orders,int $now=0):array {
    $now=$now?:time();$rows=[];$totals=['customer_orders'=>0,'payment_attention'=>0,
        'refund_attention'=>0,'fulfillment_pending'=>0,'open_followups'=>0,'refund_completed'=>0];
    foreach($orders as $order){
        if(!is_array($order)||!sf_store_customer_order_visible($order))continue;
        $id=(string)($order['id']??'');
        $flags=sf_commerce_ops_flags($order,$now);
        $followups=sf_commerce_ops_followups($id);
        $open=count(array_filter($followups,static fn($x)=>($x['status']??'')==='open'));
        $totals['customer_orders']++;
        if(array_intersect($flags,['payment_pending_review','postpayment_incomplete']))$totals['payment_attention']++;
        if(in_array('refund_pending',$flags,true))$totals['refund_attention']++;
        if(in_array('fulfillment_pending',$flags,true))$totals['fulfillment_pending']++;
        if(in_array('refund_completed',$flags,true))$totals['refund_completed']++;
        $totals['open_followups']+=$open;
        if(!$flags&&$open===0)continue;
        $rows[]=['id'=>$id,'created_at'=>(string)($order['created_at']??''),
            'customer_name'=>(string)($order['customer']['name']??''),
            'status'=>(string)($order['status']??''),'payment_status'=>(string)($order['payment']['status']??''),
            'payment_provider'=>(string)($order['payment']['provider']??''),
            'total_cents'=>(int)($order['quote']['total_cents']??0),
            'fulfillment_status'=>(string)($order['fulfillment']['status']??''),
            'flags'=>$flags,'open_followups'=>$open,'followups'=>array_slice($followups,-15)];
    }
    usort($rows,static function($a,$b){
        $weight=static fn($r)=>count(array_intersect($r['flags'],['payment_pending_review','postpayment_incomplete','refund_pending']))*10+$r['open_followups'];
        return $weight($b)<=>$weight($a)?:strcmp($a['created_at'],$b['created_at']);
    });
    return ['generated_at'=>gmdate('c',$now),'summary'=>$totals,
        'items'=>array_slice($rows,0,100),'showing'=>min(100,count($rows)),'total_attention'=>count($rows)];
}
