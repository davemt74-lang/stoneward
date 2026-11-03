<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_commerce_ensure_schema();

if($method==='GET'){
    $id=sf_clean_text($_GET['id']??'',120);
    if($id!==''){$p=sf_commerce_product($id,false);if(!$p)sf_json_response(['ok'=>false,'message'=>'Product not found.'],404);sf_json_response(['ok'=>true,'product'=>$p,'inventory_events'=>sf_commerce_inventory_events((string)$p['id'],120),'media'=>sf_media_links('store',(string)$p['id']),'summary'=>sf_commerce_summary(),'csrf'=>sf_admin_csrf()]);}
    sf_json_response(['ok'=>true,'products'=>sf_commerce_products(false),'summary'=>sf_commerce_summary(),'inventory_events'=>sf_commerce_inventory_events('',80),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='save'){
        $raw=(array)($b['product']??[]);$existing=!empty($raw['id'])?sf_commerce_product(sf_clean_text($raw['id'],120),false):null;$target=(string)($raw['status']??'draft');if($target==='active'&&($existing['status']??'')!=='active'&&empty($b['confirmed']))throw new RuntimeException('Publishing a product requires explicit Admin confirmation.');$p=sf_commerce_save_product($raw,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'store_product_saved','store_product',(string)$p['id'],['status'=>$p['status'],'price_cents'=>(int)$p['base_price_cents'],'variants'=>count($p['variants'])]);
        sf_agent_brain_log((int)$me['id'],'admin_commerce','product_save',['route'=>'store_products','action'=>'save_product','context_profile'=>'commerce','needs_llm'=>false,'requires_confirmation'=>$p['status']==='active','status'=>'completed','request'=>'Save merch product '.$p['title'],'response'=>'Product '.$p['id'].' saved as '.$p['status'].'.']);
        sf_json_response(['ok'=>true,'product'=>$p,'summary'=>sf_commerce_summary()]);
    }
    if($action==='lifecycle'||$action==='archive'){
        $id=sf_clean_text($b['id']??'',120);$next=$action==='archive'?'archived':(string)($b['status']??'');
        if(!in_array($next,['active','hidden','archived','deleted'],true))throw new InvalidArgumentException('Invalid product lifecycle status.');
        if(empty($b['confirmed']))throw new InvalidArgumentException('Confirm the merchandise lifecycle change.');
        $before=sf_commerce_product($id,false);if(!$before)throw new InvalidArgumentException('Product not found.');
        if(!sf_commerce_set_product_status($id,$next))throw new RuntimeException('Could not update product status.');
        sf_log_admin_action((int)$me['id'],'store_product_lifecycle_changed','store_product',$id,['from'=>$before['status'],'to'=>$next]);
        sf_json_response(['ok'=>true,'product'=>sf_commerce_product($id,false),'summary'=>sf_commerce_summary()]);
    }
    if($action==='adjust_inventory'){
        $id=sf_clean_text($b['product_id']??'',120);$variantId=max(0,(int)($b['variant_id']??0));$delta=(int)($b['delta']??0);$reason=sf_clean_text($b['reason']??'admin_adjustment',120);$r=sf_commerce_adjust_inventory($id,$variantId?:null,$delta,$reason,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'store_inventory_adjusted','store_product',$id,['variant_id'=>$variantId?:null,'delta'=>$r['delta'],'before'=>$r['before'],'after'=>$r['after'],'reason'=>$reason]);
        sf_agent_brain_log((int)$me['id'],'admin_commerce','inventory_adjust',['route'=>'store_inventory','action'=>'adjust_inventory','context_profile'=>'commerce','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Adjust inventory '.$id.' by '.$delta,'response'=>'Inventory changed from '.$r['before'].' to '.$r['after'].'.']);
        sf_json_response(['ok'=>true,'inventory'=>$r,'product'=>sf_commerce_product($id,false),'summary'=>sf_commerce_summary()]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported product action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
