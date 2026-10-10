<?php
declare(strict_types=1);

function sf_auto_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}'; }
function sf_auto_decode(mixed $v,array $fallback=[]): array { $d=is_array($v)?$v:json_decode((string)$v,true); return is_array($d)?$d:$fallback; }

function sf_automation_ensure_schema(): void {
    static $done=false;if($done)return;
    sf_crm_ensure_schema();sf_campaign_ensure_schema();
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automations (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',trigger_type TEXT NOT NULL DEFAULT 'manual',trigger_json TEXT NOT NULL DEFAULT '{}',segment_id INTEGER NULL,steps_json TEXT NOT NULL DEFAULT '[]',cooldown_hours INTEGER NOT NULL DEFAULT 24,max_runs_per_contact INTEGER NOT NULL DEFAULT 10,last_scheduled_at TEXT NULL,created_by INTEGER NULL,published_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(segment_id) REFERENCES campaign_segments(id) ON DELETE SET NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lifecycle_automations_status_trigger ON lifecycle_automations(status,trigger_type,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automation_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,automation_id INTEGER NOT NULL,contact_id INTEGER NOT NULL,trigger_event_id INTEGER NULL,status TEXT NOT NULL DEFAULT 'running',current_step INTEGER NOT NULL DEFAULT 0,due_at TEXT NULL,dedupe_key TEXT NOT NULL UNIQUE,details_json TEXT NOT NULL DEFAULT '{}',started_at TEXT NOT NULL,completed_at TEXT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(automation_id) REFERENCES lifecycle_automations(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lifecycle_runs_due ON lifecycle_automation_runs(status,due_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lifecycle_runs_contact ON lifecycle_automation_runs(contact_id,automation_id,started_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automation_events (id INTEGER PRIMARY KEY AUTOINCREMENT,automation_id INTEGER NOT NULL,run_id INTEGER NULL,contact_id INTEGER NOT NULL,event_type TEXT NOT NULL,step_index INTEGER NOT NULL DEFAULT -1,details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(automation_id) REFERENCES lifecycle_automations(id) ON DELETE CASCADE,FOREIGN KEY(run_id) REFERENCES lifecycle_automation_runs(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lifecycle_events_automation ON lifecycle_automation_events(automation_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_segment_memberships (segment_id INTEGER NOT NULL,contact_id INTEGER NOT NULL,matched INTEGER NOT NULL DEFAULT 0,first_matched_at TEXT NULL,last_matched_at TEXT NULL,last_evaluated_at TEXT NOT NULL,exited_at TEXT NULL,PRIMARY KEY(segment_id,contact_id),FOREIGN KEY(segment_id) REFERENCES campaign_segments(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lifecycle_segment_match ON lifecycle_segment_memberships(segment_id,matched,last_evaluated_at)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'draft',trigger_type VARCHAR(40) NOT NULL DEFAULT 'manual',trigger_json LONGTEXT NOT NULL,segment_id BIGINT UNSIGNED NULL,steps_json LONGTEXT NOT NULL,cooldown_hours INT NOT NULL DEFAULT 24,max_runs_per_contact INT NOT NULL DEFAULT 10,last_scheduled_at VARCHAR(40) NULL,created_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_lifecycle_automations_status_trigger(status,trigger_type,updated_at),CONSTRAINT fk_lifecycle_segment FOREIGN KEY(segment_id) REFERENCES campaign_segments(id) ON DELETE SET NULL,CONSTRAINT fk_lifecycle_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automation_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,automation_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,trigger_event_id BIGINT UNSIGNED NULL,status VARCHAR(32) NOT NULL DEFAULT 'running',current_step INT NOT NULL DEFAULT 0,due_at VARCHAR(40) NULL,dedupe_key VARCHAR(220) NOT NULL UNIQUE,details_json LONGTEXT NOT NULL,started_at VARCHAR(40) NOT NULL,completed_at VARCHAR(40) NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_lifecycle_runs_due(status,due_at),INDEX idx_lifecycle_runs_contact(contact_id,automation_id,started_at),CONSTRAINT fk_lifecycle_run_automation FOREIGN KEY(automation_id) REFERENCES lifecycle_automations(id) ON DELETE CASCADE,CONSTRAINT fk_lifecycle_run_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_automation_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,automation_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(80) NOT NULL,step_index INT NOT NULL DEFAULT -1,details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_lifecycle_events_automation(automation_id,created_at),CONSTRAINT fk_lifecycle_event_automation FOREIGN KEY(automation_id) REFERENCES lifecycle_automations(id) ON DELETE CASCADE,CONSTRAINT fk_lifecycle_event_run FOREIGN KEY(run_id) REFERENCES lifecycle_automation_runs(id) ON DELETE CASCADE,CONSTRAINT fk_lifecycle_event_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lifecycle_segment_memberships (segment_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,matched TINYINT(1) NOT NULL DEFAULT 0,first_matched_at VARCHAR(40) NULL,last_matched_at VARCHAR(40) NULL,last_evaluated_at VARCHAR(40) NOT NULL,exited_at VARCHAR(40) NULL,PRIMARY KEY(segment_id,contact_id),INDEX idx_lifecycle_segment_match(segment_id,matched,last_evaluated_at),CONSTRAINT fk_lifecycle_membership_segment FOREIGN KEY(segment_id) REFERENCES campaign_segments(id) ON DELETE CASCADE,CONSTRAINT fk_lifecycle_membership_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}

function sf_segment_rules_normalize(array $raw): array {
    $newsletter=(string)($raw['newsletter']??(!empty($raw['newsletter_only'])?'subscribed':'any'));
    if(!in_array($newsletter,['any','subscribed','unsubscribed'],true))$newsletter='any';
    $account=(string)($raw['account']??(!empty($raw['linked_accounts_only'])?'linked':'any'));
    if(!in_array($account,['any','linked','unlinked'],true))$account='any';
    $auto=(string)($raw['agent_auto_engage']??'any');if(!in_array($auto,['any','enabled','disabled'],true))$auto='any';
    $list=function($v,int $max=80){return array_values(array_unique(array_filter(array_map(fn($x)=>sf_clean_text($x,$max),is_array($v)?$v:preg_split('/\s*,\s*/',(string)$v)?:[]))));};
    return [
        'newsletter'=>$newsletter,'account'=>$account,'agent_auto_engage'=>$auto,
        'stages'=>$list($raw['stages']??[],40),'tags_all'=>$list($raw['tags_all']??($raw['tags']??[]),60),'tags_any'=>$list($raw['tags_any']??[],60),
        'purchase_required'=>!empty($raw['purchase_required']),'min_orders'=>max(0,(int)($raw['min_orders']??0)),'min_spend_cents'=>max(0,(int)($raw['min_spend_cents']??0)),
        'product_ids_any'=>$list($raw['product_ids_any']??[],120),'campaign_ids_any'=>array_values(array_unique(array_filter(array_map('intval',(array)($raw['campaign_ids_any']??[])),fn($x)=>$x>0))),
        'event_types_any'=>$list($raw['event_types_any']??[],80),'activity_within_days'=>max(0,(int)($raw['activity_within_days']??0)),'inactive_for_days'=>max(0,(int)($raw['inactive_for_days']??0))
    ];
}

function sf_segment_contact_metrics(array $contact): array {
    $cid=(int)$contact['id'];$uid=(int)($contact['user_id']??0);$email=(string)($contact['email']??'');
    $purchases=function_exists('sf_commerce_purchase_history')?sf_commerce_purchase_history($uid?:null,$email,200):[];
    $orders=[];$spend=0;$products=[];$lastPurchase='';
    foreach($purchases as $p){$oid=(string)($p['order_id']??'');if($oid!=='')$orders[$oid]=true;$spend+=(int)($p['subtotal_cents']??0);$pid=(string)($p['product_id']??'');if($pid!=='')$products[$pid]=true;$at=(string)($p['created_at']??'');if($at>$lastPurchase)$lastPurchase=$at;}
    $q=sf_db()->prepare('SELECT event_type,created_at FROM fan_crm_events WHERE contact_id=? ORDER BY id DESC LIMIT 500');$q->execute([$cid]);$events=[];$lastEvent='';
    foreach($q->fetchAll() as $e){$events[(string)$e['event_type']]=true;$at=(string)$e['created_at'];if($at>$lastEvent)$lastEvent=$at;}
    $campaigns=[];try{$q=sf_db()->prepare('SELECT DISTINCT campaign_id FROM campaign_participants WHERE contact_id=?');$q->execute([$cid]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $x)$campaigns[(int)$x]=true;}catch(Throwable $e){}
    $lastActivity=max((string)($contact['last_engaged_at']??''),$lastEvent,$lastPurchase,(string)($contact['updated_at']??''));
    return ['order_count'=>count($orders),'spend_cents'=>$spend,'product_ids'=>array_keys($products),'last_purchase_at'=>$lastPurchase,'event_types'=>array_keys($events),'campaign_ids'=>array_keys($campaigns),'last_activity_at'=>$lastActivity];
}

function sf_segment_contact_matches(array $contact,array $rules,?array $metrics=null): bool {
    $r=sf_segment_rules_normalize($rules);$metrics=$metrics??sf_segment_contact_metrics($contact);
    if($r['newsletter']==='subscribed'&&empty($contact['marketing_opt_in']))return false;
    if($r['newsletter']==='unsubscribed'&&!empty($contact['marketing_opt_in']))return false;
    if($r['account']==='linked'&&empty($contact['user_id']))return false;
    if($r['account']==='unlinked'&&!empty($contact['user_id']))return false;
    if($r['agent_auto_engage']==='enabled'&&empty($contact['agent_auto_engage']))return false;
    if($r['agent_auto_engage']==='disabled'&&!empty($contact['agent_auto_engage']))return false;
    if($r['stages']&&!in_array((string)$contact['status'],$r['stages'],true))return false;
    $own=array_map('strtolower',sf_auto_decode($contact['tags_json']??'[]',[]));$all=array_map('strtolower',$r['tags_all']);$any=array_map('strtolower',$r['tags_any']);
    if($all&&array_diff($all,$own))return false;if($any&&!array_intersect($any,$own))return false;
    if($r['purchase_required']&&(int)$metrics['order_count']<1)return false;
    if((int)$metrics['order_count']<$r['min_orders'])return false;
    if((int)$metrics['spend_cents']<$r['min_spend_cents'])return false;
    if($r['product_ids_any']&&!array_intersect($r['product_ids_any'],(array)$metrics['product_ids']))return false;
    if($r['campaign_ids_any']&&!array_intersect($r['campaign_ids_any'],(array)$metrics['campaign_ids']))return false;
    if($r['event_types_any']&&!array_intersect($r['event_types_any'],(array)$metrics['event_types']))return false;
    $last=(string)($metrics['last_activity_at']??'');$lastTs=$last!==''?strtotime($last):false;
    if($r['activity_within_days']>0&&(!$lastTs||$lastTs<time()-$r['activity_within_days']*86400))return false;
    if($r['inactive_for_days']>0&&$lastTs&&$lastTs>time()-$r['inactive_for_days']*86400)return false;
    return true;
}

function sf_segment_get(int $id): ?array {
    sf_automation_ensure_schema();$q=sf_db()->prepare('SELECT * FROM campaign_segments WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)return null;$r['rules']=sf_segment_rules_normalize(sf_auto_decode($r['rules_json']??'',[]));return $r;
}
function sf_segment_contacts(int $segmentId,int $limit=5000): array {
    $s=sf_segment_get($segmentId);if(!$s)return [];$rows=sf_db()->query('SELECT * FROM fan_contacts ORDER BY id')->fetchAll();$out=[];foreach($rows as $c){if(sf_segment_contact_matches($c,$s['rules']))$out[]=$c;if(count($out)>=max(1,min(5000,$limit)))break;}return $out;
}
function sf_segment_list(): array {
    sf_automation_ensure_schema();$rows=sf_db()->query('SELECT * FROM campaign_segments ORDER BY updated_at DESC,id DESC LIMIT 300')->fetchAll();
    foreach($rows as &$s){$s['rules']=sf_segment_rules_normalize(sf_auto_decode($s['rules_json']??'',[]));$q=sf_db()->prepare('SELECT COUNT(*) FROM lifecycle_segment_memberships WHERE segment_id=? AND matched=1');$q->execute([(int)$s['id']]);$s['member_count']=(int)$q->fetchColumn();}unset($s);return $rows;
}
function sf_segment_save(array $raw,int $adminId): array {
    sf_automation_ensure_schema();$id=max(0,(int)($raw['id']??0));$name=sf_clean_text($raw['name']??'',180);if($name==='')throw new InvalidArgumentException('Segment name is required.');$rules=sf_segment_rules_normalize(is_array($raw['rules']??null)?$raw['rules']:[]);$now=gmdate('c');
    if($id){$q=sf_db()->prepare('UPDATE campaign_segments SET name=?,rules_json=?,updated_at=? WHERE id=?');$q->execute([$name,sf_auto_json($rules),$now,$id]);if(!$q->rowCount()&&!sf_segment_get($id))throw new InvalidArgumentException('Segment not found.');}
    else{$q=sf_db()->prepare('INSERT INTO campaign_segments(name,rules_json,created_by,created_at,updated_at) VALUES(?,?,?,?,?)');$q->execute([$name,sf_auto_json($rules),$adminId?:null,$now,$now]);$id=(int)sf_db()->lastInsertId();}
    sf_segment_refresh($id);return sf_segment_get($id)?:[];
}

function sf_automation_event(int $automationId,?int $runId,int $contactId,string $type,int $step=-1,array $details=[]): int {
    sf_automation_ensure_schema();$q=sf_db()->prepare('INSERT INTO lifecycle_automation_events(automation_id,run_id,contact_id,event_type,step_index,details_json,created_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$automationId,$runId,$contactId,sf_clean_text($type,80),$step,sf_auto_json($details),gmdate('c')]);return (int)sf_db()->lastInsertId();
}
function sf_automation_trigger_segment_transition(int $segmentId,int $contactId,string $triggerType,string $transitionKey): int {
    sf_automation_ensure_schema();$q=sf_db()->prepare("SELECT id FROM lifecycle_automations WHERE status='published' AND trigger_type=? AND segment_id=?");$q->execute([$triggerType,$segmentId]);$started=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $aid){if(sf_automation_start((int)$aid,$contactId,0,$triggerType.':'.$segmentId.':'.$transitionKey))$started++;}return $started;
}
function sf_segment_refresh(int $segmentId,int $onlyContactId=0): array {
    sf_automation_ensure_schema();$s=sf_segment_get($segmentId);if(!$s)throw new InvalidArgumentException('Segment not found.');$now=gmdate('c');$sql='SELECT * FROM fan_contacts'.($onlyContactId?' WHERE id=?':'').' ORDER BY id';$q=sf_db()->prepare($sql);$q->execute($onlyContactId?[$onlyContactId]:[]);$entered=0;$exited=0;$matched=0;$evaluated=0;
    foreach($q->fetchAll() as $c){$cid=(int)$c['id'];$ok=sf_segment_contact_matches($c,$s['rules']);$m=sf_db()->prepare('SELECT * FROM lifecycle_segment_memberships WHERE segment_id=? AND contact_id=?');$m->execute([$segmentId,$cid]);$old=$m->fetch();$was=$old&&!empty($old['matched']);$evaluated++;if($ok)$matched++;
        if(!$old){sf_db()->prepare('INSERT INTO lifecycle_segment_memberships(segment_id,contact_id,matched,first_matched_at,last_matched_at,last_evaluated_at,exited_at) VALUES(?,?,?,?,?,?,?)')->execute([$segmentId,$cid,$ok?1:0,$ok?$now:null,$ok?$now:null,$now,null]);}
        else{$first=$old['first_matched_at']??null;if($ok&&empty($first))$first=$now;$lastMatched=$ok?$now:($old['last_matched_at']??null);$exited=(!$ok&&$was)?$now:($ok?null:($old['exited_at']??null));sf_db()->prepare('UPDATE lifecycle_segment_memberships SET matched=?,first_matched_at=?,last_matched_at=?,last_evaluated_at=?,exited_at=? WHERE segment_id=? AND contact_id=?')->execute([$ok?1:0,$first,$lastMatched,$now,$exited,$segmentId,$cid]);}
        if($ok&&!$was){$entered++;sf_automation_trigger_segment_transition($segmentId,$cid,'segment_enter',$now.':'.$cid);}
        elseif(!$ok&&$was){$exited++;sf_automation_trigger_segment_transition($segmentId,$cid,'segment_exit',$now.':'.$cid);}
    }
    return ['segment_id'=>$segmentId,'evaluated'=>$evaluated,'matched'=>$matched,'entered'=>$entered,'exited'=>$exited];
}
function sf_segment_refresh_all(int $onlyContactId=0): array {
    sf_automation_ensure_schema();$results=[];foreach(sf_db()->query('SELECT id FROM campaign_segments ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $sid)$results[]=sf_segment_refresh((int)$sid,$onlyContactId);return $results;
}

function sf_automation_steps_validate(array $steps): array {
    $allowed=['add_tag','remove_tag','set_stage','agent_message','email','enroll_campaign','wait','exit'];$out=[];$errors=[];if(count($steps)>30)$errors[]='An automation may contain at most 30 steps.';
    foreach(array_slice($steps,0,30) as $i=>$raw){if(!is_array($raw))continue;$type=(string)($raw['type']??'');if(!in_array($type,$allowed,true)){$errors[]='Unsupported step at '.($i+1).'.';continue;}$cfg=is_array($raw['config']??null)?$raw['config']:[];
        if(in_array($type,['add_tag','remove_tag'],true)&&sf_clean_text($cfg['tag']??'',60)==='')$errors[]='Tag step '.($i+1).' needs a tag.';
        if($type==='set_stage'&&!in_array((string)($cfg['stage']??''),['lead','fan','customer','member','inactive'],true))$errors[]='Stage step '.($i+1).' is invalid.';
        if($type==='agent_message'&&sf_clean_text($cfg['message']??'',1200)==='')$errors[]='Agent message step '.($i+1).' needs a message.';
        if($type==='email'&&(sf_clean_text($cfg['subject']??'',255)===''||sf_clean_text($cfg['body']??'',20000)===''))$errors[]='Email step '.($i+1).' needs subject and body.';
        if($type==='enroll_campaign'&&(int)($cfg['campaign_id']??0)<1)$errors[]='Campaign step '.($i+1).' needs a campaign.';
        if($type==='wait')$cfg['hours']=max(1,min(8760,(int)($cfg['hours']??24)));
        $out[]=['type'=>$type,'config'=>$cfg];
    }
    if(!$out)$errors[]='Add at least one automation step.';return ['ok'=>!$errors,'errors'=>$errors,'steps'=>$out];
}
function sf_automation_get(int $id): ?array {
    sf_automation_ensure_schema();$q=sf_db()->prepare('SELECT * FROM lifecycle_automations WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)return null;$r['trigger']=sf_auto_decode($r['trigger_json']??'',[]);$r['steps']=sf_auto_decode($r['steps_json']??'',[]);return $r;
}
function sf_automation_list(int $limit=300): array {
    sf_automation_ensure_schema();$sql='SELECT a.*,s.name segment_name,(SELECT COUNT(*) FROM lifecycle_automation_runs r WHERE r.automation_id=a.id) run_count,(SELECT COUNT(*) FROM lifecycle_automation_runs r WHERE r.automation_id=a.id AND r.status="completed") completed_count FROM lifecycle_automations a LEFT JOIN campaign_segments s ON s.id=a.segment_id ORDER BY a.updated_at DESC,a.id DESC LIMIT '.max(1,min(500,$limit));$rows=sf_db()->query($sql)->fetchAll();foreach($rows as &$r){$r['trigger']=sf_auto_decode($r['trigger_json']??'',[]);$r['steps']=sf_auto_decode($r['steps_json']??'',[]);}unset($r);return $rows;
}
function sf_automation_save(array $raw,int $adminId): array {
    sf_automation_ensure_schema();$id=max(0,(int)($raw['id']??0));$name=sf_clean_text($raw['name']??'',180);if($name==='')throw new InvalidArgumentException('Automation name is required.');$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','published','paused'],true))$status='draft';$triggerType=(string)($raw['trigger_type']??'manual');if(!in_array($triggerType,['manual','crm_event','segment_enter','segment_exit','scheduled'],true))throw new InvalidArgumentException('Invalid automation trigger.');$trigger=is_array($raw['trigger']??null)?$raw['trigger']:[];$segmentId=max(0,(int)($raw['segment_id']??0))?:null;if(in_array($triggerType,['segment_enter','segment_exit'],true)&&!$segmentId)throw new InvalidArgumentException('Segment enter/exit triggers require a segment.');$vr=sf_automation_steps_validate((array)($raw['steps']??[]));if(!$vr['ok'])throw new InvalidArgumentException(implode(' ',$vr['errors']));$cool=max(0,min(8760,(int)($raw['cooldown_hours']??24)));$maxRuns=max(1,min(1000,(int)($raw['max_runs_per_contact']??10)));$now=gmdate('c');$publishedAt=$status==='published'?$now:null;
    if($id){$old=sf_automation_get($id);if(!$old)throw new InvalidArgumentException('Automation not found.');$publishedAt=$status==='published'?($old['published_at']?:$now):$old['published_at'];$q=sf_db()->prepare('UPDATE lifecycle_automations SET name=?,status=?,trigger_type=?,trigger_json=?,segment_id=?,steps_json=?,cooldown_hours=?,max_runs_per_contact=?,published_at=?,updated_at=? WHERE id=?');$q->execute([$name,$status,$triggerType,sf_auto_json($trigger),$segmentId,sf_auto_json($vr['steps']),$cool,$maxRuns,$publishedAt,$now,$id]);}
    else{$q=sf_db()->prepare('INSERT INTO lifecycle_automations(name,status,trigger_type,trigger_json,segment_id,steps_json,cooldown_hours,max_runs_per_contact,last_scheduled_at,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NULL,?,?,?,?)');$q->execute([$name,$status,$triggerType,sf_auto_json($trigger),$segmentId,sf_auto_json($vr['steps']),$cool,$maxRuns,$adminId?:null,$publishedAt,$now,$now]);$id=(int)sf_db()->lastInsertId();}
    return sf_automation_get($id)?:[];
}
function sf_automation_delete(int $id): bool {$q=sf_db()->prepare('DELETE FROM lifecycle_automations WHERE id=?');$q->execute([$id]);return $q->rowCount()>0;}

function sf_automation_run_allowed(array $automation,array $contact,bool $force=false): bool {
    if($force)return true;if(($automation['status']??'')!=='published')return false;$aid=(int)$automation['id'];$cid=(int)$contact['id'];$q=sf_db()->prepare('SELECT COUNT(*) FROM lifecycle_automation_runs WHERE automation_id=? AND contact_id=?');$q->execute([$aid,$cid]);if((int)$q->fetchColumn()>=(int)$automation['max_runs_per_contact'])return false;$cool=(int)$automation['cooldown_hours'];if($cool>0){$q=sf_db()->prepare('SELECT started_at FROM lifecycle_automation_runs WHERE automation_id=? AND contact_id=? ORDER BY id DESC LIMIT 1');$q->execute([$aid,$cid]);$last=(string)($q->fetchColumn()?:'');if($last!==''&&strtotime($last)>time()-$cool*3600)return false;}if(!empty($automation['segment_id'])&&($automation['trigger_type']??'')!=='segment_exit'){ $segment=sf_segment_get((int)$automation['segment_id']); if(!$segment||!sf_segment_contact_matches($contact,(array)$segment['rules']))return false; }return true;
}
function sf_automation_start(int $automationId,int $contactId,int $triggerEventId=0,string $triggerKey='',bool $force=false): ?array {
    sf_automation_ensure_schema();$a=sf_automation_get($automationId);$c=sf_crm_contact_by_id($contactId);if(!$a||!$c||!sf_automation_run_allowed($a,$c,$force))return null;$key='auto:'.$automationId.':'.$contactId.':'.($triggerKey!==''?$triggerKey:bin2hex(random_bytes(8)));$now=gmdate('c');
    try{$q=sf_db()->prepare('INSERT INTO lifecycle_automation_runs(automation_id,contact_id,trigger_event_id,status,current_step,due_at,dedupe_key,details_json,started_at,completed_at,updated_at) VALUES(?,?,?,"running",0,NULL,?,? ,?,NULL,?)');$q->execute([$automationId,$contactId,$triggerEventId?:null,$key,sf_auto_json(['trigger_key'=>$triggerKey]),$now,$now]);}catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))return null;throw $e;}
    $runId=(int)sf_db()->lastInsertId();sf_automation_event($automationId,$runId,$contactId,'run_started',-1,['trigger_type'=>$a['trigger_type'],'trigger_event_id'=>$triggerEventId]);return sf_automation_continue($runId);
}
function sf_automation_tags(array $contact): array {return array_values(array_unique(array_filter(array_map(fn($x)=>sf_clean_text($x,60),sf_auto_decode($contact['tags_json']??'[]',[])))));}
function sf_automation_log_crm(int $contactId,?int $userId,string $eventType,string $title,string $entityType,string $entityId,array $meta=[]): void {
    $old=$GLOBALS['sf_automation_suppressed']??false;$GLOBALS['sf_automation_suppressed']=true;try{sf_crm_log_event($contactId,$userId,$eventType,$title,$entityType,$entityId,$meta);}finally{$GLOBALS['sf_automation_suppressed']=$old;}
}
function sf_automation_continue(int $runId): ?array {
    sf_automation_ensure_schema();$q=sf_db()->prepare('SELECT * FROM lifecycle_automation_runs WHERE id=?');$q->execute([$runId]);$run=$q->fetch();if(!$run)return null;if(in_array((string)$run['status'],['completed','failed','canceled'],true))return $run;$a=sf_automation_get((int)$run['automation_id']);$contact=sf_crm_contact_by_id((int)$run['contact_id']);if(!$a||!$contact)return null;$steps=(array)$a['steps'];$start=max(0,(int)$run['current_step']);$uid=!empty($contact['user_id'])?(int)$contact['user_id']:null;
    try{
        for($i=$start;$i<count($steps);$i++){$step=$steps[$i];$type=(string)$step['type'];$cfg=(array)($step['config']??[]);$details=[];
            if($type==='wait'){$hours=max(1,min(8760,(int)($cfg['hours']??24)));$due=gmdate('c',time()+$hours*3600);sf_db()->prepare('UPDATE lifecycle_automation_runs SET status="waiting",current_step=?,due_at=?,updated_at=? WHERE id=?')->execute([$i+1,$due,gmdate('c'),$runId]);sf_automation_event((int)$a['id'],$runId,(int)$contact['id'],'waiting',$i,['hours'=>$hours,'due_at'=>$due]);return sf_automation_run($runId);}
            if($type==='add_tag'||$type==='remove_tag'){$tag=sf_clean_text($cfg['tag']??'',60);$tags=sf_automation_tags($contact);if($type==='add_tag'&&!in_array($tag,$tags,true))$tags[]=$tag;if($type==='remove_tag')$tags=array_values(array_filter($tags,fn($x)=>strcasecmp($x,$tag)!==0));sf_db()->prepare('UPDATE fan_contacts SET tags_json=?,updated_at=? WHERE id=?')->execute([sf_auto_json($tags),gmdate('c'),(int)$contact['id']]);$contact['tags_json']=sf_auto_json($tags);$details=['tag'=>$tag];sf_automation_log_crm((int)$contact['id'],$uid,'lifecycle_'.$type,ucwords(str_replace('_',' ',$type)).' '.$tag,'automation',(string)$a['id'],$details);}
            elseif($type==='set_stage'){$stage=(string)($cfg['stage']??'fan');sf_db()->prepare('UPDATE fan_contacts SET status=?,updated_at=? WHERE id=?')->execute([$stage,gmdate('c'),(int)$contact['id']]);$contact['status']=$stage;$details=['stage'=>$stage];sf_automation_log_crm((int)$contact['id'],$uid,'lifecycle_stage_changed','Lifecycle automation set CRM stage to '.$stage,'automation',(string)$a['id'],$details);}
            elseif($type==='agent_message'){$message=sf_clean_text($cfg['message']??'',1200);if($uid&&$message!==''&&!empty($contact['agent_auto_engage'])){sf_notify_user($uid,'agent','Stonefellow Agent',$message,'?view=home');$dedupe='lifecycle:'.$a['id'].':'.$runId.':'.$i;sf_crm_agent_log((int)$contact['id'],$uid,'in_app','lifecycle_automation',$dedupe,$message,'Published lifecycle automation',['automation_id'=>(int)$a['id'],'run_id'=>$runId,'step'=>$i]);$details=['delivered'=>true];}else $details=['delivered'=>false,'reason'=>!$uid?'no_linked_account':'proactive_agent_disabled'];}
            elseif($type==='email'){$subject=sf_clean_text($cfg['subject']??'',255);$body=sf_clean_text($cfg['body']??'',20000);if(!empty($contact['marketing_opt_in'])){$unsubscribe=function_exists('sf_campaign_marketing_unsubscribe_link')?sf_campaign_marketing_unsubscribe_link($contact):'';$full=$body.($unsubscribe!==''?"\n\nUnsubscribe: ".$unsubscribe:'');$delivery=sf_transactional_email($uid?:0,(string)$contact['email'],$subject,$full,'lifecycle_marketing');$details=['delivery_status'=>$delivery['status']??'unknown'];sf_automation_log_crm((int)$contact['id'],$uid,'lifecycle_email_sent','Lifecycle email: '.$subject,'automation',(string)$a['id'],$details);}else $details=['skipped'=>true,'reason'=>'marketing_opt_out'];}
            elseif($type==='enroll_campaign'){$campaignId=(int)($cfg['campaign_id']??0);$campaign=sf_campaign_get($campaignId);if($campaign&&($campaign['status']??'')==='published'){$old=$GLOBALS['sf_automation_suppressed']??false;$GLOBALS['sf_automation_suppressed']=true;try{$p=sf_campaign_enter($campaign,(string)$contact['email'],(string)$contact['display_name'],'lifecycle_automation',false,$uid);}finally{$GLOBALS['sf_automation_suppressed']=$old;}$details=['campaign_id'=>$campaignId,'participant_id'=>(int)($p['id']??0)];}else $details=['campaign_id'=>$campaignId,'skipped'=>true,'reason'=>'campaign_not_published'];}
            elseif($type==='exit'){$details=['reason'=>sf_clean_text($cfg['reason']??'journey_exit',120)];sf_automation_event((int)$a['id'],$runId,(int)$contact['id'],'step_exit',$i,$details);sf_db()->prepare('UPDATE lifecycle_automation_runs SET status="completed",current_step=?,due_at=NULL,completed_at=?,updated_at=? WHERE id=?')->execute([$i+1,gmdate('c'),gmdate('c'),$runId]);return sf_automation_run($runId);}
            sf_automation_event((int)$a['id'],$runId,(int)$contact['id'],'step_'.$type,$i,$details);sf_db()->prepare('UPDATE lifecycle_automation_runs SET current_step=?,status="running",due_at=NULL,updated_at=? WHERE id=?')->execute([$i+1,gmdate('c'),$runId]);
        }
        sf_db()->prepare('UPDATE lifecycle_automation_runs SET status="completed",due_at=NULL,completed_at=?,updated_at=? WHERE id=?')->execute([gmdate('c'),gmdate('c'),$runId]);sf_automation_event((int)$a['id'],$runId,(int)$contact['id'],'run_completed',count($steps),[]);return sf_automation_run($runId);
    }catch(Throwable $e){sf_db()->prepare('UPDATE lifecycle_automation_runs SET status="failed",completed_at=?,updated_at=?,details_json=? WHERE id=?')->execute([gmdate('c'),gmdate('c'),sf_auto_json(['error'=>$e->getMessage()]),$runId]);sf_automation_event((int)$a['id'],$runId,(int)$contact['id'],'run_failed',(int)($run['current_step']??0),['error'=>$e->getMessage()]);return sf_automation_run($runId);}
}
function sf_automation_run(int $runId): ?array {$q=sf_db()->prepare('SELECT r.*,a.name automation_name,c.display_name,c.email FROM lifecycle_automation_runs r JOIN lifecycle_automations a ON a.id=r.automation_id JOIN fan_contacts c ON c.id=r.contact_id WHERE r.id=?');$q->execute([$runId]);$r=$q->fetch();if(!$r)return null;$r['details']=sf_auto_decode($r['details_json']??'',[]);return $r;}

function sf_automation_process_crm_event(int $contactId,string $eventType,int $eventId): int {
    if(!empty($GLOBALS['sf_automation_suppressed']))return 0;sf_automation_ensure_schema();static $guard=false;if($guard)return 0;$guard=true;$started=0;
    try{$q=sf_db()->query("SELECT id,trigger_json FROM lifecycle_automations WHERE status='published' AND trigger_type='crm_event'");foreach($q->fetchAll() as $r){$cfg=sf_auto_decode($r['trigger_json']??'',[]);$events=array_values(array_filter(array_map('strval',(array)($cfg['events']??[]))));if($events&&!in_array($eventType,$events,true))continue;if(sf_automation_start((int)$r['id'],$contactId,$eventId,'crm:'.$eventId))$started++;}sf_segment_refresh_all($contactId);}finally{$guard=false;}return $started;
}
function sf_automation_tick(): array {
    sf_automation_ensure_schema();$segments=sf_segment_refresh_all();$now=gmdate('c');$resumed=0;$scheduled=0;$started=0;
    $q=sf_db()->prepare("SELECT id FROM lifecycle_automation_runs WHERE status='waiting' AND due_at IS NOT NULL AND due_at<=? ORDER BY due_at,id LIMIT 500");$q->execute([$now]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $rid){sf_db()->prepare('UPDATE lifecycle_automation_runs SET status="running",updated_at=? WHERE id=?')->execute([$now,(int)$rid]);sf_automation_continue((int)$rid);$resumed++;}
    $q=sf_db()->query("SELECT * FROM lifecycle_automations WHERE status='published' AND trigger_type='scheduled'");foreach($q->fetchAll() as $raw){$a=$raw;$a['trigger']=sf_auto_decode($raw['trigger_json']??'',[]);$hours=max(1,min(8760,(int)($a['trigger']['interval_hours']??24)));$last=(string)($a['last_scheduled_at']??'');if($last!==''&&strtotime($last)>time()-$hours*3600)continue;$contacts=!empty($a['segment_id'])?sf_segment_contacts((int)$a['segment_id'],5000):sf_db()->query('SELECT * FROM fan_contacts ORDER BY id')->fetchAll();$slot=(string)floor(time()/($hours*3600));foreach($contacts as $c)if(sf_automation_start((int)$a['id'],(int)$c['id'],0,'scheduled:'.$slot))$started++;sf_db()->prepare('UPDATE lifecycle_automations SET last_scheduled_at=?,updated_at=? WHERE id=?')->execute([$now,$now,(int)$a['id']]);$scheduled++;}
    return ['segments_refreshed'=>count($segments),'runs_resumed'=>$resumed,'scheduled_automations'=>$scheduled,'runs_started'=>$started];
}
function sf_automation_manual_run(int $automationId,int $adminId,int $contactId=0): array {
    $a=sf_automation_get($automationId);if(!$a)throw new InvalidArgumentException('Automation not found.');$contacts=$contactId?[sf_crm_contact_by_id($contactId)]:(!empty($a['segment_id'])?sf_segment_contacts((int)$a['segment_id'],5000):sf_db()->query('SELECT * FROM fan_contacts ORDER BY id')->fetchAll());$started=0;$skipped=0;$stamp='manual:'.$adminId.':'.gmdate('YmdHis');
    foreach(array_filter($contacts) as $c){$r=sf_automation_start($automationId,(int)$c['id'],0,$stamp.':'.$c['id'],true);if($r)$started++;else$skipped++;}
    return ['started'=>$started,'skipped'=>$skipped];
}
function sf_automation_admin_summary(): array {
    sf_automation_ensure_schema();$pdo=sf_db();return ['segments'=>(int)$pdo->query('SELECT COUNT(*) FROM campaign_segments')->fetchColumn(),'automations'=>(int)$pdo->query('SELECT COUNT(*) FROM lifecycle_automations')->fetchColumn(),'published'=>(int)$pdo->query("SELECT COUNT(*) FROM lifecycle_automations WHERE status='published'")->fetchColumn(),'waiting'=>(int)$pdo->query("SELECT COUNT(*) FROM lifecycle_automation_runs WHERE status='waiting'")->fetchColumn(),'completed'=>(int)$pdo->query("SELECT COUNT(*) FROM lifecycle_automation_runs WHERE status='completed'")->fetchColumn(),'failed'=>(int)$pdo->query("SELECT COUNT(*) FROM lifecycle_automation_runs WHERE status='failed'")->fetchColumn()];
}
function sf_automation_recent_runs(int $limit=200): array {
    sf_automation_ensure_schema();$sql='SELECT r.*,a.name automation_name,c.display_name,c.email FROM lifecycle_automation_runs r JOIN lifecycle_automations a ON a.id=r.automation_id JOIN fan_contacts c ON c.id=r.contact_id ORDER BY r.id DESC LIMIT '.max(1,min(500,$limit));$rows=sf_db()->query($sql)->fetchAll();foreach($rows as &$r)$r['details']=sf_auto_decode($r['details_json']??'',[]);unset($r);return $rows;
}
function sf_automation_run_events(int $runId): array {$q=sf_db()->prepare('SELECT * FROM lifecycle_automation_events WHERE run_id=? ORDER BY id');$q->execute([$runId]);$rows=$q->fetchAll();foreach($rows as &$r)$r['details']=sf_auto_decode($r['details_json']??'',[]);unset($r);return $rows;}
