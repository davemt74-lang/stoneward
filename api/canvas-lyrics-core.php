<?php
declare(strict_types=1);

const SF_CANVAS_LYRIC_TEXT_BYTES=42000;
const SF_CANVAS_LYRIC_CUE_BYTES=4200;
const SF_CANVAS_LYRIC_CUES_PER_TRACK=700;

function sf_canvas_lyrics_track_ids(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw))
        throw new InvalidArgumentException('Choose songs from the lyrics picker.');
    foreach($raw as $id){
        if(!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
            throw new InvalidArgumentException('Choose songs from the lyrics picker.');
    }
    return array_values(array_unique($raw,SORT_STRING));
}

/** Schema initialization can issue DDL and belongs outside approval transactions. */
function sf_canvas_lyrics_prepare(): void {
    static $prepared=false;
    if($prepared)return;
    if(sf_db()->inTransaction())throw new LogicException('Initialize lyric resources before opening a transaction.');
    require_once __DIR__.'/songbook-core.php';
    require_once __DIR__.'/song-moments-core.php';
    sf_songbook_ensure_schema();
    sf_song_moments_schema();
    sf_media_ensure_schema();
    $prepared=true;
}

/** Keep author line breaks and Unicode. The canvas binder displays these values as text. */
function sf_canvas_lyrics_text(string $text,int $maxBytes): array {
    $text=str_replace(["\r\n","\r"],"\n",$text);
    $text=trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$text)??'');
    if(strlen($text)<=$maxBytes)return [$text,0];
    $text=substr($text,0,$maxBytes);
    // Cutting UTF-8 by bytes can split the final character.
    while(preg_match('//u',$text)!==1)$text=substr($text,0,-1);
    return [rtrim($text),1];
}

