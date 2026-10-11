<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_story_ensure_schema();
function sf_story_admin_refs(): array {
    $tracks=[];foreach(sf_catalog() as $t)if(($t['public_visible']??true)!==false&&!in_array((string)($t['state']??''),['draft','private','archived'],true))$tracks[]=['id'=>(string)($t['id']??''),'title'=>(string)($t['title']??''),'release'=>(string)($t['release']??'')];
    $releases=[];foreach(sf_release_rows() as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false)$releases[]=['id'=>(string)$r['id'],'title'=>(string)($r['title']??$r['id']),'date'=>(string)($r['release_date']??'')];
    $shows=[];if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)$shows[]=['id'=>(string)$s['id'],'title'=>(string)($s['title']??$s['venue']??$s['id']),'date'=>(string)($s['date']??''),'venue'=>(string)($s['venue']??'')];
    return ['tracks'=>$tracks,'releases'=>$releases,'shows'=>$shows];
}
if($method==='GET')sf_json_response(['ok'=>true,'stories'=>sf_story_list(false,'',500),'metrics'=>sf_story_metrics(30),'references'=>sf_story_admin_refs(),'csrf'=>sf_admin_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save'){
    $raw=(array)($b['story']??[]);$existing=!empty($raw['id'])?sf_story_get((int)$raw['id'],false):null;$target=(string)($raw['status']??'draft');$statusChanged=$existing!==null&&($existing['status']??'draft')!==$target;$consequential=$statusChanged&&in_array($target,['published','archived'],true);if($consequential&&empty($b['confirmed']))throw new RuntimeException('Publishing or archiving a story requires explicit Admin confirmation.');
    $e=sf_story_save($raw,(int)$me['id']);sf_log_admin_action((int)$me['id'],'story_saved','story_entry',(string)$e['id'],['entry_type'=>$e['entry_type'],'status'=>$e['status'],'entity_type'=>$e['entity_type'],'entity_id'=>$e['entity_id'],'readiness'=>$e['readiness']]);sf_agent_brain_log((int)$me['id'],'admin_story','story_entry',['route'=>'artist_storytelling','action'=>'save_story','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>$consequential,'status'=>'completed','request'=>'Save '.$e['entry_type'].' '.$e['title'],'response'=>'Story saved as '.$e['status'].'; readiness '.$e['readiness']['score'].'%.']);sf_json_response(['ok'=>true,'story'=>$e,'metrics'=>sf_story_metrics(30)]);
  }
  if($action==='delete'){
    if(empty($b['confirmed']))throw new RuntimeException('Deleting a story requires explicit Admin confirmation.');$id=(int)($b['id']??0);if(!sf_story_delete($id))throw new InvalidArgumentException('Story not found.');sf_log_admin_action((int)$me['id'],'story_deleted','story_entry',(string)$id,[]);sf_agent_brain_log((int)$me['id'],'admin_story','story_delete',['route'=>'artist_storytelling','action'=>'delete_story','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Delete story '.$id,'response'=>'Draft/archive story deleted.']);sf_json_response(['ok'=>true,'metrics'=>sf_story_metrics(30)]);
  }
  sf_json_response(['ok'=>false,'message'=>'Unsupported story action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
