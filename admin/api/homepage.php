<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_artist_home_ensure_schema();require_once dirname(__DIR__,2).'/api/artist-home-drafts.php';
function sf_artist_home_admin_refs(): array {
  $releases=[];foreach(sf_release_rows() as $r)if(($r['state']??'published')==='published'&&($r['public_visible']??true)!==false)$releases[]=['id'=>(string)$r['id'],'title'=>(string)($r['title']??$r['id']),'date'=>(string)($r['release_date']??'')];
  $tracks=[];foreach(sf_catalog() as $t)$tracks[]=['id'=>(string)($t['id']??''),'title'=>(string)($t['title']??''),'release'=>(string)($t['release']??'')];
  $shows=[];if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)$shows[]=['id'=>(string)$s['id'],'title'=>(string)($s['title']??$s['venue']??$s['id']),'date'=>(string)($s['date']??''),'venue'=>(string)($s['venue']??'')];
  $products=[];if(function_exists('sf_commerce_products'))foreach(sf_commerce_products(true) as $p)$products[]=['id'=>(string)$p['id'],'title'=>(string)$p['title'],'available'=>!empty($p['available'])];
  $videos=[];if(function_exists('sf_video_public_list'))foreach(sf_video_public_list() as $v)$videos[]=['slug'=>(string)$v['slug'],'title'=>(string)$v['title'],'kind'=>(string)$v['kind']];
  $tickets=[];foreach(sf_ticket_public_offers(null) as $offer)$tickets[]=['id'=>(string)$offer['id'],'title'=>(string)$offer['title'],'show_title'=>(string)$offer['show_title'],'date'=>(string)$offer['show_date'],'venue'=>(string)$offer['venue'],'price_cents'=>(int)$offer['price_cents']];
  return ['releases'=>$releases,'tracks'=>$tracks,'shows'=>$shows,'products'=>$products,'videos'=>$videos,'tickets'=>$tickets];
}
if($method==='GET')sf_json_response(['ok'=>true,'site'=>sf_site_settings(),'sections'=>sf_artist_home_sections(false),'draft'=>sf_home_design_state(),'versions'=>sf_home_design_versions(),'metrics'=>sf_artist_home_metrics(),'references'=>sf_artist_home_admin_refs(),'csrf'=>sf_admin_csrf()]);
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
  if($action==='preview_design_draft'){
    $sections=sf_home_design_clean((array)($b['sections']??[]));
    $homepage=sf_artist_home_payload(0,$sections);
    sf_json_response(['ok'=>true,'homepage'=>$homepage]);
  }
  if($action==='save_design_draft'){
    $draft=sf_home_design_save((array)($b['sections']??[]),(int)($b['expected_revision']??-1),(string)($b['base_hash']??''));
    sf_log_admin_action((int)$me['id'],'artist_home_draft_saved','artist_home','draft',['revision'=>$draft['revision']]);
    sf_json_response(['ok'=>true,'draft'=>$draft]);
  }
  if($action==='reset_design_draft'){
    if(empty($b['confirmed']))throw new InvalidArgumentException('Discarding the draft requires confirmation.');
    $draft=sf_home_design_reset_to_live((int)($b['expected_revision']??-1),(string)($b['base_hash']??''));
    sf_log_admin_action((int)$me['id'],'artist_home_draft_rebased','artist_home','draft',['revision'=>$draft['revision']]);
    sf_json_response(['ok'=>true,'draft'=>$draft]);
  }
  if($action==='publish_design_draft'){
    if(empty($b['confirmed']))throw new InvalidArgumentException('Publishing requires explicit confirmation.');
    $published=sf_home_design_publish((int)($b['revision']??-1),(string)($b['published_hash']??''));
    sf_log_admin_action((int)$me['id'],'artist_home_design_published','artist_home','homepage',['version'=>$published['version']]);
    sf_json_response(['ok'=>true]+$published+['versions'=>sf_home_design_versions()]);
  }
  if($action==='restore_design_version'){
    $draft=sf_home_design_restore((int)($b['version']??0),(int)($b['expected_revision']??-1),(string)($b['base_hash']??''));
    sf_log_admin_action((int)$me['id'],'artist_home_version_staged','artist_home','draft',['version'=>(int)($b['version']??0)]);
    sf_json_response(['ok'=>true,'draft'=>$draft]);
  }
  if($action==='save_section'){$section=sf_artist_home_save_section((array)($b['section']??[]));sf_log_admin_action((int)$me['id'],'artist_home_section_saved','artist_home',(string)$section['section_key'],['enabled'=>$section['enabled'],'position'=>$section['position']]);sf_agent_brain_log((int)$me['id'],'admin_homepage','artist_home_section',['route'=>'artist_homepage','action'=>'save_section','context_profile'=>'site','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Update artist homepage '.$section['section_key'],'response'=>'Homepage section saved.']);sf_json_response(['ok'=>true,'section'=>$section,'metrics'=>sf_artist_home_metrics()]);}
  if($action==='reorder_sections'){
    $order=(array)($b['keys']??[]);
    $all=sf_artist_home_keys();
    if(count($order)!==count($all)||count(array_unique($order))!==count($all)||array_diff($order,$all)||array_diff($all,$order))
      throw new InvalidArgumentException('Homepage section order must include every known section exactly once.');
    $pdo=sf_db();$pdo->beginTransaction();
    try{
      $q=$pdo->prepare('UPDATE artist_home_sections SET position=?,updated_at=? WHERE section_key=?');
      foreach($order as $idx=>$key)$q->execute([($idx+1)*10,gmdate('c'),(string)$key]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    sf_log_admin_action((int)$me['id'],'artist_home_sections_reordered','artist_home','all',['keys'=>$order]);
    sf_json_response(['ok'=>true,'sections'=>sf_artist_home_sections(false)]);
  }
  if($action==='reset_defaults'){if(empty($b['confirmed']))throw new RuntimeException('Resetting the Artist Homepage requires explicit confirmation.');$sections=sf_artist_home_reset();sf_log_admin_action((int)$me['id'],'artist_home_reset','site','artist_home',['sections'=>count($sections)]);sf_agent_brain_log((int)$me['id'],'admin_homepage','artist_home_reset',['route'=>'artist_homepage','action'=>'reset_defaults','context_profile'=>'site','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Reset Artist Homepage sections','response'=>'Artist Homepage sections reset to Stonefellow defaults.']);sf_json_response(['ok'=>true,'sections'=>$sections,'metrics'=>sf_artist_home_metrics()]);}
  sf_json_response(['ok'=>false,'message'=>'Unsupported homepage action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
