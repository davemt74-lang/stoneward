<?php
declare(strict_types=1);

function sf_notification_categories(): array {
    return [
        'listening'=>['label'=>'Listening reminders','description'=>'Remind me about music I left unfinished.'],
        'recommendation'=>['label'=>'Recommendations','description'=>'Occasional suggestions based on my Stonefellow listening.'],
        'release'=>['label'=>'New releases','description'=>'Tell me when new Stonefellow releases become available.'],
        'build'=>['label'=>'Saved builds','description'=>'Remind me about unfinished custom records or cassettes.'],
    ];
}
function sf_notification_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_preferences (user_id INTEGER NOT NULL,category TEXT NOT NULL,in_app_enabled INTEGER NOT NULL DEFAULT 1,email_enabled INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL,PRIMARY KEY(user_id,category),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_delivery_keys (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,dedupe_key TEXT NOT NULL,notification_id INTEGER NULL,email_outbox_id INTEGER NULL,created_at TEXT NOT NULL,UNIQUE(user_id,dedupe_key),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(notification_id) REFERENCES user_notifications(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notification_keys_created ON notification_delivery_keys(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_events (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,notification_id INTEGER NULL,event_type TEXT NOT NULL,metadata_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(notification_id) REFERENCES user_notifications(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notification_events_user_created ON notification_events(user_id,created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notification_events_notification ON notification_events(notification_id,event_type,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_generation_state (user_id INTEGER PRIMARY KEY,baseline_at TEXT NOT NULL,last_run_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_preferences (user_id BIGINT UNSIGNED NOT NULL,category VARCHAR(40) NOT NULL,in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,email_enabled TINYINT(1) NOT NULL DEFAULT 0,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,category),CONSTRAINT fk_notification_pref_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_delivery_keys (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,dedupe_key VARCHAR(255) NOT NULL,notification_id BIGINT UNSIGNED NULL,email_outbox_id BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_notification_dedupe(user_id,dedupe_key),INDEX idx_notification_keys_created(user_id,created_at),CONSTRAINT fk_notification_key_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_notification_key_notification FOREIGN KEY(notification_id) REFERENCES user_notifications(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,notification_id BIGINT UNSIGNED NULL,event_type VARCHAR(40) NOT NULL,metadata_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_notification_events_user_created(user_id,created_at),INDEX idx_notification_events_notification(notification_id,event_type,created_at),CONSTRAINT fk_notification_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_notification_event_notification FOREIGN KEY(notification_id) REFERENCES user_notifications(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_generation_state (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,baseline_at VARCHAR(40) NOT NULL,last_run_at VARCHAR(40) NOT NULL,CONSTRAINT fk_notification_state_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_notification_preference_defaults(): array {
    $out=[];foreach(sf_notification_categories() as $key=>$meta)$out[$key]=['category'=>$key,'label'=>$meta['label'],'description'=>$meta['description'],'in_app_enabled'=>true,'email_enabled'=>false];
    return $out;
}
function sf_notification_preferences(int $userId): array {
    sf_notification_ensure_schema();$out=sf_notification_preference_defaults();$q=sf_db()->prepare('SELECT category,in_app_enabled,email_enabled,updated_at FROM user_notification_preferences WHERE user_id=?');$q->execute([$userId]);
    foreach($q->fetchAll() as $r){$k=(string)$r['category'];if(!isset($out[$k]))continue;$out[$k]['in_app_enabled']=(bool)$r['in_app_enabled'];$out[$k]['email_enabled']=(bool)$r['email_enabled'];$out[$k]['updated_at']=$r['updated_at'];}
    return array_values($out);
}
function sf_notification_preferences_map(int $userId): array {$out=[];foreach(sf_notification_preferences($userId) as $r)$out[(string)$r['category']]=$r;return $out;}
function sf_notification_preferences_save(int $userId,array $raw): array {
    sf_notification_ensure_schema();$valid=sf_notification_categories();$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');$now=gmdate('c');
    foreach($raw as $row){if(!is_array($row))continue;$cat=(string)($row['category']??'');if(!isset($valid[$cat]))continue;$in=!empty($row['in_app_enabled'])?1:0;$email=!empty($row['email_enabled'])?1:0;
        if($driver==='sqlite')$sql='INSERT INTO user_notification_preferences(user_id,category,in_app_enabled,email_enabled,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(user_id,category) DO UPDATE SET in_app_enabled=excluded.in_app_enabled,email_enabled=excluded.email_enabled,updated_at=excluded.updated_at';
        else $sql='INSERT INTO user_notification_preferences(user_id,category,in_app_enabled,email_enabled,updated_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE in_app_enabled=VALUES(in_app_enabled),email_enabled=VALUES(email_enabled),updated_at=VALUES(updated_at)';
        $pdo->prepare($sql)->execute([$userId,$cat,$in,$email,$now]);
    }
    sf_log_user_activity($userId,'notification_preferences','Updated notification preferences','account',(string)$userId);
    return sf_notification_preferences($userId);
}
function sf_notification_event(int $userId,?int $notificationId,string $eventType,array $meta=[]): void {
    if($userId<1)return;sf_notification_ensure_schema();$q=sf_db()->prepare('INSERT INTO notification_events(user_id,notification_id,event_type,metadata_json,created_at) VALUES(?,?,?,?,?)');$q->execute([$userId,$notificationId?:null,sf_clean_text($eventType,40),sf_ops_json($meta),gmdate('c')]);
}
function sf_notification_dedupe_claim(int $userId,string $key): bool {
    sf_notification_ensure_schema();try{$q=sf_db()->prepare('INSERT INTO notification_delivery_keys(user_id,dedupe_key,notification_id,email_outbox_id,created_at) VALUES(?,?,NULL,NULL,?)');$q->execute([$userId,sf_clean_text($key,255),gmdate('c')]);return true;}catch(Throwable $e){return false;}
}
function sf_notification_dedupe_update(int $userId,string $key,?int $notificationId=null,?int $emailId=null): void {
    $q=sf_db()->prepare('UPDATE notification_delivery_keys SET notification_id=COALESCE(?,notification_id),email_outbox_id=COALESCE(?,email_outbox_id) WHERE user_id=? AND dedupe_key=?');$q->execute([$notificationId,$emailId,$userId,$key]);
}
function sf_smart_notification_deliver(int $userId,string $category,string $dedupeKey,string $title,string $body,string $link): array {
    $prefs=sf_notification_preferences_map($userId);$pref=$prefs[$category]??null;if(!$pref||!sf_notification_dedupe_claim($userId,$dedupeKey))return ['created'=>false,'notification_id'=>null,'email_status'=>''];
    $notificationId=null;$emailStatus='';
    if(!empty($pref['in_app_enabled'])){$notificationId=sf_notify_user($userId,$category,$title,$body,$link);sf_notification_dedupe_update($userId,$dedupeKey,$notificationId,null);}
    if(!empty($pref['email_enabled'])){
        $q=sf_db()->prepare('SELECT email FROM users WHERE id=? AND status=? LIMIT 1');$q->execute([$userId,'active']);$email=(string)($q->fetchColumn()?:'');
        if($email!==''){$base=rtrim(sf_public_base_url(),'/');$url=$link!==''?($base!==''?$base.'/'.ltrim($link,'/'):$link):$base;$mailBody=$body.($url!==''?"\n\nOpen Stonefellow: ".$url:'');$result=sf_transactional_email($userId,$email,$title,$mailBody,'smart_notification_'.$category);$emailStatus=(string)($result['status']??'');sf_notification_dedupe_update($userId,$dedupeKey,null,(int)($result['id']??0));sf_notification_event($userId,$notificationId,'email_delivery',['category'=>$category,'status'=>$emailStatus]);}
    }
    return ['created'=>$notificationId!==null||$emailStatus!=='','notification_id'=>$notificationId,'email_status'=>$emailStatus];
}
function sf_notification_seed_baseline(int $userId,string $now): void {
    foreach(sf_release_rows() as $r){$id=(string)($r['id']??'');if($id!=='')sf_notification_dedupe_claim($userId,'release:'.$id);}
}
function sf_notification_generate_for_user(int $userId): array {
    sf_notification_ensure_schema();sf_personalization_ensure_schema();sf_account_data_ensure_schema();$pdo=sf_db();$now=gmdate('c');
    $q=$pdo->prepare('SELECT baseline_at,last_run_at FROM notification_generation_state WHERE user_id=?');$q->execute([$userId]);$state=$q->fetch();
    if(!$state){$pdo->prepare('INSERT INTO notification_generation_state(user_id,baseline_at,last_run_at) VALUES(?,?,?)')->execute([$userId,$now,$now]);sf_notification_seed_baseline($userId,$now);return ['baseline_initialized'=>true,'generated'=>0,'last_run_at'=>$now];}
    $baseline=(string)$state['baseline_at'];$generated=0;$tracks=sf_track_map();
    $cutListen=gmdate('c',time()-12*3600);$q=$pdo->prepare('SELECT track_id,position_seconds,duration_seconds,updated_at FROM user_listening_progress WHERE user_id=? AND completed=0 AND position_seconds>=10 AND updated_at>=? AND updated_at<=? ORDER BY updated_at DESC LIMIT 2');$q->execute([$userId,$baseline,$cutListen]);
    foreach($q->fetchAll() as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$res=sf_smart_notification_deliver($userId,'listening','listen:'.$id.':'.$r['updated_at'],'Continue “'.($t['title']??$id).'”','You left this track unfinished. Pick it up from '.floor((int)$r['position_seconds']/60).':'.str_pad((string)((int)$r['position_seconds']%60),2,'0',STR_PAD_LEFT).'.','?view=history');if($res['created'])$generated++;}
    $cutBuild=gmdate('c',time()-24*3600);$q=$pdo->prepare('SELECT id,name,format,updated_at FROM user_saved_builds WHERE user_id=? AND updated_at>=? AND updated_at<=? ORDER BY updated_at DESC LIMIT 1');$q->execute([$userId,$baseline,$cutBuild]);
    foreach($q->fetchAll() as $r){$res=sf_smart_notification_deliver($userId,'build','build:'.$r['id'].':'.$r['updated_at'],'Your custom '.((string)$r['format']==='cassette'?'cassette':'record').' is waiting','Keep working on “'.(string)$r['name'].'” whenever you are ready.','?view=builder');if($res['created'])$generated++;}
    $baseDay=substr($baseline,0,10);$today=gmdate('Y-m-d');$releaseCount=0;foreach(sf_release_rows() as $r){if($releaseCount>=2)break;$id=(string)($r['id']??'');$date=(string)($r['release_date']??'');if($id===''||$date===''||$date<$baseDay||$date>$today||($r['state']??'published')!=='published'||($r['public_visible']??true)===false)continue;$res=sf_smart_notification_deliver($userId,'release','release:'.$id,'New release: '.(string)($r['title']??'Stonefellow release'),'A new Stonefellow release is available now.','?view=release&id='.rawurlencode($id));if($res['created']){$generated++;$releaseCount++;}}
    $q=$pdo->prepare("SELECT MAX(created_at) FROM listening_events WHERE user_id=? AND created_at>=? AND event_type IN ('start','resume','complete')");$q->execute([$userId,$baseline]);$recent=(string)($q->fetchColumn()?:'');
    if($recent!==''){$rec=sf_personalization_recommendations($userId,1);$item=$rec['items'][0]??null;if(is_array($item)&&!empty($item['track_id'])){$id=(string)$item['track_id'];$week=gmdate('o-W');$reason=(string)(($item['reasons'][0]??'Based on your recent Stonefellow listening.'));$res=sf_smart_notification_deliver($userId,'recommendation','recommendation:'.$week.':'.$id,'A Stonefellow pick for you: '.(string)($item['title']??$id),$reason,'?view=track&id='.rawurlencode($id));if($res['created'])$generated++;}}
    $pdo->prepare('UPDATE notification_generation_state SET last_run_at=? WHERE user_id=?')->execute([$now,$userId]);
    return ['baseline_initialized'=>false,'generated'=>$generated,'last_run_at'=>$now];
}
function sf_notification_generate_all(int $limit=500): array {
    sf_notification_ensure_schema();$limit=max(1,min(5000,$limit));$rows=sf_db()->query("SELECT id FROM users WHERE status='active' ORDER BY id ASC LIMIT ".$limit)->fetchAll();$users=0;$generated=0;$baselines=0;
    foreach($rows as $r){$x=sf_notification_generate_for_user((int)$r['id']);$users++;$generated+=(int)$x['generated'];if(!empty($x['baseline_initialized']))$baselines++;}
    return ['users'=>$users,'generated'=>$generated,'baselines_initialized'=>$baselines,'ran_at'=>gmdate('c')];
}
function sf_smart_user_notifications(int $userId,int $limit=60): array {
    sf_notification_ensure_schema();$limit=max(1,min(200,$limit));$q=sf_db()->prepare("SELECT n.id,n.kind,n.title,n.body,n.link_url,n.is_read,n.created_at,n.read_at FROM user_notifications n WHERE n.user_id=? AND NOT EXISTS(SELECT 1 FROM notification_events e WHERE e.notification_id=n.id AND e.event_type='dismiss') ORDER BY n.id DESC LIMIT ".$limit);$q->execute([$userId]);return $q->fetchAll();
}
function sf_notification_mark_read(int $userId,int $id): int {
    $now=gmdate('c');$q=sf_db()->prepare('UPDATE user_notifications SET is_read=1,read_at=COALESCE(read_at,?) WHERE id=? AND user_id=?');$q->execute([$now,$id,$userId]);if($q->rowCount())sf_notification_event($userId,$id,'read');return sf_notification_unread_count($userId);
}
function sf_notification_dismiss(int $userId,int $id): int {
    $q=sf_db()->prepare('SELECT id FROM user_notifications WHERE id=? AND user_id=?');$q->execute([$id,$userId]);if($q->fetchColumn()!==false){sf_notification_mark_read($userId,$id);sf_notification_event($userId,$id,'dismiss');}return sf_notification_unread_count($userId);
}
function sf_notification_click(int $userId,int $id): array {
    $q=sf_db()->prepare('SELECT link_url FROM user_notifications WHERE id=? AND user_id=? LIMIT 1');$q->execute([$id,$userId]);$link=$q->fetchColumn();if($link===false)throw new InvalidArgumentException('Notification not found.');sf_notification_mark_read($userId,$id);sf_notification_event($userId,$id,'click');return ['unread'=>sf_notification_unread_count($userId),'link_url'=>(string)$link];
}
function sf_notification_analytics(int $days=30,?int $userId=null): array {
    sf_notification_ensure_schema();$days=max(1,min(3650,$days));$since=gmdate('c',time()-$days*86400);$args=[$since];$where='e.created_at>=?';if($userId!==null){$where.=' AND e.user_id=?';$args[]=$userId;}
    $q=sf_db()->prepare("SELECT e.user_id,e.notification_id,e.event_type,e.created_at,n.kind,n.title FROM notification_events e LEFT JOIN user_notifications n ON n.id=e.notification_id WHERE $where ORDER BY e.id ASC");$q->execute($args);$rows=$q->fetchAll();
    $counts=['delivered'=>0,'read'=>0,'click'=>0,'dismiss'=>0,'email_delivery'=>0];$types=[];$users=[];$clicks=[];
    foreach($rows as $r){$ev=(string)$r['event_type'];if(isset($counts[$ev]))$counts[$ev]++;$kind=(string)($r['kind']??'service');$types[$kind]??=['kind'=>$kind,'delivered'=>0,'read'=>0,'click'=>0,'dismiss'=>0];if(isset($types[$kind][$ev]))$types[$kind][$ev]++;$uid=(int)$r['user_id'];$users[$uid]??=['user_id'=>$uid,'delivered'=>0,'read'=>0,'click'=>0,'dismiss'=>0,'listen_conversions'=>0,'purchase_conversions'=>0];if(isset($users[$uid][$ev]))$users[$uid][$ev]++;if($ev==='click')$clicks[]=$r;}
    $listenConversions=0;$purchaseConversions=0;foreach($clicks as $r){$uid=(int)$r['user_id'];$start=(string)$r['created_at'];$end=gmdate('c',strtotime($start)+86400);$l=sf_db()->prepare("SELECT 1 FROM listening_events WHERE user_id=? AND created_at>=? AND created_at<=? AND event_type IN ('start','resume') LIMIT 1");$l->execute([$uid,$start,$end]);if($l->fetchColumn()!==false){$listenConversions++;$users[$uid]['listen_conversions']++;}foreach(sf_analytics_order_rows($start,$uid) as $o){$created=(string)($o['created_at']??'');if($created>$end)continue;if(sf_analytics_paid_order($o)){$purchaseConversions++;$users[$uid]['purchase_conversions']++;break;}}}
    $userMeta=[];if($users){$ids=array_keys($users);$ph=implode(',',array_fill(0,count($ids),'?'));$q=sf_db()->prepare("SELECT id,display_name,email FROM users WHERE id IN ($ph)");$q->execute($ids);foreach($q->fetchAll() as $r)$userMeta[(int)$r['id']]=$r;}
    foreach($users as $uid=>&$row){$row['display_name']=(string)($userMeta[$uid]['display_name']??'');$row['email']=(string)($userMeta[$uid]['email']??'');$row['click_rate']=sf_analytics_percent($row['click'],$row['delivered']);}unset($row);
    return ['days'=>$days,'delivered'=>$counts['delivered'],'read'=>$counts['read'],'clicks'=>$counts['click'],'dismissed'=>$counts['dismiss'],'email_deliveries'=>$counts['email_delivery'],'read_rate'=>sf_analytics_percent($counts['read'],$counts['delivered']),'click_rate'=>sf_analytics_percent($counts['click'],$counts['delivered']),'listen_conversions'=>$listenConversions,'listen_conversion_rate'=>sf_analytics_percent($listenConversions,$counts['click']),'purchase_conversions'=>$purchaseConversions,'purchase_conversion_rate'=>sf_analytics_percent($purchaseConversions,$counts['click']),'types'=>array_values($types),'users'=>array_values($users),'recent'=>array_slice(array_reverse($rows),0,20)];
}
