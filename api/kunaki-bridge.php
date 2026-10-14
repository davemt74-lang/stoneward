<?php
declare(strict_types=1);
/**
 * Section 84A — Kunaki XML/HTTP integration contracts, not a live order sender.
 * Kunaki's XML/HTTP interfaces can quote shipping, submit existing product IDs
 * and retrieve status. Neither publishes a new vinyl product. Its public FAQ
 * says no real test mode; this file performs NO network calls or charges.
 */
require_once __DIR__.'/vinyl-production-gates.php';

function sf_kunaki_product_id(string $id): string {
    $id=strtoupper(trim($id));
    if(!preg_match('/^[A-Z0-9]{10}$/D',$id))
        throw new InvalidArgumentException('Kunaki product ID must be 10 letters or digits.');
    return $id;
}
function sf_kunaki_xml_escape(mixed $v,int $limit=255):string {
    if(!is_scalar($v)||is_bool($v))throw new InvalidArgumentException('Invalid Kunaki XML field.');
    $text=trim((string)$v);
    if(strlen($text)>$limit||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text))
        throw new InvalidArgumentException('Invalid Kunaki XML field.');
    return htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8',false);
}
function sf_kunaki_element(string $key,mixed $value,int $max=255):string {
    if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$key))
        throw new InvalidArgumentException('Unrecognized XML field.');
    return '<'.$key.'>'.sf_kunaki_xml_escape($value,$max).'</'.$key.'>';
}
function sf_kunaki_address(array $ship):array {
    $out=[
      'Name'=>trim((string)($ship['name']??'')),
      'Company'=>trim((string)($ship['company']??'')),
      'Address1'=>trim((string)($ship['line1']??'')),
      'Address2'=>trim((string)($ship['line2']??'')),
      'City'=>trim((string)($ship['city']??'')),
      'State_Province'=>strtoupper(trim((string)($ship['region']??''))),
      'PostalCode'=>trim((string)($ship['postal']??'')),
      'Country'=>trim((string)($ship['country']??''))
    ];
    foreach(['Name','Address1','City','PostalCode','Country'] as $key)
        if($out[$key]==='')throw new InvalidArgumentException('Kunaki shipping address is missing '.$key.'.');
    if(in_array(strtolower($out['Country']),['us','usa','united states','ca','canada'],true)){
        if(!preg_match('/^[A-Z]{2}$/D',$out['State_Province']))
            throw new InvalidArgumentException('Two-letter state or province required.');
    }
    // Kunaki requires spelled-out country names, not ISO country codes.
    if(in_array(strtoupper($out['Country']),['US','USA','CA'],true))
        throw new InvalidArgumentException('Use Kunaki full country name (United States or Canada).');
    foreach($out as $key=>$value)sf_kunaki_xml_escape($value,$key==='Address1'||$key==='Address2'?200:120);
    return $out;
}
function sf_kunaki_items(array $items):array {
    if(!$items||count($items)>30)throw new InvalidArgumentException('Kunaki item list required.');
    $results=[];
    foreach($items as $item){
        if(!is_array($item))throw new InvalidArgumentException('Invalid Kunaki item.');
        $id=sf_kunaki_product_id((string)($item['product_id']??''));
        $qty=(int)($item['quantity']??0);
        if($qty<1||$qty>100)throw new InvalidArgumentException('Invalid Kunaki quantity.');
        $results[]=['product_id'=>$id,'quantity'=>$qty];
    }
    return $results;
}
/**
 * Bind a Kunaki-published title to the exact purchased design. A product id
 * must be verified by an operator first: there is no supported publishing API.
 */
