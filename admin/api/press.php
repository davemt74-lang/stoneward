<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_press_ensure_schema();

if($method==='GET'){
    $id=max(0,(int)($_GET['id']??0));
    if($id){$kit=sf_press_kit($id);if(!$kit)sf_json_response(['ok'=>false,'message'=>'Press kit not found.'],404);sf_json_response(['ok'=>true,'kit'=>$kit,'contacts'=>sf_press_contacts(),'outreach'=>sf_press_outreach($id),'coverage'=>sf_press_coverage(),'events'=>sf_press_events($id,200),'summary'=>sf_press_summary(),'csrf'=>sf_admin_csrf()]);}
    sf_json_response(['ok'=>true,'kits'=>sf_press_kits(),'contacts'=>sf_press_contacts(),'outreach'=>sf_press_outreach(),'coverage'=>sf_press_coverage(),'events'=>sf_press_events(0,100),'summary'=>sf_press_summary(),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='save_kit'){
        $raw=(array)($b['kit']??[]);$existing=!empty($raw['id'])?sf_press_kit((int)$raw['id']):null;$target=(string)($raw['status']??'draft');if(in_array($target,['published','private'],true)&&($existing['status']??'draft')!==$target&&empty($b['confirmed']))throw new RuntimeException('Publishing or privatizing an EPK requires explicit Admin confirmation.');$kit=sf_press_save_kit($raw,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'press_kit_saved','press_kit',(string)$kit['id'],['status'=>$kit['status'],'release_id'=>$kit['release_id']]);
        sf_agent_brain_log((int)$me['id'],'admin_press','press_kit',['route'=>'press_epk','action'=>'save_kit','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>in_array($kit['status'],['published','private'],true),'status'=>'completed','request'=>'Save EPK '.$kit['title'],'response'=>'EPK '.$kit['slug'].' saved as '.$kit['status'].'.']);
        sf_json_response(['ok'=>true,'kit'=>$kit,'summary'=>sf_press_summary()]);
    }
    if($action==='rotate_token'){
        if(empty($b['confirmed']))throw new RuntimeException('Rotating a private EPK token requires explicit confirmation.');$id=(int)($b['id']??0);$r=sf_press_rotate_token($id);sf_log_admin_action((int)$me['id'],'press_share_token_rotated','press_kit',(string)$id,['hint'=>$r['kit']['share_token_hint']]);sf_agent_brain_log((int)$me['id'],'admin_press','press_share_token',['route'=>'press_epk','action'=>'rotate_private_link','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Rotate private EPK link for '.$r['kit']['title'],'response'=>'A new private link was generated; the previous token is invalid.']);sf_json_response(['ok'=>true]+$r);
    }
    if($action==='save_contact'){
        $p=sf_press_save_contact((array)($b['contact']??[]));sf_log_admin_action((int)$me['id'],'press_contact_saved','press_contact',(string)$p['id'],['outlet'=>$p['outlet'],'status'=>$p['status']]);sf_agent_brain_log((int)$me['id'],'admin_press','press_contact',['route'=>'press_epk','action'=>'save_contact','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save press contact '.$p['name'],'response'=>'Press contact saved for '.$p['outlet'].'.']);sf_json_response(['ok'=>true,'contact'=>$p,'contacts'=>sf_press_contacts()]);
    }
    if($action==='send_outreach'){
        if(empty($b['confirmed']))throw new RuntimeException('Sending press outreach requires explicit Admin confirmation.');$r=sf_press_send_outreach((int)($b['kit_id']??0),(int)($b['contact_id']??0),(string)($b['subject']??''),(string)($b['body']??''),(string)($b['token']??''),(int)$me['id']);$o=$r['outreach'];sf_log_admin_action((int)$me['id'],'press_outreach_sent','press_kit',(string)($b['kit_id']??0),['contact_id'=>(int)($b['contact_id']??0),'delivery_status'=>$o['status']??'']);sf_agent_brain_log((int)$me['id'],'admin_press','press_outreach',['route'=>'press_epk','action'=>'send_outreach','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Send press outreach for kit '.(int)($b['kit_id']??0),'response'=>'Outreach handed to email delivery with status '.($o['status']??'unknown').'.']);sf_json_response(['ok'=>true]+$r);
    }
    if($action==='save_coverage'){
        $c=sf_press_save_coverage((array)($b['coverage']??[]),(int)$me['id']);sf_log_admin_action((int)$me['id'],'press_coverage_saved','press_coverage',(string)$c['id'],['outlet'=>$c['outlet'],'coverage_type'=>$c['coverage_type']]);sf_agent_brain_log((int)$me['id'],'admin_press','press_coverage',['route'=>'press_epk','action'=>'save_coverage','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Record press coverage '.$c['title'],'response'=>'Coverage recorded for '.$c['outlet'].'.']);sf_json_response(['ok'=>true,'coverage'=>$c,'summary'=>sf_press_summary()]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported press action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
