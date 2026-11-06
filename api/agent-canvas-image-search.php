<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-image-inputs.php';
require_once __DIR__.'/agent-public-artist-context.php';

function sf_agent_canvas_image_search_table(string $table): bool {
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $q->execute([$table]);
    }else $q=$db->query('SHOW TABLES LIKE '.$db->quote($table));
    $exists=$q->fetchColumn()!==false;$q->closeCursor();
    return $exists;
}

function sf_agent_canvas_image_search_terms(string $query): array {
    $query=function_exists('mb_strtolower')?mb_strtolower($query,'UTF-8'):strtolower($query);
    $words=preg_split('/[^\p{L}\p{N}]+/u',$query)?:[];
    $stop=['a','an','and','are','as','at','be','by','can','could','do','for','from','has','have','i','in','into','is','it',
        'me','my','of','on','or','our','please','that','the','their','them','these','this','to','us','want','we','with','would','you','your',
        'add','build','choose','create','design','display','find','generate','give','include','let','lets','make','place','show','use','using'];
    $terms=[];
    foreach($words as $word){
        if(strlen($word)<2||strlen($word)>100||in_array($word,$stop,true))continue;
        // Keep ordinary plurals searchable without requiring another model call.
        if(strlen($word)>4&&str_ends_with($word,'s')&&!str_ends_with($word,'ss'))$word=substr($word,0,-1);
        $terms[$word]=true;
        if(count($terms)===24)break;
    }
    return array_map('strval',array_keys($terms));
}

/** Resolve only published song and album labels when searching managed asset links. */
function sf_agent_canvas_image_search_links(array $assets): array {
    if(!$assets)return [];
    $tracks=function_exists('sf_public_catalog')?array_column(sf_public_catalog(),null,'id'):[];
    $releases=[];$trackReleases=[];
    if(function_exists('sf_seo_release_rows')){
        foreach(sf_seo_release_rows() as $release){
            if(($release['state']??'published')==='published'&&($release['public_visible']??true)!==false){
                $releases[(string)$release['id']]=$release;
                foreach((array)($release['track_ids']??[]) as $trackId){
                    if(is_string($trackId)&&isset($tracks[$trackId]))$trackReleases[$trackId][]=(string)$release['id'];
                }
            }
        }
    }
    $ids=array_column($assets,'id');
    $q=sf_db()->prepare("SELECT asset_id,entity_type,entity_id FROM media_links WHERE public_visible=1
        AND entity_type IN ('track','release') AND asset_id IN (".implode(',',array_fill(0,count($ids),'?')).')');
    $q->execute($ids);$links=[];
    foreach($q->fetchAll() as $link){
        $id=(int)$link['asset_id'];$entity=(string)$link['entity_id'];
        if($link['entity_type']==='track'&&isset($tracks[$entity])){
            $links[$id]['track_ids'][]=$entity;
            $links[$id]['track_titles'][]=sf_agent_public_artist_text($tracks[$entity]['title']??'',180);
            foreach(array_unique([($tracks[$entity]['release_id']??''),...($trackReleases[$entity]??[])]) as $releaseId){
                if(isset($releases[$releaseId])){
                    $links[$id]['release_ids'][]=$releaseId;
                    $links[$id]['release_titles'][]=sf_agent_public_artist_text($releases[$releaseId]['title']??'',180);
                }
            }
        }elseif($link['entity_type']==='release'&&isset($releases[$entity])){
            $links[$id]['release_ids'][]=$entity;
            $links[$id]['release_titles'][]=sf_agent_public_artist_text($releases[$entity]['title']??'',180);
            foreach((array)($releases[$entity]['track_ids']??[]) as $trackId){
                if(is_string($trackId)&&isset($tracks[$trackId])){
                    $links[$id]['track_ids'][]=$trackId;
                    $links[$id]['track_titles'][]=sf_agent_public_artist_text($tracks[$trackId]['title']??'',180);
                }
            }
        }
    }
    foreach($links as &$fields){
        foreach($fields as &$values)$values=array_slice(array_values(array_unique($values)),0,24);
        unset($values);
    }
    unset($fields);
    return $links;
}

/** This projection is also used for the public picker. Raw rows and provenance stay on the server. */
function sf_agent_canvas_image_search_metadata(array $rows,int $owner,string $source): array {
    if(!$rows)return [];
    $analysis=[];$origins=[];$links=[];
    if($source==='reference'){
        require_once __DIR__.'/agent-canvas-image-references.php';
        if(sf_agent_canvas_image_search_table('media_image_analyses')){
            require_once __DIR__.'/media-image-analysis.php';
            $analysis=sf_media_image_analysis_approved($rows);
        }
        $links=sf_agent_canvas_image_search_links($rows);
    }else{
        if(sf_agent_canvas_image_search_table('agent_canvas_image_analyses')){
            require_once __DIR__.'/agent-canvas-image-analysis-core.php';
            $analysis=sf_agent_canvas_image_analysis_approved($rows,$owner);
        }
        if(sf_agent_canvas_image_search_table('agent_canvas_photo_sources')){
            require_once __DIR__.'/agent-canvas-photo-core.php';
            $origins=sf_agent_canvas_photo_metadata($rows,$owner);
        }
    }
    $images=[];
    foreach($rows as $row){
        $key=$source==='reference'?(int)$row['id']:$row['id'];
        $id=$source==='reference'?$row['reference_id']:$row['id'];
        $image=['id'=>$id,'title'=>sf_agent_public_artist_text($row['title']??'',180),
            'width'=>(int)$row['width'],'height'=>(int)$row['height'],
            'created_at'=>$source==='reference'?$row['reference_created_at']:$row['created_at'],
            'url'=>'api/agent-canvas-image.php?id='.$id,'source'=>$source,
            'source_kind'=>$source==='reference'?'artist_reference':($origins[$key]['source_kind']??'generated'),
            'is_own_photo'=>$source==='saved'&&($origins[$key]['is_own_photo']??false)===true];
        foreach(['caption'=>4000,'alt_text'=>500,'credit'=>255] as $field=>$limit){
            $value=sf_agent_public_artist_text($row[$field]??'',$limit);
            if($value!=='')$image[$field]=$value;
        }
        if(isset($analysis[$key]))$image['image_analysis']=$analysis[$key];
        $images[]=$image+($links[$key]??[]);
    }
    return $images;
}

function sf_agent_canvas_image_search_score(array $image,array $terms): int {
    if(!$terms)return 0;
    $analysis=$image['image_analysis']??[];
    $fields=[
        [$image['title']??'',7],
        [implode(' ',array_merge($image['track_ids']??[],$image['track_titles']??[],$image['release_ids']??[],$image['release_titles']??[])),7],
        [implode(' ',array_merge($analysis['tags']??[],$analysis['objects']??[])),6],
        [($image['caption']??'').' '.($image['alt_text']??''),4],
        [$analysis['description']??'',3],[$analysis['visible_text']??'',4],
        [($analysis['scene']??'').' '.($analysis['style']??'').' '.implode(' ',$analysis['colors']??[]),3],
        [str_replace('_',' ',$image['source_kind']??''),2],
    ];
    $score=0;
    foreach($fields as [$text,$weight]){
        $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
        foreach($terms as $term){
            if(preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term,'/').'s?(?![\p{L}\p{N}])/u',$text)===1)$score+=$weight;
        }
    }
    return $score;
}

