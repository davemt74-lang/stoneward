<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-song-intake.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth($method==='POST');
$owner=(int)$me['id'];
header('Cache-Control: private, no-store');
if($method==='GET'){
    $drafts=sf_agent_song_intake_list($owner);
    foreach($drafts as &$draft){
        $audio=sf_media_asset((int)($draft['asset_id']??0));
        $artwork=empty($draft['artwork_asset_id'])?null:sf_media_asset((int)$draft['artwork_asset_id']);
        $draft['readiness']=sf_agent_song_intake_readiness($draft,$audio,$artwork,sf_catalog());
    }
    unset($draft);
    sf_json_response(['ok'=>true,'drafts'=>$drafts]);
}

$body=sf_request_json();
$action=(string)($body['action']??'');
if(!in_array($action,['save_draft','update_draft','prepare_catalog'],true))sf_json_response(['ok'=>false,'message'=>'Unknown song intake action.'],422);
try{
    if($action==='prepare_catalog'){
        if(empty($body['confirmed']))throw new InvalidArgumentException('Preparing a catalog package requires explicit Admin approval.');
        $key=(string)($body['request_key']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Invalid song draft ID.');
        $match=null;
        foreach(sf_agent_song_intake_list($owner) as $candidate){
            if(($candidate['request_key']??'')===$key){$match=$candidate;break;}
        }
        if($match===null)throw new RuntimeException('Song draft was not found for this administrator.');
        $audio=sf_media_asset((int)($match['asset_id']??0));
        $artwork=empty($match['artwork_asset_id'])?null:sf_media_asset((int)$match['artwork_asset_id']);
        $result=sf_agent_song_intake_prepare($owner,$key,(string)($body['expected_hash']??''),
            $audio??[],$artwork,null,sf_catalog());
        if($result['changed'])sf_log_admin_action($owner,'agent_song_catalog_preflight_approved',
            'media_asset',(string)$match['asset_id'],['draft_id'=>$result['draft']['id'],
                'status'=>'catalog_ready','title'=>$result['draft']['title']]);
        sf_json_response(['ok'=>true,'changed'=>$result['changed'],'draft'=>$result['draft']]);
    }
    $assetId=filter_var($body['asset_id']??null,FILTER_VALIDATE_INT);
    if($assetId===false||$assetId<1)throw new InvalidArgumentException('Select an uploaded song first.');
    $asset=sf_media_asset($assetId);
    if($asset===null)throw new InvalidArgumentException('The uploaded recording was not found.');
    $artworkId=(int)($body['artwork_asset_id']??0);
    $artwork=$artworkId>0?sf_media_asset($artworkId):null;
    $record=sf_agent_song_intake_normalize($body,$asset,$owner,$artwork);
    $result=$action==='save_draft'?sf_agent_song_intake_save($owner,$record):
        sf_agent_song_intake_update($owner,$record,(string)($body['expected_hash']??''));
    $changed=$result['created']??$result['updated']??false;
    if($changed){
        sf_log_admin_action($owner,$action==='save_draft'?'agent_song_private_draft_created':'agent_song_private_draft_updated',
            'media_asset',(string)$assetId,['draft_id'=>$result['draft']['id'],'title'=>$record['title'],'status'=>'draft']);
    }
    sf_json_response(['ok'=>true,'changed'=>$changed,'draft'=>$result['draft'],
        'readiness'=>sf_agent_song_intake_readiness($result['draft'],$asset,$artwork,sf_catalog())]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}
