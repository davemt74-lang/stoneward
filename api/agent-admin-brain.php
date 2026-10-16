<?php
declare(strict_types=1);
require_once __DIR__.'/agent-orchestration.php';
require_once __DIR__.'/agent-mission-planner.php';
require_once __DIR__.'/agent-admin-intelligence.php';
require_once __DIR__.'/fan-discovery-paths.php';
require_once __DIR__.'/fan-journey-diagnostics.php';
require_once __DIR__.'/fan-journey-agent-bridge.php';
require_once __DIR__.'/fan-conversion-campaign-strategy.php';
require_once __DIR__.'/agent-proactive-research.php';
require_once __DIR__.'/agent-specialist-delegation.php';
require_once __DIR__.'/agent-feedback-core.php';
require_once __DIR__.'/agent-commerce-bridge.php';
require_once __DIR__.'/stem-agent-bridge.php';
require_once __DIR__.'/stem-production-advisor.php';
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
    // Current admin aggregates only. Customer identities never enter Agent facts.
    if(function_exists('sf_engagement_analytics')){
        try{
            $diagnostics=sf_fan_journey_diagnostics(
                sf_engagement_analytics(30,null),
                sf_fan_discovery_public(sf_catalog()),30);
            $summary['fan_journey_signals']=sf_fan_journey_agent_brief($diagnostics);
            $campaignStrategy=sf_fan_conversion_campaign_strategy($diagnostics,
                sf_fan_conversion_eligible_newsletter_count());
            $summary['fan_campaign_guidance']=sf_fan_conversion_agent_brief($campaignStrategy);
        }catch(Throwable $e){
            $summary['fan_journey_signals']=['source'=>'unavailable','metrics'=>[],
                'opportunity_codes'=>[],'transactions_or_changes_executed'=>false];
        }
    }
    $commerce=sf_agent_commerce_admin_snapshot();
    $summary['commerce_summary']=$commerce['summary'];
    $summary['commerce_attention']=$commerce['total_attention'];
    $summary['commerce_top_orders']=$commerce['attention_orders'];
    $summary['commerce_available']=$commerce['available'];
    $summary['commerce_triage']=(array)($commerce['triage']??[]);
    return $summary;
}
function sf_admin_agent_plan(string $message): array {
    $m=strtolower(trim($message));$type='research';$view='brain';
    if(preg_match('/\\b(?:stem|reaper|mix(?:ing)?|envelope|auxiliary bus|audio automation)\\b/',$m)){$type='stem_mix_review';$view='catalog';}
    elseif(preg_match('/\b(draft|create|prepare|build)\b.*\b(newsletter|campaign|discount|vip|offer)\b|\b(newsletter|campaign)\b.*\b(draft|create|prepare|build)\b/',$m)){$type='prepare_campaign';$view='campaigns';}
    elseif(preg_match('/\b(?:add|create|prepare)\b.*\b(?:follow[ -]?up|order note)\b/',$m)){$type='prepare_commerce_followup';$view='commerce-ops';}
    elseif(preg_match('/\b(draft|prepare|write)\b.*\b(reply|response)\b.*\border\b/',$m)){$type='draft_commerce_reply';$view='commerce-ops';}
    elseif(preg_match('/\b(draft|create|prepare|build)\b.*\b(journey|automation|follow.up|sequence)\b|\b(journey|automation)\b.*\b(draft|create|prepare|build)\b/',$m)){$type='prepare_journey';$view='automations';}
    elseif(preg_match('/\b(approval|approvals|proposals|action history|agent actions)\b/',$m)){$type='review_actions';$view='brain';}
    elseif(preg_match('/\b(orders?|commerce|stripe|refunds?|payments?|fulfill(?:ment)?|shipping|follow[ -]?ups?)\b/',$m)){$type='commerce_report';$view='commerce-ops';}
    elseif(preg_match('/\b(?:fan engagement|fan journeys?|listening trends?|song discovery|repeat listening|music conversion|song conversion|listening performance)\b/',$m)){$type='journey_report';$view='analytics';}
    elseif(preg_match('/\b(crm|fans|subscribers|audience)\b/',$m)){$type='crm_report';$view='crm';}
    elseif(preg_match('/\b(conversion|engagement|analytics|performance|journey)\b/',$m)){$type='journey_report';$view='analytics';}
    return ['type'=>$type,'view'=>$view,'requires_confirmation'=>in_array($type,['prepare_campaign','prepare_journey','prepare_commerce_followup'],true),'execution'=>'proposal_or_navigation_only'];
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
    foreach(array_slice(sf_ai_llm_candidates('agent_brain'),0,2) as $provider){
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
        'commerce'=>sf_agent_commerce_admin_snapshot(),
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
      'stem_mix_review'=>'I can inspect real private Stem Studio track, bus, send, FX, automation and mastering metadata, compare bounded reference evidence, recommend specific adjustments and prepare exact reviewed changes. Provide a song ID. Native VST audio is not independently recreated and no mix is changed automatically.',
      'prepare_campaign'=>'I can prepare a campaign in the existing Campaign Builder. You must inspect its complete draft, approve the exact hash, and separately authorize saving. Nothing will be sent or published.',
      'prepare_journey'=>'I can prepare an unpublished journey in the existing Automation Builder. You must inspect the steps and consent rules, approve the exact hash, then separately authorize saving.',
      'prepare_commerce_followup'=>'I can prepare a customer-order follow-up for exact Admin approval. Use: Create follow-up for order SF-ORDER-ID: your note. The action only saves a note; it never refunds, charges, emails, or changes shipping.',
      'draft_commerce_reply'=>'To draft an unsent customer-service response, type: Draft reply for order SF-ORDER-ID about shipping, refunds, payment, or digital access. A human must inspect and send any reply manually.',
      'commerce_report'=>(empty($context['commerce_available'])?'Commerce data is temporarily unavailable. Open Commerce Operations to inspect current orders.':'Commerce queue: '.(int)($context['commerce_attention']??0).' customer orders need attention, including '.(int)($context['commerce_summary']['payment_attention']??0).' payment exceptions, '.(int)($context['commerce_summary']['refund_attention']??0).' pending refunds and '.(int)($context['commerce_summary']['fulfillment_pending']??0).' awaiting fulfillment. Open Commerce Operations to review each order.'),
      'review_actions'=>'You have '.array_sum($missionState['counts']).' recent Agent missions, including '.(int)($missionState['counts']['review_required']??0).' requiring review and '.(int)($missionState['counts']['completed']??0).' completed. Open Agent actions for exact proposals, approved results, and interrupted work. Never automatically retry uncertain operations.',
      'crm_report'=>'Current CRM summary: '.$context['crm_contacts'].' contacts and '.$context['newsletter_opted_in'].' opted-in newsletter subscribers. Open CRM for eligible audience and campaign review.',
      'journey_report'=>sf_fan_journey_agent_message((array)($context['fan_journey_signals']??[])).' Existing lifecycle automation runs in 30 days: '.(int)($context['journey_counts']['journeys_started']??0).' started, '.(int)($context['journey_counts']['journeys_failed']??0).' failed.',
      'research'=>'I can help investigate Stonefellow campaigns, CRM aggregates, fan journeys, and operational actions. Specify an area to inspect; no private customer conversations are shared with Admin.'
    ];
    $text=$types[$plan['type']];
    if(in_array($plan['type'],['prepare_campaign','crm_report'],true)
       &&isset($context['fan_campaign_guidance']))
        $text.=' '.sf_fan_conversion_agent_copy((array)$context['fan_campaign_guidance']);
    $contract=sf_agent_orchestration_contract('admin',$plan['type']);
    if($plan['requires_confirmation']&&!$contract['may_prepare_reviewed_proposal'])throw new RuntimeException('The reviewed action is not registered.');
    if($plan['type']==='prepare_commerce_followup'){
        $draft=sf_agent_commerce_parse_followup($message);
        if($draft===null)$text='To prepare a reviewed follow-up, type: Create follow-up for order SF-ORDER-ID: the exact note. No customer record has been changed.';
        else {
            $order=sf_read_order($draft['order_id']);
            if(!$order||!sf_store_customer_order_visible($order)){
                $text='That customer order was not found. No follow-up was prepared.';
                $draft=null;
            }else $text='Prepared the following follow-up for review: '.$draft['order_id'].' — '.$draft['note'].'. Inspect the exact proposal and approve it separately before saving.';
        }
    }
    if($plan['type']==='draft_commerce_reply'){
        $request=sf_agent_commerce_reply_request($message);
        if($request===null){
            $text='Use: Draft reply for order SF-ORDER-ID about shipping, refund, payment, or digital access. No response was created or sent.';
        }else{
            $target=sf_read_order($request['order_id']);
            if(!$target||!sf_store_customer_order_visible($target)){
                $text='Customer order not found. No reply was created or sent.';
            }else{
                $replyDraft=sf_agent_commerce_reply_draft($target,$request['topic']);
                $text='Unsent customer-service draft for '.$replyDraft['order_id'].' ('.
                    $replyDraft['topic']."):\n\n".$replyDraft['text'].
                    "\n\nReview the exact order in Commerce Operations before sending anything. Nothing has been emailed or saved to the customer record.";
            }
        }
    }
    if($plan['type']==='stem_mix_review'){
        if(preg_match('/\bsong\s+(?:id\s+)?([A-Za-z0-9._-]{1,100})\b/i',$message,$m)){
            try{
                $stemReview=sf_stem_agent_review($m[1]);
                $production=sf_stem_production_inspect($m[1]);
                if(($production['status']??'')!=='inspected'){
                    $text='Import a private REAPER Stem Studio project for that song before requesting production analysis.';
                }else{
                    $brief=sf_stem_production_brief($production);
                    $issues=(array)($brief['issues']??[]);
                    $lines=[];
                    foreach(array_slice($issues,0,7) as $item)
                        $lines[]=strtoupper((string)$item['priority']).': '.(string)$item['evidence'].
                            '. '.(string)$item['recommendation'];
                    $text='Stonefellow Admin Agent Brain production review · song '.$production['song_id'].
                        ' · revision '.$production['revision'].'. '.
                        (int)$brief['track_count'].' tracks, '.(int)$brief['bus_count'].' buses, '.
                        (int)$brief['send_count'].' sends. '.
                        'Mastering evidence: '.(string)($brief['mastering']['status']??'not_analyzed').
                        '; release readiness: '.(string)$brief['readiness_status'].
                        '; catalog original: '.(string)$brief['catalog_state']."\n".
                        ($lines?implode("\n",$lines):'No specific issue identified from saved metadata; verify the audio by listening.').
                        "\n".count((array)$brief['proposals']).
                        ' candidate edit(s) may be prepared ONLY through the existing Stem Studio reviewed action panel. No change was executed.';
                    // Only bounded redacted facts enter the owner's configured
                    // Admin LLM provider. Its narrative cannot cause an action.
                    $narrative=sf_stem_production_model_explain($production,$message);
                    if($narrative!==null)$text.="\n\nModel explanation (advisory, unverified): ".$narrative;
                }
            }catch(InvalidArgumentException){
                $text='That song ID is not in the private Stonefellow catalog. No file was opened.';
            }
        }
    }
        if($plan['type']==='research'){
        $research=sf_admin_agent_research_reply($message,$context,$prior,$adminId);
        if($research!==null)$text=$research;
    }
    $action=['type'=>$plan['type'],'view'=>$plan['view'],'requires_confirmation'=>$contract['requires_exact_approval'],'tool_contract'=>$contract,'mission_plan'=>$missionPlan,'insights'=>$brief];
    if($plan['type']==='stem_mix_review'&&!empty($stemReview))$action['stem_review']=$stemReview;
    if($plan['type']==='stem_mix_review'&&!empty($production))$action['production_brief']=sf_stem_production_brief($production);
    if($plan['type']==='draft_commerce_reply' && !empty($replyDraft)){
        $action['reply_draft']=$replyDraft;
        $action['review_required']=true;
    }
    if($plan['type']==='prepare_commerce_followup' && !empty($draft) && $contract['may_prepare_reviewed_proposal']){
        $action['draft']=$draft;
        $action['request_key']='commerce_'.bin2hex(random_bytes(16));
    }
    sf_admin_agent_save_message($adminId,$threadId,'assistant',$text,$action);
    return ['conversation_id'=>$threadId,'text'=>$text,'action'=>$action,'context'=>$context,'missions'=>$missionState['missions'],'mission_plan'=>$missionPlan,'insights'=>$brief,'brain'=>['scope'=>'admin','owner_id'=>$adminId,'tool_decision'=>$plan['type'],'status'=>'review_or_read_only']];
}
