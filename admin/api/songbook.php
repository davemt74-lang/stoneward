<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_songbook_ensure_schema();
if($method==='GET'){$trackId=sf_clean_text($_GET['track_id']??'',160);$track=sf_songbook_track($trackId);if(!$track)sf_json_response(['ok'=>false,'message'=>'Track not found.'],404);$book=sf_songbook_get($trackId,false);sf_json_response(['ok'=>true,'track'=>['id'=>$trackId,'title'=>$track['title']??$trackId,'lyrics'=>$track['lyrics']??'','metadata'=>$track['metadata']??[]],'songbook'=>$book,'metrics'=>sf_songbook_metrics(30),'csrf'=>sf_admin_csrf()]);}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save'){$raw=(array)($b['songbook']??[]);$trackId=sf_clean_text($raw['track_id']??'',160);$existing=sf_songbook_get($trackId,false);$target=(string)($raw['status']??'draft');$changed=$existing!==null&&(($existing['status']??'draft')!==$target);$initialConsequential=$existing===null&&in_array($target,['published','archived'],true);$consequential=$changed||$initialConsequential;if($consequential&&empty($b['confirmed']))throw new RuntimeException('Changing Songbook visibility requires explicit Admin confirmation.');$book=sf_songbook_save($raw,(int)$me['id']);sf_log_admin_action((int)$me['id'],'songbook_saved','track',$trackId,['status'=>$book['status'],'readiness'=>$book['readiness']['score'],'sections'=>count($book['sections']),'annotations'=>count($book['annotations'])]);sf_agent_brain_log((int)$me['id'],'admin_songbook','songbook',['route'=>'songbook','action'=>'save','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Save Songbook for '.$book['track']['title'],'response'=>'Songbook saved as '.$book['status'].'; readiness '.$book['readiness']['score'].'%.']);sf_json_response(['ok'=>true,'songbook'=>$book,'metrics'=>sf_songbook_metrics(30)]);}
  sf_json_response(['ok'=>false,'message'=>'Unsupported Songbook action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
