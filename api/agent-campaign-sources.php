<?php
declare(strict_types=1);
/**
 * Section 44E: source-grounded campaign copy cues.
 * Only intentionally public Stonefellow records are eligible.
 */
function sf_agent_campaign_sources(): array {
    $items=[];
    foreach(sf_seo_release_rows() as $row){
        $id=(string)($row['id']??'');
        if($id===''||!sf_seo_public_release($id))continue;
        $title=sf_clean_text($row['title']??'',180);
        if($title==='')continue;
        $desc=sf_clean_text($row['description']??'',440);
        $items[]=['kind'=>'release','id'=>$id,'title'=>$title,'label'=>'Release: '.$title,
            'headline'=>'Discover '.$title.' from Stonefellow',
            'body_text'=>trim(($desc?:'Explore the Stonefellow release “'.$title.'”.').' Find the release at /release/'.rawurlencode($id).'.')];
        if(count($items)>=15)break;
    }
    foreach(sf_catalog() as $track){
        $id=(string)($track['id']??'');
        if($id===''||!sf_seo_public_track($id))continue;
        $title=sf_clean_text($track['title']??'',180);
        if($title==='')continue;
        $story=sf_clean_text($track['story']??'',440);
        $items[]=['kind'=>'track','id'=>$id,'title'=>$title,'label'=>'Song: '.$title,
            'headline'=>'Listen to '.$title.' by Stonefellow',
            'body_text'=>trim(($story?:'Explore “'.$title.'” from Stonefellow.').' Listen at /track/'.rawurlencode($id).'.')];
        if(count($items)>=35)break;
    }
    if(function_exists('sf_live_public_shows')){
        foreach(sf_live_public_shows() as $show){
            $id=(string)($show['id']??'');if($id==='')continue;
            $title=sf_clean_text(($show['title']??'Stonefellow live show'),180);
            $items[]=['kind'=>'show','id'=>$id,'title'=>$title,'label'=>'Live: '.$title,
                'headline'=>'Stonefellow live — '.$title,
                'body_text'=>'Explore current details for this Stonefellow show at /show/'.rawurlencode($id).'.'];
            if(count($items)>=45)break;
        }
    }
    return $items;
}
