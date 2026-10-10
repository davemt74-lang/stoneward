<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
$me=sf_admin_require_auth($method!=='GET');
sf_automation_ensure_schema();

function sf_admin_segment_preview(array $rules,int $limit=100): array {
    $rules=sf_segment_rules_normalize($rules);$rows=sf_db()->query('SELECT * FROM fan_contacts ORDER BY COALESCE(last_engaged_at,updated_at) DESC,id DESC LIMIT 5000')->fetchAll();$out=[];
    foreach($rows as $c){if(!sf_segment_contact_matches($c,$rules))continue;$m=sf_segment_contact_metrics($c);$out[]=['id'=>(int)$c['id'],'display_name'=>(string)$c['display_name'],'email'=>(string)$c['email'],'status'=>(string)$c['status'],'marketing_opt_in'=>!empty($c['marketing_opt_in']),'user_id'=>(int)($c['user_id']??0),'order_count'=>(int)$m['order_count'],'spend_cents'=>(int)$m['spend_cents'],'last_activity_at'=>(string)$m['last_activity_at']];if(count($out)>=max(1,min(500,$limit)))break;}
    return $out;
}
function sf_admin_automation_detail(int $id): array {
    $a=sf_automation_get($id);if(!$a)throw new InvalidArgumentException('Automation not found.');$q=sf_db()->prepare('SELECT r.*,c.display_name,c.email FROM lifecycle_automation_runs r JOIN fan_contacts c ON c.id=r.contact_id WHERE r.automation_id=? ORDER BY r.id DESC LIMIT 200');$q->execute([$id]);$runs=$q->fetchAll();foreach($runs as &$r)$r['details']=sf_auto_decode($r['details_json']??'',[]);unset($r);return ['automation'=>$a,'runs'=>$runs];
}

if($method==='GET'){
    $runId=max(0,(int)($_GET['run_id']??0));if($runId){$run=sf_automation_run($runId);if(!$run)sf_json_response(['ok'=>false,'message'=>'Automation run not found.'],404);sf_json_response(['ok'=>true,'run'=>$run,'events'=>sf_automation_run_events($runId)]);}
    $automationId=max(0,(int)($_GET['automation_id']??0));if($automationId){$detail=sf_admin_automation_detail($automationId);sf_json_response(['ok'=>true]+$detail+['segments'=>sf_segment_list(),'summary'=>sf_automation_admin_summary()]);}
    $segmentId=max(0,(int)($_GET['segment_id']??0));if($segmentId){$s=sf_segment_get($segmentId);if(!$s)sf_json_response(['ok'=>false,'message'=>'Segment not found.'],404);$contacts=sf_admin_segment_preview($s['rules'],200);sf_json_response(['ok'=>true,'segment'=>$s,'contacts'=>$contacts,'count'=>count(sf_segment_contacts($segmentId,5000))]);}
    sf_json_response(['ok'=>true,'summary'=>sf_automation_admin_summary(),'segments'=>sf_segment_list(),'automations'=>sf_automation_list(300),'runs'=>sf_automation_recent_runs(200)]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'');
try{
    if($action==='preview_segment'){
        $rules=sf_segment_rules_normalize(is_array($b['rules']??null)?$b['rules']:[]);$contacts=sf_admin_segment_preview($rules,200);sf_json_response(['ok'=>true,'rules'=>$rules,'contacts'=>$contacts,'count'=>count($contacts),'truncated'=>count($contacts)>=200]);
    }
    if($action==='save_segment'){
        $s=sf_segment_save(is_array($b['segment']??null)?$b['segment']:[],(int)$me['id']);$count=count(sf_segment_contacts((int)$s['id'],5000));sf_log_admin_action((int)$me['id'],'lifecycle_segment_saved','campaign_segment',(string)$s['id'],['member_count'=>$count]);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','segment_saved',['route'=>'fan_automations','action'=>'save_segment','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>false,'status'=>'completed','segment_id'=>(int)$s['id'],'request'=>'Save fan segment '.$s['name'],'response'=>'Segment saved with '.$count.' current members.']);sf_json_response(['ok'=>true,'segment'=>$s,'member_count'=>$count]);
    }
    if($action==='delete_segment'){
        $id=(int)($b['id']??0);$s=sf_segment_get($id);if(!$s)throw new InvalidArgumentException('Segment not found.');sf_db()->prepare('DELETE FROM campaign_segments WHERE id=?')->execute([$id]);sf_log_admin_action((int)$me['id'],'lifecycle_segment_deleted','campaign_segment',(string)$id,['name'=>$s['name']]);sf_json_response(['ok'=>true]);
    }
    if($action==='refresh_segment'){
        $id=(int)($b['id']??0);$r=sf_segment_refresh($id);sf_log_admin_action((int)$me['id'],'lifecycle_segment_refreshed','campaign_segment',(string)$id,$r);sf_json_response(['ok'=>true,'result'=>$r,'segment'=>sf_segment_get($id)]);
    }
    if($action==='validate_automation'){
        $r=sf_automation_steps_validate((array)($b['steps']??[]));sf_json_response(['ok'=>$r['ok'],'errors'=>$r['errors'],'steps'=>$r['steps']],$r['ok']?200:422);
    }
    if($action==='save_automation'){
        $a=sf_automation_save(is_array($b['automation']??null)?$b['automation']:[],(int)$me['id']);$requires=$a['status']==='published';sf_log_admin_action((int)$me['id'],'lifecycle_automation_saved','lifecycle_automation',(string)$a['id'],['status'=>$a['status'],'trigger_type'=>$a['trigger_type'],'segment_id'=>$a['segment_id']]);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','automation_saved',['route'=>'fan_automations','action'=>'save_automation','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>$requires,'status'=>$a['status'],'automation_id'=>(int)$a['id'],'request'=>'Save lifecycle automation '.$a['name'],'response'=>'Automation saved as '.$a['status'].' with '.count($a['steps']).' steps.']);sf_json_response(['ok'=>true,'automation'=>$a]);
    }
    if($action==='delete_automation'){
        $id=(int)($b['id']??0);$a=sf_automation_get($id);if(!$a)throw new InvalidArgumentException('Automation not found.');$ok=sf_automation_delete($id);sf_log_admin_action((int)$me['id'],'lifecycle_automation_deleted','lifecycle_automation',(string)$id,['name'=>$a['name']]);sf_json_response(['ok'=>$ok]);
    }
    if($action==='manual_run'){
        $id=(int)($b['id']??0);$contactId=max(0,(int)($b['contact_id']??0));if(empty($b['confirmed']))throw new RuntimeException('Manual automation run requires Admin confirmation.');$r=sf_automation_manual_run($id,(int)$me['id'],$contactId);sf_log_admin_action((int)$me['id'],'lifecycle_automation_manual_run','lifecycle_automation',(string)$id,$r);sf_agent_brain_log((int)$me['id'],'admin_lifecycle','automation_run',['route'=>'fan_automations','action'=>'manual_run','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','automation_id'=>$id,'request'=>'Run lifecycle automation now','response'=>'Started '.$r['started'].' fan journeys; skipped '.$r['skipped'].'.']);sf_json_response(['ok'=>true,'result'=>$r]);
    }
    if($action==='tick'){
        $r=sf_automation_tick();sf_log_admin_action((int)$me['id'],'lifecycle_automation_tick','lifecycle_automation','scheduler',$r);sf_json_response(['ok'=>true,'result'=>$r]);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
