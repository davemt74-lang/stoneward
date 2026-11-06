<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-catalog.php';

function sf_agent_canvas_public_price_text(?int $priceCents,string $currency): string {
    if($priceCents===null)return 'Price unavailable';
    return $priceCents===0?'Free':$currency.' '.sf_money($priceCents);
}

/** One row per active storefront variant, or the base product when it has none. */
function sf_agent_canvas_merch_rows(): array {
    $rows=[];
    $currency=sf_agent_canvas_catalog_currency();
    foreach(sf_commerce_products(true) as $product){
        $id=sf_agent_canvas_catalog_id($product['id']??null);
        if($id===null)continue;
        foreach($product['variants']?:[null] as $variant){
            $variantId=$variant===null?'':sf_agent_canvas_catalog_id($variant['id']??null);
            if($variantId===null)continue;
            $options=[];$labels=[];
            foreach((array)($variant['options']??[]) as $name=>$value){
                $name=sf_agent_public_artist_text($name,30);
                $value=sf_agent_public_artist_text($value,50);
                if($name===''||$value==='')continue;
                $options[strtolower($name)]=$value;
                $labels[]=$name.': '.$value;
            }
            $price=(int)($variant['price_cents']??$product['base_price_cents']);
            $available=!empty($variant['available']??$product['available']);
            $rows[]=[
                'product_id'=>$id,'variant_id'=>$variantId,'title'=>$product['title'],
                'variant_title'=>$variant['title']??'','category'=>$product['category'],
                'description'=>$product['description'],'size'=>$options['size']??'',
                'color'=>$options['color']??$options['colour']??'','options'=>implode(', ',$labels),
                'price_cents'=>$price,'currency'=>$currency,'price_text'=>sf_agent_canvas_public_price_text($price,$currency),
                'available'=>$available?1:0,'availability'=>$available?'available':'sold_out',
                'availability_label'=>$available?'Available':'Sold out',
            ];
        }
    }
    return $rows;
}

/** Published offers evaluated for visitors, with details from the public show catalog. */
function sf_agent_canvas_ticket_offer_rows(): array {
    $rows=[];
    $currency=sf_agent_canvas_catalog_currency();
    $shows=sf_agent_canvas_catalog_shows();
    if(!$shows)return [];
    foreach(sf_ticket_public_offers(null) as $offer){
        $id=sf_agent_canvas_catalog_id($offer['id']??null);
        $showId=(string)$offer['show_id'];
        if($id===null||!isset($shows[$showId]))continue;
        $show=$shows[$showId];
        $reason=(string)$offer['access']['reason'];
        $available=!empty($offer['access']['allowed'])||$reason==='sign_in_required';
        $price=(int)$offer['price_cents'];
        if($price===0&&$offer['fulfillment_type']==='external')$price=null;
        $rows[]=[
            'offer_id'=>$id,'show_id'=>$showId,'show_title'=>$show['title'],
            'show_date'=>$show['date'],'show_status'=>$show['status'],'venue'=>$show['venue'],'location'=>$show['location'],
            'title'=>$offer['title'],'offer_type'=>$offer['offer_type'],'description'=>$offer['description'],
            'fulfillment_type'=>$offer['fulfillment_type'],'price_cents'=>$price,'currency'=>$currency,
            'price_text'=>sf_agent_canvas_public_price_text($price,$currency),
            'available'=>$available?1:0,
            'availability'=>$reason,
            'availability_label'=>match($reason){
                'available'=>'Available','sign_in_required'=>'Sign in to reserve','sold_out'=>'Sold out',
                'closed'=>'Closed','not_open'=>'Not yet open','membership_required'=>'Membership required',
                'higher_tier_required'=>'Higher membership tier required','vip_required'=>'VIP access required',
                'presale_required'=>'Priority presale access required',default=>'Unavailable',
            },
            'starts_at'=>$offer['starts_at']??'','ends_at'=>$offer['ends_at']??'',
        ];
    }
    return $rows;
}
