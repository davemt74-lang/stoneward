<?php
declare(strict_types=1);
/** Section 54: server-authoritative parts, licenses and module catalog. Never accept price/ownership from the client. */
function sf_turntable_stock_modules(): array {
    return [
        ['id'=>'base-studio','slot'=>'base','name'=>'Studio Turntable','brand'=>'Stonefellow','price_cents'=>0,'visual'=>'studio','accent'=>'#c5ad86','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'base-nocturne','slot'=>'base','name'=>'Nocturne Digital Deck','brand'=>'Stonefellow','price_cents'=>1199,'visual'=>'obsidian','accent'=>'#9277b6','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'base-signature','slot'=>'base','name'=>'Stonefellow Signature','brand'=>'Stonefellow','price_cents'=>899,'visual'=>'copper','accent'=>'#c47e50','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'platter-classic','slot'=>'platter','name'=>'Classic Grooved Vinyl','brand'=>'Stonefellow','price_cents'=>0,'visual'=>'classic','accent'=>'#bab1a6','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'platter-chrome','slot'=>'platter','name'=>'Chrome Master Platter','brand'=>'Stonefellow','price_cents'=>399,'visual'=>'chrome','accent'=>'#c8d7e1','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'arm-studio','slot'=>'arm','name'=>'Studio Tonearm','brand'=>'Stonefellow','price_cents'=>0,'visual'=>'studio','accent'=>'#a9977b','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'arm-precision','slot'=>'arm','name'=>'Precision Tonearm','brand'=>'Stonefellow','price_cents'=>299,'visual'=>'precision','accent'=>'#d9c5a0','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'label-classic','slot'=>'label','name'=>'Classic Center Label','brand'=>'Stonefellow','price_cents'=>0,'visual'=>'classic','accent'=>'#aa7657','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'label-gold','slot'=>'label','name'=>'Gold Foil Label','brand'=>'Stonefellow','price_cents'=>249,'visual'=>'gold','accent'=>'#dbb868','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'effect-none','slot'=>'effect','name'=>'Clean Playback','brand'=>'Stonefellow','price_cents'=>0,'visual'=>'none','accent'=>'#aaaaaa','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'effect-aurora','slot'=>'effect','name'=>'Aurora Light Show','brand'=>'Stonefellow','price_cents'=>499,'visual'=>'aurora','accent'=>'#70a9b0','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'effect-warm','slot'=>'effect','name'=>'Analog Warmth Visuals','brand'=>'Stonefellow','price_cents'=>399,'visual'=>'warm','accent'=>'#db965c','image'=>'','includes'=>[],'active'=>true],
        ['id'=>'pack-afterdark','slot'=>'pack','name'=>'After Dark Experience','brand'=>'Stonefellow','price_cents'=>1499,'visual'=>'aurora','accent'=>'#9373b6','image'=>'','includes'=>['base-nocturne','platter-chrome','arm-precision','effect-aurora'],'active'=>true],
    ];
}
function sf_turntable_module_defaults(): array {
    return ['base'=>'base-studio','platter'=>'platter-classic','arm'=>'arm-studio','label'=>'label-classic','effect'=>'effect-none'];
}
function sf_turntable_custom_path(): string { return SF_ROOT.'/storage/config/turntable-modules.json'; }
function sf_turntable_catalog(bool $includingInactive=false): array {
    $rows=sf_turntable_stock_modules();$path=sf_turntable_custom_path();
    if(is_file($path)){
        $raw=json_decode((string)file_get_contents($path),true);
        if(is_array($raw))foreach($raw as $entry){
            if(!is_array($entry))continue;
            try{$m=sf_turntable_sanitize_module($entry);}catch(Throwable $e){continue;}
            if(str_starts_with($m['id'],'custom-'))$rows[]=$m;
        }
    }
    return array_values(array_filter($rows,static fn($m)=>$includingInactive||!empty($m['active'])));
}
function sf_turntable_sanitize_module(array $raw): array {
    $id=strtolower(sf_clean_text($raw['id']??'',70));
    if(!preg_match('/^custom-[a-z0-9][a-z0-9-]{2,55}$/',$id))throw new InvalidArgumentException('Custom module ID must begin with custom- and contain letters, numbers or hyphens.');
    $slot=(string)($raw['slot']??'');
    if(!in_array($slot,['base','platter','arm','label','effect','pack'],true))throw new InvalidArgumentException('Invalid player module slot.');
    $visual=(string)($raw['visual']??'studio');
    if(!in_array($visual,['studio','obsidian','copper','chrome','classic','precision','gold','none','aurora','warm'],true))throw new InvalidArgumentException('Unsupported player appearance.');
    $color=(string)($raw['accent']??'#c5ad86');
    if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))throw new InvalidArgumentException('Accent must be a six-digit hex color.');
    $image=sf_clean_text($raw['image']??'',350);
    if($image!==''&&!(str_starts_with($image,'/')&&!str_starts_with($image,'//'))&&!preg_match('~^https://[a-z0-9.-]+(?:[:][0-9]+)?/[^[:space:]]+$~i',$image))
        throw new InvalidArgumentException('Player artwork must be an HTTPS or site-relative URL.');
    $includes=array_values(array_unique(array_filter(array_map('strval',(array)($raw['includes']??[])),static fn($id)=>preg_match('/^[a-z0-9-]{3,70}$/',$id))));
    if(count($includes)>12)throw new InvalidArgumentException('A pack cannot include more than 12 parts.');
    return ['id'=>$id,'slot'=>$slot,'name'=>sf_clean_text($raw['name']??'',85),'brand'=>sf_clean_text($raw['brand']??'Stonefellow',60),
        'price_cents'=>max(0,min(1000000,(int)($raw['price_cents']??0))),'visual'=>$visual,'accent'=>$color,'image'=>$image,
        'includes'=>$slot==='pack'?$includes:[],'active'=>!empty($raw['active'])];
}
function sf_turntable_save_custom(array $raw): array {
    $item=sf_turntable_sanitize_module($raw);
    if($item['name']==='')throw new InvalidArgumentException('Name is required.');
    $builtins=[];foreach(sf_turntable_stock_modules() as $m)$builtins[$m['id']]=true;
    $all=sf_turntable_catalog(true);$custom=[];foreach($all as $m)if(str_starts_with($m['id'],'custom-'))$custom[$m['id']]=$m;
    $custom[$item['id']]=$item;
    $known=$builtins;foreach($custom as $m)$known[$m['id']]=true;
    if($item['slot']==='pack')foreach($item['includes'] as $id){
        $found=null;foreach(array_merge(sf_turntable_stock_modules(),array_values($custom)) as $m)if($m['id']===$id)$found=$m;
        if(!$found||$found['slot']==='pack'||$id===$item['id'])throw new InvalidArgumentException('Pack includes an unknown part or nested pack.');
    }
    $path=sf_turntable_custom_path();$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Unable to initialize private module directory.');
    $tmp=tempnam($dir,'modules-');if(!$tmp)throw new RuntimeException('Unable to store module catalog.');
    $json=json_encode(array_values($custom),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
    try{
        if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('Unable to publish module catalog.');
    }finally{if(is_file($tmp))unlink($tmp);}
    return $item;
}
function sf_turntable_module(string $id,bool $activeOnly=true): ?array {
    foreach(sf_turntable_catalog(!$activeOnly) as $m)if($m['id']===$id)return $m;return null;
}
function sf_turntable_owned(int $userId): array {
    $owned=[];foreach(sf_turntable_catalog() as $module)if($module['price_cents']===0&&$module['slot']!=='pack')$owned[$module['id']]=true;
    if($userId<1)return array_keys($owned);
    foreach(sf_account_library($userId) as $row)if(($row['item_type']??'')==='turntable_module'){
        $id=(string)($row['item_key']??'');$owned[$id]=true;
        $m=sf_turntable_module($id,false);if($m&&$m['slot']==='pack')foreach($m['includes'] as $part)$owned[$part]=true;
    }
    return array_keys($owned);
}
function sf_turntable_valid_selection(array $raw,int $userId=0): array {
    $defaults=sf_turntable_module_defaults();$owned=array_fill_keys(sf_turntable_owned($userId),true);
    foreach($defaults as $slot=>$fallback){
        $id=sf_clean_text($raw[$slot]??'',70);$item=sf_turntable_module($id,false);
        $defaults[$slot]=($item&&$item['slot']===$slot&&isset($owned[$id]))?$id:$fallback;
    }
    return $defaults;
}
function sf_turntable_quote(string $id,?int $userId): array {
    if(!$userId||$userId<1)return ['ok'=>false,'error'=>'Sign in to purchase player modules.'];
    $m=sf_turntable_module($id);
    if(!$m)return ['ok'=>false,'error'=>'This player module is not available.'];
    if(in_array($id,sf_turntable_owned($userId),true))return ['ok'=>false,'error'=>'You already own this player module.'];
    if($m['price_cents']===0)return ['ok'=>false,'error'=>'This module is already free.'];
    return ['ok'=>true,'item'=>['type'=>'turntable_module','module_id'=>$id,'label'=>$m['name'],'price_cents'=>$m['price_cents'],'physical'=>false,'slot'=>$m['slot']]];
}
