<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$cfg=sf_store_config();$products=[];foreach((array)($cfg['products']??[]) as $id=>$p)$products[]=['id'=>$id,'label'=>(string)($p['label']??$id),'price_cents'=>(int)($p['price_cents']??0),'format'=>(string)($p['format']??'')];
$rp=SF_ROOT.'/data/releases.json';$rows=is_file($rp)?json_decode((string)file_get_contents($rp),true):[];$releases=[];foreach((array)$rows as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false&&($r['purchasable']??true)!==false)$releases[]=['id'=>(string)($r['id']??''),'title'=>(string)($r['title']??''),'type'=>(string)($r['type']??'release'),'digital_price'=>(float)($r['digital_price']??0),'artwork'=>$r['artwork']??[]];
sf_json_response(['ok'=>true,'currency'=>(string)($cfg['currency']??'USD'),'products'=>$products,'releases'=>$releases]);
