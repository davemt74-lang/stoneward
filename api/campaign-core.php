<?php
declare(strict_types=1);

const SF_CAMPAIGN_NODE_TYPES=[
    'trigger','audience','condition','wait','email','crm_tag','agent_message',
    'offer_download','offer_discount','offer_vip','offer_exclusive','redirect','conversion','exit'
];

function sf_campaign_json(array $v): string {return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';}
function sf_campaign_decode(mixed $v,array $fallback=[]): array {
    if(is_array($v))return $v;$d=json_decode((string)$v,true);return is_array($d)?$d:$fallback;
}
function sf_campaign_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,name TEXT NOT NULL,goal TEXT NOT NULL DEFAULT 'fan_acquisition',status TEXT NOT NULL DEFAULT 'draft',version INTEGER NOT NULL DEFAULT 1,headline TEXT NOT NULL DEFAULT '',body_text TEXT NOT NULL DEFAULT '',artwork TEXT NOT NULL DEFAULT '',starts_at TEXT NULL,ends_at TEXT NULL,audience_json TEXT NOT NULL DEFAULT '{}',graph_json TEXT NOT NULL DEFAULT '{}',landing_json TEXT NOT NULL DEFAULT '{}',created_by INTEGER NULL,published_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaigns_status_dates ON campaigns(status,starts_at,ends_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_segments (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,rules_json TEXT NOT NULL DEFAULT '{}',created_by INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_participants (id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER NOT NULL,contact_id INTEGER NULL,user_id INTEGER NULL,email TEXT NOT NULL,name TEXT NOT NULL DEFAULT '',state TEXT NOT NULL DEFAULT 'entered',marketing_opt_in INTEGER NOT NULL DEFAULT 0,source TEXT NOT NULL DEFAULT 'campaign',entered_at TEXT NOT NULL,converted_at TEXT NULL,last_event_at TEXT NOT NULL,UNIQUE(campaign_id,email),FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_participants_campaign_state ON campaign_participants(campaign_id,state,last_event_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_events (id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER NOT NULL,participant_id INTEGER NULL,contact_id INTEGER NULL,event_type TEXT NOT NULL,node_id TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,FOREIGN KEY(participant_id) REFERENCES campaign_participants(id) ON DELETE SET NULL,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_events_campaign_created ON campaign_events(campaign_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER NOT NULL,participant_id INTEGER NOT NULL,contact_id INTEGER NULL,node_id TEXT NOT NULL,entitlement_type TEXT NOT NULL,code TEXT NOT NULL UNIQUE,token_hash TEXT NULL UNIQUE,status TEXT NOT NULL DEFAULT 'claimed',payload_json TEXT NOT NULL DEFAULT '{}',expires_at TEXT NULL,claimed_at TEXT NOT NULL,redeemed_at TEXT NULL,FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,FOREIGN KEY(participant_id) REFERENCES campaign_participants(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_campaign_entitlements_campaign_status ON campaign_entitlements(campaign_id,status,claimed_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_message_runs (id INTEGER PRIMARY KEY AUTOINCREMENT,campaign_id INTEGER NOT NULL,node_id TEXT NOT NULL,admin_user_id INTEGER NULL,status TEXT NOT NULL,total_recipients INTEGER NOT NULL DEFAULT 0,sent_count INTEGER NOT NULL DEFAULT 0,failed_count INTEGER NOT NULL DEFAULT 0,started_at TEXT NOT NULL,completed_at TEXT NULL,details_json TEXT NOT NULL DEFAULT '{}',FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(140) NOT NULL UNIQUE,name VARCHAR(180) NOT NULL,goal VARCHAR(80) NOT NULL DEFAULT 'fan_acquisition',status VARCHAR(32) NOT NULL DEFAULT 'draft',version INT NOT NULL DEFAULT 1,headline VARCHAR(255) NOT NULL DEFAULT '',body_text TEXT NOT NULL,artwork VARCHAR(500) NOT NULL DEFAULT '',starts_at VARCHAR(40) NULL,ends_at VARCHAR(40) NULL,audience_json LONGTEXT NOT NULL,graph_json LONGTEXT NOT NULL,landing_json LONGTEXT NOT NULL,created_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_campaigns_status_dates(status,starts_at,ends_at),CONSTRAINT fk_campaign_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_segments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,rules_json LONGTEXT NOT NULL,created_by BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_campaign_segment_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_participants (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NULL,user_id BIGINT UNSIGNED NULL,email VARCHAR(190) NOT NULL,name VARCHAR(120) NOT NULL DEFAULT '',state VARCHAR(40) NOT NULL DEFAULT 'entered',marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,source VARCHAR(80) NOT NULL DEFAULT 'campaign',entered_at VARCHAR(40) NOT NULL,converted_at VARCHAR(40) NULL,last_event_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_campaign_participant_email(campaign_id,email),INDEX idx_campaign_participants_campaign_state(campaign_id,state,last_event_at),CONSTRAINT fk_campaign_participant_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_participant_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL,CONSTRAINT fk_campaign_participant_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,participant_id BIGINT UNSIGNED NULL,contact_id BIGINT UNSIGNED NULL,event_type VARCHAR(80) NOT NULL,node_id VARCHAR(120) NOT NULL DEFAULT '',metadata_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_campaign_events_campaign_created(campaign_id,created_at),CONSTRAINT fk_campaign_event_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_event_participant FOREIGN KEY(participant_id) REFERENCES campaign_participants(id) ON DELETE SET NULL,CONSTRAINT fk_campaign_event_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_entitlements (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,participant_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NULL,node_id VARCHAR(120) NOT NULL,entitlement_type VARCHAR(60) NOT NULL,code VARCHAR(80) NOT NULL UNIQUE,token_hash CHAR(64) NULL UNIQUE,status VARCHAR(32) NOT NULL DEFAULT 'claimed',payload_json LONGTEXT NOT NULL,expires_at VARCHAR(40) NULL,claimed_at VARCHAR(40) NOT NULL,redeemed_at VARCHAR(40) NULL,INDEX idx_campaign_entitlements_campaign_status(campaign_id,status,claimed_at),CONSTRAINT fk_campaign_ent_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_ent_participant FOREIGN KEY(participant_id) REFERENCES campaign_participants(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_ent_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS campaign_message_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,campaign_id BIGINT UNSIGNED NOT NULL,node_id VARCHAR(120) NOT NULL,admin_user_id BIGINT UNSIGNED NULL,status VARCHAR(32) NOT NULL,total_recipients INT NOT NULL DEFAULT 0,sent_count INT NOT NULL DEFAULT 0,failed_count INT NOT NULL DEFAULT 0,started_at VARCHAR(40) NOT NULL,completed_at VARCHAR(40) NULL,details_json LONGTEXT NOT NULL,CONSTRAINT fk_campaign_run_campaign FOREIGN KEY(campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,CONSTRAINT fk_campaign_run_admin FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_campaign_slug(string $v): string {
    $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';return trim(substr($v,0,140),'-');
}
function sf_campaign_allowed_status(string $v): string {
    return in_array($v,['draft','scheduled','published','paused','completed','archived'],true)?$v:'draft';
}
function sf_campaign_graph_validate(array $graph): array {
    $nodes=array_values(array_filter((array)($graph['nodes']??[]),'is_array'));$edges=array_values(array_filter((array)($graph['edges']??[]),'is_array'));$errors=[];$ids=[];$cleanNodes=[];
    if(count($nodes)>80)$errors[]='A campaign may contain at most 80 nodes.';
    foreach($nodes as $n){
        $id=sf_clean_text($n['id']??'',120);$type=sf_clean_text($n['type']??'',60);
        if($id===''||isset($ids[$id])){$errors[]='Every node needs a unique ID.';continue;}
        if(!in_array($type,SF_CAMPAIGN_NODE_TYPES,true)){$errors[]='Unsupported campaign node type: '.$type;continue;}
        $ids[$id]=true;$x=max(0,min(4000,(int)($n['x']??40)));$y=max(0,min(4000,(int)($n['y']??40)));
        $cfg=is_array($n['config']??null)?$n['config']:[];
        $cleanNodes[]=['id'=>$id,'type'=>$type,'x'=>$x,'y'=>$y,'config'=>$cfg];
    }
    $cleanEdges=[];$edgeKeys=[];
    foreach($edges as $e){
        $from=sf_clean_text($e['from']??'',120);$to=sf_clean_text($e['to']??'',120);$label=sf_clean_text($e['label']??'',40);
        if($from===$to||!isset($ids[$from])||!isset($ids[$to])){$errors[]='Every connection must reference two existing, different nodes.';continue;}
        $key=$from.'>'.$to.'>'.$label;if(isset($edgeKeys[$key]))continue;$edgeKeys[$key]=true;$cleanEdges[]=['from'=>$from,'to'=>$to,'label'=>$label];
    }
    $triggers=array_values(array_filter($cleanNodes,fn($n)=>$n['type']==='trigger'));
    if(count($triggers)!==1)$errors[]='A campaign workflow needs exactly one Trigger node.';
    return ['ok'=>!$errors,'errors'=>array_values(array_unique($errors)),'graph'=>['nodes'=>$cleanNodes,'edges'=>$cleanEdges]];
}
function sf_campaign_graph_default(): array {
    return ['nodes'=>[
        ['id'=>'trigger_1','type'=>'trigger','x'=>60,'y'=>120,'config'=>['label'=>'Campaign entry']],
        ['id'=>'audience_1','type'=>'audience','x'=>300,'y'=>120,'config'=>['newsletter_only'=>false,'linked_accounts_only'=>false,'purchase_required'=>false,'stages'=>[],'tags'=>[]]],
        ['id'=>'exit_1','type'=>'exit','x'=>570,'y'=>120,'config'=>['label'=>'Complete']],
    ],'edges'=>[
        ['from'=>'trigger_1','to'=>'audience_1','label'=>''],
        ['from'=>'audience_1','to'=>'exit_1','label'=>'eligible'],
    ]];
}
function sf_campaign_get(int $id): ?array {
    sf_campaign_ensure_schema();$q=sf_db()->prepare('SELECT * FROM campaigns WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)return null;
    foreach(['audience_json'=>'audience','graph_json'=>'graph','landing_json'=>'landing'] as $col=>$key)$r[$key]=sf_campaign_decode($r[$col],[]);
    return $r;
}
function sf_campaign_by_slug(string $slug,bool $publicOnly=false): ?array {
    sf_campaign_ensure_schema();$q=sf_db()->prepare('SELECT * FROM campaigns WHERE slug=? LIMIT 1');$q->execute([sf_campaign_slug($slug)]);$r=$q->fetch();if(!$r)return null;
    if($publicOnly&&!sf_campaign_is_live($r))return null;
    foreach(['audience_json'=>'audience','graph_json'=>'graph','landing_json'=>'landing'] as $col=>$key)$r[$key]=sf_campaign_decode($r[$col],[]);
    return $r;
}
function sf_campaign_is_live(array $c): bool {
    if(($c['status']??'')!=='published')return false;$now=time();$start=(string)($c['starts_at']??'');$end=(string)($c['ends_at']??'');
    if($start!==''&&strtotime($start)>$now)return false;if($end!==''&&strtotime($end)<$now)return false;return true;
}
function sf_campaign_list(int $limit=200): array {
    sf_campaign_ensure_schema();$limit=max(1,min(500,$limit));$rows=sf_db()->query('SELECT * FROM campaigns ORDER BY updated_at DESC,id DESC LIMIT '.$limit)->fetchAll();
    foreach($rows as &$r){$r['audience']=sf_campaign_decode($r['audience_json'],[]);$r['graph']=sf_campaign_decode($r['graph_json'],[]);$r['landing']=sf_campaign_decode($r['landing_json'],[]);}unset($r);return $rows;
}
function sf_campaign_save(array $raw,int $adminId): array {
    sf_campaign_ensure_schema();$id=(int)($raw['id']??0);$old=$id?sf_campaign_get($id):null;$name=sf_clean_text($raw['name']??'',180);if($name==='')throw new InvalidArgumentException('Campaign name is required.');
    $slug=sf_campaign_slug((string)($raw['slug']??$name));if($slug==='')throw new InvalidArgumentException('Campaign slug is required.');
    $goal=sf_clean_text($raw['goal']??'fan_acquisition',80);$status=sf_campaign_allowed_status((string)($raw['status']??'draft'));
    $graphResult=sf_campaign_graph_validate(is_array($raw['graph']??null)?$raw['graph']:sf_campaign_graph_default());if(!$graphResult['ok'])throw new InvalidArgumentException(implode(' ',$graphResult['errors']));
    $audience=is_array($raw['audience']??null)?$raw['audience']:[];$landing=is_array($raw['landing']??null)?$raw['landing']:[];
    $now=gmdate('c');$headline=sf_clean_text($raw['headline']??'',255);$body=sf_clean_text($raw['body_text']??'',12000);$artwork=sf_clean_text($raw['artwork']??'',500);
    $starts=sf_clean_text($raw['starts_at']??'',40)?:null;$ends=sf_clean_text($raw['ends_at']??'',40)?:null;if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new InvalidArgumentException('Campaign end must be after its start.');
    $publishedAt=$old['published_at']??null;if($status==='published'&&!$publishedAt)$publishedAt=$now;
    $version=max(1,(int)($old['version']??0)+($old?1:0));
    try{
        if($old){
            $q=sf_db()->prepare('UPDATE campaigns SET slug=?,name=?,goal=?,status=?,version=?,headline=?,body_text=?,artwork=?,starts_at=?,ends_at=?,audience_json=?,graph_json=?,landing_json=?,published_at=?,updated_at=? WHERE id=?');
            $q->execute([$slug,$name,$goal,$status,$version,$headline,$body,$artwork,$starts,$ends,sf_campaign_json($audience),sf_campaign_json($graphResult['graph']),sf_campaign_json($landing),$publishedAt,$now,$id]);
        }else{
            $q=sf_db()->prepare('INSERT INTO campaigns(slug,name,goal,status,version,headline,body_text,artwork,starts_at,ends_at,audience_json,graph_json,landing_json,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$slug,$name,$goal,$status,1,$headline,$body,$artwork,$starts,$ends,sf_campaign_json($audience),sf_campaign_json($graphResult['graph']),sf_campaign_json($landing),$adminId?:null,$publishedAt,$now,$now]);$id=(int)sf_db()->lastInsertId();
        }
    }catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))throw new InvalidArgumentException('Campaign slug is already in use.');throw $e;}
    return sf_campaign_get($id)?:[];
}
function sf_campaign_delete(int $id): bool {
    $c=sf_campaign_get($id);if(!$c)return false;if(!in_array((string)$c['status'],['draft','archived'],true))throw new RuntimeException('Only draft or archived campaigns can be deleted.');
    $q=sf_db()->prepare('DELETE FROM campaigns WHERE id=?');$q->execute([$id]);return $q->rowCount()>0;
}
function sf_campaign_log_event(int $campaignId,string $eventType,?int $participantId=null,?int $contactId=null,string $nodeId='',array $meta=[]): int {
    sf_campaign_ensure_schema();$q=sf_db()->prepare('INSERT INTO campaign_events(campaign_id,participant_id,contact_id,event_type,node_id,metadata_json,created_at) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$campaignId,$participantId?:null,$contactId?:null,sf_clean_text($eventType,80),sf_clean_text($nodeId,120),sf_campaign_json($meta),gmdate('c')]);return (int)sf_db()->lastInsertId();
}
function sf_campaign_contact_matches(array $contact,array $rules): bool {
    if(!empty($rules['newsletter_only'])&&empty($contact['marketing_opt_in']))return false;
    if(!empty($rules['linked_accounts_only'])&&empty($contact['user_id']))return false;
    if(!empty($rules['purchase_required'])){$q=sf_db()->prepare("SELECT 1 FROM fan_crm_events WHERE contact_id=? AND event_type='purchase' LIMIT 1");$q->execute([(int)$contact['id']]);if(!$q->fetchColumn())return false;}
    $stages=array_values(array_filter(array_map('strval',(array)($rules['stages']??[]))));if($stages&&!in_array((string)$contact['status'],$stages,true))return false;
    $required=array_values(array_filter(array_map(fn($x)=>strtolower(trim((string)$x)),(array)($rules['tags']??[]))));if($required){$own=array_map('strtolower',sf_campaign_decode($contact['tags_json']??'[]',[]));if(array_diff($required,$own))return false;}
    return true;
}
function sf_campaign_condition_result(array $participant,array $contact,array $config): bool {
    $field=(string)($config['field']??'marketing_opt_in');$op=(string)($config['operator']??'equals');$wanted=strtolower(trim((string)($config['value']??'true')));$actual='';
    if($field==='marketing_opt_in')$actual=!empty($contact['marketing_opt_in'])?'true':'false';
    elseif($field==='has_account')$actual=!empty($participant['user_id'])?'true':'false';
    elseif($field==='status')$actual=strtolower((string)($contact['status']??''));
    elseif($field==='tag')$actual=implode(',',array_map('strtolower',sf_campaign_decode($contact['tags_json']??'[]',[])));
    else $actual=strtolower((string)($contact[$field]??''));
    return $op==='not_equals'?$actual!==$wanted:($op==='contains'?str_contains($actual,$wanted):$actual===$wanted);
}
function sf_campaign_run_entry(array $campaign,array $participant): array {
    $graph=is_array($campaign['graph']??null)?$campaign['graph']:sf_campaign_decode($campaign['graph_json']??'',[]);$nodes=(array)($graph['nodes']??[]);$edges=(array)($graph['edges']??[]);$map=[];foreach($nodes as $n)$map[(string)$n['id']]=$n;
    $trigger=null;foreach($nodes as $n)if(($n['type']??'')==='trigger'){$trigger=$n;break;}if(!$trigger)return ['status'=>'no_trigger','visited'=>[]];
    $contact=!empty($participant['contact_id'])?sf_crm_contact_by_id((int)$participant['contact_id']):null;if(!$contact)return ['status'=>'no_contact','visited'=>[]];
    $next=[];foreach($edges as $e)if(($e['from']??'')===$trigger['id'])$next[]=(string)$e['to'];$seen=[];$visited=[];$stopped=false;
    while($next&&count($visited)<80){
        $id=array_shift($next);if(isset($seen[$id]))continue;$seen[$id]=true;$node=$map[$id]??null;if(!$node)continue;$type=(string)($node['type']??'');$cfg=is_array($node['config']??null)?$node['config']:[];$visited[]=$id;$follow=true;$branch=null;
        if($type==='audience'){
            $ok=sf_campaign_contact_matches($contact,$cfg);sf_campaign_log_event((int)$campaign['id'],$ok?'audience_accepted':'audience_rejected',(int)$participant['id'],(int)$contact['id'],$id,[]);if(!$ok){sf_db()->prepare('UPDATE campaign_participants SET state="ineligible",last_event_at=? WHERE id=?')->execute([gmdate('c'),(int)$participant['id']]);$follow=false;$stopped=true;}else{$branch='eligible';}
        }elseif($type==='condition'){$ok=sf_campaign_condition_result($participant,$contact,$cfg);$branch=$ok?'yes':'no';sf_campaign_log_event((int)$campaign['id'],'condition_evaluated',(int)$participant['id'],(int)$contact['id'],$id,['result'=>$ok]);}
        elseif($type==='crm_tag'){
            $tag=sf_clean_text($cfg['tag']??'',60);if($tag!==''){$tags=sf_campaign_decode($contact['tags_json']??'[]',[]);if(!in_array($tag,$tags,true))$tags[]=$tag;sf_db()->prepare('UPDATE fan_contacts SET tags_json=?,updated_at=? WHERE id=?')->execute([sf_campaign_json(array_values($tags)),gmdate('c'),(int)$contact['id']]);$contact['tags_json']=sf_campaign_json($tags);sf_crm_log_event((int)$contact['id'],!empty($participant['user_id'])?(int)$participant['user_id']:null,'campaign_tagged','Campaign added CRM tag '.$tag,'campaign',(string)$campaign['id'],['node_id'=>$id]);}
        }elseif($type==='agent_message'){
            $uid=(int)($participant['user_id']??0);$message=sf_clean_text($cfg['message']??'',800);$allowed=empty($cfg['respect_auto_engage'])||!empty($contact['agent_auto_engage']);if($uid&&$message!==''&&$allowed){sf_notify_user($uid,'agent','Stonefellow Agent',$message,'?view=campaign&slug='.rawurlencode((string)$campaign['slug']));$key='campaign:'.$campaign['id'].':'.$participant['id'].':'.$id;try{sf_crm_agent_log((int)$contact['id'],$uid,'in_app','campaign',$key,$message,'Campaign workflow Agent action',['campaign_id'=>(int)$campaign['id'],'node_id'=>$id]);}catch(Throwable $e){}sf_campaign_log_event((int)$campaign['id'],'agent_message_delivered',(int)$participant['id'],(int)$contact['id'],$id,[]);}
        }elseif($type==='wait'){
            $hours=max(0,(int)($cfg['hours']??24));$due=gmdate('c',time()+$hours*3600);sf_db()->prepare('UPDATE campaign_participants SET state="waiting",last_event_at=? WHERE id=?')->execute([gmdate('c'),(int)$participant['id']]);sf_campaign_log_event((int)$campaign['id'],'workflow_waiting',(int)$participant['id'],(int)$contact['id'],$id,['hours'=>$hours,'due_at'=>$due]);$follow=false;$stopped=true;
        }elseif($type==='email'){
            sf_campaign_log_event((int)$campaign['id'],'email_approval_required',(int)$participant['id'],(int)$contact['id'],$id,['subject'=>sf_clean_text($cfg['subject']??'',255)]);
        }elseif(str_starts_with($type,'offer_')){
            sf_campaign_log_event((int)$campaign['id'],'offer_available',(int)$participant['id'],(int)$contact['id'],$id,['type'=>$type]);
        }elseif($type==='conversion'){
            $now=gmdate('c');sf_db()->prepare('UPDATE campaign_participants SET state="converted",converted_at=COALESCE(converted_at,?),last_event_at=? WHERE id=?')->execute([$now,$now,(int)$participant['id']]);sf_campaign_log_event((int)$campaign['id'],'campaign_converted',(int)$participant['id'],(int)$contact['id'],$id,['reason'=>'workflow_node']);
        }elseif($type==='redirect'){
            sf_campaign_log_event((int)$campaign['id'],'redirect_available',(int)$participant['id'],(int)$contact['id'],$id,['url'=>sf_clean_text($cfg['url']??'',500)]);
        }elseif($type==='exit'){
            if(($participant['state']??'')!=='converted')sf_db()->prepare('UPDATE campaign_participants SET state="completed",last_event_at=? WHERE id=?')->execute([gmdate('c'),(int)$participant['id']]);$follow=false;$stopped=true;
        }
        if($follow){$outs=array_values(array_filter($edges,fn($e)=>($e['from']??'')===$id));$labeled=array_values(array_filter($outs,fn($e)=>(string)($e['label']??'')!==''));$picked=$outs;if($branch!==null&&$labeled){$picked=array_values(array_filter($outs,function($e)use($branch){$label=strtolower((string)($e['label']??''));return $label===$branch||($branch==='yes'&&in_array($label,['true','match','eligible'],true))||($branch==='no'&&in_array($label,['false','else','ineligible'],true));}));}foreach($picked as $e)$next[]=(string)$e['to'];}
    }
    return ['status'=>$stopped?'stopped':'complete','visited'=>$visited];
}
function sf_campaign_public_payload(array $c,int $participantId=0): array {
    $graph=is_array($c['graph']??null)?$c['graph']:sf_campaign_decode($c['graph_json']??'',[]);
    $allowedOfferIds=null;if($participantId>0){$q=sf_db()->prepare("SELECT DISTINCT node_id FROM campaign_events WHERE campaign_id=? AND participant_id=? AND event_type='offer_available'");$q->execute([(int)$c['id'],$participantId]);$allowedOfferIds=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN));}
    $offers=[];foreach((array)($graph['nodes']??[]) as $n)if(in_array((string)($n['type']??''),['offer_download','offer_discount','offer_vip','offer_exclusive'],true)&&($allowedOfferIds===null||in_array((string)$n['id'],$allowedOfferIds,true))){$cfg=is_array($n['config']??null)?$n['config']:[];$offers[]=['node_id'=>$n['id'],'type'=>$n['type'],'title'=>sf_clean_text($cfg['title']??ucwords(str_replace('_',' ',substr($n['type'],6))),180),'description'=>sf_clean_text($cfg['description']??'',1200),'cta'=>sf_clean_text($cfg['cta']??'Claim offer',80)];}
    return ['id'=>(int)$c['id'],'slug'=>(string)$c['slug'],'name'=>(string)$c['name'],'goal'=>(string)$c['goal'],'headline'=>(string)$c['headline'],'body_text'=>(string)$c['body_text'],'artwork'=>(string)$c['artwork'],'starts_at'=>$c['starts_at'],'ends_at'=>$c['ends_at'],'landing'=>$c['landing']??[],'offers'=>$offers];
}
function sf_campaign_participant(int $campaignId,string $email): ?array {
    sf_campaign_ensure_schema();$q=sf_db()->prepare('SELECT * FROM campaign_participants WHERE campaign_id=? AND email=?');$q->execute([$campaignId,sf_crm_email($email)]);$r=$q->fetch();return $r?:null;
}
function sf_campaign_enter(array $campaign,string $email,string $name='',bool $marketingOptIn=false,string $source='landing'): array {
    $email=sf_crm_email($email);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
    $user=sf_user_by_email($email);$uid=$user?(int)$user['id']:null;$contact=sf_crm_upsert_contact($email,$name!==''?$name:(string)($user['display_name']??''),'campaign',$uid,null,$uid?'fan':'lead');
    if($marketingOptIn)sf_crm_newsletter_signup($email,$name!==''?$name:(string)($user['display_name']??''));
    $now=gmdate('c');$existing=sf_campaign_participant((int)$campaign['id'],$email);
    if($existing){$pid=(int)$existing['id'];sf_db()->prepare('UPDATE campaign_participants SET contact_id=?,user_id=?,name=?,marketing_opt_in=CASE WHEN marketing_opt_in=1 THEN 1 ELSE ? END,last_event_at=? WHERE id=?')->execute([(int)$contact['id'],$uid?:null,sf_clean_text($name,120),$marketingOptIn?1:0,$now,$pid]);}
    else{$q=sf_db()->prepare('INSERT INTO campaign_participants(campaign_id,contact_id,user_id,email,name,state,marketing_opt_in,source,entered_at,converted_at,last_event_at) VALUES(?,?,?,?,?,"entered",?,?,?,NULL,?)');$q->execute([(int)$campaign['id'],(int)$contact['id'],$uid?:null,$email,sf_clean_text($name,120),$marketingOptIn?1:0,sf_clean_text($source,80),$now,$now]);$pid=(int)sf_db()->lastInsertId();}
    if($marketingOptIn&&($campaign['goal']??'')==='newsletter_growth'){sf_db()->prepare('UPDATE campaign_participants SET state="converted",converted_at=?,last_event_at=? WHERE id=?')->execute([$now,$now,$pid]);sf_campaign_log_event((int)$campaign['id'],'campaign_converted',$pid,(int)$contact['id'],'',['reason'=>'newsletter_opt_in']);sf_crm_log_event((int)$contact['id'],$uid,'campaign_converted','Converted in campaign '.$campaign['name'],'campaign',(string)$campaign['id'],['reason'=>'newsletter_opt_in']);}
    sf_campaign_log_event((int)$campaign['id'],'campaign_entered',$pid,(int)$contact['id'],'',['source'=>$source,'marketing_opt_in'=>$marketingOptIn]);
    sf_crm_log_event((int)$contact['id'],$uid,'campaign_entered','Entered campaign '.$campaign['name'],'campaign',(string)$campaign['id'],['slug'=>$campaign['slug'],'source'=>$source]);
    $fresh=sf_campaign_participant((int)$campaign['id'],$email)?:[];sf_campaign_set_session_attribution($campaign,$fresh);sf_campaign_run_entry($campaign,$fresh);return sf_campaign_participant((int)$campaign['id'],$email)?:$fresh;
}
function sf_campaign_find_node(array $campaign,string $nodeId): ?array {
    $graph=is_array($campaign['graph']??null)?$campaign['graph']:sf_campaign_decode($campaign['graph_json']??'',[]);
    foreach((array)($graph['nodes']??[]) as $n)if((string)($n['id']??'')===$nodeId)return $n;return null;
}
function sf_campaign_code(string $prefix='SF'): string {return strtoupper($prefix.'-'.bin2hex(random_bytes(4)));}
function sf_campaign_claim_offer(array $campaign,array $participant,string $nodeId): array {
    $q=sf_db()->prepare("SELECT 1 FROM campaign_events WHERE campaign_id=? AND participant_id=? AND node_id=? AND event_type='offer_available' LIMIT 1");$q->execute([(int)$campaign['id'],(int)$participant['id'],$nodeId]);if(!$q->fetchColumn())throw new RuntimeException('This offer is not available for this participant.');
    $node=sf_campaign_find_node($campaign,$nodeId);if(!$node||!in_array((string)$node['type'],['offer_download','offer_discount','offer_vip','offer_exclusive'],true))throw new InvalidArgumentException('Offer not found.');
    $q=sf_db()->prepare('SELECT * FROM campaign_entitlements WHERE campaign_id=? AND participant_id=? AND node_id=? LIMIT 1');$q->execute([(int)$campaign['id'],(int)$participant['id'],$nodeId]);$existing=$q->fetch();
    if($existing){
        $public=sf_campaign_entitlement_public($existing);
        if(($existing['entitlement_type']??'')==='offer_download'){
            $rawToken=bin2hex(random_bytes(24));$hash=hash('sha256',$rawToken);sf_db()->prepare('UPDATE campaign_entitlements SET token_hash=? WHERE id=?')->execute([$hash,(int)$existing['id']]);$public['download_token']=$rawToken;
        }
        return $public;
    }
    $cfg=is_array($node['config']??null)?$node['config']:[];$limit=max(0,(int)($cfg['inventory']??0));
    if($limit>0){$q=sf_db()->prepare('SELECT COUNT(*) FROM campaign_entitlements WHERE campaign_id=? AND node_id=?');$q->execute([(int)$campaign['id'],$nodeId]);if((int)$q->fetchColumn()>=$limit)throw new RuntimeException('This offer has reached its claim limit.');}
    $type=(string)$node['type'];$code=sf_campaign_code($type==='offer_discount'?'SAVE':($type==='offer_vip'?'VIP':'SF'));$rawToken=null;$tokenHash=null;
    if($type==='offer_download'){$rawToken=bin2hex(random_bytes(24));$tokenHash=hash('sha256',$rawToken);}
    $expires=sf_clean_text($cfg['expires_at']??($campaign['ends_at']??''),40)?:null;$payload=$cfg;$now=gmdate('c');
    $q=sf_db()->prepare('INSERT INTO campaign_entitlements(campaign_id,participant_id,contact_id,node_id,entitlement_type,code,token_hash,status,payload_json,expires_at,claimed_at,redeemed_at) VALUES(?,?,?,?,?,?,?,"claimed",?,?,?,NULL)');
    $q->execute([(int)$campaign['id'],(int)$participant['id'],(int)($participant['contact_id']??0)?:null,$nodeId,$type,$code,$tokenHash,sf_campaign_json($payload),$expires,$now]);$id=(int)sf_db()->lastInsertId();
    sf_db()->prepare('UPDATE campaign_participants SET state="converted",converted_at=COALESCE(converted_at,?),last_event_at=? WHERE id=?')->execute([$now,$now,(int)$participant['id']]);
    sf_campaign_log_event((int)$campaign['id'],'offer_claimed',(int)$participant['id'],(int)($participant['contact_id']??0),$nodeId,['entitlement_type'=>$type,'code'=>$code]);
    sf_campaign_log_event((int)$campaign['id'],'campaign_converted',(int)$participant['id'],(int)($participant['contact_id']??0),$nodeId,['reason'=>'offer_claimed','entitlement_type'=>$type]);
    if(!empty($participant['contact_id'])){sf_crm_log_event((int)$participant['contact_id'],!empty($participant['user_id'])?(int)$participant['user_id']:null,'offer_claimed','Claimed '.$campaign['name'].' offer','campaign',(string)$campaign['id'],['node_id'=>$nodeId,'type'=>$type]);sf_crm_log_event((int)$participant['contact_id'],!empty($participant['user_id'])?(int)$participant['user_id']:null,'campaign_converted','Converted in campaign '.$campaign['name'],'campaign',(string)$campaign['id'],['reason'=>'offer_claimed','node_id'=>$nodeId,'type'=>$type]);}
    $row=['id'=>$id,'campaign_id'=>$campaign['id'],'participant_id'=>$participant['id'],'contact_id'=>$participant['contact_id']??null,'node_id'=>$nodeId,'entitlement_type'=>$type,'code'=>$code,'token_hash'=>$tokenHash,'status'=>'claimed','payload_json'=>sf_campaign_json($payload),'expires_at'=>$expires,'claimed_at'=>$now,'redeemed_at'=>null];
    $public=sf_campaign_entitlement_public($row);if($rawToken)$public['download_token']=$rawToken;return $public;
}
function sf_campaign_entitlement_public(array $e): array {
    return ['id'=>(int)$e['id'],'type'=>(string)$e['entitlement_type'],'code'=>(string)$e['code'],'status'=>(string)$e['status'],'payload'=>sf_campaign_decode($e['payload_json']??'',[]),'expires_at'=>$e['expires_at']??null,'redeemed_at'=>$e['redeemed_at']??null];
}
function sf_campaign_entitlement_by_token(string $token): ?array {
    if(!preg_match('/^[a-f0-9]{48}$/i',$token))return null;sf_campaign_ensure_schema();$q=sf_db()->prepare('SELECT * FROM campaign_entitlements WHERE token_hash=? LIMIT 1');$q->execute([hash('sha256',$token)]);$r=$q->fetch();if(!$r)return null;if(!empty($r['expires_at'])&&strtotime((string)$r['expires_at'])<time())return null;return $r;
}
function sf_campaign_discount_by_code(string $code): ?array {
    $code=strtoupper(trim($code));if($code==='')return null;sf_campaign_ensure_schema();$q=sf_db()->prepare("SELECT e.*,c.status AS campaign_status,c.starts_at,c.ends_at FROM campaign_entitlements e JOIN campaigns c ON c.id=e.campaign_id WHERE e.code=? AND e.entitlement_type='offer_discount' LIMIT 1");$q->execute([$code]);$e=$q->fetch();if(!$e||$e['status']!=='claimed'||$e['campaign_status']!=='published')return null;$now=time();if(!empty($e['starts_at'])&&strtotime((string)$e['starts_at'])>$now)return null;if(!empty($e['ends_at'])&&strtotime((string)$e['ends_at'])<$now)return null;if(!empty($e['expires_at'])&&strtotime((string)$e['expires_at'])<$now)return null;$e['payload']=sf_campaign_decode($e['payload_json'],[]);return $e;
}
function sf_campaign_discount_amount(?array $entitlement,int $subtotal): int {
    if(!$entitlement||$subtotal<=0)return 0;$p=$entitlement['payload']??[];$percent=max(0,min(100,(float)($p['percent_off']??0)));$fixed=max(0,(int)($p['amount_off_cents']??0));$discount=(int)round($subtotal*$percent/100)+$fixed;return min($subtotal,max(0,$discount));
}
function sf_campaign_redeem_code(string $code,string $orderId): void {
    $e=sf_campaign_discount_by_code($code);if(!$e)return;$now=gmdate('c');sf_db()->prepare("UPDATE campaign_entitlements SET status='redeemed',redeemed_at=? WHERE id=? AND status='claimed'")->execute([$now,(int)$e['id']]);$order=sf_read_order($orderId);$total=(int)($order['quote']['total_cents']??0);
    sf_campaign_log_event((int)$e['campaign_id'],'offer_redeemed',(int)$e['participant_id'],!empty($e['contact_id'])?(int)$e['contact_id']:null,(string)$e['node_id'],['order_id'=>$orderId,'code'=>$code,'total_cents'=>$total]);if(!empty($e['contact_id'])){$contact=sf_crm_contact_by_id((int)$e['contact_id']);sf_crm_log_event((int)$e['contact_id'],!empty($contact['user_id'])?(int)$contact['user_id']:null,'campaign_purchase_attributed','Purchase attributed to campaign','order',$orderId,['campaign_id'=>(int)$e['campaign_id'],'total_cents'=>$total,'discount_code'=>$code]);}
}
function sf_campaign_audience_contacts(array $campaign,int $limit=1000): array {
    sf_crm_ensure_schema();$aud=is_array($campaign['audience']??null)?$campaign['audience']:sf_campaign_decode($campaign['audience_json']??'',[]);
    if(!$aud){$graph=is_array($campaign['graph']??null)?$campaign['graph']:sf_campaign_decode($campaign['graph_json']??'',[]);foreach((array)($graph['nodes']??[]) as $n)if(($n['type']??'')==='audience'){$aud=is_array($n['config']??null)?$n['config']:[];break;}}
    $where=['1=1'];$args=[];
    if(!empty($aud['newsletter_only']))$where[]='c.marketing_opt_in=1';
    $stages=array_values(array_filter(array_map('strval',(array)($aud['stages']??[]))));if($stages){$where[]='c.status IN ('.implode(',',array_fill(0,count($stages),'?')).')';$args=array_merge($args,$stages);}
    if(!empty($aud['linked_accounts_only']))$where[]='c.user_id IS NOT NULL';
    if(!empty($aud['purchase_required']))$where[]="EXISTS (SELECT 1 FROM fan_crm_events e WHERE e.contact_id=c.id AND e.event_type='purchase')";
    $sql='SELECT c.* FROM fan_contacts c WHERE '.implode(' AND ',$where).' ORDER BY COALESCE(c.last_engaged_at,c.updated_at) DESC LIMIT '.max(1,min(5000,$limit));$q=sf_db()->prepare($sql);$q->execute($args);$rows=$q->fetchAll();
    $tags=array_values(array_filter(array_map(fn($x)=>strtolower(trim((string)$x)),(array)($aud['tags']??[]))));if($tags)$rows=array_values(array_filter($rows,function($c)use($tags){$own=array_map('strtolower',sf_campaign_decode($c['tags_json']??'[]',[]));return !array_diff($tags,$own);}));
    return $rows;
}
function sf_campaign_marketing_unsubscribe_link(array $contact): string {
    $raw=bin2hex(random_bytes(24));$hash=hash('sha256',$raw);sf_db()->prepare('UPDATE fan_contacts SET unsubscribe_token_hash=?,updated_at=? WHERE id=?')->execute([$hash,gmdate('c'),(int)$contact['id']]);$base=sf_public_base_url();return ($base!==''?$base:'').'/?view=newsletter&unsubscribe='.rawurlencode($raw);
}
function sf_campaign_send_email_node(array $campaign,string $nodeId,int $adminId,bool $confirmed=false): array {
    if(!$confirmed)throw new RuntimeException('Campaign email send requires explicit Admin confirmation.');if(($campaign['status']??'')!=='published')throw new RuntimeException('Publish the campaign before sending campaign email.');
    $node=sf_campaign_find_node($campaign,$nodeId);if(!$node||($node['type']??'')!=='email')throw new InvalidArgumentException('Email node not found.');$cfg=(array)($node['config']??[]);$subject=sf_clean_text($cfg['subject']??'',255);$body=sf_clean_text($cfg['body']??'',20000);if($subject===''||$body==='')throw new InvalidArgumentException('Email subject and body are required.');
    $contacts=array_values(array_filter(sf_campaign_audience_contacts($campaign,2000),fn($c)=>!empty($c['marketing_opt_in'])));$now=gmdate('c');$q=sf_db()->prepare('INSERT INTO campaign_message_runs(campaign_id,node_id,admin_user_id,status,total_recipients,sent_count,failed_count,started_at,completed_at,details_json) VALUES(?,?,?,"running",?,0,0,?,NULL,?)');$q->execute([(int)$campaign['id'],$nodeId,$adminId?:null,count($contacts),$now,sf_campaign_json(['subject'=>$subject])]);$runId=(int)sf_db()->lastInsertId();$sent=0;$failed=0;
    foreach($contacts as $c){try{$unsubscribe=sf_campaign_marketing_unsubscribe_link($c);$full=$body."\n\nUnsubscribe: ".$unsubscribe;$r=sf_transactional_email((int)($c['user_id']??0),(string)$c['email'],$subject,$full,'campaign_marketing');if(in_array((string)($r['status']??''),['sent','logged','queued'],true))$sent++;else$failed++;sf_campaign_log_event((int)$campaign['id'],'campaign_email_sent',null,(int)$c['id'],$nodeId,['run_id'=>$runId,'status'=>$r['status']??'']);}catch(Throwable $e){$failed++;}}
    sf_db()->prepare('UPDATE campaign_message_runs SET status=?,sent_count=?,failed_count=?,completed_at=? WHERE id=?')->execute([$failed?'completed_with_errors':'completed',$sent,$failed,gmdate('c'),$runId]);sf_log_admin_action($adminId,'campaign_email_sent','campaign',(string)$campaign['id'],['node_id'=>$nodeId,'recipients'=>count($contacts),'sent'=>$sent,'failed'=>$failed]);return ['run_id'=>$runId,'total'=>count($contacts),'sent'=>$sent,'failed'=>$failed];
}
function sf_campaign_set_session_attribution(array $campaign,array $participant): void {
    sf_user_session();$_SESSION['sf_campaign_attribution']=['campaign_id'=>(int)$campaign['id'],'participant_id'=>(int)($participant['id']??0),'contact_id'=>(int)($participant['contact_id']??0),'set_at'=>time(),'expires_at'=>time()+604800];
}
function sf_campaign_attribute_order_from_session(string $orderId,int $totalCents): void {
    sf_user_session();$a=(array)($_SESSION['sf_campaign_attribution']??[]);if(empty($a['campaign_id'])||empty($a['participant_id'])||(int)($a['expires_at']??0)<time())return;
    $cid=(int)$a['campaign_id'];$pid=(int)$a['participant_id'];$contactId=(int)($a['contact_id']??0);$q=sf_db()->prepare("SELECT 1 FROM campaign_events WHERE campaign_id=? AND participant_id=? AND event_type='purchase_attributed' AND metadata_json LIKE ? LIMIT 1");$q->execute([$cid,$pid,'%'.$orderId.'%']);if($q->fetchColumn())return;
    sf_campaign_log_event($cid,'purchase_attributed',$pid,$contactId?:null,'',['order_id'=>$orderId,'total_cents'=>$totalCents]);$now=gmdate('c');sf_db()->prepare('UPDATE campaign_participants SET state="converted",converted_at=COALESCE(converted_at,?),last_event_at=? WHERE id=?')->execute([$now,$now,$pid]);
    if($contactId){$contact=sf_crm_contact_by_id($contactId);sf_crm_log_event($contactId,!empty($contact['user_id'])?(int)$contact['user_id']:null,'campaign_purchase_attributed','Purchase attributed to campaign','order',$orderId,['campaign_id'=>$cid,'total_cents'=>$totalCents]);}
}
function sf_campaign_analytics(int $campaignId): array {
    sf_campaign_ensure_schema();$pdo=sf_db();$q=$pdo->prepare('SELECT COUNT(*) FROM campaign_participants WHERE campaign_id=?');$q->execute([$campaignId]);$participants=(int)$q->fetchColumn();$q=$pdo->prepare("SELECT COUNT(*) FROM campaign_participants WHERE campaign_id=? AND converted_at IS NOT NULL");$q->execute([$campaignId]);$converted=(int)$q->fetchColumn();$q=$pdo->prepare('SELECT event_type,COUNT(*) c FROM campaign_events WHERE campaign_id=? GROUP BY event_type ORDER BY c DESC');$q->execute([$campaignId]);$events=[];foreach($q->fetchAll() as $r)$events[(string)$r['event_type']]=(int)$r['c'];$q=$pdo->prepare('SELECT entitlement_type,status,COUNT(*) c FROM campaign_entitlements WHERE campaign_id=? GROUP BY entitlement_type,status');$q->execute([$campaignId]);$offers=$q->fetchAll();$q=$pdo->prepare("SELECT metadata_json FROM campaign_events WHERE campaign_id=? AND event_type IN ('purchase_attributed','offer_redeemed')");$q->execute([$campaignId]);$revenue=0;$orders=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $meta){$m=sf_campaign_decode($meta,[]);$oid=(string)($m['order_id']??'');if($oid!==''&&!isset($orders[$oid])){$orders[$oid]=true;$revenue+=(int)($m['total_cents']??0);}}return ['participants'=>$participants,'converted'=>$converted,'conversion_rate'=>$participants?round($converted*100/$participants,1):0,'attributed_orders'=>count($orders),'attributed_revenue_cents'=>$revenue,'events'=>$events,'offers'=>$offers];
}
function sf_campaign_admin_summary(): array {
    sf_campaign_ensure_schema();$pdo=sf_db();return ['campaigns'=>(int)$pdo->query('SELECT COUNT(*) FROM campaigns')->fetchColumn(),'active'=>(int)$pdo->query("SELECT COUNT(*) FROM campaigns WHERE status='published'")->fetchColumn(),'participants'=>(int)$pdo->query('SELECT COUNT(*) FROM campaign_participants')->fetchColumn(),'claims'=>(int)$pdo->query('SELECT COUNT(*) FROM campaign_entitlements')->fetchColumn(),'redemptions'=>(int)$pdo->query("SELECT COUNT(*) FROM campaign_entitlements WHERE status='redeemed'")->fetchColumn()];
}

function sf_campaign_live_summaries(int $limit=12): array {
    sf_campaign_ensure_schema();$now=gmdate('c');$q=sf_db()->prepare("SELECT * FROM campaigns WHERE status='published' AND (starts_at IS NULL OR starts_at='' OR starts_at<=?) AND (ends_at IS NULL OR ends_at='' OR ends_at>=?) ORDER BY COALESCE(starts_at,created_at) DESC LIMIT ".max(1,min(50,$limit)));$q->execute([$now,$now]);$rows=[];
    foreach($q->fetchAll() as $r){$r['graph']=sf_campaign_decode($r['graph_json']??'',[]);$offers=[];foreach((array)($r['graph']['nodes']??[]) as $n)if(str_starts_with((string)($n['type']??''),'offer_'))$offers[]=sf_clean_text($n['config']['title']??$n['type'],180);$rows[]=['id'=>(int)$r['id'],'slug'=>(string)$r['slug'],'name'=>(string)$r['name'],'goal'=>(string)$r['goal'],'headline'=>(string)$r['headline'],'offers'=>$offers];}
    return $rows;
}
function sf_campaign_match_query(string $query): ?array {
    $terms=array_values(array_filter(preg_split('/[^a-z0-9]+/i',strtolower($query))?:[],fn($x)=>strlen($x)>2));$best=null;$bestScore=0;
    foreach(sf_campaign_live_summaries(20) as $c){$hay=strtolower($c['name'].' '.$c['headline'].' '.$c['goal'].' '.implode(' ',$c['offers']));$score=0;foreach($terms as $t)if(str_contains($hay,$t))$score++;if($score>$bestScore){$bestScore=$score;$best=$c;}}
    return $bestScore>0?$best:null;
}
