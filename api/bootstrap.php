<?php
declare(strict_types=1);

if(!defined('SF_ROOT')) define('SF_ROOT', dirname(__DIR__));

function sf_json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function sf_request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) sf_json_response(['ok'=>false,'error'=>'invalid_json'], 400);
    return $data;
}

function sf_store_config(): array {
    static $cfg;
    if ($cfg === null) $cfg = require SF_ROOT . '/config/store.php';
    return $cfg;
}

function sf_catalog(): array {
    static $catalog;
    if ($catalog !== null) return $catalog;
    $path = SF_ROOT . '/data/catalog.json';
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('Catalog JSON is invalid.');
    $catalog = $data;
    return $catalog;
}

function sf_track_map(): array {
    $out=[]; foreach(sf_catalog() as $t) if(isset($t['id'])) $out[(string)$t['id']]=$t; return $out;
}

function sf_clean_text(mixed $v, int $max=120): string {
    $s=trim((string)$v); $s=preg_replace('/[\x00-\x1F\x7F]/u','',$s) ?? ''; return function_exists('mb_substr') ? mb_substr($s,0,$max) : substr($s,0,$max);
}

function sf_money(int $cents): string { return number_format($cents/100, 2, '.', ''); }

function sf_validate_builder(array $b, string $format, int $userId=0): array {
    $cfg=sf_store_config(); $tracks=sf_track_map(); $errors=[];
    if(!isset($cfg['limits'][$format])) $errors[]='Unsupported physical format.';
    $limit=(int)($cfg['limits'][$format] ?? 0); $seen=[]; $sides=[];
    foreach(['A','B'] as $side){
        $ids=$b[$side] ?? []; if(!is_array($ids)){$errors[]="Side $side is invalid.";$ids=[];}
        $duration=0; $rows=[];
        foreach($ids as $id){
            $id=(string)$id;
            if(isset($seen[$id])){$errors[]="Track $id appears more than once.";continue;}
            $seen[$id]=true;
            $t=$tracks[$id] ?? null;
            if(!$t){$errors[]="Unknown track: $id.";continue;}
            if(empty($t['podEligible'])){$errors[]="Track {$t['title']} is not eligible for custom media.";continue;}
            $dur=(int)($t['duration'] ?? 0); $duration += $dur;
            $rows[]=['id'=>$id,'title'=>(string)$t['title'],'duration'=>$dur,'audio_master'=>(string)($t['private_master_audio']??(($t['audio_publishing_mode']??'')==='preview_master_split'?'':($t['audio']??'')))];
        }
        if($limit && $duration>$limit) $errors[]="Side $side exceeds the format time limit.";
        $sides[$side]=['duration'=>$duration,'tracks'=>$rows];
    }
    if(count($seen)===0) $errors[]='Add at least one track before ordering.';
    $allowedCovers=['stonefellow','desert','midnight','analog'];
    $front=sf_clean_text($b['coverFront']??'stonefellow',70);$back=sf_clean_text($b['coverBack']??'stonefellow',70);
    if(!in_array($front,$allowedCovers,true))$errors[]='Unknown front cover template.';
    if(!in_array($back,$allowedCovers,true))$errors[]='Unknown back cover template.';
    return ['ok'=>!$errors,'errors'=>$errors,'format'=>$format,'title'=>sf_clean_text($b['title'] ?? 'My Stonefellow Record',60),
      'theme'=>sf_clean_text($b['theme'] ?? 'desert',30),'coverFront'=>$front,'coverBack'=>$back,
      'customNote'=>sf_clean_text($b['customNote']??'',280),'playerModules'=>!empty($b['playerModules'])?sf_turntable_valid_selection((array)$b['playerModules'],$userId):sf_turntable_module_defaults(),'sides'=>$sides];
}