/** Read published text and approved cues as distinct kinds of source material. */
function sf_canvas_lyrics_source(string $id): ?array {
    require_once __DIR__.'/canvas-audio-core.php';
    $track=sf_canvas_audio_public_track($id);
    if(($track['id']??null)!==$id)return null;
    sf_canvas_lyrics_prepare();
    $db=sf_db();
    $current=sf_media_current_read();
    $book=$db->prepare("SELECT track_id,status,language,sections_json,annotations_json,published_at,updated_at
        FROM songbooks WHERE track_id=? AND status='published' LIMIT 1".$current);
    $book->execute([$id]);
    $publication=$book->fetch(PDO::FETCH_ASSOC);
    $book->closeCursor();
    $rows=[];$versions=[];
    if($publication&&$publication['track_id']===$id&&$publication['status']==='published'){
        $publication['track']=$track;
        $publication['sections']=sf_songbook_decode($publication['sections_json']);
        $publication['annotations']=sf_songbook_decode($publication['annotations_json']);
        if(sf_songbook_readiness($publication)['ready']){
            $rawText=(string)($track['lyrics']??'');
            [$text,$truncated]=sf_canvas_lyrics_text($rawText,SF_CANVAS_LYRIC_TEXT_BYTES);
            if($text!==''){
                $rows[]=['source'=>'published_songbook','kind'=>'text','position'=>1,'text'=>$text,
                    'start_ms'=>null,'end_ms'=>null,'timing_source'=>null,'approximate'=>0,
                    'text_truncated'=>$truncated];
                $versions[]=['songbook',$publication['published_at'],$publication['updated_at'],
                    $publication['language'],hash('sha256',$rawText)];
            }
        }
    }

    $fingerprint=sf_song_moments_audio_fingerprint($track,true);
    $cueCount=0;$cuesTruncated=false;
    if($fingerprint!==''){
        // Apply the canonical audio and review scope before the limit so unrelated,
        // unapproved and superseded timeline entries cannot crowd out valid cues.
        $limit=SF_CANVAS_LYRIC_CUES_PER_TRACK+1;
        $cue=$db->prepare("SELECT id,track_id,source_fingerprint,kind,status,lyric_state,at_ms,end_ms,body,timing_source,revision
            FROM song_timeline_moments WHERE track_id=? AND source_fingerprint=? AND source_annotation_id=0
            AND kind='lyric' AND status='published' AND lyric_state='approved'
            AND at_ms>=0 AND end_ms>at_ms AND end_ms<=86400000 AND TRIM(body)<>'' ORDER BY at_ms,id LIMIT ".$limit.$current);
        $cue->execute([$id,$fingerprint]);
        while(($record=$cue->fetch(PDO::FETCH_ASSOC))!==false){
            if($record['track_id']!==$id||!hash_equals($fingerprint,$record['source_fingerprint'])
                ||$record['kind']!=='lyric'||$record['status']!=='published'||$record['lyric_state']!=='approved')continue;
            $rawText=(string)$record['body'];
            [$text,$truncated]=sf_canvas_lyrics_text($rawText,SF_CANVAS_LYRIC_CUE_BYTES);
            if($text==='')continue;
            if($cueCount===SF_CANVAS_LYRIC_CUES_PER_TRACK){$cuesTruncated=true;break;}
            $timing=(string)$record['timing_source'];
            $rows[]=['source'=>'published_timeline','kind'=>'cue','position'=>++$cueCount,'text'=>$text,
                'start_ms'=>(int)$record['at_ms'],'end_ms'=>(int)$record['end_ms'],'timing_source'=>$timing,
                'approximate'=>(int)in_array($timing,['whole_clip_estimate','catalog_chunk_estimate'],true),
                'text_truncated'=>$truncated];
            $versions[]=['cue',(int)$record['id'],(int)$record['revision'],(int)$record['at_ms'],(int)$record['end_ms'],
                $timing,hash('sha256',$rawText)];
        }
        $cue->closeCursor();
    }
    if(!$rows)return null;
    $revision=hash('sha256',json_encode([$id,$cueCount>0?$fingerprint:'',$versions,$cuesTruncated],JSON_THROW_ON_ERROR));
    return ['id'=>$id,'title'=>sf_clean_text($track['title']??$id,180),
        'release'=>sf_clean_text($track['_release']['title']??$track['release']??'',180),'source'=>$rows[0]['source'],
        'revision_hash'=>$revision,'timed'=>$cueCount>0,'cue_count'=>$cueCount,'cues_truncated'=>$cuesTruncated,'rows'=>$rows];
}

function sf_canvas_lyrics_sources(?array $ids): Generator {
    if($ids===[])return;
    // Planning, generation and settlement may happen in one long request.
    // Authorize against the current catalog file at each read boundary.
    sf_catalog(true);
    if($ids===null){
        $ids=array_values(array_filter(array_column(sf_public_catalog(),'id'),static fn(mixed $id):bool=>
            is_string($id)&&preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)===1));
    }
    foreach(sf_canvas_lyrics_track_ids($ids) as $id){
        $source=sf_canvas_lyrics_source($id);
        if($source!==null)yield $source;
    }
}

/** A public inventory exposes identities and timing availability, without copying lyric text. */
function sf_canvas_lyrics_inventory(?array $trackIds=null): array {
    $items=[];
    foreach(sf_canvas_lyrics_sources($trackIds) as $source){
        unset($source['rows']);
        $items[]=$source;
    }
    return $items;
}

function sf_canvas_lyrics_validate(array $trackIds): void {
    $trackIds=sf_canvas_lyrics_track_ids($trackIds);
    if(count(sf_canvas_lyrics_inventory($trackIds))!==count($trackIds))
        throw new InvalidArgumentException('A selected song no longer has published lyrics. Refresh the lyrics picker.');
}

/** A canvas must select or filter by canonical song ID before receiving lyric text. */
function sf_canvas_lyrics_row_stream(?array $trackIds): Generator {
    foreach(sf_canvas_lyrics_sources($trackIds??[]) as $source){
        foreach($source['rows'] as $row){
            yield $row+['track_id'=>$source['id'],'title'=>$source['title'],'revision_hash'=>$source['revision_hash'],
                'cues_truncated'=>(int)$source['cues_truncated']];
        }
    }
}

function sf_canvas_lyrics_rows(?array $trackIds): array {
    return iterator_to_array(sf_canvas_lyrics_row_stream($trackIds),false);
}
