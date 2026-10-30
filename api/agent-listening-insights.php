<?php
declare(strict_types=1);
/**
 * Section 91B7 — verified album selection and consented listening-time context.
 * Play times are already recorded in listening_events.created_at (UTC); convert
 * those server timestamps to the listener's IANA time zone for daypart insights.
 */
function sf_agent_daypart(int $hour): string {
    $hour=(($hour%24)+24)%24;
    if($hour>=5&&$hour<12)return 'morning';
    if($hour>=12&&$hour<17)return 'afternoon';
    if($hour>=17&&$hour<22)return 'evening';
    return 'night';
}
function sf_agent_timezone(string $value): DateTimeZone {
    $zone=trim($value);
    if($zone===''||strlen($zone)>70||!preg_match('/^[A-Za-z0-9_\/+\-]+$/D',$zone))return new DateTimeZone('UTC');
    try{return new DateTimeZone($zone);}catch(Throwable $e){return new DateTimeZone('UTC');}
}
function sf_agent_release_request(string $message,array $client=[],array $history=[]): ?array {
    $q=sf_agent_track_words($message);
    $q=preg_replace('/^(?:(?:hey|hi|ok|okay) )?stone ?fellow /','',$q)??$q;
    $q=preg_replace('/^please /','',$q)??$q;
    $title='';$matched=false;
    $patterns=[
        '/^(?:play|put on|start|listen to) (?:me )?(?:something|some music|any(?: song| track)?|a (?:song|track)|one) (?:from|off) (?:the )?(.+)$/',
        '/^(?:choose|pick|select) (?:me )?(?:any|a|one) (?:song|track) (?:from|off) (?:the )?(.+?)(?: and play it)?$/',
        '/^(?:play|put on|start) (?:another|a different) (?:song|track|one) (?:from|off) (?:the )?(.+)$/',
        '/^(?:choose|pick|play) (?:another|a different|one more)(?: (?:song|track|one))?(?: from (.+))?$/'
    ];
    foreach($patterns as $pattern)if(preg_match($pattern,$q,$m)){$title=trim((string)($m[1]??''));$matched=true;break;}
    if(!$matched)return null;
    if(!in_array($title,['that album','this album','the album','that release','this release','the release'],true))
        $title=preg_replace('/(?: and play it| (?:album|release))$/','',$title)??$title;
    $title=trim($title);
    if(in_array($title,['','it','that album','this album','the album','album','that release','this release','the release','release'],true)){
        foreach(array_reverse(array_slice($history,-8)) as $entry){
            if(($entry['role']??'')!=='user')continue;
            $prior=sf_agent_release_request((string)($entry['content']??''),[],[]);
            if($prior&&!in_array($prior['title'],['that album','this album','the album'],true)){$title=$prior['title'];break;}
        }
        if(in_array($title,['','it','that album','this album','the album','album','that release','this release','the release','release'],true))
            $title=sf_agent_track_words((string)($client['active_release_title']??''));
    }
    return $title!==''?['title'=>$title,'different'=>(bool)preg_match('/\b(?:another|different|one more)\b/',$q)]:null;
}
function sf_agent_release_tracks(string $query,array $catalog): array {
    $query=sf_agent_track_words($query);
    if($query==='')return [];
    $groups=[];$labels=[];
    foreach($catalog as $t){
        $title=trim((string)($t['release']??''));$id=(string)($t['id']??'');
        if($title===''||$id===''||empty($t['audio']))continue;
        $key=sf_agent_track_words($title);if($key==='')continue;
        $groups[$key][]=$t;$labels[$key]=$title;
    }
    $scores=[];
    foreach($groups as $key=>$items){
        $max=max(strlen($query),strlen($key));
        $scores[$key]=$max?1.-levenshtein(substr($query,0,200),substr($key,0,200))/$max:0.;
    }
    arsort($scores);$keys=array_keys($scores);
    if(!$keys||$scores[$keys[0]]<0.82)return [];
    if(count($keys)>1&&$keys[0]!==$keys[1]&&$scores[$keys[1]]>=0.82&&$scores[$keys[0]]-$scores[$keys[1]]<0.08)return [];
    return $groups[$keys[0]];
}
function sf_agent_listening_insights(int $userId,string $timezone='UTC'): array {
    $zone=sf_agent_timezone($timezone);$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone($zone);
    $out=['enabled'=>false,'timezone'=>$zone->getName(),'daypart'=>sf_agent_daypart((int)$now->format('G')),'daypart_counts'=>[],'recent'=>[],'top_by_daypart'=>[],'total_starts'=>0];
    if($userId<1||!function_exists('sf_privacy_listening_history_enabled')||!sf_privacy_listening_history_enabled($userId)
        ||!function_exists('sf_privacy_personalization_enabled')||!sf_privacy_personalization_enabled($userId))return $out;
    try{
        sf_ops_ensure_schema();
        $cut=(new DateTimeImmutable('-180 days',new DateTimeZone('UTC')))->format('c');
        $q=sf_db()->prepare("SELECT track_id,created_at FROM listening_events WHERE user_id=? AND event_type='start' AND created_at>=? ORDER BY id DESC LIMIT 500");
        $q->execute([$userId,$cut]);
        $allowed=[];foreach(sf_agent_public_catalog() as $t)$allowed[(string)($t['id']??'')]=$t;
        $counts=[];$recent=[];
        foreach($q->fetchAll() as $row){
            $id=(string)($row['track_id']??'');if(!isset($allowed[$id]))continue;
            try{$when=(new DateTimeImmutable((string)$row['created_at']))->setTimezone($zone);}catch(Throwable $ignored){continue;}
            $part=sf_agent_daypart((int)$when->format('G'));$counts[$part][$id]=($counts[$part][$id]??0)+1;
            if(count($recent)<6)$recent[]=['track_id'=>$id,'title'=>(string)($allowed[$id]['title']??''),'release'=>(string)($allowed[$id]['release']??''),'at'=>$when->format('Y-m-d H:i'),'daypart'=>$part];
            $out['total_starts']++;
        }
        foreach($counts as $part=>$byTrack){arsort($byTrack);$out['top_by_daypart'][$part]=array_slice(array_map(static fn($id,$count)=>['track_id'=>$id,'title'=>(string)($allowed[$id]['title']??''),'plays'=>$count],array_keys($byTrack),array_values($byTrack)),0,5);}
        $out['daypart_counts']=$counts;$out['recent']=$recent;$out['enabled']=true;
    }catch(Throwable $ignored){/* A missing analytics table must never break conversation or music playback. */}
    return $out;
}
function sf_agent_listening_context(array $insights): string {
    if(empty($insights['enabled']))return '';
    $lines=['CONSENTED LISTENING HISTORY (user-specific, server-timestamped; local time zone '.($insights['timezone']??'UTC').'): current listening period '.($insights['daypart']??'').' (not a claim about what is playing now).'];
    foreach((array)($insights['top_by_daypart']??[]) as $part=>$tracks){
        $labels=[];foreach(array_slice((array)$tracks,0,3) as $t)$labels[]=(string)$t['title'].' ('.(int)$t['plays'].' starts)';
        if($labels)$lines[]=ucfirst((string)$part).' preferences: '.implode(', ',$labels).'.';
    }
    foreach(array_slice((array)($insights['recent']??[]),0,4) as $row)$lines[]='Recently played: '.(string)$row['title'].' on '.(string)$row['at'].' ('.(string)$row['daypart'].').';
    $lines[]='Use these as bounded preferences when discussing or recommending music; never assert a preference from a single play or disclose another user\'s history.';
    return implode("\n",$lines);
}
function sf_agent_release_play_plan(string $query,array $catalog,?string $activeId=null,array $insights=[],bool $different=false): array {
    $tracks=sf_agent_release_tracks($query,$catalog);
    $plan=['route'=>'player_play_from_release','action'=>['type'=>'none'],'action_name'=>'none','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'text'=>'','track'=>null];
    if(!$tracks){$plan['text']='I could not identify a published, playable album matching “'.$query.'.” I have not changed playback.';return $plan;}
    $release=(string)($tracks[0]['release']??'');
    $options=array_values(array_filter($tracks,fn($t)=>(string)($t['id']??'')!==$activeId));
    if(!$options)$options=$tracks;
    $part=(string)($insights['daypart']??'');
    $counts=(array)($insights['daypart_counts'][$part]??[]);
    // Prefer a fresh selection for this listener's current time of day.
    usort($options,static function($a,$b)use($counts){
        $left=(int)($counts[(string)($a['id']??'')]??0);$right=(int)($counts[(string)($b['id']??'')]??0);
        return $left<=>$right;
    });
    $track=$options[0];$plan['track']=$track;$plan['action']=['type'=>'play_track','track_id'=>(string)$track['id']];$plan['action_name']='play_track';
    $plan['text']='I will load “'.(string)($track['title']??'').'” from “'.$release.'”.';
    return $plan;
}
