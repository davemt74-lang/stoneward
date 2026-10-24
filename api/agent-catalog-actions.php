<?php
declare(strict_types=1);
/**
 * Section 90E: deterministic Admin Chat -> reviewed catalog metadata edits.
 * No external model chooses a track, writes a file or supplies authority.
 */

function sf_agent_catalog_parse_edit(string $message): ?array {
    $prefix='^\s*(?:please\s+)?(?:set|change|update)\s+(title|genre|year|story)\s+(?:for|of)\s+(?:the\s+)?(?:song|track)(?:\s+id)?\s+';
    if(preg_match('~'.$prefix.'["“]([^"”]{1,180})["”]\s+to\s+(.+?)\s*$~iu',$message,$match)
       ||preg_match('~'.$prefix.'(.+)\s+to\s+(.+?)\s*$~iu',$message,$match)){
        $lookup=trim((string)$match[2]," \t\n\r\0\x0B\"“”");
        $value=trim((string)$match[3]," \t\n\r\0\x0B\"“”");
        if($lookup===''||$value==='')return null;
        return ['lookup'=>$lookup,'field'=>strtolower((string)$match[1]),'value'=>$value];
    }
    return null;
}

function sf_agent_catalog_disk_snapshot(): array {
    $raw=file_get_contents(SF_ROOT.'/data/catalog.json');
    if($raw===false)throw new RuntimeException('Catalog unavailable for review.');
    $rows=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Catalog is not a list of tracks.');
    return ['rows'=>$rows,'content_hash'=>hash('sha256',$raw)];
}
function sf_agent_catalog_disk_rows(): array {
    return sf_agent_catalog_disk_snapshot()['rows'];
}

function sf_agent_catalog_revision(array $track): string {
    return hash('sha256',json_encode($track,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function sf_agent_catalog_track(array $rows,string $reference): array {
    $matches=[];$reference=trim($reference);
    foreach($rows as $index=>$track){
        if(!is_array($track))continue;
        if((string)($track['id']??'')===$reference){return [$index,$track];}
        if(strcasecmp(trim((string)($track['title']??'')),$reference)===0)$matches[]=[$index,$track];
    }
    if(count($matches)>1)throw new InvalidArgumentException('Multiple tracks share that title. Use the unique song ID.');
    if(!$matches)throw new InvalidArgumentException('Song not found. Use its exact title or catalog ID.');
    return $matches[0];
}

function sf_agent_catalog_field_value(array $track,string $field): string {
    return match($field){
        'genre'=>(string)($track['metadata']['genre']??''),
        'title'=>(string)($track['title']??''),
        'story'=>(string)($track['story']??''),
        'year'=>(string)($track['year']??''),
        default=>throw new InvalidArgumentException('This catalog field is not available to the Agent.')
    };
}

/** Pure normalization accepts supplied rows so stale-data tests never edit real catalog files. */
function sf_agent_catalog_normalize_edit(array $input,?array $rows=null): array {
    $rows=$rows??sf_agent_catalog_disk_rows();
    $trackId=(string)($input['track_id']??'');
    $field=(string)($input['field']??'');
    $revision=(string)($input['expected_revision']??'');
    if($trackId===''||!in_array($field,['title','genre','story','year'],true)
       ||!preg_match('/^[a-f0-9]{64}$/D',$revision))
        throw new InvalidArgumentException('Unsupported or incomplete catalog edit.');
    [, $track]=sf_agent_catalog_track($rows,$trackId);
    if(!hash_equals(sf_agent_catalog_revision($track),$revision))
        throw new RuntimeException('The track changed. Review its latest details before preparing a new edit.');
    if(!is_string($input['value']??null) && !is_int($input['value']??null))
        throw new InvalidArgumentException('Catalog value must be text or year.');
    $raw=trim((string)$input['value']);
    if($field==='year'){
        if(!preg_match('/^\d{4}$/D',$raw)||(int)$raw<1900||(int)$raw>2100)
            throw new InvalidArgumentException('Year must be between 1900 and 2100.');
        $value=$raw;
    }else{
        $limit=$field==='story'?2000:($field==='genre'?80:180);
        if($raw===''||strlen($raw)>$limit||preg_match('/[\x00-\x1F\x7F]/',$raw))
            throw new InvalidArgumentException('The field is empty or exceeds its permitted text limit.');
        $value=$raw;
    }
    $previous=sf_agent_catalog_field_value($track,$field);
    if($previous===$value)throw new InvalidArgumentException('That field already has this value.');
    return [
        'track_id'=>(string)$track['id'],'track_title'=>(string)($track['title']??''),
        'field'=>$field,'previous'=>$previous,'value'=>$value,'expected_revision'=>$revision
    ];
}

function sf_agent_catalog_draft_from_chat(string $message): ?array {
    $parsed=sf_agent_catalog_parse_edit($message);
    if($parsed===null)return null;
    $rows=sf_agent_catalog_disk_rows();
    [, $track]=sf_agent_catalog_track($rows,$parsed['lookup']);
    return sf_agent_catalog_normalize_edit([
        'track_id'=>(string)$track['id'],'field'=>$parsed['field'],
        'value'=>$parsed['value'],'expected_revision'=>sf_agent_catalog_revision($track)
    ],$rows);
}

/** Recheck exact record revision at execution; never replace an entire stale catalog. */
function sf_agent_catalog_apply_snapshot(array $draft,array $rows): array {
    $normalized=sf_agent_catalog_normalize_edit($draft,$rows);
    [$index,$track]=sf_agent_catalog_track($rows,$normalized['track_id']);
    $field=$normalized['field'];$value=$normalized['value'];
    if($field==='genre'){
        $metadata=is_array($track['metadata']??null)?$track['metadata']:[];
        $metadata['genre']=$value;$track['metadata']=$metadata;
    }elseif($field==='year'){$track['year']=(int)$value;}
    else{$track[$field]=$value;}
    $rows[$index]=$track;
    return ['rows'=>$rows,'draft'=>$normalized,'revision'=>sf_agent_catalog_revision($track)];
}
