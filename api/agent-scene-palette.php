<?php
declare(strict_types=1);

/** Color values are data, never CSS source. Preserve agent_design's existing shape contract. */
function sf_agent_scene_palette(mixed $value): ?array {
    if($value===null)return null;
    $fields=['accent','secondary','surface'];
    if(!is_array($value)||array_is_list($value)||count($value)!==count($fields)||array_diff(array_keys($value),$fields))
        throw new InvalidArgumentException('Invalid canvas agent palette.');
    $palette=[];
    foreach($fields as $field){
        $color=$value[$field]??null;
        if(!is_string($color)||preg_match('/\A#[a-fA-F0-9]{6}\z/',$color)!==1)
            throw new InvalidArgumentException('Invalid canvas agent palette.');
        $palette[$field]=strtolower($color);
    }
    return $palette;
}
