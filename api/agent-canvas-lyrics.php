<?php
declare(strict_types=1);
require_once __DIR__.'/songbook-core.php';
require_once __DIR__.'/song-moments-core.php';
require_once __DIR__.'/agent-public-artist-context.php';

function sf_agent_canvas_lyric_label(string $label): ?string {
    $label=trim($label," \t\n\r[]:");
    if(!preg_match('/\A(verse|chorus|bridge)(?:\s+(?:[1-9][0-9]?|i{1,3}|iv|v))?\z/i',$label,$match))return null;
    return strtolower($match[1]);
}

function sf_agent_canvas_lyric_words(array $lines): string {
    $words=[];
    foreach($lines as $line){
        $line=trim($line);
        if($line!==''&&!sf_songbook_is_heading_line($line)&&sf_agent_canvas_lyric_label($line)===null)$words[]=$line;
    }
    return implode("\n",$words);
}

function sf_agent_canvas_lyric_match_text(string $text): string {
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    return trim(preg_replace('/[^\p{L}\p{N}\p{M}]+/u',' ',$text)??'');
}

/** A named Songbook section gets a cue only when whole approved blocks match unambiguously. */
function sf_agent_canvas_lyric_cue(string $text,array $segments): array {
    $target=sf_agent_canvas_lyric_match_text($text);
    if($target==='')return [];
    $match=[];
    foreach($segments as $index=>$first){
        $candidate='';$end=$first['start_ms'];$approximate=false;
        foreach(array_slice($segments,$index,20) as $block){
            if($block['start_ms']<$end)break;
            $end=$block['end_ms'];$approximate=$approximate||$block['approximate'];
            $candidate=trim($candidate.' '.$block['match_text']);
            if($candidate===$target){
                if($match!==[])return [];
                $match=['start_ms'=>$first['start_ms'],'end_ms'=>$end,'approximate'=>$approximate];
                break;
            }
            if(!str_starts_with($target,$candidate.' '))break;
        }
    }
    return $match;
}

/** Return only a bounded creative excerpt from publisher-approved sources. */
function sf_agent_canvas_lyrics(string $trackId,string $section='whole'): ?array {
    if(!in_array($section,['whole','verse','chorus','bridge'],true))throw new InvalidArgumentException('Choose song lyrics, a verse, a chorus, or a bridge.');
    if(sf_songbook_public_track($trackId)===null)return null;

    $segments=[];
    foreach(sf_song_moments_public($trackId) as $moment){
        if(($moment['kind']??'')!=='lyric')continue;
        $lines=sf_songbook_lines((string)$moment['body']);
        $text=sf_agent_canvas_lyric_words($lines);
        $matchText=sf_agent_canvas_lyric_match_text($text);
        $start=(int)$moment['at_ms'];$end=(int)$moment['end_ms'];
        if($matchText===''||$start<0||$end<=$start)continue;
        $label=(string)($moment['title']??'');
        if(sf_agent_canvas_lyric_label($label)===null)$label=(string)($lines[0]??'');
        $segments[]=['text'=>$text,'match_text'=>$matchText,'label'=>$label,'start_ms'=>$start,'end_ms'=>$end,
            'approximate'=>in_array($moment['timing_source']??'',['whole_clip_estimate','catalog_chunk_estimate'],true)];
    }

    $book=sf_songbook_get($trackId,true);
    // Keep Songbook identity exact even on a case-insensitive database collation.
    $sections=($book['track_id']??null)===$trackId?$book['sections']:[];
    $bookLines=$sections?sf_songbook_lines((string)($book['track']['lyrics']??'')):[];
    $words=[];$label='Song lyrics';
    foreach($sections as $entry){
        if($section!=='whole'&&sf_agent_canvas_lyric_label($entry['label'])!==$section)continue;
        $start=(int)$entry['start_line'];$end=(int)$entry['end_line'];
        $text=sf_agent_canvas_lyric_words(array_slice($bookLines,$start-1,$end-$start+1));
        if($text==='')continue;
        $words[]=$text;
        if($section!=='whole'){$label=$entry['label'];break;}
    }
    $source='published_songbook';$cue=[];
    if($words){
        $text=implode("\n",$words);
        $cue=sf_agent_canvas_lyric_cue($text,$segments);
    }else{
        $text='';
        foreach($segments as $segment){
            if($section!=='whole'&&sf_agent_canvas_lyric_label($segment['label'])!==$section)continue;
            if($section!=='whole')$label=trim($segment['label']," \t\n\r[]:");
            $text=$segment['text'];$cue=$segment;
            break;
        }
        $source='published_timeline';
    }

    $excerpt=sf_agent_public_artist_text($text,420);
    if($excerpt==='')return null;
    return ['section'=>$section,'label'=>sf_agent_public_artist_text($label,60),'text'=>$excerpt,'source'=>$source,
        'start_ms'=>$cue['start_ms']??null,'end_ms'=>$cue['end_ms']??null,
        'timing'=>$cue?'saved_segments':null,'approximate'=>$cue['approximate']??false];
}
