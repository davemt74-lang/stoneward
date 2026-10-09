<?php
declare(strict_types=1);require __DIR__.'/bootstrap.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';$me=sf_admin_require_auth($method!=='GET');sf_campaign_ensure_schema();

if($method==='GET'){
    $id=max(0,(int)($_GET['id']??0));$summary=sf_campaign_admin_summary();
    $segments=sf_db()->query('SELECT * FROM campaign_segments ORDER BY updated_at DESC,id DESC LIMIT 200')->fetchAll();
    foreach($segments as &$s)$s['rules']=sf_campaign_decode($s['rules_json']??'',[]);unset($s);
    if($id){
        $campaign=sf_campaign_get($id);if(!$campaign)sf_json_response(['ok'=>false,'message'=>'Campaign not found.'],404);
        $q=sf_db()->prepare('SELECT * FROM campaign_participants WHERE campaign_id=? ORDER BY id DESC LIMIT 300');$q->execute([$id]);$participants=$q->fetchAll();
        $q=sf_db()->prepare('SELECT * FROM campaign_events WHERE campaign_id=? ORDER BY id DESC LIMIT 400');$q->execute([$id]);$events=$q->fetchAll();
        foreach($events as &$e)$e['metadata']=sf_campaign_decode($e['metadata_json']??'',[]);unset($e);
        $q=sf_db()->prepare('SELECT * FROM campaign_message_runs WHERE campaign_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);$runs=$q->fetchAll();
        sf_json_response(['ok'=>true,'campaign'=>$campaign,'analytics'=>sf_campaign_analytics($id),'participants'=>$participants,'events'=>$events,'message_runs'=>$runs,'segments'=>$segments,'summary'=>$summary]);
    }
    sf_json_response(['ok'=>true,'campaigns'=>sf_campaign_list(300),'segments'=>$segments,'summary'=>$summary]);
}
if($method!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$b=sf_request_json();$action=(string)($b['action']??'save');
try{
    if($action==='save'){
        $campaign=sf_campaign_save(is_array($b['campaign']??null)?$b['campaign']:[],(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'campaign_saved','campaign',(string)$campaign['id'],['status'=>$campaign['status'],'version'=>$campaign['version']]);sf_agent_brain_log((int)$me['id'],'admin_campaign','campaign_authoring',['route'=>'campaign_builder','action'=>'save_campaign','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>$campaign['status']==='published','status'=>$campaign['status'],'campaign_id'=>(int)$campaign['id'],'campaign_version'=>(int)$campaign['version'],'request'=>'Admin saved campaign '.$campaign['name'],'response'=>'Campaign saved as '.$campaign['status']]);
        sf_json_response(['ok'=>true,'campaign'=>$campaign,'analytics'=>sf_campaign_analytics((int)$campaign['id'])]);
    }
    if($action==='delete'){
        $id=(int)($b['id']??0);$ok=sf_campaign_delete($id);if($ok)sf_log_admin_action((int)$me['id'],'campaign_deleted','campaign',(string)$id);sf_json_response(['ok'=>$ok]);
    }
    if($action==='duplicate'){
        $id=(int)($b['id']??0);$src=sf_campaign_get($id);if(!$src)throw new InvalidArgumentException('Campaign not found.');
        $copy=$src;$copy['id']=0;$copy['name']=$src['name'].' Copy';$copy['slug']=$src['slug'].'-copy-'.gmdate('His');$copy['status']='draft';$copy['starts_at']=null;$copy['ends_at']=null;$copy['published_at']=null;
        $campaign=sf_campaign_save($copy,(int)$me['id']);sf_log_admin_action((int)$me['id'],'campaign_duplicated','campaign',(string)$campaign['id'],['source_id'=>$id]);sf_json_response(['ok'=>true,'campaign'=>$campaign]);
    }
    if($action==='validate_graph'){
        $r=sf_campaign_graph_validate(is_array($b['graph']??null)?$b['graph']:[]);sf_json_response(['ok'=>$r['ok'],'errors'=>$r['errors'],'graph'=>$r['graph']],$r['ok']?200:422);
    }
    if($action==='send_email'){
        $id=(int)($b['id']??0);$campaign=sf_campaign_get($id);if(!$campaign)throw new InvalidArgumentException('Campaign not found.');
        $r=sf_campaign_send_email_node($campaign,(string)($b['node_id']??''),(int)$me['id'],!empty($b['confirmed']));sf_agent_brain_log((int)$me['id'],'admin_campaign','campaign_send',['route'=>'campaign_email','action'=>'send_campaign_email','context_profile'=>'crm','needs_llm'=>false,'requires_confirmation'=>true,'status'=>'completed','campaign_id'=>$id,'node_id'=>(string)($b['node_id']??''),'request'=>'Admin approved campaign email send','response'=>'Sent '.$r['sent'].' campaign emails; '.$r['failed'].' failed.']);sf_json_response(['ok'=>true,'result'=>$r]);
    }
    if($action==='save_segment'){
        $id=(int)($b['id']??0);$name=sf_clean_text($b['name']??'',180);if($name==='')throw new InvalidArgumentException('Segment name is required.');$rules=is_array($b['rules']??null)?$b['rules']:[];$now=gmdate('c');
        if($id){$q=sf_db()->prepare('UPDATE campaign_segments SET name=?,rules_json=?,updated_at=? WHERE id=?');$q->execute([$name,sf_campaign_json($rules),$now,$id]);}
        else{$q=sf_db()->prepare('INSERT INTO campaign_segments(name,rules_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?)');$q->execute([$name,sf_campaign_json($rules),(int)$me['id'],$now,$now]);$id=(int)sf_db()->lastInsertId();}
        sf_log_admin_action((int)$me['id'],'campaign_segment_saved','campaign_segment',(string)$id);sf_json_response(['ok'=>true,'id'=>$id]);
    }
    if($action==='delete_segment'){
        $id=(int)($b['id']??0);sf_db()->prepare('DELETE FROM campaign_segments WHERE id=?')->execute([$id]);sf_log_admin_action((int)$me['id'],'campaign_segment_deleted','campaign_segment',(string)$id);sf_json_response(['ok'=>true]);
    }
    sf_json_response(['ok'=>false,'error'=>'unknown_action'],400);
}catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
