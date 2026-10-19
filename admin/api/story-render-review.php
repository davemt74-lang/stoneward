<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/ai.php';
require_once SF_ROOT.'/api/media-core.php';
require_once SF_ROOT.'/api/team-workspace-core.php';
require_once SF_ROOT.'/api/story-capture-core.php';
require_once SF_ROOT.'/api/story-capture-rights-core.php';
require_once SF_ROOT.'/api/story-transcript-core.php';
require_once SF_ROOT.'/api/story-scene-core.php';
require_once SF_ROOT.'/api/story-media-evidence-core.php';
require_once SF_ROOT.'/api/story-assembly-core.php';
require_once SF_ROOT.'/api/story-composer-core.php';
require_once SF_ROOT.'/api/storyboard-preview-core.php';
require_once SF_ROOT.'/api/story-render-source-core.php';
require_once SF_ROOT.'/api/story-render-worker-core.php';
require_once SF_ROOT.'/api/story-render-review-core.php';
require_once SF_ROOT.'/api/story-render-mix-core.php';
require_once SF_ROOT.'/api/story-render-transition-core.php';
require_once SF_ROOT.'/api/story-render-edl-core.php';
require_once SF_ROOT.'/api/story-render-cut-core.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
$editor=(int)$me['id'];
if(!sf_team_can($editor,'team.assign_roles'))
 sf_json_response(['ok'=>false,'error'=>'forbidden'],403);
try{
 if($method==='GET'&&($_GET['action']??'')==='cues'){
  header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('X-Content-Type-Options: nosniff');
  sf_json_response(['ok'=>true,'review'=>sf_render_review_cues($editor,(int)($_GET['job_id']??0))]);
 }
 if($method==='GET'&&($_GET['action']??'')==='stream'){
  $jobId=(int)($_GET['job_id']??0);
  $file=sf_render_review_file($editor,$jobId);
  try{[$from,$to,$partial]=sf_render_review_range($_SERVER['HTTP_RANGE']??null,$file['size']);}
  catch(InvalidArgumentException $e){
   http_response_code(416);
   header('Content-Range: bytes */'.$file['size']);
   header('Cache-Control: private, no-store, max-age=0');
   exit;
  }
  if(function_exists('session_write_close'))@session_write_close();
  http_response_code($partial?206:200);
  header('Content-Type: video/mp4');
  header('Content-Disposition: inline; filename="private-review.mp4"');
  header('X-Content-Type-Options: nosniff');
  header('Content-Security-Policy: default-src \'none\'; sandbox');
  header('Cross-Origin-Resource-Policy: same-origin');
  header('Referrer-Policy: no-referrer');
  header('Cache-Control: private, no-store, max-age=0, must-revalidate');
  header('Accept-Ranges: bytes');
  header('Content-Length: '.($to-$from+1));
  if($partial)header('Content-Range: bytes '.$from.'-'.$to.'/'.$file['size']);
  $stream=fopen($file['path'],'rb');
  if(!is_resource($stream))exit;
  fseek($stream,$from);$left=$to-$from+1;
  while($left>0&&!feof($stream)){
   $chunk=fread($stream,min($left,65536));
   if(!is_string($chunk)||$chunk==='')break;
   echo $chunk;$left-=strlen($chunk);
   if(connection_aborted())break;
  }
  fclose($stream);exit;
 }
 if($method==='GET'){
  $assembly=(int)($_GET['assembly_id']??0);
  sf_json_response(['ok'=>true,'items'=>$assembly>0?sf_render_review_admin($editor,$assembly):[],
    'publication_allowed'=>false,'download_allowed'=>false]);
 }
 if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
 $b=sf_request_json();
 if(($b['action']??'')==='review'){
  $changed=sf_render_review_decide($editor,(int)($b['job_id']??0),
    (string)($b['decision']??''),(string)($b['basis']??''));
  sf_json_response(['ok'=>true,'changed'=>$changed,'publication_allowed'=>false]);
 }
 sf_json_response(['ok'=>false,'error'=>'unknown_action'],422);
}catch(InvalidArgumentException $e){
 sf_json_response(['ok'=>false,'error'=>'invalid_private_review'],422);
}catch(RuntimeException $e){
 sf_json_response(['ok'=>false,'error'=>'private_review_unavailable'],403);
}catch(Throwable $e){
 error_log('[Stonefellow private video review] '.get_class($e));
 sf_json_response(['ok'=>false,'error'=>'review_unavailable'],503);
}
