<?php
declare(strict_types=1);

function sf_home_recent_history(array $history,int $limit=6): array {
    $limit=max(1,min(12,$limit));$seen=[];$out=[];
    foreach($history as $row){
        $id=(string)($row['track_id']??'');if($id===''||isset($seen[$id]))continue;$seen[$id]=true;
        $out[]=$row;if(count($out)>=$limit)break;
    }
    return $out;
}

function sf_home_recent_releases(int $limit=4): array {
    $limit=max(1,min(12,$limit));$rows=[];
    foreach(sf_release_rows() as $r){
        if(($r['state']??'published')!=='published'||($r['public_visible']??true)===false)continue;
        $id=(string)($r['id']??'');if($id==='')continue;$art=$r['artwork']??[];$rows[]=[
            'id'=>$id,
            'title'=>(string)($r['title']??'Untitled release'),
            'type'=>(string)($r['type']??'release'),
            'release_date'=>(string)($r['release_date']??''),
            'description'=>(string)($r['description']??''),
            'artwork'=>is_array($art)?(string)($art['cover']??''):(string)$art,
            'track_count'=>count((array)($r['track_ids']??[])),
        ];
    }
    usort($rows,function($a,$b){
        $date=strcmp((string)$b['release_date'],(string)$a['release_date']);if($date!==0)return $date;
        return strcmp((string)$a['title'],(string)$b['title']);
    });
    return array_slice($rows,0,$limit);
}

function sf_home_daily_suggestion(int $userId,array $personalization,array $savedBuilds,array $recentReleases): array {
    $pool=[];$continue=(array)($personalization['continue_listening']??[]);
    foreach(array_slice($continue,0,3) as $row){
        if((string)($row['track_id']??'')==='')continue;$pool[]=['kind'=>'continue','eyebrow'=>'PICK UP WHERE YOU LEFT OFF','title'=>'Continue “'.(string)($row['title']??'this track').'”','text'=>'You stopped partway through this one. I can pick it up from the same spot.','action'=>['type'=>'resume_track','track_id'=>(string)($row['track_id']??''),'position_seconds'=>(int)($row['position_seconds']??0)],'button'=>'Resume'];
    }
    foreach(array_slice((array)($personalization['recommendations']['items']??[]),0,4) as $row){
        if((string)($row['track_id']??'')==='')continue;$reason=(string)(($row['reasons'][0]??'Based on your Stonefellow listening profile.'));
        $pool[]=['kind'=>'recommendation','eyebrow'=>'AGENT PICK','title'=>'Try “'.(string)($row['title']??'this track').'”','text'=>$reason,'action'=>['type'=>'play_track','track_id'=>(string)($row['track_id']??'')],'button'=>'Play'];
    }
    foreach(array_slice($savedBuilds,0,2) as $row){
        if((int)($row['id']??0)<1)continue;$pool[]=['kind'=>'build','eyebrow'=>'FINISH SOMETHING','title'=>'Keep building “'.(string)($row['name']??'your custom record').'”','text'=>'Your saved custom-media draft is ready when you are.','action'=>['type'=>'open_build','build_id'=>(int)($row['id']??0)],'button'=>'Open draft'];
    }
    foreach(array_slice((array)($personalization['favorites']['releases']??[]),0,2) as $row){
        if((string)($row['id']??'')==='')continue;$pool[]=['kind'=>'favorite_release','eyebrow'=>'FROM YOUR FAVORITES','title'=>'Return to “'.(string)($row['title']??'this release').'”','text'=>'A release you saved is worth another pass.','action'=>['type'=>'open_release','release_id'=>(string)($row['id']??'')],'button'=>'Open release'];
    }
    foreach(array_slice($recentReleases,0,2) as $row){
        if((string)($row['id']??'')==='')continue;$pool[]=['kind'=>'release','eyebrow'=>'RECENT RELEASE','title'=>'Explore “'.(string)($row['title']??'this release').'”','text'=>'One of the latest Stonefellow releases is ready to explore.','action'=>['type'=>'open_release','release_id'=>(string)($row['id']??'')],'button'=>'Explore'];
    }
    if(!$pool)return ['kind'=>'catalog','eyebrow'=>'AGENT SUGGESTION','title'=>'Start somewhere new','text'=>'Explore the Stonefellow catalog and I’ll learn what you come back to.','action'=>['type'=>'open_view','view'=>'music'],'button'=>'Browse music'];
    $seed=abs((int)crc32($userId.'|'.gmdate('Y-m-d').'|'.count($pool)));return $pool[$seed%count($pool)];
}

function sf_home_state(int $userId): array {
    $personalization=sf_personalization_state($userId);$savedBuilds=sf_account_saved_builds($userId);$recentReleases=sf_home_recent_releases(4);
    return [
        'continue_listening'=>array_slice((array)($personalization['continue_listening']??[]),0,6),
        'recently_played'=>sf_home_recent_history((array)($personalization['history']??[]),6),
        'favorites'=>[
            'tracks'=>array_slice((array)($personalization['favorites']['tracks']??[]),0,6),
            'releases'=>array_slice((array)($personalization['favorites']['releases']??[]),0,4),
        ],
        'recommendations'=>[
            'personalized'=>(bool)($personalization['recommendations']['personalized']??false),
            'summary'=>(string)($personalization['recommendations']['summary']??''),
            'items'=>array_slice((array)($personalization['recommendations']['items']??[]),0,6),
        ],
        'recent_releases'=>$recentReleases,
        'saved_builds'=>array_slice($savedBuilds,0,4),
        'suggestion'=>sf_home_daily_suggestion($userId,$personalization,$savedBuilds,$recentReleases),
        'queue_count'=>(int)(sf_queue_payload($userId)['count']??0),
        'generated_at'=>gmdate('c'),
    ];
}
