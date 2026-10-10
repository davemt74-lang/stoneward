<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_lifecycle_automation_ensure_schema();

function sf_admin_lifecycle_action_log(int $automationId,int $limit=120): array {
    $q=sf_db()->prepare('SELECT l.*,c.email,c.display_name FROM lifecycle_action_log l JOIN fan_contacts c ON c.id=l.contact_id WHERE l.automation_id=? ORDER BY l.id DESC LIMIT '.max(1,min(500,$limit)));$q->execute([$automationId]);return $q->fetchAll();
}
if($method==='GET'){
    $segmentId=max(0,(int)($_GET['segment_id']??0));if($segmentId){$segment=sf_lifecycle_segment($segmentId);if(!$segment)sf_json_response(['ok'=>false,'message'=>'Segment not found.'],404);sf_json_response(['ok'=>true,'segment'=>$segment,'preview'=>sf_lifecycle_segment_preview($segmentId,100),'summary'=>sf_lifecycle_summary()]);}
    $automationId=max(0,(int)($_GET['automation_id']??0));if($automationId){$a=sf_lifecycle_automation($automationId);if(!$a)sf_json_response(['ok'=>false,'message'=>'Automation not found.'],404);sf_json_response(['ok'=>true,'automation'=>$a,'enrollments'=>sf_lifecycle_enrollments($automationId,160),'action_log'=>sf_admin_lifecycle_action_log($automationId,160),'summary'=>sf_lifecycle_summary()]);}
    sf_json_response(['ok'=>true,'summary'=>sf_lifecycle_summary(),'segments'=>sf_lifecycle_segments(false),'automations'=>sf_lifecycle_automations(),'runs'=>sf_lifecycle_runs(100),'csrf'=>sf_admin_csrf()]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='preview_rules'){
        $rules=sf_lifecycle_segment_normalize_rules((array)($b['rules']??[]));$segment=['rules'=>$rules];$rows=sf_lifecycle_segment_contacts($segment,5000);sf_json_response(['ok'=>true,'preview'=>['count'=>count($rows),'contacts'=>array_slice(array_map(fn($c)=>['id'=>(int)$c['id'],'email'=>$c['email'],'display_name'=>$c['display_name'],'status'=>$c['status']],$rows),0,100)]]);
    }
    if($action==='save_segment'){
        $s=sf_lifecycle_segment_save((array)($b['segment']??[]),(int)$me['id']);sf_log_admin_action((int)$me['id'],'fan_segment_saved','fan_segment',(string)$s['id'],['name'=>$s['name'],'revision'=>(int)$s['revision']]);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','segment_save',['route'=>'lifecycle_segments','action'=>'save_segment','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save fan segment '.$s['name'],'response'=>'Segment saved at revision '.$s['revision'].'.']);sf_json_response(['ok'=>true,'segment'=>$s,'preview'=>sf_lifecycle_segment_preview((int)$s['id'],100),'summary'=>sf_lifecycle_summary()]);
    }
    if($action==='archive_segment'){
        $id=(int)($b['id']??0);$s=sf_lifecycle_segment($id);if(!$s)throw new InvalidArgumentException('Segment not found.');sf_db()->prepare("UPDATE fan_segments SET status='archived',updated_at=? WHERE id=?")->execute([gmdate('c'),$id]);sf_log_admin_action((int)$me['id'],'fan_segment_archived','fan_segment',(string)$id);sf_json_response(['ok'=>true,'summary'=>sf_lifecycle_summary()]);
    }
    if($action==='save_automation'){
        $a=sf_lifecycle_automation_save((array)($b['automation']??[]),(int)$me['id']);sf_log_admin_action((int)$me['id'],'lifecycle_automation_saved','lifecycle_automation',(string)$a['id'],['status'=>$a['status'],'version'=>(int)$a['version'],'trigger'=>$a['trigger_type']]);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','automation_save',['route'=>'lifecycle_automations','action'=>'save_automation','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','request'=>'Save lifecycle automation '.$a['name'],'response'=>'Automation saved as '.$a['status'].' version '.$a['version'].'.']);sf_json_response(['ok'=>true,'automation'=>$a,'summary'=>sf_lifecycle_summary()]);
    }
    if($action==='set_status'){
        $id=(int)($b['id']??0);$status=(string)($b['status']??'paused');$confirmed=!empty($b['confirmed']);$a=sf_lifecycle_automation_status($id,$status,(int)$me['id'],$confirmed);sf_log_admin_action((int)$me['id'],'lifecycle_automation_status','lifecycle_automation',(string)$id,['status'=>$status]);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','automation_status',['route'=>'lifecycle_automations','action'=>'set_status','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>$status==='active','status'=>'completed','request'=>ucfirst($status).' lifecycle automation '.$a['name'],'response'=>'Automation is now '.$status.'.']);sf_json_response(['ok'=>true,'automation'=>$a,'summary'=>sf_lifecycle_summary()]);
    }
    if($action==='run_now'){
        $id=(int)($b['id']??0);if(empty($b['confirmed']))throw new RuntimeException('Running lifecycle automation now requires explicit Admin confirmation.');$r=sf_lifecycle_run_automation($id,'manual',500,true);sf_log_admin_action((int)$me['id'],'lifecycle_automation_run','lifecycle_automation',(string)$id,$r);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','automation_run',['route'=>'lifecycle_automations','action'=>'run_now','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>$r['errors']?'completed_with_errors':'completed','request'=>'Run lifecycle automation now','response'=>'Evaluated '.$r['contacts_evaluated'].' contacts, enrolled '.$r['enrolled'].', executed '.$r['actions_executed'].' actions, skipped '.$r['actions_skipped'].'.']);sf_json_response(['ok'=>true,'run'=>$r,'summary'=>sf_lifecycle_summary()]);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
