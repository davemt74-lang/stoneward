<?php
declare(strict_types=1);

const SF_CANVAS_STORY_BODY_CHARS=4000;
const SF_CANVAS_STORY_SUMMARY_CHARS=640;

/** Initialize legacy schemas before a caller starts its approval transaction. */
function sf_canvas_story_prepare(): void {
    if(sf_db()->inTransaction())throw new LogicException('Initialize story resources before opening a transaction.');
    sf_story_ensure_schema();
    sf_media_ensure_schema();
}

/** Resolve every requested published story against its current readiness. */
function sf_canvas_story_sources(?array $selection, int $bodyChars=SF_CANVAS_STORY_BODY_CHARS): Generator {
    $mode=$selection===null?'all':($selection['mode']??null);
    if(!in_array($mode,['all','selected','tag'],true))throw new InvalidArgumentException('Choose a valid story selection.');
    $db=sf_db();
    if(!$db->inTransaction())sf_story_ensure_schema();
    $summaryChars=SF_CANVAS_STORY_SUMMARY_CHARS;
    $bodyNext=$bodyChars+1;
    $summaryNext=$summaryChars+1;
    $sql="SELECT id,slug,entry_type,title,event_date,era,entity_type,entity_id,tags_json,
          SUBSTR(body_text,1,$bodyChars) AS body_text,
          SUBSTR(summary,1,$summaryChars) AS summary,
          CASE WHEN LENGTH(SUBSTR(body_text,$bodyNext,1))>0 THEN 1 ELSE 0 END AS body_truncated,
          CASE WHEN LENGTH(SUBSTR(summary,$summaryNext,1))>0 THEN 1 ELSE 0 END AS summary_truncated
          FROM story_entries WHERE status='published'";
    if($mode==='selected'){
        $ids=$selection['story_ids']??null;
        if(!is_array($ids)||!array_is_list($ids))throw new InvalidArgumentException('Choose valid stories.');
        $query=$db->prepare($sql.' AND id=?'.sf_story_current_read());
        foreach($ids as $id){
            if(!sf_story_id_valid($id))throw new InvalidArgumentException('Choose valid stories.');
            $query->execute([$id]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if($row!==false&&sf_story_readiness($row,false)['ready'])yield $row;
        }
        return;
    }
    $arguments=[];
    if($mode==='tag'){
        $tag=$selection['tag']??null;
        if(!is_string($tag)||strlen($tag)>64||preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',$tag)!==1)
            throw new InvalidArgumentException('Choose a valid story tag.');
        $sql.=' AND tags_json LIKE ?';
        $arguments[]='%"'.$tag.'"%';
    }
    $query=$db->prepare($sql.' ORDER BY featured DESC,CASE WHEN event_date IS NULL THEN 1 ELSE 0 END,event_date DESC,position,id DESC'.sf_story_current_read());
    $query->execute($arguments);
    while(($row=$query->fetch(PDO::FETCH_ASSOC))!==false){
        if($mode==='tag'&&!in_array($tag,sf_story_stored_tags($row['tags_json']),true))continue;
        if(sf_story_readiness($row,false)['ready'])yield $row;
    }
}

/** Read governed attachment metadata, never file paths or analysis records. */
function sf_canvas_story_media(int $id): array {
    // The existing Media Library helper may initialize tables on its first use.
    // Approval and settlement only need identities and never read media here.
    if(sf_db()->inTransaction())throw new LogicException('Load story attachment metadata outside a transaction.');
    if(!sf_story_published_ready((string)$id))return ['image_url'=>'','media'=>[],'media_truncated'=>0];
    $media=[];
    $image='';
    $heroFound=false;
    foreach(sf_story_media($id,true) as $asset){
        $uuid=$asset['uuid']??'';
        if(!is_string($uuid)||preg_match('/\A[A-Za-z0-9_-]{1,64}\z/',$uuid)!==1
           ||!in_array($asset['category'],['image','audio','video','document'],true))continue;
        $url='api/media.php?id='.rawurlencode($uuid);
        $thumb=($asset['thumb_url']??'')===$url.'&variant=thumb'?$url.'&variant=thumb':$url;
        if($asset['category']==='image'&&($image===''||(!$heroFound&&$asset['role']==='hero'))){
            $image=$thumb;
            $heroFound=$asset['role']==='hero';
        }
        $media[]=['category'=>$asset['category'],'role'=>sf_clean_text($asset['role'],60),
            'title'=>sf_clean_text($asset['title'],255),'caption'=>sf_clean_text($asset['caption'],300),
            'alt_text'=>sf_clean_text($asset['alt_text'],500),'credit'=>sf_clean_text($asset['credit'],255),
            'copyright'=>sf_clean_text($asset['copyright'],500),'url'=>$url,'thumb_url'=>$thumb];
    }
    return ['image_url'=>$image,'media'=>array_slice($media,0,12),'media_truncated'=>count($media)>12?1:0];
}

function sf_canvas_story_inventory(): array {
    sf_canvas_story_prepare();
    $items=[];
    foreach(sf_canvas_story_sources(null,240) as $source){
        $related=sf_story_entity($source['entity_type'],$source['entity_id']);
        $media=sf_canvas_story_media((int)$source['id']);
        $items[]=['id'=>(string)$source['id'],'title'=>sf_clean_text($source['title'],255),
            'description'=>sf_clean_text($source['summary']!==''?$source['summary']:$source['body_text'],240),
            'category'=>str_replace('_',' ',$source['entry_type']),'event_date'=>(string)$source['event_date'],
            'related_title'=>sf_clean_text($related['title']??'',255),'image_url'=>$media['image_url'],
            'tags'=>sf_story_stored_tags($source['tags_json'])];
    }
    return $items;
}

/** Published narrative with canonical, individually authorized relationships. */
function sf_canvas_story_rows(?array $selection,bool $withMedia=true): array {
    if($withMedia)sf_canvas_story_prepare();
    $rows=[];
    foreach(sf_canvas_story_sources($selection) as $source){
        $related=sf_story_entity($source['entity_type'],$source['entity_id']);
        $relatedType=$related['type']??'';
        $relatedId=$related['id']??'';
        $row=['story_id'=>(string)$source['id'],'title'=>sf_clean_text($source['title'],255),
            'entry_type'=>$source['entry_type'],'event_date'=>(string)$source['event_date'],'era'=>sf_clean_text($source['era'],160),
            'summary'=>$source['summary'],'summary_truncated'=>(int)$source['summary_truncated'],
            'body_text'=>$source['body_text'],'body_truncated'=>(int)$source['body_truncated'],
            'tags'=>sf_story_stored_tags($source['tags_json']),
            'story_url'=>$source['entry_type']==='artist_about'?'about':'story/'.rawurlencode($source['slug']),
            'related_type'=>$relatedType,'related_id'=>$relatedId,'related_title'=>sf_clean_text($related['title']??'',255),
            'related_url'=>$relatedType===''?'':$relatedType.'/'.rawurlencode($relatedId),
            'track_id'=>$relatedType==='track'?$relatedId:'',
            'release_id'=>$relatedType==='release'?$relatedId:'',
            'show_id'=>$relatedType==='show'?$relatedId:''];
        if($withMedia)$row+=sf_canvas_story_media((int)$source['id']);
        $rows[]=$row;
    }
    return $rows;
}
