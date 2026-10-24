<?php
declare(strict_types=1);
/**
 * Section 90C: smallest provider-bound slice of PUBLIC Stonefellow facts.
 * Source selection follows published visibility resolvers, then serializes an
 * explicit field allowlist. Never forward raw catalog, media, user or KB rows.
 */
function sf_agent_public_artist_text(mixed $value,int $max=180): string {
    if(!is_scalar($value))return '';
    $text=trim((string)$value);
    $text=preg_replace('/[\p{C}\s]+/u',' ',$text)??'';
    return function_exists('mb_substr')?mb_substr($text,0,$max):substr($text,0,$max);
}

function sf_agent_public_artist_provider_context(string $query,int $maxChars=3800): string {
    $maxChars=max(300,min(5000,$maxChars));
    $terms=array_values(array_unique(array_filter(
        preg_split('/[^a-z0-9]+/i',strtolower($query))?:[],
        static fn(string $term):bool=>strlen($term)>=3&&!in_array($term,
            ['the','and','for','are','can','you','what','who','how','with','have','about','stonefellow','please','tell','show','some','from','does','your','our'],true)
    )));
    $artistQuery=(bool)preg_match('/\b(?:stonefellow|artists?|songs?|tracks?|music|albums?|releases?|concerts?|shows|tour|credits?|discography|biography|catalog|recordings?)\b/i',$query);
    $overview=$artistQuery && (bool)preg_match('/\b(?:list|all|latest|discography|catalog|published|overview|artist biography|artist bio|about stonefellow|stonefellow story|upcoming shows|concerts|tour dates)\b/i',$query);
    $preferred=preg_match('/\b(?:biography|artist bio|artist story|about stonefellow)\b/i',$query)?'published_artist_story':
        (preg_match('/\b(?:shows|concerts?|tour dates|gigs?)\b/i',$query)?'public_show':
        (preg_match('/\b(?:albums?|releases?|discography)\b/i',$query)?'published_release':
        (preg_match('/\b(?:songs?|tracks?|recordings?)\b/i',$query)?'published_track':'')));
    $records=[];
    if(function_exists('sf_catalog') && function_exists('sf_seo_public_track')){
        foreach(sf_catalog() as $row){
            if(!is_array($row))continue;
            $id=(string)($row['id']??'');
            if($id===''||!sf_seo_public_track($id))continue;
            $meta=(array)($row['metadata']??[]);
            $record=['kind'=>'published_track','title'=>sf_agent_public_artist_text($row['title']??'',160)];
            foreach(['id','release','story'] as $key){
                $value=sf_agent_public_artist_text($row[$key]??'', $key==='story'?230:130);
                if($value!=='')$record[$key]=$value;
            }
            $moods=array_slice(array_filter(array_map(static fn($v)=>sf_agent_public_artist_text($v,45),(array)($row['mood']??[]))),0,4);
            if($moods)$record['moods']=array_values($moods);
            foreach(['words_by','music_by','producer','isrc'] as $key){
                $value=sf_agent_public_artist_text($meta[$key]??'',120);
                if($value!=='')$record[$key]=$value;
            }
            $records[]=$record;
        }
    }
    if(function_exists('sf_seo_release_rows') && function_exists('sf_seo_public_release')){
        foreach(sf_seo_release_rows() as $row){
            if(!is_array($row))continue;
            $id=(string)($row['id']??'');
            if($id===''||!sf_seo_public_release($id))continue;
            $record=['kind'=>'published_release','title'=>sf_agent_public_artist_text($row['title']??'',160)];
            foreach(['type','description','release_date'] as $key){
                $value=sf_agent_public_artist_text($row[$key]??'', $key==='description'?220:90);
                if($value!=='')$record[$key]=$value;
            }
            $records[]=$record;
        }
    }
    if(function_exists('sf_live_public_shows')){
        foreach(sf_live_public_shows() as $row){
            if(!is_array($row))continue;
            $record=['kind'=>'public_show'];
            foreach(['date','venue','city','region','country','tour'] as $key){
                $value=sf_agent_public_artist_text($row[$key]??'',120);
                if($value!=='')$record[$key]=$value;
            }
            if(count($record)>1)$records[]=$record;
        }
    }
    if(function_exists('sf_story_list')){
        foreach(sf_story_list(true,'',24) as $row){
            if(!is_array($row))continue;
            $kind=(string)($row['entry_type']??'');
            if(!in_array($kind,['artist_about','essay','milestone','story'],true))continue;
            $record=['kind'=>'published_artist_story','title'=>sf_agent_public_artist_text($row['title']??'',160)];
            $summary=sf_agent_public_artist_text($row['summary']??'',260);
            if($summary!=='')$record['summary']=$summary;
            $records[]=$record;
        }
    }
    $score=static function(array $record)use($terms,$preferred):int {
        $hay=strtolower(implode(' ',array_map(static fn($v):string=>is_array($v)?implode(' ',$v):(string)$v,$record)));
        $matches=0;
        foreach($terms as $word)if(str_contains($hay,$word))$matches++;
        return $matches+($preferred!==''&&$record['kind']===$preferred?5:0);
    };
    usort($records,static fn(array $a,array $b):int=>$score($b)<=>$score($a));
    $lines=[];$total=0;$counts=[];
    $kindLimits=['published_track'=>5,'published_release'=>4,'public_show'=>3,'published_artist_story'=>3];
    foreach($records as $record){
        $kind=$record['kind'];
        if(($counts[$kind]??0)>=($kindLimits[$kind]??0))continue;
        if($terms && !$overview && $score($record)<($preferred===$kind?5:min(2,count($terms))))continue;
        $json=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        if(!is_string($json))continue;
        if($total+strlen($json)+1>$maxChars)continue;
        $lines[]=$json;
        $total+=strlen($json)+1;
        $counts[$kind]=($counts[$kind]??0)+1;
        if(count($lines)>=12)break;
    }
    return implode("\n",$lines);
}

/** Useful, privacy-preserving answer when the configured provider is down. */
function sf_agent_public_artist_local_reply(string $publicContext): ?string {
    $first=explode("\n",trim($publicContext),2)[0]??'';
    $record=json_decode($first,true);
    if(!is_array($record))return null;
    $kind=(string)($record['kind']??'');
    if($kind==='published_track'){
        $name=(string)($record['title']??'Untitled');
        $reply='The public Stonefellow catalog lists “'.$name.'”.';
        if(!empty($record['release']))$reply.=' Release: '.$record['release'].'.';
        if(!empty($record['words_by']))$reply.=' Lyrics credited to '.$record['words_by'].'.';
        if(!empty($record['music_by']))$reply.=' Music credited to '.$record['music_by'].'.';
        if(!empty($record['story']))$reply.=' About the song: '.$record['story'].'.';
        return $reply;
    }
    if($kind==='published_release')return 'The public Stonefellow discography lists “'.($record['title']??'Untitled').'”. '.($record['description']??'');
    if($kind==='public_show')return 'The public show calendar lists '.($record['venue']??'a performance').' on '.($record['date']??'an unspecified date').' in '.($record['city']??'the listed venue').'.';
    if($kind==='published_artist_story')return 'Stonefellow’s published artist information: '.($record['summary']??$record['title']??'No summary is available.');
    return null;
}
