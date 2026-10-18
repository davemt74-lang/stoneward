<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/team-workspace-core.php';
require_once __DIR__.'/story-capture-core.php';
require_once __DIR__.'/story-transcript-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_require_user(false,$method==='POST');$id=(int)$me['id'];
try{
 if($method==='GET'){
   $transcriptId=(int)($_GET['id']??0);
   if($transcriptId<1)sf_json_response(['ok'=>false,'error'=>'transcript_id_required'],422);
   $item=sf_transcript_read($id,$transcriptId);
   if(!$item)sf_json_response(['ok'=>false,'error'=>'not_found'],404);
   sf_json_response(['ok'=>true,'transcript'=>$item]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $data=sf_request_json();$action=(string)($data['action']??'create');
 if($action==='create'){
   $submission=(int)($data['submission_id']??0);
   $tid=sf_transcript_create($id,$submission,$data);
   sf_json_response(['ok'=>true,'transcript_id'=>$tid,'review_status'=>'draft']);
 }
 if($action==='review'){
   sf_transcript_review($id,(int)($data['transcript_id']??0),(string)($data['decision']??''));
   sf_json_response(['ok'=>true]);
 }
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_transcript','message'=>$e->getMessage()],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'transcript_access_denied','message'=>$e->getMessage()],403);
}catch(Throwable $e){
 error_log('[Stonefellow transcript] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'transcript_failed'],503);
}
