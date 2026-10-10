<?php
declare(strict_types=1);

function sf_calendar_json(array $v): string {return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';}
function sf_calendar_decode(mixed $v,array $fallback=[]): array {$d=is_array($v)?$v:json_decode((string)$v,true);return is_array($d)?$d:$fallback;}
function sf_calendar_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS operating_plans (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,plan_type TEXT NOT NULL DEFAULT 'custom',status TEXT NOT NULL DEFAULT 'draft',target_at TEXT NOT NULL,timezone TEXT NOT NULL DEFAULT 'America/Phoenix',primary_entity_type TEXT NOT NULL DEFAULT '',primary_entity_id TEXT NOT NULL DEFAULT '',notes TEXT NOT NULL DEFAULT '',created_by INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_operating_plans_target ON operating_plans(status,target_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS operating_milestones (id INTEGER PRIMARY KEY AUTOINCREMENT,plan_id INTEGER NOT NULL,title TEXT NOT NULL,milestone_type TEXT NOT NULL DEFAULT 'task',due_at TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'pending',priority TEXT NOT NULL DEFAULT 'normal',blocking INTEGER NOT NULL DEFAULT 0,owner_label TEXT NOT NULL DEFAULT '',entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',depends_on_id INTEGER NULL,offset_days INTEGER NULL,sort_order INTEGER NOT NULL DEFAULT 0,notes TEXT NOT NULL DEFAULT '',completed_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(plan_id) REFERENCES operating_plans(id) ON DELETE CASCADE,FOREIGN KEY(depends_on_id) REFERENCES operating_milestones(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_operating_milestones_due ON operating_milestones(status,due_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_operating_milestones_plan ON operating_milestones(plan_id,sort_order,id)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS operating_plans (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(220) NOT NULL,plan_type VARCHAR(40) NOT NULL DEFAULT 'custom',status VARCHAR(24) NOT NULL DEFAULT 'draft',target_at VARCHAR(40) NOT NULL,timezone VARCHAR(80) NOT NULL DEFAULT 'America/Phoenix',primary_entity_type VARCHAR(40) NOT NULL DEFAULT '',primary_entity_id VARCHAR(160) NOT NULL DEFAULT '',notes TEXT NOT NULL,created_by BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_operating_plans_target(status,target_at),CONSTRAINT fk_operating_plan_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS operating_milestones (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,plan_id BIGINT UNSIGNED NOT NULL,title VARCHAR(255) NOT NULL,milestone_type VARCHAR(60) NOT NULL DEFAULT 'task',due_at VARCHAR(40) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',priority VARCHAR(20) NOT NULL DEFAULT 'normal',blocking TINYINT(1) NOT NULL DEFAULT 0,owner_label VARCHAR(160) NOT NULL DEFAULT '',entity_type VARCHAR(40) NOT NULL DEFAULT '',entity_id VARCHAR(160) NOT NULL DEFAULT '',depends_on_id BIGINT UNSIGNED NULL,offset_days INT NULL,sort_order INT NOT NULL DEFAULT 0,notes TEXT NOT NULL,completed_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_operating_milestones_due(status,due_at),INDEX idx_operating_milestones_plan(plan_id,sort_order,id),CONSTRAINT fk_operating_milestone_plan FOREIGN KEY(plan_id) REFERENCES operating_plans(id) ON DELETE CASCADE,CONSTRAINT fk_operating_milestone_dep FOREIGN KEY(depends_on_id) REFERENCES operating_milestones(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }$done=true;
}
function sf_calendar_templates(): array {
    return [
      'single_release'=>[
        ['-56','Final mix/master approved','master','high',1],
        ['-49','Credits, metadata and ISRC complete','metadata','high',1],
        ['-42','Primary artwork approved','artwork','high',1],
        ['-35','Campaign journey drafted','campaign','normal',0],
        ['-28','Merch / physical offer ready','merch','normal',0],
        ['-21','Newsletter + fan segment prepared','newsletter','normal',0],
        ['-14','VIP / presale / member access checked','vip','normal',0],
        ['-7','Public pages, media and links QA','qa','high',1],
        ['0','Release day','launch','high',1],
        ['2','Post-release fan follow-up','follow_up','normal',0],
      ],
      'album_release'=>[
        ['-84','Album masters locked','master','high',1],
        ['-77','Full credits and rights metadata complete','metadata','high',1],
        ['-70','Cover + release media package approved','artwork','high',1],
        ['-63','Physical merch / bundles configured','merch','high',0],
        ['-56','Campaign + lifecycle journeys drafted','campaign','normal',0],
        ['-42','Ticket/show tie-ins and VIP access planned','vip','normal',0],
        ['-28','Newsletter sequence and fan segments ready','newsletter','normal',0],
        ['-14','Store, release, campaign and media QA','qa','high',1],
        ['-7','Final launch readiness review','qa','high',1],
        ['0','Album release day','launch','high',1],
        ['3','Post-launch follow-up / conversion review','follow_up','normal',0],
        ['14','Two-week performance review','analytics','normal',0],
      ],
      'show_launch'=>[
        ['-42','Show record / venue details confirmed','show','high',1],
        ['-35','Ticket / VIP offer configured','ticket','high',1],
        ['-28','Campaign + member presale prepared','campaign','normal',0],
        ['-21','Poster and show media ready','artwork','normal',0],
        ['-14','Newsletter / fan segment ready','newsletter','normal',0],
        ['-7','Guest list and capacity QA','qa','high',1],
        ['0','Show day','launch','high',1],
        ['1','Archive setlist / live media / follow-up','archive','normal',0],
      ],
      'campaign_launch'=>[
        ['-21','Audience / segment approved','audience','high',1],
        ['-14','Landing page and offer media ready','campaign','high',1],
        ['-10','Email / Agent messaging reviewed','newsletter','normal',0],
        ['-7','Offer entitlement + conversion path QA','qa','high',1],
        ['0','Campaign launch','launch','high',1],
        ['7','Campaign conversion review','analytics','normal',0],
      ],
      'custom'=>[]
    ];
}
function sf_calendar_valid_date(string $v): bool {return $v!==''&&strtotime($v)!==false;}
function sf_calendar_shift(string $target,int $days): string {$t=strtotime($target);if($t===false)throw new InvalidArgumentException('Target date is invalid.');return gmdate('c',$t+$days*86400);}
function sf_calendar_plan(int $id): ?array {
    sf_calendar_ensure_schema();$q=sf_db()->prepare('SELECT * FROM operating_plans WHERE id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)return null;$q=sf_db()->prepare('SELECT * FROM operating_milestones WHERE plan_id=? ORDER BY sort_order,due_at,id');$q->execute([$id]);$p['milestones']=$q->fetchAll();$p['readiness']=sf_calendar_readiness($p,$p['milestones']);return $p;
}
function sf_calendar_plans(int $limit=200): array {
    sf_calendar_ensure_schema();$q=sf_db()->query('SELECT * FROM operating_plans ORDER BY CASE status WHEN "active" THEN 0 WHEN "draft" THEN 1 WHEN "completed" THEN 2 ELSE 3 END,target_at,id LIMIT '.max(1,min(500,$limit)));$rows=$q->fetchAll();foreach($rows as &$p){$q=sf_db()->prepare('SELECT * FROM operating_milestones WHERE plan_id=? ORDER BY sort_order,due_at,id');$q->execute([(int)$p['id']]);$p['milestones']=$q->fetchAll();$p['readiness']=sf_calendar_readiness($p,$p['milestones']);}unset($p);return $rows;
}
function sf_calendar_readiness(array $plan,array $milestones=[]): array {
    $total=count($milestones);$done=0;$overdue=0;$blocked=0;$next=null;$now=time();
    $statusBy=[];foreach($milestones as $m)$statusBy[(int)$m['id']]=$m['status'];
    foreach($milestones as $m){if($m['status']==='completed'){$done++;continue;}$due=strtotime((string)$m['due_at']);if($due!==false&&$due<$now)$overdue++;$dep=(int)($m['depends_on_id']??0);if($dep&&($statusBy[$dep]??'')!=='completed')$blocked++;if(!$next||strcmp((string)$m['due_at'],(string)$next['due_at'])<0)$next=$m;}
    $blockingOpen=count(array_filter($milestones,fn($m)=>!empty($m['blocking'])&&$m['status']!=='completed'));
    return ['total'=>$total,'completed'=>$done,'percent'=>$total?round($done*100/$total):100,'overdue'=>$overdue,'blocked'=>$blocked,'blocking_open'=>$blockingOpen,'ready'=>$blockingOpen===0&&$overdue===0,'next'=>$next];
}
function sf_calendar_save_plan(array $raw,int $adminId): array {
    sf_calendar_ensure_schema();$id=max(0,(int)($raw['id']??0));$name=sf_clean_text($raw['name']??'',220);if($name==='')throw new InvalidArgumentException('Plan name is required.');$type=(string)($raw['plan_type']??'custom');if(!array_key_exists($type,sf_calendar_templates()))$type='custom';$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','active','completed','archived'],true))throw new InvalidArgumentException('Invalid plan status.');$target=sf_clean_text($raw['target_at']??'',40);if(!sf_calendar_valid_date($target))throw new InvalidArgumentException('Target date is required.');$tz=sf_clean_text($raw['timezone']??'America/Phoenix',80);try{new DateTimeZone($tz);}catch(Throwable $e){throw new InvalidArgumentException('Timezone is invalid.');}$entityType=sf_clean_text($raw['primary_entity_type']??'',40);$entityId=sf_clean_text($raw['primary_entity_id']??'',160);$notes=sf_clean_text($raw['notes']??'',5000);$now=gmdate('c');
    if($id){$q=sf_db()->prepare('UPDATE operating_plans SET name=?,plan_type=?,status=?,target_at=?,timezone=?,primary_entity_type=?,primary_entity_id=?,notes=?,updated_at=? WHERE id=?');$q->execute([$name,$type,$status,$target,$tz,$entityType,$entityId,$notes,$now,$id]);if(!$q->rowCount()&&!sf_calendar_plan($id))throw new InvalidArgumentException('Operating plan not found.');}
    else{$q=sf_db()->prepare('INSERT INTO operating_plans(name,plan_type,status,target_at,timezone,primary_entity_type,primary_entity_id,notes,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$name,$type,$status,$target,$tz,$entityType,$entityId,$notes,$adminId?:null,$now,$now]);$id=(int)sf_db()->lastInsertId();if(empty($raw['skip_template']))sf_calendar_seed_template($id,$type,$target);}
    return sf_calendar_plan($id)?:[];
}
function sf_calendar_seed_template(int $planId,string $type,string $target): int {
    $rows=sf_calendar_templates()[$type]??[];$now=gmdate('c');$i=0;$previous=null;foreach($rows as $row){[$offset,$title,$kind,$priority,$blocking]=$row;$due=sf_calendar_shift($target,(int)$offset);$q=sf_db()->prepare('INSERT INTO operating_milestones(plan_id,title,milestone_type,due_at,status,priority,blocking,owner_label,entity_type,entity_id,depends_on_id,offset_days,sort_order,notes,completed_at,created_at,updated_at) VALUES(?,?,?,?,"pending",?,?,?,"","",?,?,?,"",NULL,?,?)');$q->execute([$planId,$title,$kind,$due,$priority,$blocking,'',$previous,(int)$offset,$i,$now,$now]);$previous=(int)sf_db()->lastInsertId();$i++;}return $i;
}
function sf_calendar_milestone(int $id): ?array {sf_calendar_ensure_schema();$q=sf_db()->prepare('SELECT * FROM operating_milestones WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_calendar_save_milestone(array $raw): array {
    sf_calendar_ensure_schema();$id=max(0,(int)($raw['id']??0));$planId=max(1,(int)($raw['plan_id']??0));if(!sf_calendar_plan($planId))throw new InvalidArgumentException('Operating plan not found.');$title=sf_clean_text($raw['title']??'',255);if($title==='')throw new InvalidArgumentException('Milestone title is required.');$type=sf_clean_text($raw['milestone_type']??'task',60);$due=sf_clean_text($raw['due_at']??'',40);if(!sf_calendar_valid_date($due))throw new InvalidArgumentException('Milestone due date is required.');$status=(string)($raw['status']??'pending');if(!in_array($status,['pending','in_progress','completed','skipped'],true))throw new InvalidArgumentException('Invalid milestone status.');$priority=(string)($raw['priority']??'normal');if(!in_array($priority,['low','normal','high','urgent'],true))$priority='normal';$dep=max(0,(int)($raw['depends_on_id']??0))?:null;if($dep===$id&&$id>0)throw new InvalidArgumentException('A milestone cannot depend on itself.');if($dep){$dm=sf_calendar_milestone($dep);if(!$dm||(int)$dm['plan_id']!==$planId)throw new InvalidArgumentException('Dependency must belong to the same plan.');}$completed=$status==='completed'?gmdate('c'):null;$now=gmdate('c');$vals=[$title,$type,$due,$status,$priority,!empty($raw['blocking'])?1:0,sf_clean_text($raw['owner_label']??'',160),sf_clean_text($raw['entity_type']??'',40),sf_clean_text($raw['entity_id']??'',160),$dep,array_key_exists('offset_days',$raw)?(int)$raw['offset_days']:null,(int)($raw['sort_order']??0),sf_clean_text($raw['notes']??'',5000),$completed,$now];
    if($id){$q=sf_db()->prepare('UPDATE operating_milestones SET title=?,milestone_type=?,due_at=?,status=?,priority=?,blocking=?,owner_label=?,entity_type=?,entity_id=?,depends_on_id=?,offset_days=?,sort_order=?,notes=?,completed_at=?,updated_at=? WHERE id=? AND plan_id=?');$q->execute([...$vals,$id,$planId]);}
    else{$q=sf_db()->prepare('INSERT INTO operating_milestones(plan_id,title,milestone_type,due_at,status,priority,blocking,owner_label,entity_type,entity_id,depends_on_id,offset_days,sort_order,notes,completed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$planId,...array_slice($vals,0,14),$now,$now]);$id=(int)sf_db()->lastInsertId();}
    return sf_calendar_milestone($id)?:[];
}
function sf_calendar_set_milestone_status(int $id,string $status): array {
    $m=sf_calendar_milestone($id);if(!$m)throw new InvalidArgumentException('Milestone not found.');if(!in_array($status,['pending','in_progress','completed','skipped'],true))throw new InvalidArgumentException('Invalid milestone status.');if($status==='completed'&&!empty($m['depends_on_id'])){$dep=sf_calendar_milestone((int)$m['depends_on_id']);if($dep&&$dep['status']!=='completed')throw new RuntimeException('Complete the dependency first.');}$now=gmdate('c');sf_db()->prepare('UPDATE operating_milestones SET status=?,completed_at=?,updated_at=? WHERE id=?')->execute([$status,$status==='completed'?$now:null,$now,$id]);return sf_calendar_milestone($id)?:[];
}
function sf_calendar_delete_plan(int $id): bool {$q=sf_db()->prepare('DELETE FROM operating_plans WHERE id=?');$q->execute([$id]);return $q->rowCount()>0;}
function sf_calendar_delete_milestone(int $id): bool {$q=sf_db()->prepare('DELETE FROM operating_milestones WHERE id=?');$q->execute([$id]);return $q->rowCount()>0;}
function sf_calendar_source_events(): array {
    $out=[];$add=function(string $at,string $kind,string $title,string $entityType,string $entityId,string $phase='',string $status='')use(&$out){if($at===''||strtotime($at)===false)return;$out[]=['id'=>'src:'.$kind.':'.$entityType.':'.$entityId.':'.$phase,'at'=>$at,'kind'=>$kind,'title'=>$title,'entity_type'=>$entityType,'entity_id'=>$entityId,'phase'=>$phase,'status'=>$status,'source'=>'native'];};
    $rp=SF_ROOT.'/data/releases.json';$rels=is_file($rp)?json_decode((string)file_get_contents($rp),true):[];foreach((array)$rels as $r)$add((string)($r['release_date']??''),'release',(string)($r['title']??'Release'),'release',(string)($r['id']??''),'release_date',(string)($r['state']??''));
    if(function_exists('sf_live_shows'))foreach(sf_live_shows() as $s)$add((string)($s['date']??''),'show',(string)($s['title']??$s['venue']??'Show'),'show',(string)($s['id']??''),'show_date',(string)($s['status']??''));
    if(function_exists('sf_campaign_list'))foreach(sf_campaign_list(500) as $c){$add((string)($c['starts_at']??''),'campaign',(string)$c['name'],'campaign',(string)$c['id'],'starts',(string)$c['status']);$add((string)($c['ends_at']??''),'campaign',(string)$c['name'],'campaign',(string)$c['id'],'ends',(string)$c['status']);}
    try{sf_ticketing_ensure_schema();foreach(sf_db()->query('SELECT id,title,status,starts_at,ends_at FROM ticket_offers')->fetchAll() as $x){$add((string)($x['starts_at']??''),'ticket',(string)$x['title'],'ticket',(string)$x['id'],'starts',(string)$x['status']);$add((string)($x['ends_at']??''),'ticket',(string)$x['title'],'ticket',(string)$x['id'],'ends',(string)$x['status']);}}catch(Throwable $e){}
    try{sf_membership_ensure_schema();foreach(sf_db()->query('SELECT id,title,status,starts_at,ends_at FROM membership_content')->fetchAll() as $x){$add((string)($x['starts_at']??''),'member_content',(string)$x['title'],'membership_content',(string)$x['id'],'starts',(string)$x['status']);$add((string)($x['ends_at']??''),'member_content',(string)$x['title'],'membership_content',(string)$x['id'],'ends',(string)$x['status']);}}catch(Throwable $e){}
    try{sf_automation_ensure_schema();foreach(sf_automation_list(500) as $a)if(($a['status']??'')==='published'&&($a['trigger_type']??'')==='scheduled'){$hours=max(1,(int)($a['trigger']['interval_hours']??24));$base=(string)($a['last_scheduled_at']??$a['published_at']??$a['created_at']??'');if($base!=='')$add(gmdate('c',strtotime($base)+$hours*3600),'automation',(string)$a['name'],'automation',(string)$a['id'],'next_due','published');}}catch(Throwable $e){}
    usort($out,fn($a,$b)=>strcmp($a['at'],$b['at']));return $out;
}
function sf_calendar_timeline(?string $from=null,?string $to=null): array {
    sf_calendar_ensure_schema();$from=$from&&sf_calendar_valid_date($from)?$from:gmdate('c',time()-30*86400);$to=$to&&sf_calendar_valid_date($to)?$to:gmdate('c',time()+120*86400);$events=[];
    foreach(sf_calendar_source_events() as $e)if($e['at']>=$from&&$e['at']<=$to)$events[]=$e;
    $q=sf_db()->prepare('SELECT m.*,p.name plan_name,p.status plan_status FROM operating_milestones m JOIN operating_plans p ON p.id=m.plan_id WHERE m.due_at>=? AND m.due_at<=? ORDER BY m.due_at,m.id');$q->execute([$from,$to]);foreach($q->fetchAll() as $m)$events[]=['id'=>'milestone:'.$m['id'],'at'=>$m['due_at'],'kind'=>'milestone','title'=>$m['title'],'entity_type'=>$m['entity_type'],'entity_id'=>$m['entity_id'],'phase'=>$m['milestone_type'],'status'=>$m['status'],'source'=>'plan','plan_id'=>(int)$m['plan_id'],'plan_name'=>$m['plan_name'],'priority'=>$m['priority'],'blocking'=>!empty($m['blocking']),'owner_label'=>$m['owner_label']];
    usort($events,fn($a,$b)=>strcmp($a['at'],$b['at']));return $events;
}
function sf_calendar_dashboard(): array {
    $plans=sf_calendar_plans(200);$active=array_values(array_filter($plans,fn($p)=>$p['status']==='active'));$overdue=0;$blocking=0;$due7=0;$now=time();foreach($plans as $p)foreach($p['milestones'] as $m)if($m['status']!=='completed'&&$m['status']!=='skipped'){$due=strtotime((string)$m['due_at']);if($due!==false&&$due<$now)$overdue++;if(!empty($m['blocking']))$blocking++;if($due!==false&&$due>=$now&&$due<=$now+7*86400)$due7++;}
    return ['plans'=>count($plans),'active_plans'=>count($active),'overdue_milestones'=>$overdue,'open_blockers'=>$blocking,'due_next_7_days'=>$due7,'timeline'=>sf_calendar_timeline(gmdate('c',time()-7*86400),gmdate('c',time()+60*86400)),'plans_detail'=>$plans];
}
function sf_calendar_agent_context(): string {
    $d=sf_calendar_dashboard();$lines=[];$now=time();foreach($d['timeline'] as $e){$t=strtotime((string)$e['at']);if($t===false||$t<$now-86400||$t>$now+30*86400)continue;$lines[]='- '.$e['at'].' | '.$e['kind'].' | '.$e['title'].' | status '.$e['status'].(!empty($e['plan_name'])?' | plan '.$e['plan_name']:'');if(count($lines)>=24)break;}$head='OPERATING CALENDAR | active plans '.$d['active_plans'].' | overdue milestones '.$d['overdue_milestones'].' | blockers '.$d['open_blockers'].' | due next 7 days '.$d['due_next_7_days'];return $head.($lines?"\n".implode("\n",$lines):'');
}
