<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_team_require('media.upload',true);$id=(int)$me['id'];
if(empty($_FILES['file'])||!is_array($_FILES['file']))sf_json_response(['ok'=>false,'message'=>'Media file required'],422);
try{
    $input=$_FILES['file'];
    $submittedName=sf_clean_text(basename((string)($input['name']??'media')),180);
    // Canonical Media Library may deduplicate by hash. Do not reveal the
    // existing identity or metadata of a private asset to a scoped uploader.
    $existingId=0;
    $tmp=(string)($input['tmp_name']??'');
    if($tmp!==''&&is_uploaded_file($tmp)&&($hash=hash_file('sha256',$tmp))){
        sf_media_ensure_schema();
        $q=sf_db()->prepare('SELECT id FROM media_assets WHERE sha256=? AND size_bytes=? LIMIT 1');
        $q->execute([$hash,(int)($input['size']??0)]);$existingId=(int)$q->fetchColumn();
    }
    $asset=sf_media_ingest_upload($input,$id);
    $newAsset=$existingId<1;
    sf_team_audit($id,'media.upload_submitted','media_asset',
      $newAsset?(string)$asset['id']:'',
      'Submitted '.($submittedName?:'media file').' for review',
      ['category'=>$asset['category'],'filename'=>$submittedName]);
    // Return only the submitting user's name, never a deduplicated private
    // Media Library asset UUID, numeric ID, owner name or storage path.
    sf_json_response(['ok'=>true,'review_required'=>true,
      'asset'=>['original_name'=>$submittedName,'category'=>$asset['category']]]);
}catch(Throwable $e){
    error_log('[team media upload] '.get_class($e));
    sf_json_response(['ok'=>false,'message'=>'Media upload failed validation'],422);
}
