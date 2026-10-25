<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-song-catalog.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth(true);
$owner=(int)$me['id'];
header('Cache-Control: private, no-store');
$body=sf_request_json();
try{
    if(($body['action']??'')!=='publish_track'||empty($body['confirmed']))
        throw new InvalidArgumentException('Publishing requires explicit administrator confirmation.');
    $key=(string)($body['request_key']??'');
    sf_agent_song_catalog_track_id($key);
    $draft=null;
    foreach(sf_agent_song_intake_list($owner) as $candidate)
        if(($candidate['request_key']??'')===$key){$draft=$candidate;break;}
    if($draft===null)throw new RuntimeException('Prepared song not found for your account.');
    $previewId=filter_var($body['preview_asset_id']??null,FILTER_VALIDATE_INT);
    if($previewId===false||$previewId<1)throw new InvalidArgumentException('Upload a separate MP3 listening preview.');
    $master=sf_media_asset((int)($draft['asset_id']??0));
    $preview=sf_media_asset($previewId);
    $cover=sf_media_asset((int)($draft['artwork_asset_id']??0));
    if(!$master||!$preview||!$cover)throw new RuntimeException('Source audio, preview or artwork is missing.');
    $result=sf_agent_song_catalog_publish($owner,$key,(string)($body['expected_hash']??''),
        $master,$preview,$cover);
    if($result['changed'])sf_log_admin_action($owner,'agent_song_catalog_track_published',
        'track',$result['track_id'],['draft_id'=>$result['draft']['id'],
            'preview_asset_id'=>(int)$preview['id'],'private_master_asset_id'=>(int)$master['id'],
            'artwork_asset_id'=>(int)$cover['id']]);
    sf_json_response(['ok'=>true]+$result);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}
