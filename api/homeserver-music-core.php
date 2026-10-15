<?php
declare(strict_types=1);
/**
 * 84B7: a read-only, account-scoped native playback contract.
 * Physical vinyl credits never create digital access.
 */
function sf_hs_music_audio_reference(int $userId,string $trackId,array $owned,array $catalog,bool $download=false):string {
    if($userId<1||!preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$trackId))
        throw new RuntimeException('Invalid music request.');
    $permitted=false;
    foreach($owned as $row){
        if(is_array($row)&&hash_equals((string)($row['id']??''),$trackId)
           &&!empty($row[$download?'can_download':'can_stream'])){$permitted=true;break;}
    }
    if(!$permitted)throw new RuntimeException('Music entitlement unavailable.');
    $track=$catalog[$trackId]??null;
    if(!is_array($track))throw new RuntimeException('Music master unavailable.');
    $original=(string)($track['private_master_audio']??'');
    if($original!==''&&preg_match('/^api\\/media\\.php\\?id=M[A-F0-9]{20}$/D',$original))
        return $original;
    // Preserve previously issued 84B7 legacy streams only until a song is
    // switched to explicit preview/private-master separation.
    if(($track['audio_publishing_mode']??'')==='preview_master_split')
        throw new RuntimeException('A private master has not been published.');
    if(!is_string($track['audio']??null)||$track['audio']==='')
        throw new RuntimeException('Music master unavailable.');
    return $track['audio'];
}
function sf_hs_music_range(?string $header,int $size):array {
    if($size<1)throw new RuntimeException('Empty music master.');
    $start=0;$end=$size-1;$partial=false;
    if($header!==null&&$header!==''){
        if(strlen($header)>100)throw new InvalidArgumentException('Invalid music range.');
        if(preg_match('/^bytes=([0-9]+)-([0-9]*)$/D',$header,$m)){
            $start=(int)$m[1];$end=$m[2]===''?$end:min((int)$m[2],$end);
        }elseif(preg_match('/^bytes=-([0-9]+)$/D',$header,$m) && (int)$m[1]>0){
            $start=max(0,$size-(int)$m[1]);
        }else throw new InvalidArgumentException('Invalid music range.');
        if($start>$end||$start>=$size)throw new InvalidArgumentException('Music range unavailable.');
        $partial=true;
    }
    return ['start'=>$start,'end'=>$end,'length'=>$end-$start+1,'partial'=>$partial,'size'=>$size];
}
function sf_hs_music_rights(array $tracks,array $balances,array $catalog):array {
    foreach($tracks as &$track){
        $id=(string)($track['id']??'');
        $balance=(array)($balances[$id]??[]);
        $t=(array)($catalog[$id]??[]);
        $packages=[];
        if(!empty($t['podEligible']))
            foreach((array)($t['vinyl_license_packages']??[]) as $p){
                if(!is_array($p))continue;
                $count=(int)($p['licenses']??0);$price=(int)($p['price_cents']??0);
                if(in_array($count,[1,2,3,5,10],true)&&$price>=50&&$price<=100000&&!array_key_exists($count,$packages))
                    $packages[$count]=['licenses'=>$count,'price_cents'=>$price];
            }
        ksort($packages,SORT_NUMERIC);
        $track['vinyl_print_rights']=[
            'granted'=>max(0,(int)($balance['granted']??0)),
            'reserved_or_used'=>max(0,(int)($balance['reserved_or_used']??0)),
            'available'=>max(0,(int)($balance['available']??0)),
            'packages'=>array_values($packages)
        ];
    }
    unset($track);
    return $tracks;
}

/** Open a validated private media range before any headers reach the caller. */
function sf_hs_music_open_range(string $path,array $range) {
    $stream=@fopen($path,'rb');
    if($stream===false)throw new RuntimeException('Music master unavailable.');
    if(fseek($stream,(int)$range['start'],SEEK_SET)!==0){
        fclose($stream);
        throw new RuntimeException('Music range could not be opened.');
    }
    return $stream;
}
