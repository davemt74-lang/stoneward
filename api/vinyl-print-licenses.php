<?php
declare(strict_types=1);
/**
 * Vinyl printing is a finite, per-song right. Digital stream/download ownership
 * remains permanent when a print credit is reserved or spent.
 * Source-of-truth: immutable paid checkout line + corresponding Library grant.
 * Active Kunaki jobs reserve one credit per song per record copy.
 */
function sf_vinyl_license_packages(mixed $raw):array {
    if(!is_array($raw))return [];
    $out=[];
    foreach($raw as $p){
        if(!is_array($p))continue;
        $count=(int)($p['licenses']??0);$price=(int)($p['price_cents']??0);
        if(!in_array($count,[1,2,3,5,10],true)||$price<50||$price>100000
            ||array_key_exists($count,$out))continue;
        $out[$count]=['licenses'=>$count,'price_cents'=>$price];
    }
    ksort($out,SORT_NUMERIC);
    return array_values($out);
}
function sf_vinyl_package(array $packages,int $licenses):?array {
    foreach(sf_vinyl_license_packages($packages) as $p)
        if($p['licenses']===$licenses)return $p;
    return null;
}
function sf_vinyl_release_map_from_rows(array $rows):array {
    $out=[];
    foreach($rows as $r)if(is_array($r)&&isset($r['id']))$out[(string)$r['id']]=$r;
    return $out;
}
/** Build immutable tracks, side order and artwork from a published fixed album. */
function sf_vinyl_locked_album(array $release,array $tracks,int $sideLimit):array {
    if(($release['state']??'')!=='published'
        ||($release['type']??'')!=='album'
        ||empty($release['vinyl_enabled'])
        ||empty($release['purchasable'])
        ||empty($release['public_visible'])
        ||(int)($release['vinyl_price_cents']??0)<50
        ||(int)($release['vinyl_price_cents']??0)>500000)
        throw new InvalidArgumentException('Locked album vinyl edition is not on sale.');
    $ids=(array)($release['track_ids']??[]);$count=count($ids);
    if($count<1||$count>40||count(array_unique($ids))!==$count)
        throw new InvalidArgumentException('Invalid locked album track list.');
    $split=(int)($release['vinyl_side_a_count']??0);
    if($split<1||$split>$count)throw new InvalidArgumentException('Set the locked album side A track count.');
    $art=(array)($release['artwork']??[]);
    if(empty($art['cover'])||empty($art['back']))throw new InvalidArgumentException('Album vinyl needs front and back artwork.');
    $sides=['A'=>['duration'=>0,'tracks'=>[]],'B'=>['duration'=>0,'tracks'=>[]]];
    foreach($ids as $index=>$id){
        $t=$tracks[(string)$id]??null;
        if(!is_array($t)||empty($t['podEligible'])||empty($t['audio']))
            throw new InvalidArgumentException('Album contains an unavailable vinyl master.');
        $dur=(int)($t['duration']??0);
        if($dur<=0)throw new InvalidArgumentException('Album track needs a duration.');
        $side=$index<$split?'A':'B';
        $sides[$side]['duration']+=$dur;
        $sides[$side]['tracks'][]=['id'=>(string)$id,'title'=>(string)($t['title']??$id),
            'duration'=>$dur,'audio_master'=>(string)$t['audio']];
    }
    foreach($sides as $side)
        if($sideLimit>0&&$side['duration']>$sideLimit)
            throw new InvalidArgumentException('Album runtime exceeds vinyl side limit.');
    $edition=[
        'release_id'=>(string)$release['id'],
        'title'=>(string)$release['title'],
        'track_ids'=>array_values(array_map('strval',$ids)),
        'artwork_front'=>(string)$art['cover'],
        'artwork_back'=>(string)$art['back'],
        'sides'=>$sides
    ];
    return [
        'edition_id'=>'ALB-'.strtoupper(substr(hash('sha256',json_encode($edition,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),0,24)),
        'release_id'=>(string)$release['id'],
        'track_ids'=>$edition['track_ids'],
        'builder'=>[
            'title'=>$edition['title'],'coverFront'=>$edition['artwork_front'],
            'coverBack'=>$edition['artwork_back'],'customNote'=>'',
            'theme'=>'album','sides'=>$sides,'playerModules'=>[]
        ],
        'edition_fingerprint'=>hash('sha256',json_encode($edition,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES))
    ];
}
/** Digital album and per-track license pack lines never grant a physical record. */
function sf_vinyl_license_quote(array $request,array $tracks,array $releases):array {
    $type=(string)($request['type']??'');
    $licenses=(int)($request['licenses']??0);
    if($type==='vinyl_license_pack'){
        $id=(string)($request['track_id']??'');
        $track=$tracks[$id]??null;
        if(!is_array($track)||empty($track['podEligible']))throw new InvalidArgumentException('Unknown vinyl-eligible song.');
        $p=sf_vinyl_package((array)($track['vinyl_license_packages']??[]),$licenses);
        if(!$p)throw new InvalidArgumentException('That song print-license package is not for sale.');
        return ['type'=>'vinyl_license_pack','track_id'=>$id,'licenses'=>$licenses,
            'label'=>(string)$track['title'].' · '.$licenses.' additional vinyl print license(s)',
            'price_cents'=>$p['price_cents'],'digital_access_granted'=>false,'physical'=>false];
    }
    if($type==='album_vinyl_license_pack'){
        $id=(string)($request['release_id']??'');
        $release=$releases[$id]??null;
        if(!is_array($release)||($release['state']??'')!=='published'
            ||($release['type']??'')!=='album'||empty($release['purchasable'])
            ||empty($release['public_visible']))
            throw new InvalidArgumentException('Album license is unavailable.');
        $p=sf_vinyl_package((array)($release['vinyl_license_packages']??[]),$licenses);
        if(!$p)throw new InvalidArgumentException('That album print-license package is not for sale.');
        $ids=array_values(array_map('strval',(array)($release['track_ids']??[])));
        if(!$ids||count($ids)>40||count(array_unique($ids))!==count($ids))
            throw new InvalidArgumentException('Album has no valid tracklist.');
        foreach($ids as $tid)if(empty($tracks[$tid]['podEligible']))
            throw new InvalidArgumentException('Album contains a non-printable track.');
        return ['type'=>'album_vinyl_license_pack','release_id'=>$id,
            'track_ids'=>$ids,'licenses'=>$licenses,'label'=>(string)$release['title'].' · '.$licenses.' album vinyl print license(s)',
            'price_cents'=>$p['price_cents'],'digital_access_granted'=>false,'physical'=>false];
    }
    throw new InvalidArgumentException('Unknown print-license product.');
}
/** Reconstruct durable grants using one verified paid order + matching library
 * entry. The order's frozen tracklist prevents later album edits changing rights.
 */
function sf_vinyl_license_grants(int $userId,array $library,array $orders):array {
    if($userId<1)return [];
    $entries=[];
    foreach($library as $row){
        if(!is_array($row))continue;
        $src=(string)($row['source_order_id']??'');
        $type=(string)($row['item_type']??'');$key=(string)($row['item_key']??'');
        if($src!==''&&$type!==''&&$key!=='')$entries[$src][$type][$key]=true;
    }
    $balance=[];
    foreach($entries as $sourceId=>$rights){
        $order=$orders[$sourceId]??null;
        if(!is_array($order)||(int)($order['user_id']??0)!==$userId
            ||!function_exists('sf_kunaki_live_purchase_confirmed')
            ||!sf_kunaki_live_purchase_confirmed($order,false))continue;
        foreach((array)($order['quote']['items']??[]) as $item){
            if(!is_array($item))continue;
            $type=(string)($item['type']??'');
            $add=[];
            if($type==='track'){
                $id=(string)($item['track_id']??'');
                if(isset($rights['track'][$id]))$add=[$id=>1];
            }elseif($type==='release'){
                $releaseId=(string)($item['release_id']??'');
                if(isset($rights['release'][$releaseId])){
                    foreach((array)($item['track_ids']??[]) as $id)$add[(string)$id]=1;
                }
            }elseif($type==='vinyl_license_pack'){
                $id=(string)($item['track_id']??'');
                if(isset($rights['vinyl_license_pack'][$id]))
                    $add=[$id=>(int)($item['licenses']??0)];
            }elseif($type==='album_vinyl_license_pack'){
                $id=(string)($item['release_id']??'');
                if(isset($rights['album_vinyl_license_pack'][$id])){
                    foreach((array)($item['track_ids']??[]) as $tid)
                        $add[(string)$tid]=(int)($item['licenses']??0);
                }
            }
            foreach($add as $id=>$qty){
                if(!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D',(string)$id)||$qty<1||$qty>10)continue;
                $balance[$id]=($balance[$id]??0)+$qty;
            }
        }
    }
    return $balance;
}
/** Jobs reserve credits from the moment they enter the publishing queue.
 * Unknown provider outcomes keep their reservation to prevent double-pressing.
 */
function sf_vinyl_license_balances(int $userId,array $library,array $orders,array $jobs):array {
    $grants=sf_vinyl_license_grants($userId,$library,$orders);
    $used=[];
    foreach($jobs as $job){
        if(!is_array($job)||(int)($job['owner_id']??0)!==$userId
            ||in_array((string)($job['state']??''),['canceled_unsubmitted'],true))continue;
        foreach(array_unique((array)($job['license_track_ids']??[])) as $id){
            $id=(string)$id;$used[$id]=($used[$id]??0)+1;
        }
    }
    $out=[];
    foreach(array_unique(array_merge(array_keys($grants),array_keys($used))) as $id){
        $total=(int)($grants[$id]??0);$spent=(int)($used[$id]??0);
        $out[$id]=['granted'=>$total,'reserved_or_used'=>$spent,'available'=>max(0,$total-$spent)];
    }
    return $out;
}
function sf_vinyl_license_assert_available(array $ids,array $balances):void {
    foreach($ids as $id)
        if((int)($balances[(string)$id]['available']??0)<1)
            throw new RuntimeException('Additional vinyl print license required for song '.(string)$id.'.');
}
