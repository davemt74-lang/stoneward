<?php
declare(strict_types=1);
require_once __DIR__.'/agent-tool-registry.php';
/**
 * Section 44B–F: durable, review-first governed campaign draft operations.
 * Proposal IDs are server-created; authenticated admin identity is server-side.
 * Any interruption after execution starts is held for operator review.
 */
function sf_agent_actions_ensure_schema(): void {
    $db=sf_db();$sqlite=(string)(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS agent_action_proposals (
          id TEXT PRIMARY KEY, admin_user_id INTEGER NOT NULL, request_key TEXT NOT NULL,
          tool_id TEXT NOT NULL, status TEXT NOT NULL, payload_json TEXT NOT NULL,
          payload_hash TEXT NOT NULL, result_json TEXT NOT NULL DEFAULT '{}',
          created_at TEXT NOT NULL, approved_at TEXT NULL, executed_at TEXT NULL,
          UNIQUE(admin_user_id,request_key),
          FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE CASCADE)");
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS agent_action_proposals (
          id VARCHAR(64) PRIMARY KEY, admin_user_id BIGINT UNSIGNED NOT NULL,
          request_key VARCHAR(100) NOT NULL, tool_id VARCHAR(80) NOT NULL,
          status VARCHAR(32) NOT NULL, payload_json LONGTEXT NOT NULL,
          payload_hash CHAR(64) NOT NULL, result_json LONGTEXT NOT NULL,
          created_at VARCHAR(40) NOT NULL, approved_at VARCHAR(40) NULL,
          executed_at VARCHAR(40) NULL, UNIQUE KEY uq_agent_action_request(admin_user_id,request_key),
          INDEX idx_agent_action_owner_status(admin_user_id,status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
function sf_agent_action_normalize_campaign(array $input): array {
    $graph=is_array($input['graph']??null)?$input['graph']:sf_campaign_graph_default();
    $checked=sf_campaign_graph_validate($graph);
    if(!$checked['ok'])throw new InvalidArgumentException('Campaign graph is invalid: '.implode(' ',(array)$checked['errors']));
    $name=sf_clean_text($input['name']??'',180);
    if($name==='')throw new InvalidArgumentException('Campaign name is required.');
    return [
        'id'=>0,'name'=>$name,'status'=>'draft','goal'=>sf_clean_text($input['goal']??'fan_acquisition',80),
        'headline'=>sf_clean_text($input['headline']??'',255),
        'body_text'=>sf_clean_text($input['body_text']??'',12000),
        'graph'=>$checked['graph'],'audience'=>is_array($input['audience']??null)?$input['audience']:[],
        'landing'=>is_array($input['landing']??null)?$input['landing']:[],
    ];
}
/**
 * Strict reviewed Agent journey draft. No Agent action can publish or send:
 * both ID and status are server-enforced at preparation and execution.
 */
function sf_agent_action_normalize_automation(array $input): array {
    $name=sf_clean_text($input['name']??'',180);
    if($name==='')throw new InvalidArgumentException('Journey name is required.');
    $trigger=(string)($input['trigger_type']??'manual');
    if(!in_array($trigger,['manual','crm_event','segment_enter','segment_exit','scheduled'],true))
        throw new InvalidArgumentException('Journey trigger type is not supported.');
    $segment=max(0,(int)($input['segment_id']??0));
    if(in_array($trigger,['segment_enter','segment_exit'],true)&&$segment<1)
        throw new InvalidArgumentException('A segment-triggered journey needs a saved segment.');
    $verified=sf_automation_steps_validate((array)($input['steps']??[]));
    if(!$verified['ok'])throw new InvalidArgumentException('Journey steps are invalid: '.implode(' ',(array)$verified['errors']));
    $triggerCfg=is_array($input['trigger']??null)?$input['trigger']:[];
    $events=[];
    foreach(array_slice((array)($triggerCfg['events']??[]),0,20) as $e){
        $v=sf_clean_text((string)$e,80);
        if($v!=='')$events[]=$v;
    }
    return [
        'id'=>0,'name'=>$name,'status'=>'draft','trigger_type'=>$trigger,
        'trigger'=>['events'=>array_values(array_unique($events)),'interval_hours'=>max(1,min(8760,(int)($triggerCfg['interval_hours']??24)))],
        'segment_id'=>$segment,'steps'=>$verified['steps'],
        'cooldown_hours'=>max(24,min(8760,(int)($input['cooldown_hours']??24))),
        'max_runs_per_contact'=>max(1,min(100,(int)($input['max_runs_per_contact']??3)))
    ];
}
function sf_agent_action_normalize_commerce_followup(array $input):array {
    require_once __DIR__.'/agent-commerce-bridge.php';
    $id=strtoupper(trim((string)($input['order_id']??'')));
    $note=trim((string)($input['note']??''));
    $priority=(string)($input['priority']??'normal');
    $due=(string)($input['due_date']??'');
    if(!preg_match('/^SF-[A-Z0-9-]+$/D',$id)||str_starts_with($id,'SF-CERT-'))
        throw new InvalidArgumentException('A real customer order is required.');
    $order=sf_read_order($id);
    if(!$order||!sf_store_customer_order_visible($order))
        throw new InvalidArgumentException('Customer order could not be verified.');
    if($note===''||strlen($note)>600)
        throw new InvalidArgumentException('Note must be 1–600 bytes.');
    if(!in_array($priority,['normal','high'],true))
        throw new InvalidArgumentException('Unsupported priority.');
    if($due!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$due)
      ||date('Y-m-d',strtotime($due)?:0)!==$due))
        throw new InvalidArgumentException('Invalid due date.');
    return ['order_id'=>$id,'note'=>$note,'priority'=>$priority,'due_date'=>$due];
}
function sf_agent_action_normalize_stem_automation(array $input):array {
    require_once __DIR__.'/stem-agent-bridge.php';
    return sf_stem_agent_normalize_draft($input);
}
function sf_agent_action_hash(string $toolId,array $payload): string {
    return hash('sha256',$toolId.'|'.json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function sf_agent_action_public(array $row): array {
    return [
        'id'=>(string)$row['id'],'tool_id'=>(string)$row['tool_id'],'status'=>(string)$row['status'],
        'payload_hash'=>(string)$row['payload_hash'],'payload'=>json_decode((string)$row['payload_json'],true)?:[],
        'result'=>json_decode((string)$row['result_json'],true)?:[],
        'created_at'=>(string)$row['created_at'],'approved_at'=>$row['approved_at']??null,
    ];
}
function sf_agent_action_get(int $adminId,string $id): ?array {
    if($adminId<1||!preg_match('/^act_[a-f0-9]{32}$/D',$id))return null;
    sf_agent_actions_ensure_schema();
    $q=sf_db()->prepare('SELECT * FROM agent_action_proposals WHERE id=? AND admin_user_id=? LIMIT 1');
    $q->execute([$id,$adminId]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return $row?sf_agent_action_public($row):null;
}
function sf_agent_action_recent(int $adminId,int $limit=25): array {
    if($adminId<1)return [];
    sf_agent_actions_ensure_schema();
    $limit=max(1,min(50,$limit));
    $q=sf_db()->prepare('SELECT * FROM agent_action_proposals WHERE admin_user_id=? ORDER BY created_at DESC,id DESC LIMIT '.$limit);
    $q->execute([$adminId]);
    return array_map('sf_agent_action_public',$q->fetchAll(PDO::FETCH_ASSOC));
}

function sf_agent_action_prepare(int $adminId,string $requestKey,string $toolId,array $raw): array {
    if($adminId<1||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$requestKey))throw new InvalidArgumentException('Invalid request idempotency key.');
    if(!sf_agent_tool_executable($toolId,'admin',true))throw new InvalidArgumentException('Action is not available for governed preparation.');
    $payload=match($toolId){'stem_automation_update'=>sf_agent_action_normalize_stem_automation($raw),'automation_draft_save'=>sf_agent_action_normalize_automation($raw),'commerce_followup_add'=>sf_agent_action_normalize_commerce_followup($raw),default=>sf_agent_action_normalize_campaign($raw)};$hash=sf_agent_action_hash($toolId,$payload);
    $payloadJson=json_encode($payload,JSON_THROW_ON_ERROR);
    if(strlen($payloadJson)>131072)throw new InvalidArgumentException('Campaign proposal exceeds the permitted size.');
    sf_agent_actions_ensure_schema();$db=sf_db();
    $q=$db->prepare('SELECT id,payload_hash FROM agent_action_proposals WHERE admin_user_id=? AND request_key=?');
    $q->execute([$adminId,$requestKey]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing){
        if(!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('An existing request key has different content.');
        return sf_agent_action_get($adminId,(string)$existing['id']);
    }
    // Keep proposal storage bounded while preserving idempotent existing requests.
    $quota=$db->prepare('SELECT COUNT(*) FROM agent_action_proposals WHERE admin_user_id=? AND created_at>=?');
    $quota->execute([$adminId,gmdate('c',time()-3600)]);
    if((int)$quota->fetchColumn()>=40)throw new RuntimeException('Too many recent draft proposals; review the existing ones.');
    $id='act_'.bin2hex(random_bytes(16));$now=gmdate('c');
    try{
        $db->prepare('INSERT INTO agent_action_proposals(id,admin_user_id,request_key,tool_id,status,payload_json,payload_hash,result_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)')
          ->execute([$id,$adminId,$requestKey,$toolId,'proposed',$payloadJson,$hash, '{}',$now]);
    }catch(PDOException $e){
        $q->execute([$adminId,$requestKey]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if(!$existing||!hash_equals((string)$existing['payload_hash'],$hash))throw new RuntimeException('Proposal could not be created safely.');
        return sf_agent_action_get($adminId,(string)$existing['id']);
    }
    return sf_agent_action_get($adminId,$id);
}
function sf_agent_action_approve(int $adminId,string $id,string $expectedHash,string $typed): array {
    if($typed!=='APPROVE')throw new InvalidArgumentException('Type APPROVE to confirm this exact draft.');
    $proposal=sf_agent_action_get($adminId,$id);
    if(!$proposal)throw new InvalidArgumentException('Proposal not found.');
    if(!preg_match('/^[a-f0-9]{64}$/D',$expectedHash)||!hash_equals($proposal['payload_hash'],$expectedHash))
        throw new RuntimeException('The proposal changed; approval was rejected.');
    if($proposal['status']==='approved'||$proposal['status']==='completed')return $proposal;
    if($proposal['status']!=='proposed')throw new RuntimeException('This proposal requires administrator review.');
    if(time()-strtotime((string)$proposal['created_at'])>86400)throw new RuntimeException('This proposal expired; prepare a fresh review.');
    $q=sf_db()->prepare("UPDATE agent_action_proposals SET status='approved',approved_at=? WHERE id=? AND admin_user_id=? AND status='proposed'");
    $q->execute([gmdate('c'),$id,$adminId]);
    if($q->rowCount()!==1)throw new RuntimeException('Proposal state changed before approval; refresh and review.');
    $approved=sf_agent_action_get($adminId,$id);
    if(!$approved||$approved['status']!=='approved')throw new RuntimeException('Approval was not confirmed.');
    return $approved;
}
/**
 * Cancels only unexecuted drafts. A claimed operation cannot be cancelled
 * because it may already have produced an external side effect.
 */
function sf_agent_action_cancel(int $adminId,string $id,string $typed): array {
    if($typed!=='CANCEL')throw new InvalidArgumentException('Type CANCEL to cancel this pending proposal.');
    $p=sf_agent_action_get($adminId,$id);
    if(!$p)throw new InvalidArgumentException('Proposal not found.');
    if($p['status']==='cancelled')return $p;
    if(!in_array($p['status'],['proposed','approved'],true))throw new RuntimeException('The action may already have started; it cannot be cancelled automatically.');
    $q=sf_db()->prepare("UPDATE agent_action_proposals SET status='cancelled' WHERE id=? AND admin_user_id=? AND status IN ('proposed','approved')");
    $q->execute([$id,$adminId]);
    if($q->rowCount()!==1)throw new RuntimeException('The proposal state changed; refresh its status.');
    return sf_agent_action_get($adminId,$id);
}
/**
 * Manual review is a terminal acknowledgement, NOT a replay, rollback or
 * assertion that a draft was saved. A separate new proposal must be prepared.
 */
function sf_agent_action_reconcile(int $adminId,string $id,string $typed): array {
    if($typed!=='RECONCILED')throw new InvalidArgumentException('Type RECONCILED after inspecting Campaign Builder.');
    $p=sf_agent_action_get($adminId,$id);
    if(!$p)throw new InvalidArgumentException('Proposal not found.');
    if($p['status']==='reviewed_no_retry')return $p;
    if(!in_array($p['status'],['executing','review_required'],true))throw new RuntimeException('Only interrupted or uncertain actions can be reconciled.');
    // An executing request may still be running. Never race an active save.
    // Only previously approved work older than two hours can be reconciled in-place.
    if($p['status']==='executing'){
        $approvedAt=strtotime((string)($p['approved_at']??''));
        if($approvedAt===false || time()-$approvedAt<7200)throw new RuntimeException('This operation may still be running. Review it after the safety window.');
    }
    $q=sf_db()->prepare("UPDATE agent_action_proposals SET status='reviewed_no_retry' WHERE id=? AND admin_user_id=? AND status IN ('executing','review_required')");
    $q->execute([$id,$adminId]);
    if($q->rowCount()!==1)throw new RuntimeException('Proposal status changed; refresh its details.');
    return sf_agent_action_get($adminId,$id);
}

function sf_agent_action_execute(int $adminId,string $id): array {
    $p=sf_agent_action_get($adminId,$id);
    if(!$p)throw new InvalidArgumentException('Proposal not found.');
    if($p['status']==='completed')return $p;
    if($p['status']!=='approved')throw new RuntimeException('Proposal must be approved; interrupted work requires review.');
    if(!sf_agent_tool_executable($p['tool_id'],'admin',true))throw new RuntimeException('This tool cannot be executed here.');
    if(!hash_equals($p['payload_hash'],sf_agent_action_hash($p['tool_id'],(array)$p['payload'])))
        throw new RuntimeException('Proposal content no longer matches exact approval.');
    if(empty($p['approved_at'])||time()-strtotime((string)$p['approved_at'])>3600){
        sf_db()->prepare("UPDATE agent_action_proposals SET status='review_required' WHERE id=? AND admin_user_id=? AND status='approved'")->execute([$id,$adminId]);
        throw new RuntimeException('Approval expired; prepare a fresh review.');
    }
    $db=sf_db();
    $q=$db->prepare("UPDATE agent_action_proposals SET status='executing' WHERE id=? AND admin_user_id=? AND status='approved'");
    $q->execute([$id,$adminId]);
    if($q->rowCount()!==1)throw new RuntimeException('Another operation is handling this proposal.');
    try {
        // Re-validate from the immutable approved snapshot. Never publish or send.
        if($p['tool_id']==='stem_automation_update'){
            require_once __DIR__.'/stem-agent-bridge.php';
            $draft=sf_agent_action_normalize_stem_automation((array)$p['payload']);
            $saved=sf_stem_project_update($draft['track_id'],[
                'action'=>'automation_set_lane','revision'=>$draft['revision'],'lane'=>$draft['lane']]);
            $public=['track_id'=>$draft['track_id'],'revision'=>(int)$saved['revision'],
                'status'=>'automation_saved'];
        }elseif($p['tool_id']==='automation_draft_save'){
            $draft=sf_agent_action_normalize_automation((array)$p['payload']);
            $result=sf_automation_save($draft,$adminId);
            $public=['automation_id'=>(int)$result['id'],'status'=>'draft','name'=>(string)$result['name']];
        }elseif($p['tool_id']==='campaign_draft_save'){
            $campaign=sf_agent_action_normalize_campaign((array)$p['payload']);
            $result=sf_campaign_save($campaign,$adminId);
            $public=['campaign_id'=>(int)$result['id'],'status'=>'draft','name'=>(string)$result['name']];
        }elseif($p['tool_id']==='commerce_followup_add'){
            require_once __DIR__.'/commerce-operations.php';
            $draft=sf_agent_action_normalize_commerce_followup((array)$p['payload']);
            // Stable idempotency key across crash after effect, before receipt.
            $items=sf_commerce_ops_update($draft['order_id'],$adminId,'add_followup',[
                'request_key'=>'agent_'.$p['id'],'note'=>$draft['note'],
                'priority'=>$draft['priority'],'due_date'=>$draft['due_date']
            ]);
            $digest=hash('sha256',$draft['order_id'].'|'.'agent_'.$p['id']);
            $match=null;
            foreach($items as $item)if(($item['request_hash']??'')===$digest)$match=$item;
            if(!$match)throw new RuntimeException('Follow-up result receipt missing.');
            $public=['order_id'=>$draft['order_id'],'followup_id'=>(string)$match['id'],
                'status'=>(string)$match['status']];
        }else throw new RuntimeException('This tool cannot be executed here.');
        $finish=$db->prepare("UPDATE agent_action_proposals SET status='completed',result_json=?,executed_at=? WHERE id=? AND admin_user_id=? AND status='executing'");
        $finish->execute([json_encode($public,JSON_THROW_ON_ERROR),gmdate('c'),$id,$adminId]);
        if($finish->rowCount()!==1)throw new RuntimeException('Execution status changed before the result was recorded.');
    }catch(Throwable $e) {
        $db->prepare("UPDATE agent_action_proposals SET status='review_required',executed_at=? WHERE id=? AND admin_user_id=? AND status='executing'")
           ->execute([gmdate('c'),$id,$adminId]);
        throw new RuntimeException('Action did not complete with confirmed results. Review the destination Admin workspace before retrying.');
    }
    return sf_agent_action_get($adminId,$id);
}