require_once __DIR__.'/vinyl-print-licenses.php';
function sf_quote(array $cart,string $campaignCode='',?int $userId=null): array {
    if(count($cart)>20) return ['ok'=>false,'errors'=>['Cart is too large.']];
    $cfg=sf_store_config(); $tracks=sf_track_map(); $errors=[]; $items=[]; $subtotal=0; $physical=false;
    $releases=sf_vinyl_release_map_from_rows(sf_release_rows());
    $turntableInCart=[];
    foreach($cart as $i=>$raw){
        if(!is_array($raw)){ $errors[]="Invalid item at position $i."; continue; }
        $type=(string)($raw['type'] ?? '');
        if($type==='track'){
            $id=(string)($raw['track_id'] ?? ''); $t=$tracks[$id] ?? null;
            if(!$t){$errors[]="Unknown track: $id.";continue;}
            $price=(int)round(((float)($t['price'] ?? 0))*100); if($price<0)$price=0;
            $items[]=['type'=>'track','track_id'=>$id,'label'=>(string)$t['title'],'price_cents'=>$price,
                'vinyl_print_licenses_included'=>1]; $subtotal+=$price;
        } elseif($type==='vinyl_license_pack'||$type==='album_vinyl_license_pack'){
            try{$p=sf_vinyl_license_quote($raw,$tracks,$releases);
                $items[]=$p;$subtotal+=(int)$p['price_cents'];}
            catch(InvalidArgumentException $e){$errors[]=$e->getMessage();}
        } elseif($type==='release'){
            $rid=(string)($raw['release_id']??'');$r=$releases[$rid]??null;
            if(!$r||($r['state']??'')!=='published'||empty($r['purchasable'])
               ||empty($r['public_visible'])||($r['type']??'')!=='album'){
                $errors[]='Album is not available for digital purchase.';continue;
            }
            $ids=array_values(array_map('strval',(array)($r['track_ids']??[])));
            if(!$ids||count($ids)>40||count($ids)!==count(array_unique($ids))
                ||count(array_filter($ids,fn($id)=>isset($tracks[$id])))!==count($ids)){
                $errors[]='Album track list is invalid.';continue;
            }
            $price=(int)round(max(0,(float)($r['digital_price']??0))*100);
            $items[]=['type'=>'release','release_id'=>$rid,'track_ids'=>$ids,
                'label'=>(string)$r['title'].' · digital album','price_cents'=>$price,
                'vinyl_print_licenses_included_per_song'=>1];$subtotal+=$price;
        } elseif($type==='album_vinyl'){
            $rid=(string)($raw['release_id']??'');$r=$releases[$rid]??null;
            try{
                if(!is_array($r))throw new InvalidArgumentException('Album vinyl is unavailable.');
                $edition=sf_vinyl_locked_album($r,$tracks,(int)($cfg['limits']['vinyl']??0));
                $price=(int)$r['vinyl_price_cents'];
                $items[]=['type'=>'album_vinyl','format'=>'vinyl','release_id'=>$rid,
                    'edition_id'=>$edition['edition_id'],'edition_fingerprint'=>$edition['edition_fingerprint'],
                    'track_ids'=>$edition['track_ids'],'builder'=>$edition['builder'],
                    'label'=>(string)$r['title'].' · locked album vinyl',
                    'price_cents'=>$price,'physical'=>true,'quantity'=>1];
                $subtotal+=$price;$physical=true;
            }catch(InvalidArgumentException $e){$errors[]=$e->getMessage();}
        } elseif($type==='merch'){
            $merch=sf_commerce_quote_item($raw);if(!$merch['ok']){$errors[]=$merch['error'];continue;}$item=$merch['item'];$items[]=$item;$subtotal+=(int)$item['price_cents'];if(!empty($item['physical']))$physical=true;
        } elseif($type==='turntable_module'){
            $requestedModule=sf_clean_text($raw['module_id']??'',70);
            if(isset($turntableInCart[$requestedModule])){$errors[]='A player module can only be purchased once per order.';continue;}
            $turntableInCart[$requestedModule]=true;
            $quoted=sf_turntable_quote(sf_clean_text($raw['module_id']??'',70),$userId);
            if(!$quoted['ok']){$errors[]=$quoted['error'];continue;}
            $item=$quoted['item'];$items[]=$item;$subtotal+=(int)$item['price_cents'];
        } elseif($type==='custom_media'){
            $format=(string)($raw['format'] ?? ''); $key=$format==='vinyl'?'custom_vinyl':($format==='cassette'?'custom_cassette':'');
            $p=$cfg['products'][$key] ?? null; if(!$p){$errors[]='Unsupported custom-media format.';continue;}
            $builder=is_array($raw['builder'] ?? null)?$raw['builder']:[]; $valid=sf_validate_builder($builder,$format,$userId??0);
            if(!$valid['ok']){$errors=array_merge($errors,$valid['errors']);continue;}
            $price=(int)$p['price_cents']; $physical=true; $subtotal+=$price;
            $items[]=['type'=>'custom_media','format'=>$format,'label'=>(string)$p['label'],'price_cents'=>$price,'builder'=>$valid];
        } else $errors[]='Unsupported cart item type.';
    }
    // A bundle must not bill again for parts also present as standalone lines.
    $requestedModules=[];foreach($items as $line)if(($line['type']??'')==='turntable_module')$requestedModules[(string)$line['module_id']]=true;
    foreach(array_keys($requestedModules) as $id){
        $module=sf_turntable_module($id);
        if($module&&$module['slot']==='pack')foreach($module['includes'] as $part){
            if(isset($requestedModules[$part])){$errors[]='Remove the duplicate player part included in '.$module['name'].'.';break;}
        }
    }
    if(!$items)$errors[]='Your cart is empty.';
    $shipping=$physical?(int)$cfg['shipping_flat_cents']:0;
    $campaignCode=sf_clean_text($campaignCode,80);$entitlement=$campaignCode!==''?sf_campaign_discount_by_code($campaignCode):null;
    if($campaignCode!==''&&!$entitlement)$errors[]='Campaign offer code is invalid, expired, or already redeemed.';
    $discountBase=$subtotal;if($entitlement&&!empty($entitlement['payload']['product_ids'])){$ids=array_values(array_filter(array_map('strval',(array)$entitlement['payload']['product_ids'])));$discountBase=0;foreach($items as $item)if(($item['type']??'')==='merch'&&in_array((string)($item['product_id']??''),$ids,true))$discountBase+=(int)($item['price_cents']??0);}$campaignDiscount=sf_campaign_discount_amount($entitlement,$discountBase);$memberDiscount=function_exists('sf_membership_discount_amount')?sf_membership_discount_amount($userId,$items):0;$discount=min($subtotal,$campaignDiscount+$memberDiscount);$total=max(0,$subtotal-$discount+$shipping);
    return ['ok'=>!$errors,'errors'=>$errors,'currency'=>$cfg['currency'],'items'=>$items,'subtotal_cents'=>$subtotal,'campaign_discount_cents'=>$campaignDiscount,'member_discount_cents'=>$memberDiscount,'discount_cents'=>$discount,'campaign_code'=>$campaignCode,'shipping_cents'=>$shipping,'tax_cents'=>0,'total_cents'=>$total,'physical'=>$physical];
}

