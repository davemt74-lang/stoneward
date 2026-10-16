<?php
declare(strict_types=1);
require_once __DIR__.'/fan-engagement-signals.php';
require_once __DIR__.'/fan-purchase-journey-core.php';

/**
 * 87C1 trusted reader: current owner is supplied by the authenticated server
 * session, never by a browser user_id. Reads existing canonical event tables
 * and paid-account library only; does not create a second event store.
 */
function sf_fan_engagement_owner_signals(int $ownerId,int $days=30): array {
    if ($ownerId < 1) throw new InvalidArgumentException('Sign in to review your engagement.');
    $days=max(1,min(90,$days));$since=gmdate('c',time()-$days*86400);
    $listening=sf_privacy_listening_history_enabled($ownerId);
    $personal=sf_privacy_personalization_enabled($ownerId);
    $publicIds=array_column(sf_fan_discovery_public(sf_catalog()),'id');
    $events=[];$pdo=sf_db();$truncated=false;
    if ($listening) {
        sf_ops_ensure_schema();
        $q=$pdo->prepare("SELECT id,user_id,track_id,created_at FROM listening_events
             WHERE user_id=? AND event_type='start' AND created_at>=?
             ORDER BY id DESC LIMIT 2001");
        $q->execute([$ownerId,$since]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>2000)$truncated=true;
        foreach(array_slice($rows,0,2000) as $r) $events[]=[
            'event_id'=>'listen:'.$r['id'],'user_id'=>(int)$r['user_id'],
            'track_id'=>(string)$r['track_id'],'type'=>'listen',
            'occurred_at'=>(string)$r['created_at']
        ];
    }
    if ($personal) {
        sf_personalization_ensure_schema();
        $q=$pdo->prepare("SELECT id,user_id,item_id,created_at FROM user_favorites
            WHERE user_id=? AND item_type='track' AND created_at>=?
            ORDER BY id DESC LIMIT 1001");
        $q->execute([$ownerId,$since]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)>1000)$truncated=true;
        foreach(array_slice($rows,0,1000) as $r)$events[]=[
            'event_id'=>'favorite:'.$r['id'],'user_id'=>(int)$r['user_id'],
            'track_id'=>(string)$r['item_id'],'type'=>'save',
            'occurred_at'=>(string)$r['created_at']
        ];
    }
    // Library rows are verified by the existing paid-order entitlement reader.
    // Album rights are expanded by the same canonical metadata as 87B.
    $purchaseRows=sf_library_purchase_rows($ownerId);
    if(count($purchaseRows)>1000)$truncated=true;
    $purchaseCount=0;
    foreach(array_slice($purchaseRows,0,1000) as $p) {
        $meta=(array)($p['metadata']??[]);
        $ids=[];
        if(($p['item_type']??'')==='track')$ids=[(string)($p['track_id']??$meta['track_id']??'')];
        if(($p['item_type']??'')==='release')$ids=array_map('strval',(array)($meta['track_ids']??[]));
        $when=(string)($p['saved_at']??'');
        if($when===''||strcmp($when,$since)<0)continue;
        foreach(array_slice(array_unique($ids),0,50) as $trackId) {
            if($purchaseCount>=1500){$truncated=true;break 2;}
            $purchaseCount++;
            if($trackId==='')continue;
            $events[]=[
                'event_id'=>'purchase:'.(string)($p['id']??'').':'.$trackId,
                'user_id'=>$ownerId,'track_id'=>$trackId,'type'=>'paid_purchase',
                'payment_verified'=>true,'occurred_at'=>$when
            ];
        }
    }
    $normalized=sf_engagement_normalize_events($events,$publicIds,$ownerId,$listening,$personal);
    return [
        'summary'=>sf_engagement_summary($normalized),
        'days'=>$days,'is_partial'=>$truncated,
        'sources'=>['listen'=>$listening?'listening_events':'disabled_by_privacy',
            'save'=>$personal?'user_favorites':'disabled_by_privacy',
            'paid_purchase'=>'verified_user_library',
            'cart'=>'not_instrumented','vinyl_interest'=>'not_instrumented'],
        'limits'=>'Recent public songs only. Counts are observed events, not conversion rates or individual marketing permissions.',
        'marketing_consent_inferred'=>false,
        'generated_at'=>gmdate('c')
    ];
}
