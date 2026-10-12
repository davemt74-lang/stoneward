<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_gallery_ensure_schema();
function sf_gallery_admin_refs(): array {
  $releases=[];foreach(sf_seo_release_rows() as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false)$releases[]=['id'=>(string)$r['id'],'title'=>(string)($r['title']??$r['id']),'date'=>(string)($r['release_date']??'')];
  $shows=[];if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)$shows[]=['id'=>(string)$s['id'],'title'=>(string)($s['title']??$s['venue']??$s['id']),'date'=>(string)($s['date']??''),'venue'=>(string)($s['venue']??'')];
  $stories=[];if(function_exists('sf_story_list'))foreach(sf_story_list(true,'',500) as $s)$stories[]=['id'=>(int)$s['id'],'slug'=>(string)$s['slug'],'title'=>(string)$s['title'],'type'=>(string)$s['entry_type']];
  return ['releases'=>$releases,'shows'=>$shows,'stories'=>$stories];
}
if($method==='GET'){$id=max(0,(int)($_GET['id']??0));if($id){$g=sf_gallery_get($id,false);if(!$g)sf_json_response(['ok'=>false,'message'=>'Gallery not found.'],404);sf_json_response(['ok'=>true,'gallery'=>$g,'metrics'=>sf_gallery_metrics(30),'references'=>sf_gallery_admin_refs(),'csrf'=>sf_admin_csrf()]);}sf_json_response(['ok'=>true,'galleries'=>sf_gallery_list(false),'metrics'=>sf_gallery_metrics(30),'references'=>sf_gallery_admin_refs(),'csrf'=>sf_admin_csrf()]);}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save'){
    $raw=(array)($b['gallery']??[]);$existing=!empty($raw['id'])?sf_gallery_get((int)$raw['id'],false):null;$target=(string)($raw['status']??'draft');$statusChanged=$existing!==null&&($existing['status']??'draft')!==$target;$initialConsequential=$existing===null&&in_array($target,['published','archived'],true);$consequential=$statusChanged||$initialConsequential;if($consequential&&empty($b['confirmed']))throw new RuntimeException('Changing gallery visibility requires explicit Admin confirmation.');
    $g=sf_gallery_save($raw,(int)$me['id']);sf_log_admin_action((int)$me['id'],'photo_gallery_saved','photo_gallery',(string)$g['id'],['status'=>$g['status'],'readiness'=>$g['readiness']['score'],'featured'=>$g['featured']]);if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_gallery','photo_gallery',['route'=>'photo_gallery','action'=>'save_gallery','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Save photo gallery '.$g['title'],'response'=>'Gallery saved as '.$g['status'].'; readiness '.$g['readiness']['score'].'%.']);sf_json_response(['ok'=>true,'gallery'=>$g,'metrics'=>sf_gallery_metrics(30)]);
  }
  sf_json_response(['ok'=>false,'message'=>'Unsupported gallery action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
