<?php
declare(strict_types=1);
/**
 * Artist World 88B1: a read-only public connection map.
 * No second catalog, owned-record inference, hidden media, fan profiling or writes.
 */
function sf_world_text(mixed $value,int $length=260):string {
    $v=strip_tags((string)$value);
    $v=preg_replace('/[\x00-\x1F\x7F]/u',' ',$v)??'';
    return trim(function_exists('mb_substr')?mb_substr($v,0,$length):substr($v,0,$length));
}
function sf_world_node(string $type,array $data):?array {
    $types=['track'=>'track','release'=>'release','story'=>'story','video'=>'video','photo'=>'photo','show'=>'show'];
    if(!isset($types[$type]))return null;
    if(($data['public_visible']??true)===false)return null;
    if(in_array((string)($data['status']??$data['state']??'published'),['draft','private','archived','disabled'],true))return null;
    $id=(string)($data['id']??'');
    if($id===''||strlen($id)>160||!preg_match('/^[a-zA-Z0-9_-]+$/D',$id))return null;
    $title=sf_world_text($data['title']??$data['venue']??'',180);
    if($title==='')return null;
    $slug=(string)($data['slug']??'');
    if(in_array($type,['story','video','photo'],true)&&!preg_match('/^[a-z0-9-]{1,160}$/D',$slug))return null;
    $image='';
    if($type==='release')$image=(string)($data['artwork']['cover']??$data['artwork']??'');
    elseif($type==='track')$image=(string)($data['artwork']??'');
    elseif($type==='video')$image=(string)($data['poster']['url']??'');
    elseif($type==='photo')$image=(string)($data['cover']['thumb_url']??$data['cover']['url']??'');
    elseif($type==='story'){
        foreach((array)($data['media']??[]) as $m)
            if(is_array($m)&&($m['category']??'')==='image'){$image=(string)($m['url']??'');break;}
    }
    // Images are presentation-only. Never forward arbitrary schemes into DOM.
    if($image!==''&&!preg_match('#^(?:https://[^\s"<>]+|(?:api/)?media\.php\?id=[A-Za-z0-9]+(?:&variant=[a-z]+)?|assets/[a-zA-Z0-9/_\.%-]+|uploads/[a-zA-Z0-9/_\.%-]+)$#D',$image))$image='';
    $view=$type;
    $params=in_array($type,['story','video','photo'],true)?['slug'=>$slug]:['id'=>$id];
    $summary=sf_world_text($data['summary']??$data['description']??$data['story']??'',260);
    return ['key'=>$type.':'.$id,'type'=>$type,'id'=>$id,'title'=>$title,
        'summary'=>$summary,'image'=>$image,'view'=>$view,'params'=>$params,
        'featured'=>!empty($data['featured']),
        'date'=>sf_world_text($data['event_date']??$data['release_date']??$data['date']??'',24)];
}
function sf_world_graph_assemble(array $sources):array {
    $nodes=[];$edges=[];$edgeSeen=[];$releaseTitles=[];
    foreach(['release','track','story','video','photo','show'] as $type){
        $rows=(array)($sources[$type]??[]);
        foreach(array_slice($rows,0,500) as $row){
            if(!is_array($row))continue;
            $node=sf_world_node($type,$row);
            if(!$node)continue;$nodes[$node['key']]=$node;
            if($type==='release')$releaseTitles[strtolower($node['title'])]=$node['id'];
        }
    }
    $connect=function(string $a,string $b,string $kind)use(&$edges,&$edgeSeen,$nodes):void {
        if($a===$b||!isset($nodes[$a],$nodes[$b]))return;
        $key=implode('|',[$a,$b,$kind]);if(isset($edgeSeen[$key]))return;$edgeSeen[$key]=true;
        $edges[]=['from'=>$a,'to'=>$b,'relation'=>$kind];
    };
    foreach((array)($sources['track']??[]) as $row){
        if(!is_array($row))continue;$key='track:'.(string)($row['id']??'');
        if(!isset($nodes[$key]))continue;
        $release=(string)($row['release_id']??'');
        if($release===''&&isset($releaseTitles[strtolower((string)($row['release']??''))]))
            $release=$releaseTitles[strtolower((string)($row['release']??''))];
        if($release!=='')$connect('release:'.$release,$key,'contains');
    }
    foreach(['story','video','photo'] as $type){
        foreach((array)($sources[$type]??[]) as $row){
            if(!is_array($row))continue;
            $from=$type.':'.(string)($row['id']??'');if(!isset($nodes[$from]))continue;
            $refs=$type==='story'
              ?[['type'=>(string)($row['entity_type']??''),'id'=>(string)($row['entity_id']??'')]]
              :(array)($row['entities']??[]);
            foreach(array_slice($refs,0,15) as $ref){
                if(!is_array($ref))continue;$t=(string)($ref['type']??'');$id=(string)($ref['id']??'');
                if(!in_array($t,['track','release','show','story'],true))continue;
                $connect($from,$t.':'.$id,'about');
            }
        }
    }
    // Discover linked pieces in both directions, with no orphaned private endpoints.
    $byType=[];foreach($nodes as $node)$byType[$node['type']][]=$node;
    foreach($byType as &$items)usort($items,fn($a,$b)=>(int)$b['featured']<=>(int)$a['featured']?:strcmp($a['title'],$b['title']));
    unset($items);
    $nodeList=array_values($nodes);
    usort($nodeList,fn($a,$b)=>(int)$b['featured']<=>(int)$a['featured']?:strcmp($a['title'],$b['title']));
    return ['nodes'=>$nodeList,'edges'=>$edges,
       'counts'=>array_map('count',$byType),
       'total_nodes'=>count($nodeList),'total_connections'=>count($edges),
       'source'=>'published_stonefellow_catalog_media_and_events',
       'includes_private_data'=>false,'marketing_or_commerce_action_executed'=>false];
}
function sf_world_public_graph():array {
    $sources=['release'=>[],'track'=>[],'story'=>[],'video'=>[],'photo'=>[],'show'=>[]];$missing=[];
    try{
        foreach(sf_seo_release_rows() as $r){
            if(!is_array($r))continue;$id=(string)($r['id']??'');
            if($id!==''&&sf_seo_public_release($id))$sources['release'][]=$r;
        }
        foreach(sf_catalog() as $t){
            if(!is_array($t))continue;$id=(string)($t['id']??'');
            if($id!==''&&sf_seo_public_track($id))$sources['track'][]=$t;
        }
    }catch(Throwable $e){$missing[]='music';error_log('[Artist World music] '.get_class($e));}
    foreach(['story'=>'sf_story_list','video'=>'sf_video_public_list','photo'=>'sf_gallery_public_list','show'=>'sf_live_public_shows'] as $type=>$fn){
        try{$sources[$type]=$type==='story'?sf_story_list(true,'',200):$fn();}
        catch(Throwable $e){$missing[]=$type;error_log('[Artist World '.$type.'] '.get_class($e));}
    }
    $graph=sf_world_graph_assemble($sources);
    $graph['unavailable_sources']=$missing;
    $graph['complete']=count($missing)===0;
    return $graph;
}
