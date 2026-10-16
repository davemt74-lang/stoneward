<?php
declare(strict_types=1);
/**
 * 87C2 admin-only aggregate diagnostics. Existing analytics observations are
 * independent event counts, not a joined checkout funnel or causal attribution.
 * The output intentionally contains no user, email, contact or order records.
 */
function sf_fan_journey_diagnostics(array $analytics,array $publicSongs,int $days=30):array {
    $days=max(1,min(3650,$days));
    $count=static fn(string $key):int=>max(0,(int)($analytics[$key]??0));
    $starts=$count('starts');$skips=$count('skips');$repeat=$count('repeat_starts');
    $saves=$count('favorites_added');$paid=$count('paid_orders');
    $builds=$count('builds_created');$custom=$count('custom_media_orders');
    $public=[];
    foreach(array_slice($publicSongs,0,1000) as $song) {
        if(!is_array($song))continue;
        $id=(string)($song['id']??'');
        if($id!==''&&!isset($public[$id]))$public[$id]=substr((string)($song['title']??$id),0,160);
    }
    $tracks=[];
    foreach(array_slice((array)($analytics['tracks']??[]),0,150) as $row) {
        if(!is_array($row))continue;
        $id=(string)($row['track_id']??'');
        if(!isset($public[$id]))continue;
        // Existing listener keys may represent sessions rather than unique people.
        $keys=max(0,(int)($row['listeners']??0));
        if($keys<5)continue;
        $tracks[]=['track_id'=>$id,'title'=>$public[$id],
            'starts'=>max(0,(int)($row['listens']??0)),
            'repeat_starts'=>max(0,(int)($row['repeat_starts']??0)),
            'favorites'=>max(0,(int)($row['favorites']??0)),
            'digital_purchases'=>max(0,(int)($row['digital_purchases']??0)),
            'listener_keys'=>$keys];
    }
    usort($tracks,static fn(array $a,array $b):int=>($b['starts']<=>$a['starts'])?:strcmp($a['track_id'],$b['track_id']));
    $tracks=array_slice($tracks,0,8);
    $opportunities=[];
    $add=static function(string $code,string $title,string $evidence,string $suggestion,string $strength)use(&$opportunities):void {
        $opportunities[]=['code'=>$code,'title'=>$title,'evidence'=>$evidence,
            'suggestion'=>$suggestion,'strength'=>$strength,'action_type'=>'review_only','executed'=>false];
    };
    if($starts<20) {
        $add('sample_limited','Gather more listening evidence',
            $starts.' starts in '.$days.' days.',
            'Wait for enough activity before treating differences as behavioral patterns.','insufficient_sample');
    } else {
        if($repeat>=max(10,(int)ceil($starts/4)))
            $add('repeat_interest','Feature frequently replayed songs',
                $repeat.' repeat starts from '.$starts.' listening starts.',
                'Inspect top public songs for stronger discovery placement.','exploratory');
        if($skips>=10&&$skips*100>=$starts*40)
            $add('skip_events','Review early listening experience',
                $skips.' skip events and '.$starts.' starts in this period.',
                'Check playback reliability, preview quality and song-page expectations.','exploratory');
        if($saves<=max(1,(int)floor($starts*.02)))
            $add('save_discovery','Make song saving easy to discover',
                $saves.' favorite events versus '.$starts.' listening starts.',
                'Check that the Save action is visible from the player and track page.','exploratory');
        if($paid===0&&$starts>=30)
            $add('purchase_path','Review song purchase navigation',
                $starts.' listening starts and zero recorded paid orders.',
                'Inspect track-to-cart navigation and checkout clarity; do not assume a failed conversion.','exploratory');
        if($builds>=5&&$custom===0)
            $add('vinyl_path','Review vinyl Builder handoff',
                $builds.' saved builds and zero recorded paid custom-media orders.',
                'Check Builder review, licensing explanation and checkout handoff.','exploratory');
        if(!$opportunities)
            $add('baseline','No strong warning in this window',
                $starts.' starts, '.$saves.' favorites and '.$paid.' paid orders.',
                'Continue monitoring before making site changes.','observational');
    }
    return [
        'days'=>$days,
        'measures'=>[
            ['label'=>'Listening starts','count'=>$starts,'unit'=>'events'],
            ['label'=>'Repeat starts','count'=>$repeat,'unit'=>'events'],
            ['label'=>'Favorites added','count'=>$saves,'unit'=>'events'],
            ['label'=>'Recorded paid-status orders','count'=>$paid,'unit'=>'orders'],
            ['label'=>'Saved builds','count'=>$builds,'unit'=>'builds'],
            ['label'=>'Recorded custom-media orders','count'=>$custom,'unit'=>'orders'],
        ],
        'top_public_songs'=>$tracks,
        'opportunities'=>array_slice($opportunities,0,6),
        'data_quality'=>[
            'observational_only'=>true,'funnel_attribution_verified'=>false,
            'cart_events'=>'not_instrumented','vinyl_interest_events'=>'not_instrumented',
            'track_min_listener_keys'=>5,
            'note'=>'Independent event counts, not a sequential funnel. Listener keys can represent sessions rather than unique fans. No causal attribution or marketing consent is inferred.',
        ],
        'privacy'=>'Aggregate admin diagnostics; no customer IDs, emails, order IDs or private songs.',
        'changes_executed'=>false,
    ];
}
