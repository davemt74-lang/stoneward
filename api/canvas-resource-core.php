<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-public-commerce.php';

/** Syntax only. An omitted type and an explicitly empty selection have different meanings. */
function sf_canvas_resource_artist_inputs(mixed $raw): ?array {
    if($raw===null)return null;
    if(!is_array($raw)||($raw&&array_is_list($raw))||array_diff(array_keys($raw),['merch_ids','ticket_ids']))
        throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
    $inputs=[];
    foreach(['merch_ids','ticket_ids'] as $field){
        if(!array_key_exists($field,$raw))continue;
        $ids=$raw[$field];
        if(!is_array($ids)||!array_is_list($ids))throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
        foreach($ids as $id)if(!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
            throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
        $inputs[$field]=array_values(array_unique($ids,SORT_STRING));
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
    $artistTokens=[];$sourceTokens=[];$cleared=[];
    $clean=preg_replace_callback('/(`?)\{(?:(canvas|merch|ticket):([^}]*)|(merch-clear|tickets-clear))\}\1/i',
        static function(array $match)use(&$artistTokens,&$sourceTokens,&$cleared):string {
            if(($match[4]??'')!==''){
                $cleared[strtolower($match[4])==='merch-clear'?'merch_ids':'ticket_ids']=true;
                return ' ';
            }
            $kind=strtolower($match[2]);$id=$match[3];
            if($kind==='canvas'){
                $number=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if($number===false||!ctype_digit($id))throw new InvalidArgumentException('Choose a saved canvas from the picker.');
                $sourceTokens[]=$number;
            }else{
                if(preg_match('/\A[A-Za-z0-9_-]{1,180}\z/',$id)!==1)
                    throw new InvalidArgumentException('Choose merchandise or ticket resources from the picker.');
                $artistTokens[$kind==='merch'?'merch_ids':'ticket_ids'][]=$id;
            }
            return ' ';
        },$brief);
    if($clean===null)throw new InvalidArgumentException('Choose valid canvas resources.');
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

/** Model context contains selected public facts. Account purchases are separate private tools. */
function sf_canvas_resource_artist_context(?array $inputs): array {
    $inputs=sf_canvas_resource_artist_inputs($inputs);$context=[];
    foreach(['merch_ids'=>'published_merch','ticket_ids'=>'published_ticket_offers'] as $field=>$tool){
        if($inputs!==null&&array_key_exists($field,$inputs))$context[$tool]=sf_canvas_resource_artist_rows($tool,$inputs);
    }
    return $context;
}

/** Validate current access before a metered operation, independently of picker contents. */
function sf_canvas_resource_validate(int $uid,?array $inputs,?array $sources=null): void {
    $inputs=sf_canvas_resource_artist_inputs($inputs);
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
    if($uid<1)throw new InvalidArgumentException('Sign in to choose canvas resources.');
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
    throw new InvalidArgumentException('Choose canvas, merchandise, or ticket resources.');
}
