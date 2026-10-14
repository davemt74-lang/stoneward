<?php
declare(strict_types=1);
/** Section 84A: no external Kunaki submissions, immutable product mapping. */
$root=dirname(__DIR__);
require $root.'/api/kunaki-bridge.php';
$passed=0;$failed=0;
function k84(bool $yes,string $label):void{global $passed,$failed;$yes?$passed++:$failed++;echo ($yes?'PASS ':'FAIL ').$label.PHP_EOL;}
function denial84(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}}
$address=[
 'name'=>'An Artist','company'=>'','line1'=>'Record & Main <Street>',
 'line2'=>'Apt 4','city'=>'Phoenix','region'=>'AZ','postal'=>'85001','country'=>'United States'
];
$items=[['product_id'=>'PX12345678','quantity'=>2]];
$quote=sf_kunaki_quote_xml($address,$items);
k84(str_contains($quote,'<ShippingOptions>')&&str_contains($quote,'<ProductId>PX12345678</ProductId>'),'Kunaki shipping quote contains existing published product');
k84(str_contains($quote,'<Country>United States</Country>')&&str_contains($quote,'<State_Province>AZ</State_Province>'),'Shipping quote uses documented full country and province');
k84(!str_contains($quote,'An Artist')&&!str_contains($quote,'Record & Main'),'Shipping quote includes no name or delivery address');
$preview=sf_kunaki_order_xml_preview($address,$items,'USPS First Class Mail');
k84(str_contains($preview,'<Name>An Artist</Name>')&&str_contains($preview,'Record &amp; Main &lt;Street&gt;'),'Order preview escapes all customer address XML');
k84(str_contains($preview,'<ShippingDescription>USPS First Class Mail</ShippingDescription>'),'Manufacturing preview requires exact quoted shipping service');
k84(!str_contains($preview,'<Password>')&&!str_contains($preview,'<UserId>')&&!str_contains($preview,'<Mode>'),'Review payload never includes credentials or a live-submission mode');
k84(denial84(fn()=>sf_kunaki_product_id('!BAD')),'Invalid Kunaki IDs blocked');
k84(denial84(fn()=>sf_kunaki_quote_xml($address,[['product_id'=>'PX12345678','quantity'=>0]])),'Zero quantity rejected');
k84(denial84(fn()=>sf_kunaki_quote_xml($address,[['product_id'=>'PX12345678','quantity'=>101]])),'Oversize order quantity rejected');
$noRegion=$address;$noRegion['region']='';
k84(denial84(fn()=>sf_kunaki_quote_xml($noRegion,$items)),'US/Canada address requires two-letter province');
$iso=$address;$iso['country']='US';
k84(denial84(fn()=>sf_kunaki_quote_xml($iso,$items)),'Kunaki requires country names, not ISO codes');
k84(denial84(fn()=>sf_kunaki_order_xml_preview($address,$items,'')),'Missing shipping quote cannot form order');
$proof=['ready'=>true,'fingerprint'=>hash('sha256','vinyl design'),
    'items'=>[['item_index'=>0,'title'=>'Personal Album']]];
$order=['id'=>'SF-PILOT-84','status'=>'paid'];
$record=['status'=>'artwork_approved','proof_sha256'=>$proof['fingerprint']];
$mapped=sf_kunaki_mapping($order,$proof,$record,0,'PX12345678',true);
k84($mapped['product_id']==='PX12345678'&&$mapped['proof_sha256']===$proof['fingerprint'],
    'Published product ID binds exact approved artwork fingerprint');
k84(!$mapped['safe_to_submit']&&!$mapped['provider_product_publication_verified'],
    'A manual ID entry never claims provider verification or authorizes submission');
