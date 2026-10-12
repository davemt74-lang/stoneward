<?php
declare(strict_types=1);
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
    sf_admin_agent_thread($adminId,$threadId);$q=sf_db()->prepare('SELECT role,content,action_json,created_at FROM admin_agent_messages WHERE admin_user_id=? AND thread_id=? ORDER BY id DESC LIMIT 40');
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
function sf_admin_agent_reply(int $adminId,string $threadId,string $message): array {
    if($adminId<1)throw new RuntimeException('Administrator authentication required.');
    $message=trim($message);
    if($message===''||strlen($message)>4000)throw new InvalidArgumentException('Message must be 1–4000 bytes.');
    $threadId=sf_admin_agent_thread($adminId,$threadId);
    sf_admin_agent_save_message($adminId,$threadId,'user',$message);
    $plan=sf_admin_agent_plan($message);$context=sf_admin_agent_operational_context();
    $types=[
      'prepare_campaign'=>'I can prepare a campaign in the existing Campaign Builder. You must inspect its complete draft, approve the exact hash, and separately authorize saving. Nothing will be sent or published.',
      'prepare_journey'=>'I can prepare an unpublished journey in the existing Automation Builder. You must inspect the steps and consent rules, approve the exact hash, then separately authorize saving.',
      'review_actions'=>'Open Agent actions to review exact proposals, results and any interrupted work before taking another action.',
      'crm_report'=>'Current CRM summary: '.$context['crm_contacts'].' contacts and '.$context['newsletter_opted_in'].' opted-in newsletter subscribers. Open CRM for eligible audience and campaign review.',
      'journey_report'=>'The last 30 days include '.(int)($context['journey_counts']['journeys_started']??0).' journey runs, '.(int)($context['journey_counts']['journeys_completed']??0).' completed and '.(int)($context['journey_counts']['journeys_failed']??0).' failed. These independent counts are not a sequential customer funnel.',
      'research'=>'I can help investigate Stonefellow campaigns, CRM aggregates, fan journeys, and operational actions. Specify an area to inspect; no private customer conversations are shared with Admin.'
    ];
    $text=$types[$plan['type']];
    $action=['type'=>$plan['type'],'view'=>$plan['view'],'requires_confirmation'=>$plan['requires_confirmation']];
    sf_admin_agent_save_message($adminId,$threadId,'assistant',$text,$action);
    return ['conversation_id'=>$threadId,'text'=>$text,'action'=>$action,'context'=>$context,'brain'=>['scope'=>'admin','owner_id'=>$adminId,'tool_decision'=>$plan['type'],'status'=>'review_or_read_only']];
}
