<?php
declare(strict_types=1);
require_once __DIR__.'/song-moments-core.php';

/** Bound evidence fields by bytes without cutting an encoded character. */
function sf_song_section_context_text(string $text,int $limit): string {
    $text=trim(preg_replace('/[\x00-\x1F\x7F\s]+/u',' ',$text)??'');
    if(strlen($text)<=$limit)return $text;
    $text=substr($text,0,$limit);
    while($text!==''&&preg_match('//u',$text)!==1)$text=substr($text,0,-1);
    return rtrim($text);
}

/** Read public evidence only for the selected interval on a current public listening copy. */
function sf_song_section_public_context(string $trackId,int $startMs,int $endMs): array {
    try {
        $track=sf_song_moments_track($trackId);
        if(sf_song_moments_audio_fingerprint($track)==='')return [];
        [$startMs,$endMs]=sf_song_moments_lyric_range($startMs,$endMs,$track);
    } catch(InvalidArgumentException) {
        // Older clip analysis accepts listener-reported ranges. Context is optional there.
        return [];
    }
    sf_song_moments_schema();
    // Preserve the catalog's case-sensitive song identity under MySQL's collation.
    $exactTrack=(string)(sf_db_config()['driver']??'')==='sqlite'?'':' AND BINARY track_id=BINARY ?';
    $sql="SELECT id,at_ms,title,body,story_id,media_uuid FROM song_timeline_moments
      WHERE track_id=? AND status='published' AND source_annotation_id=0 AND kind=? AND at_ms>=? AND at_ms<?".$exactTrack;
    $out=[];
    foreach(['story','photo','comment'] as $kind){
        // Bound each kind separately so a busy comment thread cannot displace artist material.
        $moderation=$kind==='comment'?" AND moderated_by>0 AND moderated_at<>''":'';
        $q=sf_db()->prepare($sql.$moderation.' ORDER BY at_ms,id LIMIT 20');
        $args=[(string)$track['id'],$kind,$startMs,$endMs];if($exactTrack!=='')$args[]=(string)$track['id'];
        $q->execute($args);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$count=0;
        foreach($rows as $row){
            $title=sf_song_section_context_text((string)$row['title'],160);
            $text=sf_song_section_context_text((string)$row['body'],600);
            $item=['id'=>(int)$row['id'],'kind'=>$kind,'at_ms'=>(int)$row['at_ms'],'title'=>$title,'text'=>$text];
            if($kind==='story'){
                $story=sf_story_get((int)$row['story_id'],true);
                if(!$story||(($story['entity_type']??'')==='track'&&(string)($story['entity_id']??'')!==(string)$track['id']))continue;
                $slug=(string)($story['slug']??'');
                if(!preg_match('/\A[A-Za-z0-9_-]{1,160}\z/',$slug))continue;
                $item['title']=$title!==''?$title:sf_song_section_context_text((string)($story['title']??''),160);
                $excerpt=sf_song_section_context_text((string)($story['summary']??'')?:(string)($story['body_text']??''),600);
                $item['text']=sf_song_section_context_text(implode(' ',array_unique(array_filter([$text,$excerpt],static fn($value)=>$value!==''))),1000);
                $item['href']='story/'.rawurlencode($slug);
            }elseif($kind==='photo'){
                $photo=sf_song_moments_public_image((string)$row['media_uuid']);
                if(!$photo||!preg_match('/\Aapi\/media\.php\?id=M[A-F0-9]{20}&variant=thumb\z/',$photo['thumb_url']))continue;
                $item['title']=$title!==''?$title:(sf_song_section_context_text($photo['title'],160)?:'Song photo');
                $caption=sf_song_section_context_text($photo['caption'],600);
                $item['text']=sf_song_section_context_text(implode(' ',array_unique(array_filter([$text,$caption],static fn($value)=>$value!==''))),800);
                $item['image_url']=$photo['thumb_url'];
                $item['alt']=sf_song_section_context_text($photo['alt'],180)?:$item['title'];
            }else{
                if($text==='')continue;
                $item['title']='Listener comment';
            }
            $out[]=$item;if(++$count===3)break;
        }
    }
    usort($out,static fn(array $a,array $b)=>[$a['at_ms'],$a['id']]<=>[$b['at_ms'],$b['id']]);
    return $out;
}
