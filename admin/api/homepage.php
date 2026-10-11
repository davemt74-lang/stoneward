<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_artist_home_ensure_schema();
function sf_artist_home_admin_refs(): array {
  $releases=[];foreach(sf_release_rows() as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false)$releases[]=['id'=>(string)$r['id'],'title'=>(string)($r['title']??$r['id']),'date'=>(string)($r['release_date']??'')];
  $tracks=[];foreach(sf_catalog() as $t)$tracks[]=['id'=>(string)($t['id']??''),'title'=>(string)($t['title']??''),'release'=>(string)($t['release']??'')];
  $shows=[];if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)$shows[]=['id'=>(string)$s['id'],'title'=>(string)($s['title']??$s['venue']??$s['id']),'date'=>(string)($s['date']??''),'venue'=>(string)($s['venue']??'')];
  $products=[];if(function_exists('sf_commerce_products'))foreach(sf_commerce_products(true) as $p)$products[]=['id'=>(string)$p['id'],'title'=>(string)$p['title'],'available'=>!empty($p['available'])];
  return ['releases'=>$releases,'tracks'=>$tracks,'shows'=>$shows,'products'=>$products];
}
if($method==='GET')sf_json_response(['ok'=>true,'site'=>sf_site_settings(),'sections'=>sf_artist_home_sections(false),'metrics'=>sf_artist_home_metrics(),'references'=>sf_artist_home_admin_refs(),'csrf'=>sf_admin_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='save_section'){$section=sf_artist_home_save_section((array)($b['section']??[]));sf_log_admin_action((int)$me['id'],'artist_home_section_saved','artist_home',(string)$section['section_key'],['enabled'=>$section['enabled'],'position'=>$section['position']]);sf_agent_brain_log((int)$me['id'],'admin_homepage','artist_home_section',['route'=>'artist_homepage','action'=>'save_section','context_profile'=>'site','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Update artist homepage '.$section['section_key'],'response'=>'Homepage section saved.']);sf_json_response(['ok'=>true,'section'=>$section,'metrics'=>sf_artist_home_metrics()]);}
  if($action==='reset_defaults'){if(empty($b['confirmed']))throw new RuntimeException('Resetting the Artist Homepage requires explicit confirmation.');$sections=sf_artist_home_reset();sf_log_admin_action((int)$me['id'],'artist_home_reset','site','artist_home',['sections'=>count($sections)]);sf_agent_brain_log((int)$me['id'],'admin_homepage','artist_home_reset',['route'=>'artist_homepage','action'=>'reset_defaults','context_profile'=>'site','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Reset Artist Homepage sections','response'=>'Artist Homepage sections reset to Stonefellow defaults.']);sf_json_response(['ok'=>true,'sections'=>$sections,'metrics'=>sf_artist_home_metrics()]);}
  sf_json_response(['ok'=>false,'message'=>'Unsupported homepage action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
