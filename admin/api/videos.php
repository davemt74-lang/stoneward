<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_video_ensure_schema();
function sf_video_admin_references(): array {
    $tracks=[];foreach(sf_catalog() as $t){$id=(string)($t['id']??'');if($id!==''&&function_exists('sf_seo_public_track')&&sf_seo_public_track($id))$tracks[]=['id'=>$id,'title'=>(string)($t['title']??$id),'release'=>(string)($t['release']??'')];}
    $releases=[];foreach(sf_seo_release_rows() as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false)$releases[]=['id'=>(string)$r['id'],'title'=>(string)($r['title']??$r['id']),'date'=>(string)($r['release_date']??'')];
    $shows=[];if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)$shows[]=['id'=>(string)$s['id'],'title'=>(string)($s['title']??$s['venue']??$s['id']),'date'=>(string)($s['date']??''),'venue'=>(string)($s['venue']??'')];
    return ['tracks'=>$tracks,'releases'=>$releases,'shows'=>$shows];
}
if($method==='GET'){$id=max(0,(int)($_GET['id']??0));if($id){$v=sf_video_get($id,false);if(!$v)sf_json_response(['ok'=>false,'message'=>'Video not found.'],404);sf_json_response(['ok'=>true,'video'=>$v,'metrics'=>sf_video_metrics(30),'references'=>sf_video_admin_references(),'csrf'=>sf_admin_csrf()]);}sf_json_response(['ok'=>true,'videos'=>sf_video_list(false),'metrics'=>sf_video_metrics(30),'references'=>sf_video_admin_references(),'csrf'=>sf_admin_csrf()]);}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save'){
    $raw=(array)($b['video']??[]);$existing=!empty($raw['id'])?sf_video_get((int)$raw['id'],false):null;$target=(string)($raw['status']??'draft');$statusChanged=$existing!==null&&($existing['status']??'draft')!==$target;$initialConsequential=$existing===null&&in_array($target,['published','archived'],true);$consequential=$statusChanged||$initialConsequential;if($consequential&&empty($b['confirmed']))throw new RuntimeException('Changing video visibility requires explicit Admin confirmation.');
    $v=sf_video_save($raw,(int)$me['id']);sf_log_admin_action((int)$me['id'],'video_feature_saved','video_feature',(string)$v['id'],['status'=>$v['status'],'kind'=>$v['kind'],'readiness'=>$v['readiness']['score'],'featured'=>$v['featured']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_video','video_feature',['route'=>'video_library','action'=>'save_video_feature','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Save video '.$v['title'],'response'=>'Video saved as '.$v['status'].'; readiness '.$v['readiness']['score'].'%.']);sf_json_response(['ok'=>true,'video'=>$v,'metrics'=>sf_video_metrics(30)]);
  }
  sf_json_response(['ok'=>false,'message'=>'Unsupported video action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
