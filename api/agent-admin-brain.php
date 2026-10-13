<?php
declare(strict_types=1);
require_once __DIR__.'/agent-orchestration.php';
require_once __DIR__.'/agent-mission-planner.php';
require_once __DIR__.'/agent-admin-intelligence.php';
require_once __DIR__.'/agent-proactive-research.php';
require_once __DIR__.'/agent-specialist-delegation.php';
require_once __DIR__.'/agent-feedback-core.php';
/**
 * Administrator Agent Brain. A separate operational scope, NEVER a fan memory
 * or conversation table. Every read/write uses the authenticated Admin ID.
 */
function sf_admin_agent_ensure_schema(): void {
    $db=sf_db();$sqlite=(string)(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS admin_agent_threads(id TEXT PRIMARY KEY,admin_user_id INTEGER NOT NULL,title TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_agent_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,thread_id TEXT NOT NULL,admin_user_id INTEGER NOT NULL,role TEXT NOT NULL,content TEXT NOT NULL,action_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(thread_id) REFERENCES admin_agent_threads(id) ON DELETE CASCADE)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_admin_agent_messages_thread ON admin_agent_messages(admin_user_id,thread_id,id)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS admin_agent_threads(id VARCHAR(80) PRIMARY KEY,admin_user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(180) NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_admin_threads_owner(admin_user_id),CONSTRAINT fk_admin_agent_thread_user FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS admin_agent_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,thread_id VARCHAR(80) NOT NULL,admin_user_id BIGINT UNSIGNED NOT NULL,role VARCHAR(16) NOT NULL,content LONGTEXT NOT NULL,action_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_admin_agent_messages_thread(admin_user_id,thread_id,id),CONSTRAINT fk_admin_agent_messages_thread FOREIGN KEY(thread_id) REFERENCES admin_agent_threads(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_admin_agent_thread(int $adminId,string $threadId=''): string {
    if($adminId<1)throw new RuntimeException('Administrator authentication required.');
    sf_admin_agent_ensure_schema();$db=sf_db();
    if($threadId!==''){
        if(!preg_match('/^admin_[a-f0-9]{32}$/D',$threadId))throw new InvalidArgumentException('Invalid Admin conversation identifier.');
        $q=$db->prepare('SELECT 1 FROM admin_agent_threads WHERE id=? AND admin_user_id=?');
        $q->execute([$threadId,$adminId]);
        if(!$q->fetchColumn())throw new RuntimeException('Admin conversation is unavailable.');
        return $threadId;
    }
    $id='admin_'.bin2hex(random_bytes(16));$now=gmdate('c');
    $q=$db->prepare('INSERT INTO admin_agent_threads(id,admin_user_id,title,created_at,updated_at) VALUES(?,?,?,?,?)');
    $q->execute([$id,$adminId,'Admin Agent conversation',$now,$now]);
    return $id;
}
function sf_admin_agent_thread_list(int $adminId): array {
    if($adminId<1)return [];
    sf_admin_agent_ensure_schema();$q=sf_db()->prepare('SELECT id,title,updated_at FROM admin_agent_threads WHERE admin_user_id=? ORDER BY updated_at DESC LIMIT 25');$q->execute([$adminId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_admin_agent_thread_messages(int $adminId,string $threadId): array {
    sf_admin_agent_thread($adminId,$threadId);$q=sf_db()->prepare('SELECT id,role,content,action_json,created_at FROM admin_agent_messages WHERE admin_user_id=? AND thread_id=? ORDER BY id DESC LIMIT 40');
    $q->execute([$adminId,$threadId]);$rows=array_reverse($q->fetchAll(PDO::FETCH_ASSOC));
    foreach($rows as &$row){$row['action']=json_decode((string)$row['action_json'],true)?:[];unset($row['action_json']);}
    return $rows;
}
function sf_admin_agent_save_message(int $adminId,string $threadId,string $role,string $text,array $action=[]): void {
    if(!in_array($role,['user','assistant'],true))throw new InvalidArgumentException('Invalid role.');
    sf_admin_agent_thread($adminId,$threadId);
    if(strlen($text)>12000)throw new InvalidArgumentException('Message too long.');
    $db=sf_db();$now=gmdate('c');
    $q=$db->prepare('INSERT INTO admin_agent_messages(thread_id,admin_user_id,role,content,action_json,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$threadId,$adminId,$role,$text,json_encode($action,JSON_THROW_ON_ERROR),$now]);
    $db->prepare('UPDATE admin_agent_threads SET updated_at=? WHERE id=? AND admin_user_id=?')->execute([$now,$threadId,$adminId]);
}
function sf_admin_agent_operational_context(): array {
    $crm=sf_crm_admin_summary();$campaigns=sf_campaign_admin_summary();
    $summary=[
        'crm_contacts'=>(int)($crm['contacts']??0),
        'newsletter_opted_in'=>(int)($crm['newsletter']??0),
        'campaigns_total'=>(int)($campaigns['campaigns']??0),
        'campaigns_active'=>(int)($campaigns['active']??0)
    ];
    if(function_exists('sf_fan_journey_counts')){
        $journey=sf_fan_journey_counts(30);
        $summary['journey_counts']=(array)($journey['counts']??[]);
        $summary['journey_rates']=(array)($journey['rates']??[]);
    }
    return $summary;
}
function sf_admin_agent_plan(string $message): array {
    $m=strtolower(trim($message));$type='research';$view='brain';
    if(preg_match('/\b(draft|create|prepare|build)\b.*\b(newsletter|campaign|discount|vip|offer)\b|\b(newsletter|campaign)\b.*\b(draft|create|prepare|build)\b/',$m)){$type='prepare_campaign';$view='campaigns';}
    elseif(preg_match('/\b(draft|create|prepare|build)\b.*\b(journey|automation|follow.up|sequence)\b|\b(journey|automation)\b.*\b(draft|create|prepare|build)\b/',$m)){$type='prepare_journey';$view='automations';}
    elseif(preg_match('/\b(approval|pending|proposals|action history|agent actions)\b/',$m)){$type='review_actions';$view='brain';}
    elseif(preg_match('/\b(crm|fans|subscribers|audience)\b/',$m)){$type='crm_report';$view='crm';}
    elseif(preg_match('/\b(conversion|engagement|analytics|performance|journey)\b/',$m)){$type='journey_report';$view='automations';}
    return ['type'=>$type,'view'=>$view,'requires_confirmation'=>in_array($type,['prepare_campaign','prepare_journey'],true),'execution'=>'proposal_or_navigation_only'];
}
/**
 * Reuse the existing provider clients for conversational explanation, never
 * for selecting permissions, approving actions, or writing to the platform.
 */
function sf_admin_agent_research_reply(string $message,array $context,array $prior,int $adminId=0): ?string {
    if(!function_exists('sf_ai_llm_candidates'))return null;
    $history=[];
    foreach(array_slice($prior,-8) as $row){
        if(!in_array((string)($row['role']??''),['user','assistant'],true))continue;
        $history[]=['role'=>(string)$row['role'],'content'=>substr((string)$row['content'],0,1800)];
    }
    $system="You are Stonefellow's administrative research assistant. Use only the aggregate operational facts supplied. Never request or reveal any fan's private conversations or memories. Never claim to have edited CRM, sent messages, purchased, published, changed a journey or saved a campaign. The current tool contract allows no direct writes. State uncertainty if data is absent. Keep your answer under 220 words.\nAGGREGATE FACTS: ".json_encode($context,JSON_UNESCAPED_SLASHES);
    if($adminId>0){$quality=(string)(sf_agent_feedback_summary('admin',$adminId)['guidance']??'');
      if($quality!=='')$system.="\\nADMIN FEEDBACK (quality guidance only): ".$quality;}
    foreach(array_slice(sf_ai_llm_candidates(),0,2) as $provider){
        try{
            $result=$provider['provider']==='openai'
              ?sf_openai_call($provider,$system,$history,$message,450)
              :sf_anthropic_call($provider,$system,$history,$message,450);
            $answer=trim((string)($result['text']??''));
            if($answer!=='')return substr($answer,0,2200);
        }catch(Throwable $e){/* Never surface provider keys or internal paths. */}
    }
    return null;
}
/**
 * Mission state comes from the existing Section 44 approved action ledger.
 * Do not maintain a second executor or duplicate action state.
 */
function sf_admin_agent_mission_summary(int $adminId): array {
    if($adminId<1)return ['missions'=>[],'counts'=>[]];
    $rows=function_exists('sf_agent_action_recent')?sf_agent_action_recent($adminId,30):[];
    $missions=[];$counts=[];
    foreach($rows as $p){
        $status=(string)($p['status']??'unknown');
        $counts[$status]=($counts[$status]??0)+1;
        $result=(array)($p['result']??[]);
        $missions[]=[
            'id'=>(string)$p['id'],
            'name'=>substr((string)($p['payload']['name']??'Reviewed draft'),0,160),
            'tool_id'=>(string)$p['tool_id'],
            'status'=>$status,
            'created_at'=>(string)($p['created_at']??''),
            'result_entity_id'=>(int)($result['automation_id']??$result['campaign_id']??0),
            'review_required'=>in_array($status,['review_required','executing'],true),
        ];
    }
    return ['missions'=>$missions,'counts'=>$counts];
}
function sf_admin_agent_brain_snapshot(int $adminId): array {
    if($adminId<1)return ['conversations'=>[],'missions'=>[]];
    $m=sf_admin_agent_mission_summary($adminId);
    return [
        'scope'=>'admin','conversations'=>sf_admin_agent_thread_list($adminId),
        'missions'=>$m['missions'],'mission_counts'=>$m['counts'],
        'tool_inventory'=>function_exists('sf_agent_tool_inventory')?sf_agent_tool_inventory('admin'):[],
        'skill_inventory'=>sf_agent_skill_inventory('admin'),
        'planned_missions'=>sf_agent_mission_plan_recent('admin',$adminId),
        'specialist_assignments'=>sf_agent_specialist_recent('admin',$adminId),
        'feedback_summary'=>sf_agent_feedback_summary('admin',$adminId),
        'recovery_summary'=>sf_agent_mission_recovery_summary('admin',$adminId),
        'research_opportunities'=>sf_agent_proactive_admin_research(sf_admin_agent_operational_context())
    ];
}
function sf_admin_agent_reply(int $adminId,string $threadId,string $message): array {
    if($adminId<1)throw new RuntimeException('Administrator authentication required.');
    $message=trim($message);
    if($message===''||strlen($message)>4000)throw new InvalidArgumentException('Message must be 1–4000 bytes.');
    $threadId=sf_admin_agent_thread($adminId,$threadId);
    $prior=sf_admin_agent_thread_messages($adminId,$threadId);
    sf_admin_agent_save_message($adminId,$threadId,'user',$message);
    $plan=sf_admin_agent_plan($message);$context=sf_admin_agent_operational_context();$missionState=sf_admin_agent_mission_summary($adminId);
    $brief=sf_admin_agent_decision_brief($context);
    $missionPlan=sf_agent_mission_plan('admin',$message,$plan['type']);
    if($missionPlan!==null){$missionPlan=sf_agent_mission_apply_admin_facts($missionPlan,$context);
        $missionPlan=sf_agent_mission_plan_save('admin',$adminId,$threadId,$message,$missionPlan);}
    $types=[
      'prepare_campaign'=>'I can prepare a campaign in the existing Campaign Builder. You must inspect its complete draft, approve the exact hash, and separately authorize saving. Nothing will be sent or published.',
      'prepare_journey'=>'I can prepare an unpublished journey in the existing Automation Builder. You must inspect the steps and consent rules, approve the exact hash, then separately authorize saving.',
      'review_actions'=>'You have '.array_sum($missionState['counts']).' recent Agent missions, including '.(int)($missionState['counts']['review_required']??0).' requiring review and '.(int)($missionState['counts']['completed']??0).' completed. Open Agent actions for exact proposals, approved results, and interrupted work. Never automatically retry uncertain operations.',
      'crm_report'=>'Current CRM summary: '.$context['crm_contacts'].' contacts and '.$context['newsletter_opted_in'].' opted-in newsletter subscribers. Open CRM for eligible audience and campaign review.',
      'journey_report'=>'The last 30 days include '.(int)($context['journey_counts']['journeys_started']??0).' journey runs, '.(int)($context['journey_counts']['journeys_completed']??0).' completed and '.(int)($context['journey_counts']['journeys_failed']??0).' failed. These independent counts are not a sequential customer funnel.',
      'research'=>'I can help investigate Stonefellow campaigns, CRM aggregates, fan journeys, and operational actions. Specify an area to inspect; no private customer conversations are shared with Admin.'
    ];
    $text=$types[$plan['type']];
    $contract=sf_agent_orchestration_contract('admin',$plan['type']);
    if($plan['requires_confirmation']&&!$contract['may_prepare_reviewed_proposal'])throw new RuntimeException('The reviewed action is not registered.');
    if($plan['type']==='research'){
        $research=sf_admin_agent_research_reply($message,$context,$prior,$adminId);
        if($research!==null)$text=$research;
    }
    $action=['type'=>$plan['type'],'view'=>$plan['view'],'requires_confirmation'=>$contract['requires_exact_approval'],'tool_contract'=>$contract,'mission_plan'=>$missionPlan,'insights'=>$brief];
    sf_admin_agent_save_message($adminId,$threadId,'assistant',$text,$action);
    return ['conversation_id'=>$threadId,'text'=>$text,'action'=>$action,'context'=>$context,'missions'=>$missionState['missions'],'mission_plan'=>$missionPlan,'insights'=>$brief,'brain'=>['scope'=>'admin','owner_id'=>$adminId,'tool_decision'=>$plan['type'],'status'=>'review_or_read_only']];
}
