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

function sf_validate_builder(array $b, string $format): array {
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
            $rows[]=['id'=>$id,'title'=>(string)$t['title'],'duration'=>$dur,'audio_master'=>(string)($t['audio'] ?? '')];
        }
        if($limit && $duration>$limit) $errors[]="Side $side exceeds the format time limit.";
        $sides[$side]=['duration'=>$duration,'tracks'=>$rows];
    }
    if(count($seen)===0) $errors[]='Add at least one track before ordering.';
    return ['ok'=>!$errors,'errors'=>$errors,'format'=>$format,'title'=>sf_clean_text($b['title'] ?? 'My Stonefellow Record',60),'theme'=>sf_clean_text($b['theme'] ?? 'desert',30),'sides'=>$sides];
}

function sf_quote(array $cart): array {
    if(count($cart)>20) return ['ok'=>false,'errors'=>['Cart is too large.']];
    $cfg=sf_store_config(); $tracks=sf_track_map(); $errors=[]; $items=[]; $subtotal=0; $physical=false;
    foreach($cart as $i=>$raw){
        if(!is_array($raw)){ $errors[]="Invalid item at position $i."; continue; }
        $type=(string)($raw['type'] ?? '');
        if($type==='track'){
            $id=(string)($raw['track_id'] ?? ''); $t=$tracks[$id] ?? null;
            if(!$t){$errors[]="Unknown track: $id.";continue;}
            $price=(int)round(((float)($t['price'] ?? 0))*100);
            if($price<0) $price=0;
            $items[]=['type'=>'track','track_id'=>$id,'label'=>(string)$t['title'],'price_cents'=>$price]; $subtotal+=$price;
        } elseif($type==='custom_media'){
            $format=(string)($raw['format'] ?? ''); $key=$format==='vinyl'?'custom_vinyl':($format==='cassette'?'custom_cassette':'');
            $p=$cfg['products'][$key] ?? null; if(!$p){$errors[]='Unsupported custom-media format.';continue;}
            $builder=is_array($raw['builder'] ?? null)?$raw['builder']:[]; $valid=sf_validate_builder($builder,$format);
            if(!$valid['ok']){$errors=array_merge($errors,$valid['errors']);continue;}
            $price=(int)$p['price_cents']; $physical=true; $subtotal+=$price;
            $items[]=['type'=>'custom_media','format'=>$format,'label'=>(string)$p['label'],'price_cents'=>$price,'builder'=>$valid];
        } else $errors[]='Unsupported cart item type.';
    }
    if(!$items) $errors[]='Your cart is empty.';
    $shipping=$physical?(int)$cfg['shipping_flat_cents']:0;
    return ['ok'=>!$errors,'errors'=>$errors,'currency'=>$cfg['currency'],'items'=>$items,'subtotal_cents'=>$subtotal,'shipping_cents'=>$shipping,'tax_cents'=>0,'total_cents'=>$subtotal+$shipping,'physical'=>$physical];
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
    $out=[]; foreach($quote['items'] as $idx=>$item){ if($item['type']!=='custom_media')continue; $b=$item['builder']; $h=['schema'=>'stonefellow.pod-handoff.v1','provider'=>'file_handoff','order_id'=>$orderId,'item_index'=>$idx,'format'=>$item['format'],'title'=>$b['title'],'artwork_theme'=>$b['theme'],'sides'=>$b['sides'],'recipient'=>$customer['shipping']??null,'status'=>'ready_for_provider','created_at'=>gmdate('c')]; $path=SF_ROOT.'/storage/pod/'.$orderId.'-'.$idx.'.json'; sf_write_json($path,$h); $out[]=['item_index'=>$idx,'status'=>'ready_for_provider','file'=>basename($path)]; }
    return $out;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/site-settings.php';
require_once __DIR__ . '/account-data.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/entitlements.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/personalization-core.php';
require_once __DIR__ . '/playlists-core.php';
require_once __DIR__ . '/listening-sessions-core.php';
require_once __DIR__ . '/queue-core.php';
require_once __DIR__ . '/home-core.php';
require_once __DIR__ . '/analytics-core.php';
require_once __DIR__ . '/notification-core.php';
