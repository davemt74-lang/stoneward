<?php
declare(strict_types=1);

require_once __DIR__.'/agent-public-artist-context.php';

const SF_AGENT_CANVAS_CATALOG_MAX_REFS=6;
const SF_AGENT_CANVAS_CATALOG_MAX_TRACKS=120;

function sf_agent_canvas_catalog_id(mixed $value): ?string {
    if(!is_string($value)&&!is_int($value))return null;
    $id=(string)$value;
    return preg_match('/\A[A-Za-z0-9_-]{1,160}\z/',$id)===1?$id:null;
}

function sf_agent_canvas_catalog_album_ref(string $id): string {
    // The wall owns the bare media-albums alias, so its namesake release uses the colon form.
    return (strcasecmp($id,'albums')===0?'media:':'media-').$id;
}

function sf_agent_canvas_catalog_ref(mixed $raw): ?string {
    if(!is_string($raw)||strlen($raw)>180)return null;
    $ref=trim($raw);
    if(str_starts_with($ref,'[[')&&str_ends_with($ref,']]'))$ref=trim(substr($ref,2,-2));
    elseif(str_starts_with($ref,'{')&&str_ends_with($ref,'}'))$ref=trim(substr($ref,1,-1));
    $alias=match(strtolower($ref)){
        'merch-hoodies','merch:hoodies'=>'merch-hoodies',
        'show-locations','shows:locations'=>'show-locations',
        'price-ticket','tickets:price'=>'price-ticket',
        'media-albums'=>'media-albums',
        default=>null,
    };
    if($alias!==null)return $alias;
    if(preg_match('/\A(?:price-ticket:|tickets:price:)([A-Za-z0-9_-]{1,160})\z/i',$ref,$m)===1)return 'price-ticket:'.$m[1];
    if(preg_match('/\Amedia[:-]([A-Za-z0-9_-]{1,160})\z/i',$ref,$m)===1)return sf_agent_canvas_catalog_album_ref($m[1]);
    return null;
}

