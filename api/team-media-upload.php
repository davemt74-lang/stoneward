<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_team_require('media.upload',true);$id=(int)$me['id'];
if(empty($_FILES['file'])||!is_array($_FILES['file']))sf_json_response(['ok'=>false,'message'=>'Media file required'],422);
try{
    $asset=sf_media_ingest_upload($_FILES['file'],$id);
    sf_team_audit($id,'media.uploaded','media_asset',(string)$asset['id'],
      'Uploaded '.sf_clean_text((string)$asset['original_name'],180),
      ['category'=>$asset['category'],'filename'=>$asset['original_name'],'asset_id'=>$asset['id']]);
    sf_json_response(['ok'=>true,'asset'=>['id'=>(int)$asset['id'],'uuid'=>$asset['uuid'],
      'category'=>$asset['category'],'original_name'=>$asset['original_name'],'created_at'=>$asset['created_at']]]);
}catch(Throwable $e){
    error_log('[team media upload] '.get_class($e));
    sf_json_response(['ok'=>false,'message'=>'Media upload failed validation'],422);
}
