<?php
declare(strict_types=1);
require_once __DIR__.'/agent-canvas-images.php';

const SF_AGENT_CANVAS_IMAGE_REFERENCE_MAX_BYTES=15*1024*1024-64;

function sf_agent_canvas_image_asset_id(mixed $id): bool {
    return is_string($id)&&preg_match('/\A(?:ref-)?[a-f0-9]{32}\z/',$id)===1;
}

function sf_agent_canvas_image_asset_get(int $owner,string $id): ?array {
    if($owner<1||!sf_agent_canvas_image_asset_id($id))return null;
    if(str_starts_with($id,'ref-')){
        require_once __DIR__.'/agent-canvas-image-references.php';
        return sf_agent_canvas_reference_get($id);
    }
    return sf_agent_canvas_image_get($owner,$id);
}

function sf_agent_canvas_image_asset_file(int $owner,string $id): ?array {
    if($owner<1||!sf_agent_canvas_image_asset_id($id))return null;
    if(str_starts_with($id,'ref-')){
        require_once __DIR__.'/agent-canvas-image-references.php';
        return sf_agent_canvas_reference_file($id);
    }
    return sf_agent_canvas_image_file($owner,$id);
}

function sf_agent_canvas_image_reference_ids(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw)||count($raw)>SF_AGENT_CANVAS_IMAGE_LIMIT)
        throw new InvalidArgumentException('Choose up to four image references.');
    foreach($raw as $id){
        if(!sf_agent_canvas_image_asset_id($id))throw new InvalidArgumentException('Choose an available image reference.');
    }
    return array_values(array_unique($raw));
}

/** Explicit visual inputs are independent of image placement and never request generation by themselves. */
function sf_agent_canvas_image_reference_selection(mixed $raw,string $brief): array {
    $ids=sf_agent_canvas_image_reference_ids($raw);
    preg_match_all('/\{reference-image-([^}]*)\}/i',$brief,$tags);
    foreach($tags[1] as $id){
        $id=strtolower($id);
        if(!sf_agent_canvas_image_asset_id($id))throw new InvalidArgumentException('Choose an available image reference.');
        $ids[]=$id;
    }
    return sf_agent_canvas_image_reference_ids(array_values(array_unique($ids)));
}

function sf_agent_canvas_image_reference_metadata(int $owner,array $ids): array {
    $metadata=[];
    foreach(sf_agent_canvas_image_reference_ids($ids) as $id){
        $asset=sf_agent_canvas_image_asset_get($owner,$id);
        if($asset===null)throw new InvalidArgumentException('A selected image reference is no longer available for your account.');
        $metadata[]=array_intersect_key($asset,array_flip(['id','title','width','height']))+
            ['source'=>str_starts_with($id,'ref-')?'artist':'saved'];
    }
    return $metadata;
}

/** Resolve current authorization and local bytes. No URL or file path supplied by a caller is accepted. */
function sf_agent_canvas_image_reference_inputs(int $owner,array $ids): array {
    $ids=sf_agent_canvas_image_reference_ids($ids);
    sf_agent_canvas_image_reference_metadata($owner,$ids);
    $images=[];$total=0;$encodedBytes=0;
    foreach($ids as $id){
        $file=sf_agent_canvas_image_asset_file($owner,$id);
        if($file===null||!in_array($file['mime']??null,SF_AGENT_CANVAS_IMAGE_TYPES,true))
            throw new InvalidArgumentException('A selected image reference could not be read.');
        $size=(int)($file['size']??0);
        $total+=$size;
        if($size<1||$total>SF_AGENT_CANVAS_IMAGE_REFERENCE_MAX_BYTES)
            throw new InvalidArgumentException('The selected image references are too large. Choose smaller images.');
        $bytes=@file_get_contents($file['path'],false,null,0,$size+1);
        if(!is_string($bytes)||strlen($bytes)!==$size||(@getimagesizefromstring($bytes)['mime']??null)!==$file['mime']||
            (isset($file['sha256'])&&!hash_equals($file['sha256'],hash('sha256',$bytes))))
            throw new InvalidArgumentException('A selected image reference could not be read.');
        $image=['image_url'=>'data:'.$file['mime'].';base64,'.base64_encode($bytes)];
        $encodedBytes+=strlen(json_encode($image,JSON_THROW_ON_ERROR));
        if($encodedBytes>20*1024*1024-8192)
            throw new InvalidArgumentException('The selected image references are too large. Choose smaller images.');
        $images[]=$image;
    }
    return $images;
}
