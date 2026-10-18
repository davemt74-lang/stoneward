<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-media-evidence-core.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$me=sf_require_user(false,true);$uid=(int)$me['id'];
if(empty($_FILES['file'])||!is_array($_FILES['file']))
 sf_json_response(['ok'=>false,'error'=>'file_required'],422);
$file=$_FILES['file'];
$size=(int)($file['size']??0);
if($size<1||$size>250*1024*1024)sf_json_response(['ok'=>false,'error'=>'file_size_limit','message'=>'Capture uploads must be 250 MB or less.'],422);
if(($_POST['rights_attested']??'')!=='1')
 sf_json_response(['ok'=>false,'error'=>'rights_attestation_required'],422);
$kind=(string)($_POST['submission_type']??'');
$title=sf_capture_clean($_POST['title']??'',180);
if(!sf_capture_kind($kind)||$title==='')sf_json_response(['ok'=>false,'error'=>'invalid_capture'],422);
// Reject exhausted quotas before Media Library ingestion; a rejected upload
// must not leave an unreferenced large private media file behind.
sf_capture_schema();
$dailyLimit=sf_team_can($uid,'media.upload')?15:5;
$q=sf_db()->prepare('SELECT COUNT(*) FROM story_capture_submissions WHERE contributor_id=? AND created_at>=?');
$q->execute([$uid,gmdate('c',time()-86400)]);
if((int)$q->fetchColumn()>=$dailyLimit)
 sf_json_response(['ok'=>false,'error'=>'capture_quota_reached',
   'message'=>'Daily media capture quota reached.'],429);
$requestId=(int)($_POST['request_id']??0);
if($requestId>0&&!in_array($requestId,array_map('intval',array_column(sf_capture_requests($uid),'id')),true))
 sf_json_response(['ok'=>false,'error'=>'shot_request_unavailable'],403);
// Intake is private. No automatic publication, role grants or media links.
try{
 $observed=sf_evidence_observe_upload($file);
 $asset=sf_media_ingest_upload($file,$uid);
 if(!$asset||empty($asset['id'])||sf_media_protected_master_asset((int)$asset['id']))
   throw new InvalidArgumentException('The uploaded item cannot be used for capture.');
 $draft=['title'=>$title,'submission_type'=>$kind,
   'description'=>(string)($_POST['description']??''),
   'dialogue_text'=>(string)($_POST['dialogue_text']??''),
   'captured_at'=>(string)($_POST['captured_at']??''),
   'request_id'=>$requestId,'rights_attested'=>true,
   'people_release_status'=>(string)($_POST['people_release_status']??'unconfirmed')];
 $id=sf_capture_submit($uid,$draft,(int)$asset['id']);
 // Technical indexing is best-effort: never misrepresent capture acceptance as AI analysis.
 try{sf_evidence_record($id,(int)$asset['id'],$observed);}catch(Throwable $indexError){
   error_log('[Stonefellow evidence index] '.get_class($indexError));
 }
 sf_json_response(['ok'=>true,'submission_id'=>$id,'review_status'=>'pending_review',
   'message'=>'Captured privately and submitted for editorial review.']);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'capture_rejected','message'=>$e->getMessage()],422);
}catch(Throwable $e){
 error_log('[Stonefellow capture upload] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'capture_upload_failed','message'=>'The capture could not be accepted.'],503);
}
