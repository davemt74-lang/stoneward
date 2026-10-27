<?php
declare(strict_types=1);
function sf_admin_public_credits(array $metadata):array{return array_values(array_filter([
    $metadata['words_by']??'',$metadata['music_by']??'']));}
require dirname(__DIR__).'/api/media-catalog-intake.php';
function verify90t(bool $okay,string $description):void{
    if(!$okay){fwrite(STDERR,"FAIL: $description\n");exit(1);}
    echo "PASS: $description\n";
}
$key=str_repeat('e',32);
$draft=[
    'request_key'=>$key,'payload_hash'=>str_repeat('a',64),
    'title'=>'Night Drive','release'=>'Unreleased','year'=>2026,
    'words_by'=>'David','music_by'=>'David','artist_display'=>'Stonefellow',
    'master_owner'=>'David','composition_owner'=>'David','genre'=>'Rock',
    'isrc'=>'','release_date'=>'','explicit'=>false,'pod_eligible'=>true,
    'template_metadata'=>['template'=>['id'=>'studio','version'=>2],
        'price'=>3.5,'label'=>'Stonefellow']
];
$preview=['id'=>1,'sha256'=>str_repeat('b',64),'uuid'=>'M'.str_repeat('A',20),'duration_seconds'=>195];
$master=['id'=>2,'sha256'=>str_repeat('c',64),'uuid'=>'M'.str_repeat('B',20),'duration_seconds'=>195];
$stream=sf_media_catalog_candidate($draft,$preview,null,null);
verify90t($stream['state']==='published'&&$stream['public_visible'],
    'Approved catalog publication enters the public listening player.');
verify90t($stream['audio_publishing_mode']==='preview_only'
    &&$stream['digital_purchase_enabled']===false&&$stream['podEligible']===false
    &&empty($stream['private_master_audio']),
    'One MP3/WAV is never exposed as a private purchased or vinyl master.');
verify90t($stream['price']===3.5&&$stream['metadata']['template']['id']==='studio',
    'Digital price and selected metadata template survive publication.');
$paid=sf_media_catalog_candidate($draft,$preview,$master,null);
verify90t($paid['audio_publishing_mode']==='preview_master_split'
    &&$paid['digital_purchase_enabled']&&$paid['podEligible'],
    'Distinct preview and private original support paid digital and vinyl opt-in.');
verify90t($paid['id']===$stream['id']
    &&$paid['audio']===$stream['audio']
    &&$paid['media_private_master_asset']===2,
    'Stable Catalog ID and public preview persist with a protected private master.');
try{sf_media_catalog_publish_draft(7,$key,str_repeat('a',64),false);
    verify90t(false,'Rights should block publication');
}catch(InvalidArgumentException $e){verify90t(true,'Missing rights approval rejects publication before writes.');}
echo "Section 90T Catalog candidate privacy and commerce: PASS\n";
