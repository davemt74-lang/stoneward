<?php
declare(strict_types=1);

/** A public catalog issue never changes inventory, prices, or customer entitlements. */
function sf_storefront_warning(array &$warnings,string $component,Throwable $e): void {
    $ref='STORE-'.strtoupper(bin2hex(random_bytes(5)));
    $sqlstate=$e instanceof PDOException?(string)$e->getCode():'none';
    error_log('Stonefellow '.$ref.' storefront '.$component.' '.get_class($e).' SQLSTATE='.$sqlstate.' detail='.$e->getMessage());
    $labels=[
        'media'=>'Some product artwork is temporarily unavailable.',
        'merch'=>'Merchandise is temporarily unavailable. Custom records and published releases can still be browsed.',
        'summary'=>'Store sales summary is temporarily unavailable.',
        'releases'=>'Published releases are temporarily unavailable.',
    ];
    $warnings[$component]=$labels[$component]??'Some store information is temporarily unavailable.';
}

/** Public GET: show independent sections even if an optional database read fails. */
function sf_storefront_payload(): array {
    $cfg=sf_store_config();
    $warnings=[];$products=[];
    foreach((array)($cfg['products']??[]) as $id=>$p){
        $media=[];
        try{$media=function_exists('sf_media_public_links')?sf_media_public_links('store',(string)$id):[];}
        catch(Throwable $e){sf_storefront_warning($warnings,'media',$e);}
        $primary=null;
        foreach($media as $m)if(($m['role']??'')==='product_primary'){$primary=$m;break;}
        if(!$primary)foreach($media as $m)if(($m['category']??'')==='image'){$primary=$m;break;}
        $products[]=['id'=>(string)$id,'kind'=>'builder','label'=>(string)($p['label']??$id),
            'price_cents'=>(int)($p['price_cents']??0),'format'=>(string)($p['format']??''),
            'image'=>(string)($primary['url']??''),'media'=>$media,'available'=>true];
    }
    $merchAvailable=true;
    try{
        foreach(sf_commerce_products(true) as $p){
            $products[]=['id'=>(string)$p['id'],'slug'=>(string)$p['slug'],'kind'=>'merch',
                'label'=>(string)$p['title'],'description'=>(string)$p['description'],
                'category'=>(string)$p['category'],'price_cents'=>(int)$p['base_price_cents'],
                'compare_at_price_cents'=>(int)$p['compare_at_price_cents'],
                'physical'=>!empty($p['physical']),'featured'=>!empty($p['featured']),
                'available'=>!empty($p['available']),'low_stock'=>!empty($p['low_stock']),
                'max_per_order'=>(int)$p['max_per_order'],'image'=>(string)$p['image'],
                'media'=>(array)$p['media'],'variants'=>(array)$p['variants'],'tags'=>(array)$p['tags']];
        }
    }catch(Throwable $e){
        // Don't publish potentially incomplete merchandise results after a failed read.
        $products=array_values(array_filter($products,static fn($p)=>($p['kind']??'')!=='merch'));
        $merchAvailable=false;
        sf_storefront_warning($warnings,'merch',$e);
    }
    $releases=[];
    try {
        foreach(sf_release_rows() as $r){
            if(!is_array($r)||empty($r['purchasable'])||($r['state']??'')!=='published'
                ||($r['public_visible']??true)===false)continue;
            $cover=is_array($r['artwork']??null)?(string)($r['artwork']['cover']??''):(string)($r['artwork']??'');
            $digitalPrice=max(0,(float)($r['digital_price']??0));
            $releases[]=['id'=>(string)($r['id']??''),'title'=>(string)($r['title']??''),
                'type'=>(string)($r['type']??'release'),'digital_price'=>$digitalPrice,
                'price_cents'=>(int)round($digitalPrice*100),'artwork'=>['cover'=>$cover]];
        }
    } catch(Throwable $e){sf_storefront_warning($warnings,'releases',$e);}
    $summary=null;
    if($merchAvailable){
        try{$summary=sf_commerce_summary();}
        catch(Throwable $e){sf_storefront_warning($warnings,'summary',$e);}
    }
    return ['ok'=>true,'currency'=>(string)($cfg['currency']??'USD'),'products'=>$products,
        'releases'=>$releases,'merch_summary'=>$summary,
        'merch_status'=>$merchAvailable?'available':'unavailable',
        'partial'=>count($warnings)>0,'warnings'=>array_values($warnings)];
}
