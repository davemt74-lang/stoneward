<?php
declare(strict_types=1);

require_once __DIR__.'/customer-commerce-view.php';
require_once __DIR__.'/vinyl-customer-status.php';

/** Order lines visible in the account purchase history, with canvas-safe fields only. */
function sf_agent_canvas_purchase_rows(array $user): array {
    $userId=(int)($user['id']??0);
    if($userId<1)return [];
    $rows=[];
    $vinylByOrder=null;
    foreach(glob(SF_ROOT.'/storage/orders/SF-*.json')?:[] as $path){
        $order=sf_read_order(basename($path,'.json'));
        if(!$order||!sf_customer_commerce_owns($order,$user))continue;
        $id=(string)$order['id'];
        $quote=(array)($order['quote']??[]);
        $payment=(array)($order['payment']??[]);
        $fulfillment=(array)($order['fulfillment']??[]);
        $orderFields=[
            'order_id'=>$id,
            'order_total_cents'=>$quote['total_cents']??null,
            'currency'=>$quote['currency']??'',
            'ordered_at'=>$order['created_at']??'',
            'order_status'=>$order['status']??'',
            'payment_status'=>$payment['status']??'',
            'fulfillment_status'=>$fulfillment['status']??(!empty($quote['physical'])?'pending':'not_required'),
        ];
        $items=array_values(array_filter((array)($quote['items']??[]),'is_array'));
        foreach(array_slice($items?:[[]],0,100) as $index=>$item){
            $type=$item['type']??'';
            $isCustomVinyl=$type==='custom_media'&&($item['format']??'vinyl')==='vinyl';
            if($isCustomVinyl&&$vinylByOrder===null){
                $vinylByOrder=array_column(sf_vinyl_customer_status($userId,(string)($user['email']??''),25),'status','order_id');
            }
            $rows[]=$orderFields+[
                'purchase_id'=>$id.'-line-'.($index+1),
                'item_label'=>$item['label']??'',
                'item_type'=>$type,
                'product_id'=>$item['product_id']??'',
                'variant_id'=>$item['variant_id']??'',
                'track_id'=>$item['track_id']??'',
                'release_id'=>$item['release_id']??'',
                'quantity'=>$item['quantity']??($item?1:null),
                'item_total_cents'=>$item['price_cents']??null,
                'vinyl_status'=>$isCustomVinyl?($vinylByOrder[$id]??''):'',
            ];
        }
    }
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)$b['ordered_at'],(string)$a['ordered_at'])
        ?:strcmp($b['order_id'],$a['order_id']));
    return $rows;
}

/** Include historical owner reservations and enrich them with published show details. */
function sf_agent_canvas_owned_ticket_rows(int $userId): array {
    if($userId<1)return [];
    $shows=array_column(sf_live_public_shows(),null,'id');
    $rows=[];
    foreach(sf_ticket_reservations_for_user($userId) as $reservation){
        $show=$shows[$reservation['show_id']]??null;
        $rows[]=[
            'reservation_id'=>$reservation['id'],
            'offer_id'=>$reservation['offer_id'],
            'show_id'=>$reservation['show_id'],
            'offer_title'=>$reservation['offer_title'],
            'offer_type'=>$reservation['offer_type'],
            'show_title'=>$show['title']??$show['venue']??'',
            'show_date'=>$show['date']??'',
            'venue'=>$show['venue']??'',
            'location'=>$show?sf_live_location($show):'',
            'quantity'=>$reservation['quantity'],
            'status'=>$reservation['status'],
            'reserved_at'=>$reservation['reserved_at'],
        ];
    }
    return $rows;
}