function sf_agent_canvas_image_search_get(int $owner,string $id): ?array {
    $available=sf_agent_canvas_image_asset_get($owner,$id);
    if($available===null)return null;
    $reference=str_starts_with($id,'ref-');
    $row=$reference?sf_agent_canvas_reference_row($id):sf_agent_canvas_image_row($owner,$id);
    if($row===null)return null;
    $metadata=sf_agent_canvas_image_search_metadata([$row],$owner,$reference?'reference':'saved');
    return $available+($metadata[0]??[]);
}

function sf_agent_canvas_image_search_compare(array $a,array $b): int {
    return $b['score']<=>$a['score']?:strcmp($b['image']['created_at'],$a['image']['created_at'])?:strcmp($b['image']['id'],$a['image']['id']);
}

/** Keep provider context small and show the relevant OCR excerpt instead of forwarding the whole transcription. */
function sf_agent_canvas_image_search_prompt(array $images,string $query): array {
    $terms=sf_agent_canvas_image_search_terms($query);$result=[];$used=0;
    foreach($images as $image){
        $compact=array_intersect_key($image,array_flip(['id','title','width','height','source','source_kind']));
        $compact['title']=sf_agent_public_artist_text($compact['title']??'',90);
        $analysis=$image['image_analysis']??[];
        foreach(['description'=>180,'visible_text'=>140,'scene'=>100,'style'=>100] as $field=>$max){
            $text=sf_agent_public_artist_text($analysis[$field]??'',12000);
            if($text==='')continue;
            foreach($terms as $term){
                if(preg_match('/.{0,35}'.preg_quote($term,'/').'.{0,100}/isu',$text,$excerpt)===1){$text=$excerpt[0];break;}
            }
            $compact[$field]=sf_agent_public_artist_text($text,$max);
        }
        foreach(['tags','objects','uncertainties','colors'] as $field){
            $values=[];
            foreach(array_slice($analysis[$field]??[],0,$field==='uncertainties'?2:5) as $value)
                $values[]=sf_agent_public_artist_text($value,$field==='uncertainties'?60:32);
            if($values)$compact[$field]=$values;
        }
        foreach(['track_titles','release_titles'] as $field){
            if(!empty($image[$field]))$compact[$field]=array_map(static fn(string $value):string=>sf_agent_public_artist_text($value,60),array_slice($image[$field],0,2));
        }
        $budget=min(900,2200-$used);
        $bytes=strlen(json_encode($compact,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if($bytes>$budget){
            foreach(['objects','release_titles','track_titles','description','tags','visible_text','uncertainties','scene','style','colors'] as $field){
                unset($compact[$field]);
                $bytes=strlen(json_encode($compact,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                if($bytes<=$budget)break;
            }
        }
        if($bytes>$budget)continue;
        $result[]=$compact;$used+=$bytes;
        if(count($result)===6)break;
    }
    return $result;
}

/** Search all eligible inventory, retaining only the requested page in memory. */
function sf_agent_canvas_image_search(int $owner,string $query,int $limit=24,?string $before=null,string $scope='all'): array {
    if(!in_array($scope,['saved','reference','all'],true))throw new InvalidArgumentException('Choose an image inventory.');
    if($owner<1&&$scope!=='reference')throw new InvalidArgumentException('Sign in to search your images.');
    $empty=['images'=>[],'next_cursor'=>null];
    $limit=max(1,min(100,$limit));$terms=sf_agent_canvas_image_search_terms(sf_agent_public_artist_text($query,1800));
    $db=sf_db();$sources=[];
    if($scope!=='reference'){
        sf_agent_canvas_image_ensure_schema();
        $sources['saved']=['SELECT * FROM agent_canvas_images WHERE user_id=?',[$owner]];
    }
    if($scope!=='saved'&&sf_agent_canvas_image_search_table('agent_canvas_reference_images')){
        $sources['reference']=['SELECT a.*,r.id reference_id,r.created_at reference_created_at
            FROM agent_canvas_reference_images r JOIN media_assets a ON a.id=r.asset_id',[]];
    }
    $ranked=[];$cursor=null;
    if($before!==null){
        if(!sf_agent_canvas_image_asset_id($before))return $empty;
        $source=str_starts_with($before,'ref-')?'reference':'saved';
        if(!isset($sources[$source]))return $empty;
        [$sql,$params]=$sources[$source];
        $q=$db->prepare($sql.($source==='reference'?' WHERE r.id=?':' AND id=?'));
        $q->execute([...$params,$before]);$row=$q->fetch();$q->closeCursor();
        if(!$row)return $empty;
        $image=sf_agent_canvas_image_search_metadata([$row],$owner,$source)[0];
        $score=sf_agent_canvas_image_search_score($image,$terms);
        if(($terms&&$score===0)||($source==='reference'?sf_agent_canvas_reference_get($before):sf_agent_canvas_image_get($owner,$before))===null)
            return $empty;
        $cursor=['score'=>$score,'image'=>$image];
    }
    foreach($sources as $source=>[$sql,$params]){
        $offset=0;
        do{
            $order=$source==='reference'?'a.id':'id';
            $q=$db->prepare($sql.' ORDER BY '.$order.' LIMIT 100 OFFSET '.$offset);$q->execute($params);
            $rows=$q->fetchAll();$q->closeCursor();$offset+=100;
            foreach(sf_agent_canvas_image_search_metadata($rows,$owner,$source) as $image){
                $score=sf_agent_canvas_image_search_score($image,$terms);
                if($terms&&$score===0)continue;
                $candidate=['score'=>$score,'image'=>$image];
                if($cursor!==null&&sf_agent_canvas_image_search_compare($candidate,$cursor)<=0)continue;
                if(count($ranked)>$limit&&sf_agent_canvas_image_search_compare($candidate,$ranked[$limit])>=0)continue;
                $available=$source==='reference'?sf_agent_canvas_reference_get($image['id']):sf_agent_canvas_image_get($owner,$image['id']);
                if($available===null)continue;
                $candidate['image']=$available+$image;
                $ranked[]=$candidate;
                usort($ranked,'sf_agent_canvas_image_search_compare');
                if(count($ranked)>$limit+1)array_pop($ranked);
            }
        }while(count($rows)===100);
    }
    $images=array_column(array_slice($ranked,0,$limit),'image');
    return ['images'=>$images,'next_cursor'=>count($ranked)>$limit?$images[$limit-1]['id']:null];
}