function sf_order_id(): string { return 'SF-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); }
function sf_token(): string { return bin2hex(random_bytes(16)); }

function sf_resolve_payment(string $requested, array $cfg): array {
    $test=($cfg['mode']??'test')==='test';
    $method=sf_clean_text($requested!==''?$requested:($test?'test':'provider'),32);
    if($method==='cash'){
        if(!$test || empty($cfg['simulated_cash_enabled'])) return ['ok'=>false,'error'=>'cash_simulation_unavailable'];
        return ['ok'=>true,'method'=>'cash','order_status'=>'paid_cash_simulated','provider'=>'cash_simulation','payment_status'=>'simulated_paid'];
    }
    if($method==='test'){
        if(!$test) return ['ok'=>false,'error'=>'test_payment_unavailable'];
        return ['ok'=>true,'method'=>'test','order_status'=>'paid_test','provider'=>'test','payment_status'=>'test_paid'];
    }
    if(!$test && ($cfg['payment_provider']??'')==='test') return ['ok'=>false,'error'=>'payment_provider_not_configured'];
    return ['ok'=>true,'method'=>$method,'order_status'=>$test?'paid_test':'payment_pending','provider'=>$cfg['payment_provider']??'test','payment_status'=>$test?'test_paid':'pending'];
}

function sf_write_json(string $path, array $data): void {
    $dir=dirname($path); if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Cannot create storage directory.');
    $tmp=$path.'.tmp.'.bin2hex(random_bytes(3));
    if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new RuntimeException('Cannot write storage file.');
    if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot finalize storage file.');}
}

function sf_read_order(string $id): ?array {
    if(!preg_match('/^SF-[A-Z0-9-]+$/',$id)) return null;
    $path=SF_ROOT.'/storage/orders/'.$id.'.json'; if(!is_file($path)) return null;
    $d=json_decode((string)file_get_contents($path),true); return is_array($d)?$d:null;
}

function sf_customer(array $raw, bool $physical): array {
    $c=['name'=>sf_clean_text($raw['name']??'',100),'email'=>strtolower(sf_clean_text($raw['email']??'',180))];
    $errors=[]; if($c['name']==='')$errors[]='Name is required.'; if(!filter_var($c['email'],FILTER_VALIDATE_EMAIL))$errors[]='A valid email is required.';
    if($physical){$ship=is_array($raw['shipping']??null)?$raw['shipping']:[];$c['shipping']=['line1'=>sf_clean_text($ship['line1']??'',120),'line2'=>sf_clean_text($ship['line2']??'',120),'city'=>sf_clean_text($ship['city']??'',80),'region'=>sf_clean_text($ship['region']??'',80),'postal'=>sf_clean_text($ship['postal']??'',32),'country'=>strtoupper(sf_clean_text($ship['country']??'US',2))]; foreach(['line1','city','region','postal','country'] as $f) if($c['shipping'][$f]==='')$errors[]='Shipping '.($f==='line1'?'address':$f).' is required.';}
    return ['ok'=>!$errors,'errors'=>$errors,'customer'=>$c];
}

function sf_build_pod_handoffs(string $orderId, array $quote, array $customer): array {
    $out=[]; foreach($quote['items'] as $idx=>$item){ if(!in_array((string)($item['type']??''),['custom_media','album_vinyl'],true))continue; $b=$item['builder']; $h=['schema'=>'stonefellow.pod-handoff.v1','provider'=>'file_handoff','order_id'=>$orderId,'item_index'=>$idx,'format'=>$item['format'],'edition_id'=>$item['edition_id']??null,'release_id'=>$item['release_id']??null,'title'=>$b['title'],'artwork_theme'=>$b['theme'],'artwork_front'=>$b['coverFront']??'stonefellow','artwork_back'=>$b['coverBack']??'stonefellow','dedication_note'=>$b['customNote']??'','sides'=>$b['sides'],'customer_player_preview'=>['visual_only'=>true,'components'=>$b['playerModules']??sf_turntable_module_defaults()],'recipient'=>$customer['shipping']??null,'status'=>'ready_for_provider','created_at'=>gmdate('c')]; $path=SF_ROOT.'/storage/pod/'.$orderId.'-'.$idx.'.json'; sf_write_json($path,$h); $out[]=['item_index'=>$idx,'status'=>'ready_for_provider','file'=>basename($path)]; }
    return $out;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/site-settings.php';
require_once __DIR__ . '/theme-core.php';
require_once __DIR__ . '/account-data.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/crm-core.php';
require_once __DIR__ . '/campaign-core.php';
require_once __DIR__ . '/agent-actions-core.php';
require_once __DIR__ . '/media-core.php';
require_once __DIR__ . '/commerce-core.php';
require_once __DIR__ . '/turntable-core.php';
require_once __DIR__ . '/membership-core.php';
require_once __DIR__ . '/ticketing-core.php';
require_once __DIR__ . '/fulfillment-core.php';
require_once __DIR__ . '/operating-calendar-core.php';
require_once __DIR__ . '/automation-core.php';
require_once __DIR__ . '/privacy-core.php';
require_once __DIR__ . '/personalization-core.php';
require_once __DIR__ . '/playlists-core.php';
require_once __DIR__ . '/listening-sessions-core.php';
require_once __DIR__ . '/queue-core.php';
require_once __DIR__ . '/home-core.php';
require_once __DIR__ . '/analytics-core.php';
require_once __DIR__ . '/growth-analytics-core.php';
require_once __DIR__ . '/rights-core.php';
require_once __DIR__ . '/distribution-core.php';
require_once __DIR__ . '/press-core.php';
require_once __DIR__ . '/artist-home-core.php';
require_once __DIR__ . '/story-core.php';
require_once __DIR__ . '/video-core.php';
require_once __DIR__ . '/gallery-core.php';
require_once __DIR__ . '/seo-core.php';
require_once __DIR__ . '/inquiry-core.php';
require_once __DIR__ . '/songbook-core.php';
require_once __DIR__ . '/notification-core.php';
require_once __DIR__ . '/search-core.php';
require_once __DIR__ . '/archive-core.php';
require_once __DIR__ . '/live-core.php';
require_once __DIR__ . '/library-core.php';
