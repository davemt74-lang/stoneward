<?php
declare(strict_types=1);

/** Public reference shape and source-specific rights, with no storage or network side effects. */
function sf_track_is_vp3_source(array $track): bool {
    return (is_array($track['audio_source']??null)&&($track['audio_source']['kind']??null)==='vp3')
        ||($track['audio_publishing_mode']??null)==='vp3_stream_only';
}
/** Structural catalog eligibility only. Visibility and current provider access remain caller/resolver checks. */
function sf_track_has_playable_source(array $track): bool {
    if(!sf_track_is_vp3_source($track))return is_string($track['audio']??null)&&trim($track['audio'])!=='';
    $source=$track['audio_source']??null;
    if(!is_array($source)||($source['kind']??null)!=='vp3'||($track['vp3_availability']??null)!=='ready')return false;
    foreach(['installation_id','site_id','recording_id'] as $field)
        if(!is_string($source[$field]??null)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$source[$field]))return false;
    return true;
}
function sf_public_track_projection(array $track): array {
    unset($track['vp3_connection'],$track['vp3_import'],$track['vp3_receipt'],$track['vp3_journal']);
    if(!sf_track_is_vp3_source($track))return $track;
    $source=is_array($track['audio_source']??null)?$track['audio_source']:[];
    $safe=['kind'=>'vp3'];
    foreach(['installation_id','site_id','recording_id'] as $field){
        $value=$source[$field]??null;
        $safe[$field]=is_string($value)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value)?$value:'';
    }
    $track['audio_source']=$safe;$track['audio']='';$track['audio_publishing_mode']='vp3_stream_only';
    $track['digital_purchase_enabled']=false;$track['podEligible']=false;$track['price']=0;$track['vinyl_license_packages']=[];
    unset($track['private_master_audio']);
    return $track;
}