function sf_kunaki_mapping(array $order,array $proof,array $record,
    int $itemIndex,string $productId,bool $confirmed):array {
    $orderId=(string)($order['id']??'');
    if(!$confirmed||empty($proof['ready'])||empty($proof['fingerprint']))
        throw new RuntimeException('Exact artwork proof and manual product mapping approval required.');
    if(!preg_match('/^SF-[A-Z0-9-]{1,96}$/D',$orderId)
       ||str_starts_with($orderId,'SF-CERT-'))
        throw new RuntimeException('Customer vinyl order required.');
    if(!isset($proof['items'])||!is_array($proof['items'])
       ||!in_array($itemIndex,array_column($proof['items'],'item_index'),true))
        throw new RuntimeException('Selected item is not a reviewed vinyl record.');
    $approved=(string)($record['proof_sha256']??'');
    if(!preg_match('/^[a-f0-9]{64}$/D',$approved)
       ||!hash_equals($approved,(string)$proof['fingerprint']))
        throw new RuntimeException('Production artwork approval must match the current purchased design.');
    if(!in_array((string)($record['status']??''),[
        'artwork_approved','in_production','quality_check','production_complete'
    ],true))throw new RuntimeException('Artwork must be approved first.');
    return [
      'order_id'=>$orderId,'item_index'=>$itemIndex,
      'product_id'=>sf_kunaki_product_id($productId),
      'edition_id'=>(string)($order['quote']['items'][$itemIndex]['edition_id']??''),
      'edition_fingerprint'=>(string)($order['quote']['items'][$itemIndex]['edition_fingerprint']??''),
      'proof_sha256'=>$approved,
      'verification'=>'staff_confirmed_mapping',
      'provider_product_publication_verified'=>false,
      'safe_to_submit'=>false
    ];
}
function sf_kunaki_quote_xml(array $address,array $items):string {
    $ship=sf_kunaki_address($address);
    $products=sf_kunaki_items($items);
    $xml='<?xml version="1.0" encoding="UTF-8"?><ShippingOptions>';
    foreach(['Country','State_Province','PostalCode'] as $k)
        $xml.=sf_kunaki_element($k,$ship[$k],120);
    foreach($products as $item)$xml.='<Product>'.
        sf_kunaki_element('ProductId',$item['product_id']).
        sf_kunaki_element('Quantity',$item['quantity']).'</Product>';
    return $xml.'</ShippingOptions>';
}
/** This constructs a review-only payload. No call to XMLService.ASP is made. */
function sf_kunaki_order_xml_preview(array $address,array $items,
    string $shippingDescription):string {
    $ship=sf_kunaki_address($address);
    if(trim($shippingDescription)==='')
        throw new InvalidArgumentException('Select an actual Kunaki shipping option first.');
    $products=sf_kunaki_items($items);
    $xml='<?xml version="1.0" encoding="UTF-8"?><Order>';
    // Deliberately do not include credentials or Mode=LIVE in a review payload.
    foreach(['Name','Company','Address1','Address2','City','State_Province','PostalCode','Country'] as $k)
        $xml.=sf_kunaki_element($k,$ship[$k],200);
    $xml.=sf_kunaki_element('ShippingDescription',$shippingDescription,120);
    foreach($products as $item)$xml.='<Product>'.
        sf_kunaki_element('ProductId',$item['product_id']).
        sf_kunaki_element('Quantity',$item['quantity']).'</Product>';
    return $xml.'</Order>';
}
/** Safe XML parser: fixed schema, bounded bytes, DTD and external entities disallowed. */
function sf_kunaki_response(string $xml,string $kind):array {
    if(!in_array($kind,['shipping','order','status'],true))
        throw new InvalidArgumentException('Unknown Kunaki response type.');
    if(strlen($xml)>65536||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)
        throw new RuntimeException('Unsafe Kunaki XML response.');
    if(!function_exists('simplexml_load_string'))
        throw new RuntimeException('PHP SimpleXML extension required.');
    $prev=libxml_use_internal_errors(true);
    try {$doc=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);}
    finally {libxml_clear_errors();libxml_use_internal_errors($prev);}
    if(!$doc||$doc->getName()!=='Response')
        throw new RuntimeException('Invalid Kunaki XML response.');
    $code=trim((string)($doc->ErrorCode??''));
    if(!preg_match('/^\d{1,3}$/D',$code))
        throw new RuntimeException('Kunaki response has no valid error code.');
    $result=['ok'=>$code==='0','code'=>(int)$code];
    if($code!=='0')return $result+['error'=>'Kunaki rejected request; inspect response code.'];
    if($kind==='shipping'){
        $options=[];
        foreach($doc->Option as $node){
            $name=trim((string)($node->Description??''));
            $price=trim((string)($node->Price??''));
            if($name===''||!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D',$price))
                throw new RuntimeException('Malformed Kunaki shipping option.');
            $options[]=['description'=>substr($name,0,120),
                'delivery_time'=>substr(trim((string)($node->DeliveryTime??'')),0,120),
                'price_cents'=>(int)round((float)$price*100)];
            if(count($options)>25)throw new RuntimeException('Excess Kunaki shipping options.');
        }
        return $result+['options'=>$options];
    }
    $orderId=trim((string)($doc->OrderId??''));
    if(!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$orderId))
        throw new RuntimeException('Invalid Kunaki order id.');
    if($kind==='order')return $result+['provider_order_id'=>$orderId];
    $state=strtoupper(trim((string)($doc->OrderStatus??'')));
    if(!in_array($state,['APPROVED','DECLINED','PENDING','PROCESSING','SHIPPED'],true))
        throw new RuntimeException('Unknown Kunaki order status.');
    $tracking=trim((string)($doc->TrackingId??''));
    $trackingType=trim((string)($doc->TrackingType??''));
    return $result+[
      'provider_order_id'=>$orderId,'status'=>$state,
      'tracking_type'=>substr($trackingType,0,80),
      'tracking_id'=>strlen($tracking)<=100&&preg_match('/^[a-zA-Z0-9_-]*$/D',$tracking)?$tracking:'',
      'tracking_available'=>$state==='SHIPPED'
          &&$tracking!==''&&strtoupper($tracking)!=='NA'
    ];
}
