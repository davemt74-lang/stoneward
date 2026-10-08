<?php
declare(strict_types=1);

function sf_analytics_percent(int|float $numerator,int|float $denominator): float {
    return $denominator>0?round(min(100,max(0,($numerator/$denominator)*100)),1):0.0;
}
function sf_analytics_paid_order(array $order): bool {
    $status=strtolower((string)($order['status']??''));$payment=strtolower((string)($order['payment']['status']??''));
    return str_starts_with($status,'paid_')||in_array($payment,['paid','test_paid','simulated_paid','succeeded','complete','completed'],true);
}
function sf_analytics_order_rows(string $since,?int $userId=null): array {
    $rows=[];
    foreach(glob(SF_ROOT.'/storage/orders/*.json')?:[] as $path){
        $o=json_decode((string)@file_get_contents($path),true);if(!is_array($o))continue;
        $created=(string)($o['created_at']??'');if($created===''||strcmp($created,$since)<0)continue;
        if($userId!==null&&(int)($o['user_id']??0)!==$userId)continue;
        $rows[]=$o;
    }
    usort($rows,fn($a,$b)=>strcmp((string)($a['created_at']??''),(string)($b['created_at']??'')));
    return $rows;
}
function sf_analytics_day(array &$daily,string $day): array {
    if(!isset($daily[$day]))$daily[$day]=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];
    return $daily[$day];
}
function sf_engagement_analytics(int $days=30,?int $userId=null): array {
    sf_ops_ensure_schema();sf_personalization_ensure_schema();sf_playlists_ensure_schema();sf_account_data_ensure_schema();
    $days=max(1,min(3650,$days));$since=gmdate('c',time()-$days*86400);$pdo=sf_db();$args=[$since];$where='created_at>=?';
    if($userId!==null){$where.=' AND user_id=?';$args[]=$userId;}
    $q=$pdo->prepare("SELECT id,user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at FROM listening_events WHERE $where ORDER BY id ASC");$q->execute($args);$events=$q->fetchAll();

    $starts=0;$completes=0;$skips=0;$pauses=0;$repeatStarts=0;$sessionSet=[];$listenerUsers=[];$repeatUsers=[];$firstListenAt=[];$lastListenAt=[];$trackAgg=[];$userAgg=[];$seenStarts=[];$sources=[];$daily=[];
    foreach($events as $e){
        $uid=(int)($e['user_id']??0);$session=(string)($e['session_key']??'');$trackId=(string)($e['track_id']??'');$type=(string)($e['event_type']??'');$created=(string)($e['created_at']??'');$day=substr($created,0,10);
        if($session!=='')$sessionSet[$session]=true;
        if($uid>0){$listenerUsers[$uid]=true;if(!isset($firstListenAt[$uid])||strcmp($created,$firstListenAt[$uid])<0)$firstListenAt[$uid]=$created;if(!isset($lastListenAt[$uid])||strcmp($created,$lastListenAt[$uid])>0)$lastListenAt[$uid]=$created;}
        if(!isset($trackAgg[$trackId]))$trackAgg[$trackId]=['track_id'=>$trackId,'starts'=>0,'completes'=>0,'skips'=>0,'repeat_starts'=>0,'listener_keys'=>[],'favorites'=>0,'digital_purchases'=>0,'custom_build_uses'=>0];
        if($uid>0&&!isset($userAgg[$uid]))$userAgg[$uid]=['user_id'=>$uid,'starts'=>0,'completes'=>0,'skips'=>0,'repeat_starts'=>0,'session_keys'=>[],'track_ids'=>[],'favorites_added'=>0,'playlists_created'=>0,'builds_created'=>0,'paid_orders'=>0,'custom_media_orders'=>0,'revenue_cents'=>0,'last_listen_at'=>''];
        if($uid>0){$userAgg[$uid]['session_keys'][$session]=true;$userAgg[$uid]['track_ids'][$trackId]=true;$userAgg[$uid]['last_listen_at']=$lastListenAt[$uid];}
        if($type==='start'){
            $starts++;$trackAgg[$trackId]['starts']++;if($uid>0)$userAgg[$uid]['starts']++;$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['listens']++;
            $identity=($uid>0?'u:'.$uid:'s:'.$session).'|'.$trackId;$trackAgg[$trackId]['listener_keys'][($uid>0?'u:'.$uid:'s:'.$session)]=true;
            if(isset($seenStarts[$identity])){$repeatStarts++;$trackAgg[$trackId]['repeat_starts']++;if($uid>0){$userAgg[$uid]['repeat_starts']++;$repeatUsers[$uid]=true;}}$seenStarts[$identity]=($seenStarts[$identity]??0)+1;
            $src=(string)($e['source']??'player');if($src==='')$src='player';$sources[$src]=($sources[$src]??0)+1;
        }elseif($type==='complete'){
            $completes++;$trackAgg[$trackId]['completes']++;if($uid>0)$userAgg[$uid]['completes']++;$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['completes']++;
        }elseif($type==='skip'){
            $skips++;$trackAgg[$trackId]['skips']++;if($uid>0)$userAgg[$uid]['skips']++;$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['skips']++;
        }elseif($type==='pause')$pauses++;
    }

    $favoriteUsers=[];$qArgs=[$since];$favWhere='created_at>=?';if($userId!==null){$favWhere.=' AND user_id=?';$qArgs[]=$userId;}
    $q=$pdo->prepare("SELECT user_id,item_type,item_id,created_at FROM user_favorites WHERE $favWhere ORDER BY id ASC");$q->execute($qArgs);$favorites=$q->fetchAll();
    foreach($favorites as $r){$uid=(int)$r['user_id'];$created=(string)$r['created_at'];$day=substr($created,0,10);$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['favorites']++;
        if(($r['item_type']??'')==='track'&&isset($trackAgg[(string)$r['item_id']]))$trackAgg[(string)$r['item_id']]['favorites']++;
        if(isset($userAgg[$uid]))$userAgg[$uid]['favorites_added']++;
        if(isset($firstListenAt[$uid])&&strcmp($created,$firstListenAt[$uid])>=0)$favoriteUsers[$uid]=true;
    }

    $playlistUsers=[];$qArgs=[$since];$plWhere='created_at>=?';if($userId!==null){$plWhere.=' AND user_id=?';$qArgs[]=$userId;}
    $q=$pdo->prepare("SELECT id,user_id,created_at FROM user_playlists WHERE $plWhere ORDER BY id ASC");$q->execute($qArgs);$playlists=$q->fetchAll();
    foreach($playlists as $r){$uid=(int)$r['user_id'];$created=(string)$r['created_at'];$day=substr($created,0,10);$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['playlists']++;if(isset($userAgg[$uid]))$userAgg[$uid]['playlists_created']++;if(isset($firstListenAt[$uid])&&strcmp($created,$firstListenAt[$uid])>=0)$playlistUsers[$uid]=true;}

    $buildUsers=[];$qArgs=[$since];$bWhere='created_at>=?';if($userId!==null){$bWhere.=' AND user_id=?';$qArgs[]=$userId;}
    $q=$pdo->prepare("SELECT id,user_id,created_at FROM user_saved_builds WHERE $bWhere ORDER BY id ASC");$q->execute($qArgs);$builds=$q->fetchAll();
    foreach($builds as $r){$uid=(int)$r['user_id'];$created=(string)$r['created_at'];$day=substr($created,0,10);$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['builds']++;if(isset($userAgg[$uid]))$userAgg[$uid]['builds_created']++;if(isset($firstListenAt[$uid])&&strcmp($created,$firstListenAt[$uid])>=0)$buildUsers[$uid]=true;}

    $paidOrders=[];$purchaseUsers=[];$customMediaUsers=[];$revenue=0;$customOrders=0;$digitalUnits=0;$orders=sf_analytics_order_rows($since,$userId);
    foreach($orders as $o){
        if(!sf_analytics_paid_order($o))continue;$paidOrders[]=$o;$uid=(int)($o['user_id']??0);$created=(string)($o['created_at']??'');$day=substr($created,0,10);$total=(int)($o['quote']['total_cents']??0);$revenue+=$total;$daily[$day]??=['day'=>$day,'listens'=>0,'completes'=>0,'skips'=>0,'favorites'=>0,'playlists'=>0,'builds'=>0,'orders'=>0,'revenue_cents'=>0];$daily[$day]['orders']++;$daily[$day]['revenue_cents']+=$total;
        $hasCustom=false;foreach((array)($o['quote']['items']??[]) as $item){$type=(string)($item['type']??'');if($type==='track'){$digitalUnits++;$tid=(string)($item['track_id']??'');if(isset($trackAgg[$tid]))$trackAgg[$tid]['digital_purchases']++;}elseif($type==='custom_media'){$hasCustom=true;foreach(['A','B'] as $side){foreach((array)($item['builder']['sides'][$side]['tracks']??[]) as $t){$tid=(string)($t['id']??'');if(isset($trackAgg[$tid]))$trackAgg[$tid]['custom_build_uses']++;}}}}
        if($hasCustom)$customOrders++;
        if($uid>0&&isset($userAgg[$uid])){$userAgg[$uid]['paid_orders']++;$userAgg[$uid]['revenue_cents']+=$total;if($hasCustom)$userAgg[$uid]['custom_media_orders']++;}
        if($uid>0&&isset($firstListenAt[$uid])&&strcmp($created,$firstListenAt[$uid])>=0){$purchaseUsers[$uid]=true;if($hasCustom)$customMediaUsers[$uid]=true;}
    }

    $userMap=[];if($listenerUsers){$ids=array_keys($listenerUsers);$placeholders=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id,email,display_name,role,status,created_at,last_login_at FROM users WHERE id IN ($placeholders)");$q->execute($ids);foreach($q->fetchAll() as $r)$userMap[(int)$r['id']]=$r;}
    $users=[];foreach($userAgg as $uid=>$a){$meta=$userMap[$uid]??[];$users[]=[
        'user_id'=>$uid,'display_name'=>(string)($meta['display_name']??''),'email'=>(string)($meta['email']??''),'role'=>(string)($meta['role']??''),'status'=>(string)($meta['status']??''),
        'listens'=>(int)$a['starts'],'completes'=>(int)$a['completes'],'skips'=>(int)$a['skips'],'repeat_starts'=>(int)$a['repeat_starts'],'sessions'=>count($a['session_keys']),'tracks'=>count($a['track_ids']),
        'completion_rate'=>sf_analytics_percent((int)$a['completes'],(int)$a['starts']),'skip_rate'=>sf_analytics_percent((int)$a['skips'],(int)$a['starts']),
        'favorites_added'=>(int)$a['favorites_added'],'playlists_created'=>(int)$a['playlists_created'],'builds_created'=>(int)$a['builds_created'],'paid_orders'=>(int)$a['paid_orders'],'custom_media_orders'=>(int)$a['custom_media_orders'],'revenue_cents'=>(int)$a['revenue_cents'],'last_listen_at'=>(string)$a['last_listen_at'],
    ];}
    usort($users,fn($a,$b)=>($b['listens']<=>$a['listens'])?:strcmp((string)$b['last_listen_at'],(string)$a['last_listen_at']));

    $map=sf_track_map();$tracks=[];foreach($trackAgg as $id=>$a){$t=$map[$id]??[];$tracks[]=[
        'track_id'=>$id,'title'=>(string)($t['title']??$id),'release'=>(string)($t['release']??''),'listens'=>(int)$a['starts'],'completes'=>(int)$a['completes'],'skips'=>(int)$a['skips'],'repeat_starts'=>(int)$a['repeat_starts'],'listeners'=>count($a['listener_keys']),
        'completion_rate'=>sf_analytics_percent((int)$a['completes'],(int)$a['starts']),'skip_rate'=>sf_analytics_percent((int)$a['skips'],(int)$a['starts']),'favorites'=>(int)$a['favorites'],'digital_purchases'=>(int)$a['digital_purchases'],'custom_build_uses'=>(int)$a['custom_build_uses'],
    ];}
    usort($tracks,fn($a,$b)=>($b['listens']<=>$a['listens'])?:($b['completes']<=>$a['completes'])?:strcmp((string)$a['title'],(string)$b['title']));

    arsort($sources);$sourceRows=[];foreach($sources as $source=>$count)$sourceRows[]=['source'=>$source,'starts'=>$count,'percent'=>sf_analytics_percent($count,$starts)];
    ksort($daily);$dailyRows=array_values($daily);$listenerCount=count($listenerUsers);
    return [
        'days'=>$days,'since'=>$since,'starts'=>$starts,'completes'=>$completes,'skips'=>$skips,'pauses'=>$pauses,'sessions'=>count($sessionSet),'listeners'=>$listenerCount,'repeat_starts'=>$repeatStarts,'repeat_listeners'=>count($repeatUsers),
        'completion_rate'=>sf_analytics_percent($completes,$starts),'skip_rate'=>sf_analytics_percent($skips,$starts),'repeat_rate'=>sf_analytics_percent($repeatStarts,$starts),
        'favorites_added'=>count($favorites),'playlists_created'=>count($playlists),'builds_created'=>count($builds),'paid_orders'=>count($paidOrders),'custom_media_orders'=>$customOrders,'digital_units'=>$digitalUnits,'revenue_cents'=>$revenue,
        'conversion'=>[
            'listeners'=>$listenerCount,
            'favorite_users'=>count($favoriteUsers),'favorite_rate'=>sf_analytics_percent(count($favoriteUsers),$listenerCount),
            'playlist_users'=>count($playlistUsers),'playlist_rate'=>sf_analytics_percent(count($playlistUsers),$listenerCount),
            'build_users'=>count($buildUsers),'build_rate'=>sf_analytics_percent(count($buildUsers),$listenerCount),
            'purchase_users'=>count($purchaseUsers),'purchase_rate'=>sf_analytics_percent(count($purchaseUsers),$listenerCount),
            'custom_media_users'=>count($customMediaUsers),'custom_media_rate'=>sf_analytics_percent(count($customMediaUsers),$listenerCount),
        ],
        'tracks'=>array_slice($tracks,0,50),'users'=>array_slice($users,0,250),'sources'=>$sourceRows,'daily'=>$dailyRows,
    ];
}
