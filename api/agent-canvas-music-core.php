<?php
declare(strict_types=1);

/** Syntax normalization is deliberately independent of catalog loading and database access. */
function sf_agent_canvas_music_inputs(mixed $raw): ?array {
    if($raw===null)return null;
    if(!is_array($raw)||array_is_list($raw)||array_diff(array_keys($raw),['mode','track_ids']))
        throw new InvalidArgumentException('Choose songs from the music picker.');
    if(($raw['mode']??null)==='all'){
        if(array_key_exists('track_ids',$raw))throw new InvalidArgumentException('Choose all music or selected songs.');
        return ['mode'=>'all'];
    }
    if(($raw['mode']??null)!=='selected'||!is_array($raw['track_ids']??null)||!array_is_list($raw['track_ids']))
        throw new InvalidArgumentException('Choose a valid selected music scope.');
    foreach($raw['track_ids'] as $id)if(!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
        throw new InvalidArgumentException('Choose songs from the music picker.');
    return ['mode'=>'selected','track_ids'=>array_values(array_unique($raw['track_ids'],SORT_STRING))];
}

/** Composer tokens carry resource identity, leaving the description budget for the design itself. */
function sf_agent_canvas_music_selection(string $brief,mixed $raw=null,bool $provided=false): array {
    $ids=[];$all=false;$clear=false;
    $brief=preg_replace_callback('/(?<!\S)(\(*)(`?)\/music-all\2(?=[\s),.!?;:]|$)/i',static function(array $match)use(&$all):string {
        $all=true;return $match[1].' ';
    },$brief)??$brief;
    $clean=preg_replace_callback('/(`?)\{music-(all|clear|track:([^}]*))\}\1/i',
        static function(array $match)use(&$ids,&$all,&$clear):string {
            if(strcasecmp($match[2],'all')===0)$all=true;
            elseif(strcasecmp($match[2],'clear')===0)$clear=true;
            else $ids[]=$match[3];
            return ' ';
        },$brief);
    if($clean===null)throw new InvalidArgumentException('Choose a valid music selection.');
    if(($all&&$ids)||($clear&&($all||$ids)))throw new InvalidArgumentException('Choose all music, selected songs, or clear the direct music selection.');
    $tokens=$all?['mode'=>'all']:($clear?['mode'=>'selected','track_ids'=>[]]:($ids?sf_agent_canvas_music_inputs(['mode'=>'selected','track_ids'=>$ids]):null));
    $selection=$provided?sf_agent_canvas_music_inputs($raw):$tokens;
    if($provided&&$tokens!==null&&$selection!==$tokens)
        throw new InvalidArgumentException('The music selection changed. Apply the current picker selection.');
    return [trim($clean),$selection,$provided||$tokens!==null];
}

/** Current published hosted MP3s only. All mode has no list or sample cap. */
function sf_agent_canvas_music_catalog(?array $inputs=null): array {
    require_once __DIR__.'/canvas-audio-core.php';
    $inputs=sf_agent_canvas_music_inputs($inputs);
    $catalog=sf_canvas_audio_catalog();$available=[];
    foreach($catalog['tracks'] as $track)if(($track['source_available']??false)===true)$available[$track['track_id']]=$track;
    if(($inputs['mode']??null)==='selected'){
        $tracks=[];
        foreach($inputs['track_ids'] as $id)if(isset($available[$id]))$tracks[]=$available[$id];
    }else $tracks=array_values($available);
    $allowed=array_fill_keys(array_column($tracks,'track_id'),true);$releases=[];
    foreach($catalog['releases'] as $release){
        $release['track_ids']=array_values(array_filter($release['track_ids'],static fn(string $id):bool=>isset($allowed[$id])));
        if($release['track_ids'])$releases[]=$release;
    }
    return ['tracks'=>$tracks,'releases'=>$releases];
}

/** Explicit new selections fail before any provider is called, with no private-source details. */
function sf_agent_canvas_music_validate(array $inputs): void {
    $inputs=sf_agent_canvas_music_inputs($inputs);
    if($inputs['mode']==='all')return;
    require_once __DIR__.'/canvas-audio-core.php';
    foreach($inputs['track_ids'] as $id)if(sf_canvas_audio_source($id)===null)
        throw new InvalidArgumentException('One or more selected songs are unavailable. Refresh the music picker.');
}

function sf_agent_canvas_music_descriptor(array $inputs): array {
    $catalog=sf_agent_canvas_music_catalog($inputs);
    return ['mode'=>$inputs['mode'],'count'=>count($catalog['tracks'])];
}

/** Album resource aliases must not introduce a host layout for explicit song inputs. */
function sf_agent_canvas_music_catalog_refs(array $refs): array {
    return array_values(array_filter($refs,static fn(mixed $ref):bool=>!is_string($ref)||preg_match('/\Amedia[:-]/i',$ref)!==1));
}

/** Existing music dataset bindings remain authorized and preserve the selected song order. */
function sf_agent_canvas_music_rows(array $rows,array $catalog): array {
    $groups=[];
    foreach($rows as $row)if(is_string($row['track_id']??null))$groups[$row['track_id']][]=$row;
    $result=[];
    foreach($catalog['tracks'] as $track)foreach($groups[$track['track_id']]??[] as $row)$result[]=$row;
    return $result;
}
