<?php
declare(strict_types=1);
// Read-only, owner-scoped production status shared by account API and customer Agent.
function sf_vinyl_customer_status(int $userId, string $userEmail='', int $limit=5, string $exactOrderId=''): array {
    if($userId<1)return [];
    $directory=SF_ROOT.'/storage/orders';
    $stateFile=SF_ROOT.'/storage/fulfillment/vinyl-production.json';
    $records=[];
    if(is_file($stateFile)){
        $decoded=json_decode((string)file_get_contents($stateFile),true);
        if(is_array($decoded))$records=$decoded;
    }
    $email=strtolower(trim($userEmail));
    $result=[];
    if($exactOrderId!==''&&!preg_match('/^SF-[A-Z0-9-]+$/',$exactOrderId))return [];
    $files=$exactOrderId!==''?[$directory.'/'.$exactOrderId.'.json']:(glob($directory.'/*.json')?:[]);
    foreach($files as $file){
        if(!is_file($file))continue;
        $order=json_decode((string)file_get_contents($file),true);
        if(!is_array($order))continue;
        $owner=(int)($order['user_id']??0);
        if($owner!==$userId && !($owner===0 && $email!=='' && $email===strtolower((string)($order['customer']['email']??''))))continue;
        $id=(string)($order['id']??'');
        if($id===''||!preg_match('/^SF-[A-Z0-9-]+$/',$id))continue;
        $quote=(array)($order['quote']??[]);
        $isVinyl=false;
        foreach((array)($quote['items']??[]) as $item){if(is_array($item)&&($item['type']??'')==='custom_media'&&($item['format']??'vinyl')==='vinyl'){$isVinyl=true;break;}}
        if(!$isVinyl)continue;
        // Include newly purchased orders before the first Admin production transition.
        $record=is_array($records[$id]??null)?$records[$id]:[];
        $events=[];
        foreach(array_slice((array)($record['events']??[]),-10) as $event){
            if(!is_array($event))continue;
            $events[]=['at'=>(string)($event['at']??''),'status'=>(string)($event['to']??'')];
        }
        $result[]=['order_id'=>$id,'status'=>(string)($record['status']??'awaiting_artwork_review'),'updated_at'=>(string)($record['updated_at']??$order['created_at']??''),'events'=>$events,'shipping_status'=>(string)($order['fulfillment']['status']??'pending')];
    }
    usort($result,fn($a,$b)=>strcmp($b['updated_at'],$a['updated_at']));
    return array_slice($result,0,max(1,min(25,$limit)));
}