/** Canonical references keep their order and do not retain any resolved values. */
function sf_agent_canvas_catalog_refs(string $brief,mixed $explicit=[]): array {
    if(!is_array($explicit)||!array_is_list($explicit)||count($explicit)>SF_AGENT_CANVAS_CATALOG_MAX_REFS)
        throw new InvalidArgumentException('Choose up to six catalog shortcodes.');
    $refs=[];
    foreach($explicit as $value){
        $ref=sf_agent_canvas_catalog_ref($value);
        if($ref===null)throw new InvalidArgumentException('Use a supported catalog shortcode.');
        $refs[$ref]=true;
    }
    $bare=sf_agent_canvas_catalog_ref($brief);
    if($bare!==null)$refs[$bare]=true;
    preg_match_all('/\{([^{}\r\n]+)\}|\[\[([^\[\]\r\n]+)\]\]/',substr($brief,0,8192),$matches,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
    foreach($matches as $match){
        $value=trim($match[1]??$match[2]);
        if(preg_match('/\A(?:merch|show|shows|price|ticket|tickets|media)[:-]/i',$value)!==1)continue;
        $ref=sf_agent_canvas_catalog_ref($value);
        if($ref===null)throw new InvalidArgumentException('Use a supported catalog shortcode such as {merch-hoodies}, {show-locations}, {price-ticket}, or {media-<albumID>}.');
        $refs[$ref]=true;
    }
    if(count($refs)>SF_AGENT_CANVAS_CATALOG_MAX_REFS)throw new InvalidArgumentException('Choose up to six catalog shortcodes.');
    return array_keys($refs);
}

function sf_agent_canvas_catalog_release_rows(string $brief='',string $trackId=''): array {
    $ranked=[];$query=strtolower(sf_agent_public_artist_text($brief,1800));
    foreach(sf_seo_release_rows() as $row){
        $id=sf_agent_canvas_catalog_id($row['id']??null);
        if($id===null||isset($ranked[$id])||($row['state']??'published')!=='published'||($row['public_visible']??true)===false)continue;
        $title=strtolower(sf_agent_public_artist_text($row['title']??'',160));
        $onTrack=$trackId!==''&&in_array($trackId,(array)($row['track_ids']??[]),true);
        $idMention=preg_match('/(?<![A-Za-z0-9_-])'.preg_quote(strtolower($id),'/').'(?![A-Za-z0-9_-])/',$query)===1;
        $titleMention=strlen($title)>2&&str_contains($query,$title);
        $ranked[$id]=['row'=>$row,'rank'=>($onTrack?4:0)+($idMention||$titleMention?8:0),'id'=>$id];
    }
    usort($ranked,static fn(array $a,array $b):int=>$b['rank']<=>$a['rank']?:
        strcmp((string)($b['row']['release_date']??''),(string)($a['row']['release_date']??''))?:strcmp($a['id'],$b['id']));
    return array_column($ranked,'row');
}

function sf_agent_canvas_catalog_requested_types(string $brief): array {
    // An artist, track, or album mentioned as visual inspiration is not a request for catalog controls.
    $brief=str_replace('’',"'",$brief);
    $brief=preg_replace("/\b(?:do not|don't|never)\s+(?:show|display|list|include|add|feature|build|make|design|create)\b[^.!?;\n]*?(?=\b(?:but|instead)\b|[.!?;\n]|$)/i",'',$brief)??$brief;
    $show='\b(?:show|display|list|browse|explore|include|add|feature|shop(?:\s+for)?|buy|sell)\s+';
    $create='\b(?:build|make|design|create|open|want|need|with)\s+';
    $qualifiers="(?:(?:me|a|an|the|all|any|some|our|my|your|current|published|live|latest|available|stonefellow(?:'s)?)\s+){0,8}";
    $views='(?:pages?|walls?|galler(?:y|ies)|dropdowns?|widgets?|pickers?|browsers?|lists?|cards?|shelves|shelf|menus?|grid|catalog)';
    $album='(?:albums?|releases?|discography|track\s*lists?|catalog|(?:music|records?)\s+(?:store|shop|catalog))';
    $merch='(?:merch(?:andise)?|hoodies?|storefronts?|store|shop)';
    $shows='(?:shows|show\s+locations?|tour\s+dates?|gigs|concerts|events)';
    $tickets='(?:tickets?|ticket\s+prices?)';
    $types=[];
    foreach(['album'=>$album,'albums'=>$album,'merch'=>$merch,'shows'=>$shows,'tickets'=>$tickets] as $type=>$noun){
        if(preg_match('~(?:'.$show.$qualifiers.'(?:'.$views.'\s+of\s+'.$qualifiers.')?'.$noun.'\b|'.$create.$qualifiers.$noun.'\s+'.$views.'\b)~iu',$brief)===1)$types[]=$type;
    }
    if(preg_match('~'.$create.$qualifiers.'(?:discography|catalog|(?:music|records?)\s+(?:store|shop|catalog))\b~iu',$brief)===1){
        $types[]='album';$types[]='albums';
    }
    if(preg_match('~'.$create.$qualifiers.'(?:storefronts?|store|shop)\b~iu',$brief)===1)$types[]='merch';
    return array_values(array_unique($types));
}

/** Recognize a catalog-only edit so the saved visual source can be reused. */
function sf_agent_canvas_catalog_removal(string $brief): array {
    if(preg_match('~\A(?:please\s+)?(?:remove|hide|drop|omit|exclude|delete)\s+(?:(?:the|all|any|live|this|these)\s+)*(.+?)(?:\s+from\s+(?:the|this)\s+canvas)?[.!]?\z~iu',$brief,$match)!==1)return [];
    $controls='(?:\s+(?:dropdowns?|widgets?|controls?|blocks?|sections?|cards?|panel|wall|list|gallery))?';
    $groups=[
        'catalog'=>['album','albums','merch','shows','tickets'],
        'albums?'=>['album','albums'],
        '(?:merch(?:andise)?|hoodies?)'=>['merch'],
        '(?:shows?|show\s+locations?)'=>['shows'],
        '(?:tickets?|ticket\s+prices?)'=>['tickets'],
    ];
    foreach($groups as $target=>$types){
        if(preg_match('~\A'.$target.$controls.'\z~iu',$match[1])===1)return $types;
    }
    return [];
}

function sf_agent_canvas_catalog_type(string $ref): string {
    return match($ref){
        'merch-hoodies'=>'merch','show-locations'=>'shows','media-albums'=>'albums',
        default=>str_starts_with($ref,'price-ticket')?'tickets':'album',
    };
}

function sf_agent_canvas_catalog_candidates(string $brief,string $trackId='',?array $types=null): array {
    $refs=[];
    foreach(['merch'=>'merch-hoodies','shows'=>'show-locations','tickets'=>'price-ticket','albums'=>'media-albums'] as $type=>$ref){
        if($types===null||in_array($type,$types,true))$refs[]=$ref;
    }
    if($types===null||in_array('album',$types,true)){
        foreach(array_slice(sf_agent_canvas_catalog_release_rows($brief,$trackId),0,SF_AGENT_CANVAS_CATALOG_MAX_REFS-count($refs)) as $row)
            $refs[]=sf_agent_canvas_catalog_album_ref((string)$row['id']);
    }
    return $refs;
}

function sf_agent_canvas_catalog_artwork(mixed $value,bool $staticOnly=false): ?string {
    if(!is_string($value)||strlen($value)>500)return null;
    $path=ltrim($value,'/');
    if(str_starts_with($value,'//'))return null;
    if(!$staticOnly&&preg_match('/\Aapi\/media\.php\?id=[A-Za-z0-9_-]{1,160}(?:&variant=(?:thumb|medium))?\z/',$path)===1)return $path;
    if(preg_match('~(?:^|/)\.\.?(?:/|$)~',$path)===1)return null;
    return preg_match('~\Aassets/(?:[A-Za-z0-9_.+-]+/)*[A-Za-z0-9_.+-]+\.(?:png|jpe?g|gif|webp|avif)\z~i',$path)===1?$path:null;
}

function sf_agent_canvas_catalog_album_artwork(array $release): ?string {
    $cover=sf_seo_public_media('release',(string)$release['id'],['cover','primary_artwork','artwork']);
    if(($cover['category']??null)==='image'){
        $path=sf_agent_canvas_catalog_artwork($cover['thumb_url']??null)??sf_agent_canvas_catalog_artwork($cover['url']??null);
        if($path!==null)return $path;
    }
    $legacy=$release['artwork']??null;
    return sf_agent_canvas_catalog_artwork(is_array($legacy)?($legacy['cover']??null):$legacy,true);
}

function sf_agent_canvas_catalog_album_tracks(array $release,array &$resolved): array {
    $rows=[];$seen=[];
    foreach((array)($release['track_ids']??[]) as $rawId){
        $id=sf_agent_canvas_catalog_id($rawId);
        if($id===null||isset($seen[$id]))continue;
        $seen[$id]=true;
        if(!array_key_exists($id,$resolved))$resolved[$id]=sf_seo_public_track($id);
        $track=$resolved[$id];
        if($track===null)continue;
        $title=sf_agent_public_artist_text($track['title']??'',160);
        if($title==='')continue;
        $rows[]=['id'=>$id,'track_id'=>$id,'title'=>$title,'duration_seconds'=>max(0,(int)($track['duration']??0)),'position'=>count($rows)+1];
        if(count($rows)>=SF_AGENT_CANVAS_CATALOG_MAX_TRACKS)break;
    }
    return $rows;
}

function sf_agent_canvas_catalog_currency(): string {
    $currency=strtoupper((string)(sf_store_config()['currency']??'USD'));
    return preg_match('/\A[A-Z]{3}\z/',$currency)===1?$currency:'USD';
}

function sf_agent_canvas_catalog_hoodies(): array {
    $out=[];$currency=sf_agent_canvas_catalog_currency();
    foreach(sf_commerce_products(true) as $product){
        $id=sf_agent_canvas_catalog_id($product['id']??null);
        $slug=sf_agent_canvas_catalog_id($product['slug']??null);
        $title=sf_agent_public_artist_text($product['title']??'',140);
        $words=implode(' ',[$id,$slug,$title,(string)($product['category']??''),
            ...array_filter((array)($product['tags']??[]),'is_string')]);
        if($id===null||$slug===null||$title===''||preg_match('/(?<![a-z0-9])(?:hoodies?|hooded[\s_-]+sweatshirts?)(?![a-z0-9])/i',$words)!==1)continue;
        $variants=[];$available=false;
        foreach((array)($product['variants']??[]) as $variant){
            if(($variant['status']??null)!=='active')continue;
            $variantId=sf_agent_canvas_catalog_id($variant['id']??null);
            $variantTitle=sf_agent_public_artist_text($variant['title']??'',100);
            if($variantId===null||$variantTitle==='')continue;
            $inStock=!empty($variant['available']);
            $available=$available||$inStock;
            if(count($variants)>=12)continue;
            $options=[];
            foreach((array)($variant['options']??[]) as $key=>$value){
                $name=sf_agent_public_artist_text($key,40);$label=sf_agent_public_artist_text($value,50);
                if($name!==''&&$label!=='')$options[$name]=$label;
                if(count($options)>=6)break;
            }
            $variants[]=['id'=>$variantId,'title'=>$variantTitle,'price_cents'=>(int)$variant['price_cents'],
                'available'=>$inStock,'options'=>$options];
        }
        $out[]=['id'=>$id,'slug'=>$slug,'title'=>$title,'artwork'=>sf_agent_canvas_catalog_artwork($product['image']??null),
            'price_cents'=>(int)$product['base_price_cents'],'currency'=>$currency,
            'available'=>$variants!==[]?$available:!empty($product['available']),'variants'=>$variants];
        if(count($out)>=10)break;
    }
    return $out;
}

function sf_agent_canvas_catalog_shows(): array {
    $rows=[];
    foreach(sf_live_public_shows() as $show){
        $id=sf_agent_canvas_catalog_id($show['id']??null);
        if($id===null||isset($rows[$id]))continue;
        $venue=sf_agent_public_artist_text($show['venue']??'',120);
        $rows[$id]=['id'=>$id,'title'=>sf_agent_public_artist_text($show['title']??'',140)?:($venue?:'Stonefellow show'),
            'venue'=>$venue,'date'=>sf_agent_public_artist_text($show['date']??'',32),
            'location'=>sf_agent_public_artist_text(sf_live_location($show),160),
            'status'=>sf_agent_public_artist_text($show['status']??'scheduled',30)];
    }
    return $rows;
}

function sf_agent_canvas_catalog_tickets(array $shows,string $showId=''): array {
    $rows=[];$currency=sf_agent_canvas_catalog_currency();
    foreach(sf_ticket_public_offers(null,$showId) as $offer){
        $id=sf_agent_canvas_catalog_id($offer['id']??null);
        $idOfShow=(string)($offer['show_id']??'');
        if($id===null||!isset($shows[$idOfShow])||($showId!==''&&$idOfShow!==$showId))continue;
        $show=$shows[$idOfShow];
        $reason=sf_agent_public_artist_text($offer['access']['reason']??'',40);
        $price=(int)$offer['price_cents'];
        if($price===0&&($offer['fulfillment_type']??null)==='external')$price=null;
        $rows[]=['id'=>$id,'title'=>sf_agent_public_artist_text($offer['title']??'',140)?:'Ticket option',
            'show_id'=>$idOfShow,'show_title'=>$show['title'],'date'=>$show['date'],'venue'=>$show['venue'],'location'=>$show['location'],
            'offer_type'=>sf_agent_public_artist_text($offer['offer_type']??'',30),'price_cents'=>$price,'currency'=>$currency,
            'available'=>!empty($offer['access']['allowed'])||$reason==='sign_in_required','availability'=>$reason];
        if(count($rows)>=24)break;
    }
    return $rows;
}

function sf_agent_canvas_catalog_upcoming_shows(array $shows): array {
    $today=gmdate('Y-m-d');$upcoming=[];
    foreach($shows as $show){
        if(substr($show['date'],0,10)<$today||in_array(strtolower($show['status']),['completed','archived','cancelled','canceled'],true))continue;
        $upcoming[]=$show;
        if(count($upcoming)>=16)break;
    }
    return $upcoming;
}

/** All item fields are public data. The parent UI supplies playback and commerce actions. */
function sf_agent_canvas_catalog_blocks(array $refs,bool $includeItems=true): array {
    $blocks=[];$shows=null;$resolvedTracks=[];
    foreach(sf_agent_canvas_catalog_refs('',$refs) as $ref){
        $type=sf_agent_canvas_catalog_type($ref);
        $title=match($type){
            'merch'=>'Stonefellow hoodies','shows'=>'Show locations','tickets'=>'Ticket prices','albums'=>'Stonefellow albums',
            default=>'Album unavailable',
        };
        $block=['ref'=>$ref,'type'=>$type,'title'=>$title,'available'=>true,'items'=>[]];
        if($type==='album'){
            $id=substr($ref,6);$release=sf_seo_public_release($id);
            $block['available']=$release!==null;
            if($release!==null){
                $block['album_id']=$id;
                $block['title']=sf_agent_public_artist_text($release['title']??'',160)?:'Stonefellow album';
                if($includeItems){
                    $block['artwork']=sf_agent_canvas_catalog_album_artwork($release);
                    $block['items']=sf_agent_canvas_catalog_album_tracks($release,$resolvedTracks);
                    $block['track_ids']=array_column($block['items'],'track_id');
                }
            }
        }elseif($type==='tickets'){
            $showId=str_starts_with($ref,'price-ticket:')?substr($ref,13):'';
            if($showId!==''){
                $shows??=sf_agent_canvas_catalog_shows();
                $block['available']=isset($shows[$showId]);
                if($block['available'])$block['title']='Ticket prices · '.$shows[$showId]['title'];
            }
            if($includeItems&&$block['available'])$block['items']=sf_agent_canvas_catalog_tickets($shows??=sf_agent_canvas_catalog_shows(),$showId);
        }elseif($includeItems){
            if($type==='merch')$block['items']=sf_agent_canvas_catalog_hoodies();
            elseif($type==='shows')$block['items']=sf_agent_canvas_catalog_upcoming_shows($shows??=sf_agent_canvas_catalog_shows());
            else foreach(array_slice(sf_agent_canvas_catalog_release_rows(),0,8) as $release){
                $block['items'][]=['id'=>(string)$release['id'],'title'=>sf_agent_public_artist_text($release['title']??'',160)?:'Stonefellow album',
                    'subtitle'=>sf_agent_public_artist_text($release['release_date']??'',32),'artwork'=>sf_agent_canvas_catalog_album_artwork($release),
                    'track_ids'=>array_column(sf_agent_canvas_catalog_album_tracks($release,$resolvedTracks),'track_id')];
            }
        }
        $blocks[]=$block;
    }
    return $blocks;
}
