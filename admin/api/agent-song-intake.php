<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/agent-song-intake.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true))sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_admin_require_auth($method==='POST');
$owner=(int)$me['id'];
header('Cache-Control: private, no-store');
if($method==='GET')sf_json_response(['ok'=>true,'drafts'=>sf_agent_song_intake_list($owner)]);

$body=sf_request_json();
$action=(string)($body['action']??'');
if(!in_array($action,['save_draft','update_draft'],true))sf_json_response(['ok'=>false,'message'=>'Unknown song intake action.'],422);
try{
    $assetId=filter_var($body['asset_id']??null,FILTER_VALIDATE_INT);
    if($assetId===false||$assetId<1)throw new InvalidArgumentException('Select an uploaded song first.');
    $asset=sf_media_asset($assetId);
    if($asset===null)throw new InvalidArgumentException('The uploaded recording was not found.');
    $record=sf_agent_song_intake_normalize($body,$asset,$owner);
    $result=$action==='save_draft'?sf_agent_song_intake_save($owner,$record):
        sf_agent_song_intake_update($owner,$record,(string)($body['expected_hash']??''));
    $changed=$result['created']??$result['updated']??false;
    if($changed){
        sf_log_admin_action($owner,$action==='save_draft'?'agent_song_private_draft_created':'agent_song_private_draft_updated',
            'media_asset',(string)$assetId,['draft_id'=>$result['draft']['id'],'title'=>$record['title'],'status'=>'draft']);
    }
    sf_json_response(['ok'=>true,'changed'=>$changed,'draft'=>$result['draft']]);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
    sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);
}
