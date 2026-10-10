<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
sf_rights_ensure_schema();

if($method==='GET'){
    $trackId=sf_clean_text($_GET['track_id']??'',160);
    if($trackId!==''){
        $work=sf_rights_work($trackId);
        if(!$work){sf_rights_backfill_catalog();$work=sf_rights_work($trackId);}
        if(!$work)sf_json_response(['ok'=>false,'message'=>'Rights work not found.'],404);
        sf_json_response(['ok'=>true,'work'=>$work,'track'=>sf_rights_track($trackId),'parties'=>sf_rights_parties(),'summary'=>sf_rights_summary(),'csrf'=>sf_admin_csrf()]);
    }
    sf_json_response(['ok'=>true,'summary'=>sf_rights_summary(),'parties'=>sf_rights_parties(),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='save_work'){
        $trackId=sf_clean_text($b['track_id']??'',160);$w=sf_rights_save_work($trackId,(array)($b['work']??[]));
        sf_log_admin_action((int)$me['id'],'rights_work_saved','track',$trackId,['readiness'=>$w['readiness'],'registration_status'=>$w['registration_status']]);
        sf_agent_brain_log((int)$me['id'],'admin_rights','rights_work',['route'=>'rights_registry','action'=>'save_work','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save rights record for '.$w['title'],'response'=>'Rights record saved; readiness '.(!empty($w['readiness']['ready'])?'ready':'blocked').'.']);
        sf_json_response(['ok'=>true,'work'=>$w,'summary'=>sf_rights_summary()]);
    }
    if($action==='save_party'){
        $p=sf_rights_save_party((array)($b['party']??[]),(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'rights_party_saved','rights_party',(string)$p['id'],['party_type'=>$p['party_type'],'name'=>$p['name']]);
        sf_agent_brain_log((int)$me['id'],'admin_rights','rights_party',['route'=>'rights_registry','action'=>'save_party','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save rights party '.$p['name'],'response'=>'Rights party saved.']);
        sf_json_response(['ok'=>true,'party'=>$p,'parties'=>sf_rights_parties()]);
    }
    if($action==='save_split'){
        $trackId=sf_clean_text($b['track_id']??'',160);$w=sf_rights_save_split($trackId,(array)($b['split']??[]));
        sf_log_admin_action((int)$me['id'],'rights_split_saved','track',$trackId,['readiness'=>$w['readiness']]);
        sf_agent_brain_log((int)$me['id'],'admin_rights','rights_split',['route'=>'rights_registry','action'=>'save_split','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Update rights ownership split for '.$w['title'],'response'=>'Ownership split saved; composition '.$w['readiness']['composition_percent'].'%, master '.$w['readiness']['master_percent'].'%.']);
        sf_json_response(['ok'=>true,'work'=>$w]);
    }
    if($action==='delete_split'){
        if(empty($b['confirmed']))throw new RuntimeException('Deleting an ownership split requires explicit confirmation.');
        $trackId=sf_clean_text($b['track_id']??'',160);$splitId=(int)($b['split_id']??0);$ok=sf_rights_delete_split($trackId,$splitId);
        if($ok){sf_log_admin_action((int)$me['id'],'rights_split_deleted','track',$trackId,['split_id'=>$splitId]);sf_agent_brain_log((int)$me['id'],'admin_rights','rights_split_delete',['route'=>'rights_registry','action'=>'delete_split','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Delete ownership split '.$splitId.' for '.$trackId,'response'=>'Ownership split removed.']);}
        sf_json_response(['ok'=>$ok,'work'=>sf_rights_work($trackId)]);
    }
    if($action==='save_license'){
        $l=sf_rights_save_license((array)($b['license']??[]),(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'rights_license_saved',(string)$l['entity_type'],(string)$l['entity_id'],['license_id'=>(int)$l['id'],'license_type'=>$l['license_type'],'status'=>$l['status']]);
        sf_agent_brain_log((int)$me['id'],'admin_rights','rights_license',['route'=>'rights_registry','action'=>'save_license','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>in_array($l['status'],['cleared','restricted'],true),'status'=>'completed','request'=>'Save '.$l['license_type'].' license for '.$l['entity_type'].' '.$l['entity_id'],'response'=>'License saved as '.$l['status'].'.']);
        sf_json_response(['ok'=>true,'license'=>$l,'work'=>$l['entity_type']==='track'?sf_rights_work((string)$l['entity_id']):null,'summary'=>sf_rights_summary()]);
    }
    if($action==='delete_license'){
        if(empty($b['confirmed']))throw new RuntimeException('Deleting a license record requires explicit confirmation.');
        $id=(int)($b['id']??0);$ok=sf_rights_delete_license($id);if($ok){sf_log_admin_action((int)$me['id'],'rights_license_deleted','rights_license',(string)$id);sf_agent_brain_log((int)$me['id'],'admin_rights','rights_license_delete',['route'=>'rights_registry','action'=>'delete_license','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','request'=>'Delete rights license '.$id,'response'=>'License record deleted.']);}
        sf_json_response(['ok'=>$ok,'summary'=>sf_rights_summary()]);
    }
    if($action==='sync_catalog'){
        $r=sf_rights_backfill_catalog();sf_log_admin_action((int)$me['id'],'rights_catalog_synced','rights','catalog',$r);sf_agent_brain_log((int)$me['id'],'admin_rights','rights_sync',['route'=>'rights_registry','action'=>'sync_catalog','context_profile'=>'catalog','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Synchronize catalog into rights registry','response'=>'Rights works synchronized: '.$r['added'].' added, '.$r['updated'].' refreshed.']);sf_json_response(['ok'=>true,'result'=>$r,'summary'=>sf_rights_summary()]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported rights action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
