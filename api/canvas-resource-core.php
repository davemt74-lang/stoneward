<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-public-commerce.php';
require_once __DIR__.'/canvas-playlists-core.php';
require_once __DIR__.'/canvas-stories-core.php';
require_once __DIR__.'/canvas-lyrics-core.php';

function sf_canvas_resource_story_scope(mixed $raw): array {
    if($raw===['mode'=>'all'])return $raw;
    if(!is_array($raw)||count($raw)!==2)throw new InvalidArgumentException('Choose a story scope from the picker.');
    if(($raw['mode']??null)==='tag'&&is_string($raw['tag']??null)&&strlen($raw['tag'])<=64&&
        preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',$raw['tag'])===1)
        return ['mode'=>'tag','tag'=>$raw['tag']];
    if(($raw['mode']??null)!=='selected'||!is_array($raw['story_ids']??null)||!array_is_list($raw['story_ids']))
        throw new InvalidArgumentException('Choose a story scope from the picker.');
    foreach($raw['story_ids'] as $id){
        if(!is_string($id)||preg_match('/\A[1-9][0-9]{0,18}\z/',$id)!==1||filter_var($id,FILTER_VALIDATE_INT)===false)
            throw new InvalidArgumentException('Choose published stories from the picker.');
    }
    return ['mode'=>'selected','story_ids'=>array_values(array_unique($raw['story_ids'],SORT_STRING))];
}

/** Syntax only. An omitted type and an explicitly empty selection have different meanings. */
function sf_canvas_resource_artist_inputs(mixed $raw): ?array {
    if($raw===null)return null;
    if(!is_array($raw)||($raw&&array_is_list($raw))||array_diff(array_keys($raw),['merch_ids','ticket_ids','stories','lyrics_ids','me_tools']))
        throw new InvalidArgumentException('Choose canvas resources from the picker.');
    $inputs=[];
    foreach(['merch_ids','ticket_ids'] as $field){
        if(!array_key_exists($field,$raw))continue;
        $ids=$raw[$field];
        if(!is_array($ids)||!array_is_list($ids))throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
        foreach($ids as $id)if(!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
            throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
        $inputs[$field]=array_values(array_unique($ids,SORT_STRING));
    }
    if(array_key_exists('lyrics_ids',$raw))$inputs['lyrics_ids']=sf_canvas_lyrics_track_ids($raw['lyrics_ids']);
    if(array_key_exists('stories',$raw))$inputs['stories']=sf_canvas_resource_story_scope($raw['stories']);
    if(array_key_exists('me_tools',$raw)){
        require_once __DIR__.'/canvas-account-data-core.php';
        $inputs['me_tools']=sf_canvas_account_data_ids($raw['me_tools']);
    }
    return $inputs;
}

function sf_canvas_resource_source_ids(array $raw): array {
    if(!array_is_list($raw))throw new InvalidArgumentException('Choose saved canvas sources.');
    foreach($raw as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose saved canvas sources.');
    return array_values(array_unique($raw,SORT_REGULAR));
}

/** Resource tokens carry identity without consuming the natural-language design budget. */
function sf_canvas_resource_selection(string $brief,mixed $rawArtist=null,bool $artistProvided=false,?array $rawSources=null): array {
    $artistTokens=[];$sourceTokens=[];$cleared=[];$storyIds=[];$storyScopes=[];
    $clean=preg_replace_callback('/(`?)\{(?:(canvas|merch|ticket|story|stories-tag|lyrics|me):([^}]*)|(merch-clear|tickets-clear|stories-all|stories-clear|lyrics-clear|me-clear))\}\1/i',
        static function(array $match)use(&$artistTokens,&$sourceTokens,&$cleared,&$storyIds,&$storyScopes):string {
            if(($match[4]??'')!==''){
                $command=strtolower($match[4]);
                if($command==='stories-all')$storyScopes['all']=['mode'=>'all'];
                elseif($command==='stories-clear')$storyScopes['clear']=['mode'=>'selected','story_ids'=>[]];
                elseif($command==='lyrics-clear')$cleared['lyrics_ids']=true;
                elseif($command==='me-clear')$cleared['me_tools']=true;
                else $cleared[$command==='merch-clear'?'merch_ids':'ticket_ids']=true;
                return ' ';
            }
            $kind=strtolower($match[2]);$id=$match[3];
            if($kind==='canvas'){
                $number=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($number===false||!ctype_digit($id))throw new InvalidArgumentException('Choose a saved canvas from the picker.');
                $sourceTokens[]=$number;
            }elseif($kind==='me'){
                $artistTokens['me_tools'][] = strtolower($id);
            }elseif($kind==='lyrics'){
                $artistTokens['lyrics_ids'][]=$id;
            }elseif($kind==='story'){
                $storyIds[]=$id;
            }elseif($kind==='stories-tag'){
                $tag=strtolower($id);
                $storyScopes['tag:'.$tag]=['mode'=>'tag','tag'=>$tag];
            }else{
                if(preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
                    throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
                $artistTokens[$kind==='merch'?'merch_ids':'ticket_ids'][]=$id;
            }
            return ' ';
        },$brief);
    if($clean===null)throw new InvalidArgumentException('Choose valid canvas resources.');
    if($storyIds)$storyScopes['selected']=['mode'=>'selected','story_ids'=>$storyIds];
    if(count($storyScopes)>1)throw new InvalidArgumentException('Choose individual stories, all stories, or one story tag.');
    if($storyScopes)$artistTokens['stories']=reset($storyScopes);
    foreach($cleared as $field=>$_){
        if(!empty($artistTokens[$field]))throw new InvalidArgumentException('Choose resources or clear the selection for each type.');
        $artistTokens[$field]=[];
    }
    $tokens=$artistTokens?sf_canvas_resource_artist_inputs($artistTokens):null;
    $artist=$artistProvided?sf_canvas_resource_artist_inputs($rawArtist):$tokens;
    if($artistProvided&&$tokens!==null){
        if($artist===null)throw new InvalidArgumentException('The resource selection changed. Apply the current picker selection.');
        foreach($tokens as $field=>$ids){
            if(array_key_exists($field,$artist)&&$artist[$field]!==$ids)
                throw new InvalidArgumentException('The resource selection changed. Apply the current picker selection.');
            $artist[$field]=$ids;
        }
        $artist=sf_canvas_resource_artist_inputs($artist);
    }
    $sources=$rawSources===null?null:sf_canvas_resource_source_ids($rawSources);
    if($sourceTokens){
        $sourceTokens=sf_canvas_resource_source_ids($sourceTokens);
        if($sources!==null&&$sources!==$sourceTokens)
            throw new InvalidArgumentException('The canvas selection changed. Apply the current picker selection.');
        $sources=$sourceTokens;
    }
    return [trim($clean),$artist,$artistProvided||$tokens!==null,$sources];
}

/** Current published rows, retaining every variant of each explicitly selected product. */
function sf_canvas_resource_artist_rows(string $toolId,?array $inputs): array {
    $inputs=sf_canvas_resource_artist_inputs($inputs);
    if($toolId==='published_stories')return sf_canvas_story_rows($inputs['stories']??null,false);
    $field=$toolId==='published_merch'?'merch_ids':'ticket_ids';
    $identity=$toolId==='published_merch'?'product_id':'offer_id';
    $rows=$toolId==='published_merch'?sf_agent_canvas_merch_rows():sf_agent_canvas_ticket_offer_rows();
    if($inputs===null||!array_key_exists($field,$inputs))return $rows;
    $groups=[];
    foreach($rows as $row)$groups[(string)$row[$identity]][]=$row;
    $selected=[];
    foreach($inputs[$field] as $id)foreach($groups[$id]??[] as $row)$selected[]=$row;
    return $selected;
}

function sf_canvas_resource_lyrics_enabled(): bool {
    require_once __DIR__.'/canvas-capabilities.php';
    return sf_canvas_permission_enabled('published_lyrics');
}

/** Model context contains selected public facts. Account purchases are separate private tools. */
function sf_canvas_resource_artist_context(?array $inputs,bool $includeLyricExcerpts=false): array {
    $inputs=sf_canvas_resource_artist_inputs($inputs);$context=[];
    foreach(['merch_ids'=>'published_merch','ticket_ids'=>'published_ticket_offers'] as $field=>$tool){
        if($inputs!==null&&array_key_exists($field,$inputs))$context[$tool]=sf_canvas_resource_artist_rows($tool,$inputs);
    }
    if(isset($inputs['stories'])){
        $scope=$inputs['stories'];
        $rows=sf_canvas_story_rows($scope,false);
        $preview=[];$bytes=0;
        foreach($rows as $row){
            $item=array_intersect_key($row,array_flip(['story_id','title','summary','entry_type','event_date','related_type','related_id','related_title','tags']));
            $bytes+=strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            if($bytes>12000)break;
            $preview[]=$item;
        }
        $context['published_stories']=[
            'scope'=>array_intersect_key($scope,['mode'=>true,'tag'=>true]),'story_count'=>count($rows),
            'preview'=>$preview,'preview_complete'=>count($preview)===count($rows),
        ];
    }
    if(isset($inputs['lyrics_ids'])&&sf_canvas_resource_lyrics_enabled()){
        $items=sf_canvas_lyrics_inventory($inputs['lyrics_ids']);
        $preview=[];$bytes=0;
        foreach($items as $item){
            $bytes+=strlen(json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            if($bytes>12000)break;
            $preview[]=$item;
        }
        $context['published_lyrics']=['song_count'=>count($items),'preview'=>$preview,
            'preview_complete'=>count($preview)===count($items)];
        if($includeLyricExcerpts){
            $excerpts=[];
            foreach(sf_canvas_lyrics_sources(array_column(array_slice($preview,0,4),'id')) as $source){
                $row=$source['rows'][0];
                $excerpts[]=['track_id'=>$source['id'],'source'=>$row['source'],'text'=>sf_agent_public_artist_text($row['text'],420)];
            }
            if($excerpts)$context['published_lyrics']['creative_excerpts']=$excerpts;
        }
    }
    return $context;
}

/** Initialize published resource storage before a canvas transaction uses it. */
function sf_canvas_resource_prepare(?array $inputs): void {
    if(isset($inputs['stories'])&&!sf_db()->inTransaction())sf_canvas_story_prepare();
    if(!empty($inputs['lyrics_ids'])&&!sf_db()->inTransaction())sf_canvas_lyrics_prepare();
}

/** Explicit selections must still exist when an approved project uses them. */
function sf_canvas_resource_validate_stories(?array $inputs): void {
    $scope=sf_canvas_resource_artist_inputs($inputs)['stories']??null;
    if(($scope['mode']??null)!=='selected'||$scope['story_ids']===[])return;
    if(count(sf_canvas_story_rows($scope,false))!==count($scope['story_ids']))
        throw new InvalidArgumentException('A selected story is no longer published. Refresh the story picker.');
}

/** Recheck published sources and enabled account capabilities before saving a creative revision. */
function sf_canvas_resource_validate_scopes(?array $inputs): void {
    $inputs=sf_canvas_resource_artist_inputs($inputs);
    sf_canvas_resource_validate_stories($inputs);
    if(!empty($inputs['lyrics_ids'])){
        if(!sf_canvas_resource_lyrics_enabled())
            throw new InvalidArgumentException('Lyrics are turned off for canvases.');
        sf_canvas_lyrics_validate($inputs['lyrics_ids']);
    }
    if(!empty($inputs['me_tools'])){
        require_once __DIR__.'/canvas-capabilities.php';
        $policy=sf_canvas_permission_policy();
        foreach($inputs['me_tools'] as $id)if(!sf_canvas_permission_enabled($id,$policy))
            throw new InvalidArgumentException('An account data source is turned off for canvases. Update the /me selection.');
    }
}

/** Validate current access before a metered operation, independently of picker contents. */
function sf_canvas_resource_validate(int $uid,?array $inputs,?array $sources=null): void {
    $inputs=sf_canvas_resource_artist_inputs($inputs);
    sf_canvas_resource_validate_scopes($inputs);
    if($sources!==null){
        require_once __DIR__.'/agent-scene-history.php';
        foreach(sf_canvas_resource_source_ids($sources) as $id)if(sf_agent_scene_history_get($uid,$id)===null)
            throw new InvalidArgumentException('A selected canvas source is no longer available.');
    }
    foreach(['merch_ids'=>'published_merch','ticket_ids'=>'published_ticket_offers'] as $field=>$tool){
        if($inputs===null||empty($inputs[$field]))continue;
        $identity=$field==='merch_ids'?'product_id':'offer_id';
        $available=array_fill_keys(array_column(sf_canvas_resource_artist_rows($tool,null),$identity),true);
        foreach($inputs[$field] as $id)if(!isset($available[$id]))
            throw new InvalidArgumentException('A selected merchandise or ticket resource is no longer available. Refresh the resource picker.');
    }
}

/** Complete picker inventory. These rows describe resources without generating a layout. */
function sf_canvas_resource_inventory(int $uid,string $kind): array {
    if($kind==='lyrics')return sf_canvas_resource_lyrics_enabled()?sf_canvas_lyrics_inventory():[];
    if($kind==='stories')return sf_canvas_story_inventory();
    if($kind==='playlists')return sf_canvas_playlists_inventory($uid);
    if($uid<1)throw new InvalidArgumentException('Sign in to choose canvas resources.');
    if($kind==='me'){
        require_once __DIR__.'/canvas-account-data-core.php';
        return sf_canvas_account_data_inventory();
    }
    if($kind==='canvas'){
        require_once __DIR__.'/agent-scene-history.php';
        sf_agent_scene_history_ensure_schema();
        $query=sf_db()->prepare('SELECT id,title,canvas_kind,previous_id,created_at,design_brief FROM agent_scene_history WHERE user_id=? ORDER BY id DESC');
        $query->execute([$uid]);$items=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
            $item=['id'=>(string)$row['id'],'title'=>$row['title'],'canvas_kind'=>$row['canvas_kind'],
                'previous_id'=>$row['previous_id']===null?null:(int)$row['previous_id'],'created_at'=>$row['created_at']];
            $brief=sf_agent_scene_design_brief($row['design_brief']);
            if($brief!==null){$item['design_brief']=$brief;$item['description']=$brief;}
            $items[]=$item;
        }
        return $items;
    }
    if($kind==='merch'){
        $items=[];$images=[];
        foreach(sf_commerce_products(true) as $product){
            $image=sf_agent_canvas_catalog_artwork($product['image']??null);
            if($image!==null)$images[(string)$product['id']]=$image;
        }
        foreach(sf_agent_canvas_merch_rows() as $row){
            $id=$row['product_id'];
            if(!isset($items[$id]))$items[$id]=['id'=>$id,'title'=>$row['title'],'description'=>$row['description'],
                'category'=>$row['category'],'price_cents'=>$row['price_cents'],'price_text'=>$row['price_text'],
                'currency'=>$row['currency'],'available'=>(bool)$row['available'],'variant_count'=>0];
            if(isset($images[$id]))$items[$id]['image_url']=$images[$id];
            $items[$id]['variant_count']++;
            $items[$id]['available']=$items[$id]['available']||(bool)$row['available'];
            if($row['price_cents']<$items[$id]['price_cents']){
                $items[$id]['price_cents']=$row['price_cents'];$items[$id]['price_text']=$row['price_text'];
            }
        }
        return array_values($items);
    }
    if($kind==='tickets'){
        $items=[];
        foreach(sf_agent_canvas_ticket_offer_rows() as $row)$items[]=['id'=>$row['offer_id']]+$row;
        return $items;
    }
    throw new InvalidArgumentException('Choose canvas, story, lyric, playlist, account, merchandise, or ticket resources.');
}