k84(denial84(fn()=>sf_kunaki_mapping($order,$proof,$record,0,'PX12345678',false)),'Manual mapping confirmation required');
k84(denial84(fn()=>sf_kunaki_mapping($order,$proof,['status'=>'awaiting_artwork_review'],0,'PX12345678',true)),'Unapproved artwork cannot bind Kunaki product');
k84(denial84(fn()=>sf_kunaki_mapping($order,$proof,$record,1,'PX12345678',true)),'Non-vinyl item cannot bind Kunaki ID');
k84(denial84(fn()=>sf_kunaki_mapping(['id'=>'SF-CERT-84'],$proof,$record,0,'PX12345678',true)),'Certification orders cannot create manufacturer mappings');
$altered=$proof;$altered['fingerprint']=hash('sha256','tampered audio');
k84(denial84(fn()=>sf_kunaki_mapping($order,$altered,$record,0,'PX12345678',true)),'Changed vinyl design cannot reuse approval');
$shipping='<Response><ErrorCode>0</ErrorCode><Option><Description>USPS Ground</Description><DeliveryTime>2-5 days</DeliveryTime><Price>12.34</Price></Option></Response>';
$r=sf_kunaki_response($shipping,'shipping');
k84($r['ok']&&$r['options'][0]['price_cents']===1234,'Official XML shipping prices are parsed as cents');
$orderResult=sf_kunaki_response('<Response><ErrorCode>0</ErrorCode><OrderId>567129</OrderId></Response>','order');
k84($orderResult['provider_order_id']==='567129','Kunaki order ID response parser works without submitting');
$status=sf_kunaki_response('<Response><ErrorCode>0</ErrorCode><OrderId>567129</OrderId><OrderStatus>SHIPPED</OrderStatus><TrackingType>Fedex</TrackingType><TrackingId>TRACK123</TrackingId></Response>','status');
k84($status['tracking_available']&&$status['status']==='SHIPPED','Provider tracking state recognized');
$untrackable=sf_kunaki_response('<Response><ErrorCode>0</ErrorCode><OrderId>567129</OrderId><OrderStatus>PROCESSING</OrderStatus><TrackingType>NA</TrackingType><TrackingId>NA</TrackingId></Response>','status');
k84(!$untrackable['tracking_available'],'Untrackable shipping never fabricates a tracking number');
k84(!sf_kunaki_response('<Response><ErrorCode>12</ErrorCode><ErrorText>Bad Credentials</ErrorText></Response>','order')['ok'],'Provider failure code remains failure');
k84(denial84(fn()=>sf_kunaki_response('<!DOCTYPE Response [<!ENTITY exploit SYSTEM "file:///etc/passwd">]><Response><ErrorCode>0</ErrorCode></Response>','order')),'DOCTYPE/XXE XML refused');
k84(denial84(fn()=>sf_kunaki_response('<Response><ErrorCode>0</ErrorCode><OrderId>567129</OrderId><OrderStatus>UNKNOWN</OrderStatus></Response>','status')),'Unknown provider status blocks completion');
$api=file_get_contents($root.'/admin/api/vinyl-production.php');
$ui=file_get_contents($root.'/admin/assets/fulfillment.js');
$bridge=file_get_contents($root.'/api/kunaki-bridge.php');
k84(str_contains($api,'sf_admin_require_auth')&&str_contains($api,'link_kunaki_product')
    &&str_contains($api,'sf_kunaki_mapping'),'Only authenticated Admin can link proof-bound product ID');
k84(str_contains($api,'flock($lock,LOCK_EX)')&&str_contains($api,'different vinyl design'),
    'Serialized production lock and cross-design Product ID collision guard');
k84(str_contains($ui,'data-kunaki-bind')&&str_contains($ui,'proofMatches')
    &&str_contains($ui,'No vendor order was placed'),'Original Fulfillment canvas offers non-submitting mapping');
k84(!str_contains($bridge,'curl_exec(')&&!str_contains($bridge,'file_get_contents("https://'),
    'Section 84A adapter makes no external manufacturing calls');
echo 'Section 84A Kunaki safe bridge: '.$passed.'/'.($passed+$failed).' checks passed'.PHP_EOL;
exit($failed?1:0);
