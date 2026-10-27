<?php
declare(strict_types=1);
/** Read-only, entity-verified activity receipts for the Admin Agent inbox. */
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-song-intake.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth(false);
header('Cache-Control: private, no-store');
$owner=(int)$me['id'];
$catalog=[];foreach(sf_catalog() as $track)if(is_array($track)&&!empty($track['id']))$catalog[(string)$track['id']]=$track;
$releases=[];foreach(sf_admin_releases() as $release)if(is_array($release)&&!empty($release['id']))$releases[(string)$release['id']]=$release;
$shows=[];foreach(sf_admin_shows() as $show)if(is_array($show)&&!empty($show['id']))$shows[(string)$show['id']]=$show;
$drafts=sf_agent_song_intake_list($owner);
$draftMap=[];foreach($drafts as $draft)$draftMap[(string)$draft['id']]=$draft;
$events=[];$seen=[];
$productCache=[];$commerceAvailable=null;$mediaCache=[];
foreach(sf_ops_recent_admin_audit(180) as $entry){
    if((int)($entry['admin_user_id']??0)!==$owner)continue;
    $action=(string)($entry['action']??'');$type=(string)($entry['entity_type']??'');$id=(string)($entry['entity_id']??'');
    $title='';$destination='';$page='';$verified=false;$state='recorded';
    if($type==='track'&&isset($catalog[$id])){
        $title=(string)$catalog[$id]['title'];$destination='catalog';$page='index.php?view=catalog&track='.rawurlencode($id);
        $verified=true;$state='published';
    }elseif($type==='release'&&isset($releases[$id])){
        $title=(string)$releases[$id]['title'];$destination='releases';$page='index.php?view=releases&release='.rawurlencode($id);
        $verified=true;$state=(string)($releases[$id]['state']??'draft');
    }elseif($type==='show'&&isset($shows[$id])){
        $title=(string)($shows[$id]['title']?:$shows[$id]['venue']);$destination='shows';
        $page='index.php?view=shows&show='.rawurlencode($id);
        $verified=true;$state=(string)($shows[$id]['status']??'scheduled');
    }elseif($type==='store_product'&&$id!==''){
        if($commerceAvailable===null){try{sf_commerce_ensure_schema();$commerceAvailable=true;}
            catch(Throwable $e){$commerceAvailable=false;}}
        if($commerceAvailable){if(!array_key_exists($id,$productCache)){
                try{$productCache[$id]=sf_commerce_product($id,false);}catch(Throwable $e){$productCache[$id]=null;}}
            $product=$productCache[$id];}else{$product=null;}
        if(is_array($product)){$title=(string)$product['title'];$destination='products';
            $page='index.php?view=products&product='.rawurlencode($id);
            $verified=true;$state=(string)($product['status']??'draft');}
    }elseif($type==='media_asset'&&ctype_digit($id)&&($asset=$mediaCache[$id]??sf_media_asset((int)$id))){
        $mediaCache[$id]=$asset;
        $title=(string)($asset['title']?:$asset['original_name']);$destination='media';
        $page='index.php?view=media&asset='.rawurlencode($id);$verified=true;$state='stored';
    }elseif(($type==='song_draft'||$type==='media_asset')&&isset($draftMap[$id])){
        $title=(string)$draftMap[$id]['title'];$destination='catalog';$page='index.php?view=catalog&draft='.rawurlencode($id);
        $verified=true;$state=(string)$draftMap[$id]['status'];
    }
    if(!$verified){$title=str_replace('_',' ',$action);$destination='operations';
        $page='index.php?view=operations';$state='audit only';}
    $key=$action.'|'.$type.'|'.$id.'|'.(string)($entry['id']??'');
    $seen[$type.'|'.$id]=true;
    $events[]=['id'=>'audit-'.(int)$entry['id'],'action'=>$action,'title'=>$title,
        'entity_type'=>$type,'entity_id'=>$id,'state'=>$state,'verified'=>$verified,
        'provenance'=>'admin_audit','actor'=>'Administrator',
        'view'=>$destination,'url'=>$page,'at'=>(string)$entry['created_at']];
}
// Legacy drafts without an audit receipt remain independently provable by their
// persisted, owner-scoped source record. Never claim that an Agent published them.
foreach($drafts as $draft){
    $id=(string)$draft['id'];if(isset($seen['song_draft|'.$id]))continue;
    if(($draft['status']??'')==='published')continue;
    $events[]=['id'=>'draft-'.$id,'action'=>'private_song_draft_saved','title'=>(string)$draft['title'],
        'entity_type'=>'song_draft','entity_id'=>$id,'state'=>(string)$draft['status'],
        'verified'=>true,'provenance'=>'private_draft_store','actor'=>'Administrator',
        'view'=>'catalog','url'=>'index.php?view=catalog&draft='.rawurlencode($id),
        'at'=>(string)($draft['created_at']??'')];
}
usort($events,static fn($a,$b)=>strcmp($b['at'],$a['at'])?:strcmp($b['id'],$a['id']));
$events=array_slice($events,0,80);
sf_json_response(['ok'=>true,'events'=>$events,'count'=>count($events),'as_of'=>gmdate('c')]);
