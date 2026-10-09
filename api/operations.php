<?php
declare(strict_types=1);

function sf_ops_ensure_schema(): void {
    static $done=false;
    if($done) return;
    $pdo=sf_db(); $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,kind TEXT NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL DEFAULT '',link_url TEXT NOT NULL DEFAULT '',is_read INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,read_at TEXT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON user_notifications(user_id,is_read,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,event_type TEXT NOT NULL,title TEXT NOT NULL,entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_user_created ON user_activity(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS listening_events (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,session_key TEXT NOT NULL,track_id TEXT NOT NULL,event_type TEXT NOT NULL,position_seconds INTEGER NOT NULL DEFAULT 0,duration_seconds INTEGER NOT NULL DEFAULT 0,source TEXT NOT NULL DEFAULT 'player',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_listening_track_created ON listening_events(track_id,created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_listening_user_created ON listening_events(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS provider_health_checks (id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT NOT NULL,status TEXT NOT NULL,latency_ms INTEGER NOT NULL DEFAULT 0,message TEXT NOT NULL DEFAULT '',details_json TEXT NOT NULL DEFAULT '{}',checked_by_user_id INTEGER NULL,checked_at TEXT NOT NULL,FOREIGN KEY(checked_by_user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_provider_health_provider ON provider_health_checks(provider,checked_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,admin_user_id INTEGER NULL,action TEXT NOT NULL,entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_audit_created ON admin_audit_log(created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_event_log (id INTEGER PRIMARY KEY AUTOINCREMENT,severity TEXT NOT NULL,event_type TEXT NOT NULL,message TEXT NOT NULL,details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_site_event_created ON site_event_log(created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,identifier_hash TEXT NOT NULL,ip_hash TEXT NOT NULL,success INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_auth_attempt_lookup ON auth_attempts(identifier_hash,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_request_keys (request_key TEXT PRIMARY KEY,user_id INTEGER NULL,order_id TEXT NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notifications (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(60) NOT NULL,title VARCHAR(255) NOT NULL,body TEXT NOT NULL,link_url VARCHAR(500) NOT NULL DEFAULT '',is_read TINYINT(1) NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,read_at VARCHAR(40) NULL,INDEX idx_notifications_user_read(user_id,is_read,created_at),CONSTRAINT fk_notifications_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(80) NOT NULL,title VARCHAR(255) NOT NULL,entity_type VARCHAR(80) NOT NULL DEFAULT '',entity_id VARCHAR(180) NOT NULL DEFAULT '',metadata_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_activity_user_created(user_id,created_at),CONSTRAINT fk_activity_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS listening_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,session_key VARCHAR(128) NOT NULL,track_id VARCHAR(180) NOT NULL,event_type VARCHAR(40) NOT NULL,position_seconds INT NOT NULL DEFAULT 0,duration_seconds INT NOT NULL DEFAULT 0,source VARCHAR(40) NOT NULL DEFAULT 'player',created_at VARCHAR(40) NOT NULL,INDEX idx_listening_track_created(track_id,created_at),INDEX idx_listening_user_created(user_id,created_at),CONSTRAINT fk_listening_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS provider_health_checks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,provider VARCHAR(60) NOT NULL,status VARCHAR(32) NOT NULL,latency_ms INT NOT NULL DEFAULT 0,message TEXT NOT NULL,details_json LONGTEXT NOT NULL,checked_by_user_id BIGINT UNSIGNED NULL,checked_at VARCHAR(40) NOT NULL,INDEX idx_provider_health_provider(provider,checked_at),CONSTRAINT fk_provider_health_user FOREIGN KEY(checked_by_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,admin_user_id BIGINT UNSIGNED NULL,action VARCHAR(120) NOT NULL,entity_type VARCHAR(80) NOT NULL DEFAULT '',entity_id VARCHAR(180) NOT NULL DEFAULT '',details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_admin_audit_created(created_at),CONSTRAINT fk_admin_audit_user FOREIGN KEY(admin_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_event_log (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,severity VARCHAR(20) NOT NULL,event_type VARCHAR(80) NOT NULL,message TEXT NOT NULL,details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_site_event_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,identifier_hash CHAR(64) NOT NULL,ip_hash CHAR(64) NOT NULL,success TINYINT(1) NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,INDEX idx_auth_attempt_lookup(identifier_hash,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_request_keys (request_key VARCHAR(128) NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NULL,order_id VARCHAR(100) NOT NULL,created_at VARCHAR(40) NOT NULL,CONSTRAINT fk_order_request_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_ops_json(array $data): string { return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}'; }
function sf_notify_user(int $userId,string $kind,string $title,string $body='',string $link=''): int {
    sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO user_notifications(user_id,kind,title,body,link_url,is_read,created_at) VALUES(?,?,?,?,?,0,?)');
    $q->execute([$userId,sf_clean_text($kind,60),sf_clean_text($title,255),sf_clean_text($body,1000),sf_clean_text($link,500),gmdate('c')]);
    $id=(int)sf_db()->lastInsertId();if(function_exists('sf_notification_event'))sf_notification_event($userId,$id,'delivered',['kind'=>$kind]);return $id;
}
function sf_log_user_activity(int $userId,string $eventType,string $title,string $entityType='',string $entityId='',array $meta=[]): void {
    if($userId<1)return;sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO user_activity(user_id,event_type,title,entity_type,entity_id,metadata_json,created_at) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$userId,sf_clean_text($eventType,80),sf_clean_text($title,255),sf_clean_text($entityType,80),sf_clean_text($entityId,180),sf_ops_json($meta),gmdate('c')]);
    if(function_exists('sf_crm_record_user_activity'))sf_crm_record_user_activity($userId,$eventType,$title,$entityType,$entityId,$meta);
}
function sf_log_admin_action(?int $userId,string $action,string $entityType='',string $entityId='',array $details=[]): void {
    sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO admin_audit_log(admin_user_id,action,entity_type,entity_id,details_json,created_at) VALUES(?,?,?,?,?,?)');
    $q->execute([$userId?:null,sf_clean_text($action,120),sf_clean_text($entityType,80),sf_clean_text($entityId,180),sf_ops_json($details),gmdate('c')]);
}
function sf_log_site_event(string $severity,string $eventType,string $message,array $details=[]): void {
    sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO site_event_log(severity,event_type,message,details_json,created_at) VALUES(?,?,?,?,?)');
    $q->execute([sf_clean_text($severity,20),sf_clean_text($eventType,80),sf_clean_text($message,1000),sf_ops_json($details),gmdate('c')]);
}
function sf_user_notifications(int $userId,int $limit=60): array {
    sf_ops_ensure_schema();$limit=max(1,min(200,$limit));$q=sf_db()->prepare('SELECT id,kind,title,body,link_url,is_read,created_at,read_at FROM user_notifications WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$userId]);return $q->fetchAll();
}
function sf_user_history(int $userId,int $limit=80): array {
    sf_ops_ensure_schema();$limit=max(1,min(200,$limit));$q=sf_db()->prepare('SELECT id,event_type,title,entity_type,entity_id,metadata_json,created_at FROM user_activity WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$userId]);$rows=$q->fetchAll();foreach($rows as &$r){$r['metadata']=json_decode((string)$r['metadata_json'],true)?:[];unset($r['metadata_json']);}unset($r);return $rows;
}
function sf_user_brain_timeline(int $userId,int $limit=80): array {
    sf_agent_brain_ensure_schema();$limit=max(1,min(200,$limit));$q=sf_db()->prepare('SELECT id,phase,route,action,context_profile,needs_llm,requires_confirmation,request_excerpt,response_excerpt,provider_status,input_tokens,output_tokens,created_at FROM agent_brain_decisions WHERE user_id=? ORDER BY id DESC LIMIT '.$limit);$q->execute([$userId]);return $q->fetchAll();
}
function sf_notification_unread_count(int $userId): int {
    sf_ops_ensure_schema();$q=sf_db()->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id=? AND is_read=0');$q->execute([$userId]);return (int)$q->fetchColumn();
}
function sf_listen_record(?int $userId,string $sessionKey,string $trackId,string $eventType,int $position,int $duration,string $source='player'): void {
    sf_ops_ensure_schema();
    if(!in_array($eventType,['start','resume','pause','complete','progress','skip'],true))throw new InvalidArgumentException('Unsupported listening event.');
    if(!preg_match('/^[A-Za-z0-9._:-]{1,180}$/',$trackId)||!isset(sf_track_map()[$trackId]))throw new InvalidArgumentException('Invalid track.');
    $sessionKey=preg_replace('/[^A-Za-z0-9._:-]/','',$sessionKey)??'';if($sessionKey==='')$sessionKey=bin2hex(random_bytes(12));$sessionKey=substr($sessionKey,0,128);
    $window=$eventType==='progress'?20:2;$cut=gmdate('c',time()-$window);$dupe=sf_db()->prepare('SELECT id FROM listening_events WHERE session_key=? AND track_id=? AND event_type=? AND created_at>=? ORDER BY id DESC LIMIT 1');$dupe->execute([$sessionKey,$trackId,$eventType,$cut]);if($dupe->fetchColumn()!==false)return;
    $q=sf_db()->prepare('INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$userId?:null,$sessionKey,$trackId,$eventType,max(0,$position),max(0,$duration),sf_clean_text($source,40),gmdate('c')]);
    if($userId && in_array($eventType,['start','complete'],true)){
        $track=sf_track_map()[$trackId]??null;
        sf_log_user_activity($userId,$eventType==='complete'?'listen_complete':'listen_start',($eventType==='complete'?'Finished ':'Played ').($track['title']??$trackId),'track',$trackId,['position'=>$position,'duration'=>$duration]);
    }
}
function sf_listening_analytics(int $days=30,?int $userId=null): array {
    sf_ops_ensure_schema();$days=max(1,min(3650,$days));$since=gmdate('c',time()-$days*86400);$where='created_at>=?';$args=[$since];if($userId){$where.=' AND user_id=?';$args[]=$userId;}
    $pdo=sf_db();
    $q=$pdo->prepare("SELECT COUNT(*) FROM listening_events WHERE $where AND event_type='start'");$q->execute($args);$starts=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM listening_events WHERE $where AND event_type='complete'");$q->execute($args);$completes=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(DISTINCT session_key) FROM listening_events WHERE $where");$q->execute($args);$sessions=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN user_id END) FROM listening_events WHERE $where");$q->execute($args);$listeners=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT track_id,SUM(CASE WHEN event_type='start' THEN 1 ELSE 0 END) AS listens,SUM(CASE WHEN event_type='complete' THEN 1 ELSE 0 END) AS completes FROM listening_events WHERE $where AND event_type IN ('start','complete') GROUP BY track_id ORDER BY listens DESC LIMIT 30");$q->execute($args);$tracks=$q->fetchAll();
    $map=sf_track_map();foreach($tracks as &$r){$r['title']=$map[$r['track_id']]['title']??$r['track_id'];$r['release']=$map[$r['track_id']]['release']??'';}unset($r);
    $q=$pdo->prepare("SELECT substr(created_at,1,10) AS day,SUM(CASE WHEN event_type='start' THEN 1 ELSE 0 END) AS listens,SUM(CASE WHEN event_type='complete' THEN 1 ELSE 0 END) AS completes FROM listening_events WHERE $where GROUP BY substr(created_at,1,10) ORDER BY day ASC");$q->execute($args);$daily=$q->fetchAll();
    return ['days'=>$days,'starts'=>$starts,'completes'=>$completes,'sessions'=>$sessions,'listeners'=>$listeners,'completion_rate'=>$starts?round($completes/$starts*100,1):0,'tracks'=>$tracks,'daily'=>$daily];
}
function sf_listening_users(int $days=30): array {
    sf_ops_ensure_schema();$since=gmdate('c',time()-max(1,min(3650,$days))*86400);
    $q=sf_db()->prepare("SELECT l.user_id,COALESCE(u.display_name,'Guest') AS display_name,COALESCE(u.email,'') AS email,SUM(CASE WHEN l.event_type='start' THEN 1 ELSE 0 END) AS listens,SUM(CASE WHEN l.event_type='complete' THEN 1 ELSE 0 END) AS completes,MAX(l.created_at) AS last_listen_at FROM listening_events l LEFT JOIN users u ON u.id=l.user_id WHERE l.created_at>=? GROUP BY l.user_id,u.display_name,u.email ORDER BY listens DESC LIMIT 250");
    $q->execute([$since]);return $q->fetchAll();
}
function sf_ops_recent_admin_audit(int $limit=100): array {sf_ops_ensure_schema();$limit=max(1,min(250,$limit));$q=sf_db()->query("SELECT a.*,u.display_name,u.email FROM admin_audit_log a LEFT JOIN users u ON u.id=a.admin_user_id ORDER BY a.id DESC LIMIT ".$limit);return $q->fetchAll();}
function sf_ops_recent_site_events(int $limit=100): array {sf_ops_ensure_schema();$limit=max(1,min(250,$limit));return sf_db()->query("SELECT * FROM site_event_log ORDER BY id DESC LIMIT ".$limit)->fetchAll();}
function sf_auth_attempt_hash(string $email): string {return hash('sha256',strtolower(trim($email)));}
function sf_auth_ip_hash(): string {return hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));}
function sf_auth_login_allowed(string $email): array {
    sf_ops_ensure_schema();$hash=sf_auth_attempt_hash($email);$ip=sf_auth_ip_hash();$since=gmdate('c',time()-15*60);$q=sf_db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE identifier_hash=? AND success=0 AND created_at>=?');$q->execute([$hash,$since]);$emailFails=(int)$q->fetchColumn();$q=sf_db()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE ip_hash=? AND success=0 AND created_at>=?');$q->execute([$ip,$since]);$ipFails=(int)$q->fetchColumn();$blocked=$emailFails>=8||$ipFails>=30;
    return ['ok'=>!$blocked,'failures'=>$emailFails,'ip_failures'=>$ipFails,'retry_after_seconds'=>$blocked?900:0];
}
function sf_auth_record_attempt(string $email,bool $success): void {
    sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO auth_attempts(identifier_hash,ip_hash,success,created_at) VALUES(?,?,?,?)');$q->execute([sf_auth_attempt_hash($email),sf_auth_ip_hash(),$success?1:0,gmdate('c')]);
    // Keep the table bounded.
    if(random_int(1,50)===1){$cut=gmdate('c',time()-30*86400);sf_db()->prepare('DELETE FROM auth_attempts WHERE created_at<?')->execute([$cut]);}
}
function sf_order_request_claim(string $key,?int $userId): array {sf_ops_ensure_schema();if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$key))return ['state'=>'none','order_id'=>''];$pdo=sf_db();try{$q=$pdo->prepare('INSERT INTO order_request_keys(request_key,user_id,order_id,created_at) VALUES(?,?,?,?)');$q->execute([$key,$userId?:null,'',gmdate('c')]);return ['state'=>'new','order_id'=>''];}catch(Throwable $e){$q=$pdo->prepare('SELECT order_id,created_at FROM order_request_keys WHERE request_key=? LIMIT 1');$q->execute([$key]);$r=$q->fetch();if(!$r)return ['state'=>'none','order_id'=>''];if((string)($r['order_id']??'')!=='')return ['state'=>'existing','order_id'=>(string)$r['order_id']];if(strtotime((string)($r['created_at']??''))<time()-300){$pdo->prepare("UPDATE order_request_keys SET user_id=?,created_at=? WHERE request_key=? AND order_id=''")->execute([$userId?:null,gmdate('c'),$key]);return ['state'=>'new','order_id'=>''];}return ['state'=>'in_progress','order_id'=>''];}}
function sf_order_request_complete(string $key,string $orderId): void {if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/',$key))return;sf_ops_ensure_schema();$q=sf_db()->prepare('UPDATE order_request_keys SET order_id=? WHERE request_key=?');$q->execute([$orderId,$key]);}
function sf_ops_runtime_readiness(): array {
    $dirs=['storage'=>SF_ROOT.'/storage','orders'=>SF_ROOT.'/storage/orders','pod'=>SF_ROOT.'/storage/pod','knowledge'=>SF_ROOT.'/storage/knowledge'];
    $storage=[];foreach($dirs as $k=>$d){if(!is_dir($d))@mkdir($d,0770,true);$storage[$k]=['exists'=>is_dir($d),'writable'=>is_dir($d)&&is_writable($d)];}
    $backupDir=SF_ROOT.'/storage/backups';if(!is_dir($backupDir))@mkdir($backupDir,0770,true);
    return ['database_driver'=>(string)(sf_db_config()['driver']??''),'database_config'=>is_file(SF_DB_CONFIG),'app_secret'=>is_file(SF_AI_SECRET_CONFIG),'storage'=>$storage,'backup_ready'=>is_dir($backupDir)&&is_writable($backupDir),'disk_free_bytes'=>@disk_free_space(SF_ROOT)?:null,'php'=>PHP_VERSION];
}
function sf_provider_health_rows(): array {
    sf_ops_ensure_schema();$providers=['openai','anthropic','elevenlabs','jev','stripe','email'];$out=[];
    foreach($providers as $p){$q=sf_db()->prepare('SELECT provider,status,latency_ms,message,details_json,checked_at FROM provider_health_checks WHERE provider=? ORDER BY id DESC LIMIT 1');$q->execute([$p]);$r=$q->fetch();$out[$p]=$r?:['provider'=>$p,'status'=>'unknown','latency_ms'=>0,'message'=>'Not tested yet.','details_json'=>'{}','checked_at'=>null];}
    return $out;
}
function sf_provider_health_store(string $provider,string $status,int $latency,string $message,array $details=[],?int $adminUserId=null): array {
    sf_ops_ensure_schema();$q=sf_db()->prepare('INSERT INTO provider_health_checks(provider,status,latency_ms,message,details_json,checked_by_user_id,checked_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$provider,$status,max(0,$latency),sf_clean_text($message,1000),sf_ops_json($details),$adminUserId?:null,gmdate('c')]);return ['provider'=>$provider,'status'=>$status,'latency_ms'=>$latency,'message'=>$message,'checked_at'=>gmdate('c')];
}
