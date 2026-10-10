<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_calendar_ensure_schema();
if($method==='GET'){
    $id=max(0,(int)($_GET['id']??0));if($id){$p=sf_calendar_plan($id);if(!$p)sf_json_response(['ok'=>false,'message'=>'Operating plan not found.'],404);sf_json_response(['ok'=>true,'plan'=>$p,'templates'=>sf_calendar_templates(),'timeline'=>sf_calendar_timeline(),'csrf'=>sf_admin_csrf()]);}
    sf_json_response(['ok'=>true,'dashboard'=>sf_calendar_dashboard(),'templates'=>sf_calendar_templates(),'source_events'=>sf_calendar_source_events(),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='save_plan'){
        $raw=(array)($b['plan']??[]);$old=!empty($raw['id'])?sf_calendar_plan((int)$raw['id']):null;$plan=sf_calendar_save_plan($raw,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'operating_plan_saved','operating_plan',(string)$plan['id'],['status'=>$plan['status'],'plan_type'=>$plan['plan_type'],'target_at'=>$plan['target_at']]);
        if(function_exists('sf_agent_brain_log'))sf_agent_brain_log((int)$me['id'],'admin_calendar','operating_plan',['route'=>'operating_calendar','action'=>'save_plan','context_profile'=>'operations','needs_llm'=>false,'requires_confirmation'=>$plan['status']==='active'&&($old['status']??'')!=='active','status'=>'completed','request'=>'Save operating plan '.$plan['name'],'response'=>'Plan '.$plan['name'].' is '.$plan['status'].' with target '.$plan['target_at'].'.']);
        sf_json_response(['ok'=>true,'plan'=>$plan,'dashboard'=>sf_calendar_dashboard()]);
    }
    if($action==='save_milestone'){
        $m=sf_calendar_save_milestone((array)($b['milestone']??[]));sf_log_admin_action((int)$me['id'],'operating_milestone_saved','operating_milestone',(string)$m['id'],['plan_id'=>(int)$m['plan_id'],'status'=>$m['status'],'due_at'=>$m['due_at']]);sf_json_response(['ok'=>true,'milestone'=>$m,'plan'=>sf_calendar_plan((int)$m['plan_id'])]);
    }
    if($action==='set_milestone_status'){
        $m=sf_calendar_set_milestone_status((int)($b['id']??0),(string)($b['status']??''));sf_log_admin_action((int)$me['id'],'operating_milestone_status','operating_milestone',(string)$m['id'],['plan_id'=>(int)$m['plan_id'],'status'=>$m['status']]);if(function_exists('sf_agent_brain_log')&&$m['status']==='completed')sf_agent_brain_log((int)$me['id'],'admin_calendar','milestone_complete',['route'=>'operating_calendar','action'=>'complete_milestone','context_profile'=>'operations','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Complete milestone '.$m['title'],'response'=>'Completed '.$m['title'].' due '.$m['due_at'].'.']);sf_json_response(['ok'=>true,'milestone'=>$m,'plan'=>sf_calendar_plan((int)$m['plan_id'])]);
    }
    if($action==='delete_milestone'){$m=sf_calendar_milestone((int)($b['id']??0));if(!$m)throw new InvalidArgumentException('Milestone not found.');$ok=sf_calendar_delete_milestone((int)$m['id']);sf_log_admin_action((int)$me['id'],'operating_milestone_deleted','operating_milestone',(string)$m['id'],['plan_id'=>(int)$m['plan_id']]);sf_json_response(['ok'=>$ok,'plan'=>sf_calendar_plan((int)$m['plan_id'])]);}
    if($action==='delete_plan'){if(empty($b['confirmed']))sf_json_response(['ok'=>false,'message'=>'Deleting a launch plan requires explicit Admin confirmation.'],422);$id=(int)($b['id']??0);$p=sf_calendar_plan($id);if(!$p)throw new InvalidArgumentException('Operating plan not found.');$ok=sf_calendar_delete_plan($id);sf_log_admin_action((int)$me['id'],'operating_plan_deleted','operating_plan',(string)$id,['name'=>$p['name']]);sf_json_response(['ok'=>$ok,'dashboard'=>sf_calendar_dashboard()]);}
    sf_json_response(['ok'=>false,'message'=>'Unsupported operating-calendar action.'],422);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
