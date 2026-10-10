<?php
declare(strict_types=1);

function sf_crm_json(array $data): string {
    return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
}
function sf_crm_ensure_schema(): void {
    static $done=false;if($done)return;
    $pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL UNIQUE,email TEXT NOT NULL UNIQUE,display_name TEXT NOT NULL DEFAULT '',source TEXT NOT NULL DEFAULT 'account',status TEXT NOT NULL DEFAULT 'lead',marketing_opt_in INTEGER NOT NULL DEFAULT 0,marketing_opt_in_at TEXT NULL,unsubscribed_at TEXT NULL,agent_auto_engage INTEGER NOT NULL DEFAULT 1,last_engaged_at TEXT NULL,tags_json TEXT NOT NULL DEFAULT '[]',notes TEXT NOT NULL DEFAULT '',unsubscribe_token_hash TEXT NULL UNIQUE,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fan_contacts_status ON fan_contacts(status,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_crm_events (id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER NOT NULL,user_id INTEGER NULL,event_type TEXT NOT NULL,title TEXT NOT NULL,entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',metadata_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fan_crm_events_contact ON fan_crm_events(contact_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_agent_engagements (id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER NOT NULL,user_id INTEGER NULL,channel TEXT NOT NULL,trigger_type TEXT NOT NULL,dedupe_key TEXT NOT NULL UNIQUE,status TEXT NOT NULL,message_text TEXT NOT NULL,reason TEXT NOT NULL DEFAULT '',details_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,delivered_at TEXT NULL,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fan_agent_engagement_user ON fan_agent_engagements(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_posts (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,contact_id INTEGER NOT NULL,body_text TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'published',agent_reply_text TEXT NOT NULL DEFAULT '',agent_replied_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_community_posts_status_created ON community_posts(status,created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_contacts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL UNIQUE,email VARCHAR(190) NOT NULL UNIQUE,display_name VARCHAR(120) NOT NULL DEFAULT '',source VARCHAR(60) NOT NULL DEFAULT 'account',status VARCHAR(40) NOT NULL DEFAULT 'lead',marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,marketing_opt_in_at VARCHAR(40) NULL,unsubscribed_at VARCHAR(40) NULL,agent_auto_engage TINYINT(1) NOT NULL DEFAULT 1,last_engaged_at VARCHAR(40) NULL,tags_json LONGTEXT NOT NULL,notes TEXT NOT NULL,unsubscribe_token_hash CHAR(64) NULL UNIQUE,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_fan_contacts_status(status,updated_at),CONSTRAINT fk_fan_contact_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_crm_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,event_type VARCHAR(80) NOT NULL,title VARCHAR(255) NOT NULL,entity_type VARCHAR(80) NOT NULL DEFAULT '',entity_id VARCHAR(180) NOT NULL DEFAULT '',metadata_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_fan_crm_events_contact(contact_id,created_at),CONSTRAINT fk_fan_event_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE,CONSTRAINT fk_fan_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS fan_agent_engagements (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,contact_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,channel VARCHAR(40) NOT NULL,trigger_type VARCHAR(80) NOT NULL,dedupe_key VARCHAR(190) NOT NULL UNIQUE,status VARCHAR(40) NOT NULL,message_text TEXT NOT NULL,reason VARCHAR(255) NOT NULL DEFAULT '',details_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,delivered_at VARCHAR(40) NULL,INDEX idx_fan_agent_engagement_user(user_id,created_at),CONSTRAINT fk_fan_engagement_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE,CONSTRAINT fk_fan_engagement_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS community_posts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,contact_id BIGINT UNSIGNED NOT NULL,body_text VARCHAR(1200) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'published',agent_reply_text TEXT NOT NULL,agent_replied_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_community_posts_status_created(status,created_at),CONSTRAINT fk_community_post_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_community_post_contact FOREIGN KEY(contact_id) REFERENCES fan_contacts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_crm_email(string $email): string { return strtolower(trim($email)); }
function sf_crm_contact_by_id(int $id): ?array {
    sf_crm_ensure_schema();$q=sf_db()->prepare('SELECT * FROM fan_contacts WHERE id=?');$q->execute([$id]);$r=$q->fetch();return $r?:null;
}
function sf_crm_contact_by_user(int $userId): ?array {
    if($userId<1)return null;sf_crm_ensure_schema();$q=sf_db()->prepare('SELECT * FROM fan_contacts WHERE user_id=?');$q->execute([$userId]);$r=$q->fetch();return $r?:null;
}
function sf_crm_contact_by_email(string $email): ?array {
    $email=sf_crm_email($email);if($email==='')return null;sf_crm_ensure_schema();$q=sf_db()->prepare('SELECT * FROM fan_contacts WHERE email=?');$q->execute([$email]);$r=$q->fetch();return $r?:null;
}
function sf_crm_merge_contacts(int $keepId,int $dropId): void {
    if($keepId<1||$dropId<1||$keepId===$dropId)return;
    $pdo=sf_db();$pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE fan_crm_events SET contact_id=? WHERE contact_id=?')->execute([$keepId,$dropId]);
        $pdo->prepare('UPDATE fan_agent_engagements SET contact_id=? WHERE contact_id=?')->execute([$keepId,$dropId]);
        $pdo->prepare('UPDATE community_posts SET contact_id=? WHERE contact_id=?')->execute([$keepId,$dropId]);
        $pdo->prepare('DELETE FROM fan_contacts WHERE id=?')->execute([$dropId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function sf_crm_upsert_contact(string $email,string $name='',string $source='account',?int $userId=null,?bool $marketingOptIn=null,string $status=''): array {
    sf_crm_ensure_schema();$email=sf_crm_email($email);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('A valid email address is required.');
    $name=sf_clean_text($name,120);$source=sf_clean_text($source,60);$now=gmdate('c');$byUser=$userId?sf_crm_contact_by_user($userId):null;$byEmail=sf_crm_contact_by_email($email);
    if($byUser&&$byEmail&&(int)$byUser['id']!==(int)$byEmail['id']){
        $keep=(int)$byUser['id'];$drop=(int)$byEmail['id'];
        $opt=(int)$byUser['marketing_opt_in']||(int)$byEmail['marketing_opt_in'];
        sf_crm_merge_contacts($keep,$drop);$byUser=sf_crm_contact_by_id($keep);
        sf_db()->prepare('UPDATE fan_contacts SET marketing_opt_in=?,marketing_opt_in_at=COALESCE(marketing_opt_in_at,?),updated_at=? WHERE id=?')->execute([$opt,$opt?$now:null,$now,$keep]);
        $byEmail=null;
    }
    $row=$byUser?:$byEmail;
    if($row){
        $id=(int)$row['id'];$fields=['email'=>$email,'display_name'=>$name!==''?$name:(string)$row['display_name'],'source'=>$source!==''?$source:(string)$row['source'],'updated_at'=>$now];
        if($userId)$fields['user_id']=$userId;if($status!=='')$fields['status']=$status;
        if($marketingOptIn!==null){$fields['marketing_opt_in']=$marketingOptIn?1:0;$fields['marketing_opt_in_at']=$marketingOptIn?($row['marketing_opt_in_at']?:$now):null;$fields['unsubscribed_at']=$marketingOptIn?null:$now;}
        $sets=[];$args=[];foreach($fields as $k=>$v){$sets[]=$k.'=?';$args[]=$v;}$args[]=$id;sf_db()->prepare('UPDATE fan_contacts SET '.implode(',',$sets).' WHERE id=?')->execute($args);
        return sf_crm_contact_by_id($id)?:[];
    }
    $initialStatus=$status!==''?$status:($userId?'fan':'lead');$opt=$marketingOptIn===true?1:0;
    $q=sf_db()->prepare('INSERT INTO fan_contacts(user_id,email,display_name,source,status,marketing_opt_in,marketing_opt_in_at,unsubscribed_at,agent_auto_engage,last_engaged_at,tags_json,notes,unsubscribe_token_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,1,NULL,?,?,NULL,?,?)');
    $q->execute([$userId?:null,$email,$name,$source,$initialStatus,$opt,$opt?$now:null,null,'[]','',$now,$now]);
    return sf_crm_contact_by_id((int)sf_db()->lastInsertId())?:[];
}
function sf_crm_sync_user(int $userId): ?array {
    if($userId<1)return null;$q=sf_db()->prepare('SELECT id,email,display_name,status FROM users WHERE id=?');$q->execute([$userId]);$u=$q->fetch();if(!$u)return null;
    $contact=sf_crm_upsert_contact((string)$u['email'],(string)$u['display_name'],'account',$userId,null,'');
    return $contact?:null;
}
function sf_crm_log_event(int $contactId,?int $userId,string $eventType,string $title,string $entityType='',string $entityId='',array $meta=[]): int {
    sf_crm_ensure_schema();$now=gmdate('c');$q=sf_db()->prepare('INSERT INTO fan_crm_events(contact_id,user_id,event_type,title,entity_type,entity_id,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$contactId,$userId?:null,sf_clean_text($eventType,80),sf_clean_text($title,255),sf_clean_text($entityType,80),sf_clean_text($entityId,180),sf_crm_json($meta),$now]);$eventId=(int)sf_db()->lastInsertId();
    sf_db()->prepare('UPDATE fan_contacts SET last_engaged_at=?,updated_at=? WHERE id=?')->execute([$now,$now,$contactId]);
    if(function_exists('sf_automation_process_crm_event')&&empty($GLOBALS['sf_automation_suppressed']))sf_automation_process_crm_event($contactId,$eventType,$eventId);
    return $eventId;
}
function sf_crm_record_user_activity(int $userId,string $eventType,string $title,string $entityType='',string $entityId='',array $meta=[]): void {
    $c=sf_crm_sync_user($userId);if(!$c)return;$stage='';
    if($eventType==='purchase')$stage='customer';elseif(str_contains($eventType,'subscription'))$stage='member';elseif($eventType==='account_created')$stage='fan';
    if($stage!=='')sf_db()->prepare('UPDATE fan_contacts SET status=?,updated_at=? WHERE id=?')->execute([$stage,gmdate('c'),(int)$c['id']]);
    sf_crm_log_event((int)$c['id'],$userId,$eventType,$title,$entityType,$entityId,$meta);
}
function sf_crm_newsletter_signup(string $email,string $name=''): array {
    $email=sf_crm_email($email);$existing=sf_crm_contact_by_email($email);$wasOpted=$existing&&!empty($existing['marketing_opt_in']);$user=sf_user_by_email($email);$userId=$user?(int)$user['id']:null;
    $contact=sf_crm_upsert_contact($email,$name!==''?$name:(string)($user['display_name']??''),'newsletter',$userId,true,$userId?'fan':'lead');
    $token=bin2hex(random_bytes(24));$hash=hash('sha256',$token);sf_db()->prepare('UPDATE fan_contacts SET unsubscribe_token_hash=?,marketing_opt_in=1,marketing_opt_in_at=COALESCE(marketing_opt_in_at,?),unsubscribed_at=NULL,updated_at=? WHERE id=?')->execute([$hash,gmdate('c'),gmdate('c'),(int)$contact['id']]);
    sf_crm_log_event((int)$contact['id'],$userId,$wasOpted?'newsletter_signup_repeat':'newsletter_signup',$wasOpted?'Confirmed newsletter interest':'Joined the Stonefellow newsletter','newsletter','',[]);
    if(!$wasOpted){
        try{
            $base=sf_public_base_url();$link=($base!==''?$base:'').'/?view=community&unsubscribe='.rawurlencode($token);
            $body="You’re on the Stonefellow newsletter list.\n\nI’ll use this for Stonefellow music, releases, shows, community updates, and related news.\n\nUnsubscribe anytime: {$link}";
            sf_transactional_email($userId?:0,$email,'Welcome to the Stonefellow newsletter',$body,'newsletter_welcome');
        }catch(Throwable $e){}
        if($userId)sf_notify_user($userId,'community','Newsletter joined','You are subscribed to Stonefellow news and community updates.','?view=community');
    }
    return ['subscribed'=>true,'already_subscribed'=>$wasOpted,'contact_id'=>(int)$contact['id']];
}
function sf_crm_newsletter_unsubscribe(string $token): bool {
    sf_crm_ensure_schema();$token=trim($token);if(strlen($token)<20)return false;$hash=hash('sha256',$token);$q=sf_db()->prepare('SELECT * FROM fan_contacts WHERE unsubscribe_token_hash=?');$q->execute([$hash]);$c=$q->fetch();if(!$c)return false;
    $now=gmdate('c');sf_db()->prepare('UPDATE fan_contacts SET marketing_opt_in=0,unsubscribed_at=?,unsubscribe_token_hash=NULL,updated_at=? WHERE id=?')->execute([$now,$now,(int)$c['id']]);
    sf_crm_log_event((int)$c['id'],!empty($c['user_id'])?(int)$c['user_id']:null,'newsletter_unsubscribe','Unsubscribed from the Stonefellow newsletter','newsletter','',[]);
    return true;
}
function sf_crm_newsletter_state_for_user(int $userId): array {
    $c=sf_crm_sync_user($userId);return ['contact_id'=>(int)($c['id']??0),'marketing_opt_in'=>!empty($c['marketing_opt_in']),'agent_auto_engage'=>!isset($c['agent_auto_engage'])||!empty($c['agent_auto_engage']),'status'=>(string)($c['status']??'fan')];
}
function sf_community_posts(int $limit=80): array {
    sf_crm_ensure_schema();$limit=max(1,min(150,$limit));$q=sf_db()->query("SELECT p.id,p.user_id,p.body_text,p.agent_reply_text,p.agent_replied_at,p.created_at,u.display_name FROM community_posts p JOIN users u ON u.id=p.user_id WHERE p.status='published' ORDER BY p.id DESC LIMIT ".$limit);return $q->fetchAll();
}
function sf_community_create(int $userId,string $body): array {
    $body=trim($body);if($body===''||(function_exists('mb_strlen')?mb_strlen($body):strlen($body))>600)throw new InvalidArgumentException('Community posts must be between 1 and 600 characters.');
    if(preg_match_all('~https?://~i',$body)>2)throw new InvalidArgumentException('Please keep community posts focused and limit external links.');
    $c=sf_crm_sync_user($userId);if(!$c)throw new RuntimeException('Fan profile unavailable.');$since=gmdate('c',time()-600);$q=sf_db()->prepare("SELECT COUNT(*) FROM community_posts WHERE user_id=? AND created_at>=?");$q->execute([$userId,$since]);if((int)$q->fetchColumn()>=5)throw new RuntimeException('You’ve posted several times recently. Please wait a few minutes.');
    $now=gmdate('c');$q=sf_db()->prepare("INSERT INTO community_posts(user_id,contact_id,body_text,status,agent_reply_text,agent_replied_at,created_at,updated_at) VALUES(?,?,?,'published','',NULL,?,?)");$q->execute([$userId,(int)$c['id'],$body,$now,$now]);$id=(int)sf_db()->lastInsertId();
    sf_log_user_activity($userId,'community_post','Posted in the Stonefellow fan community','community_post',(string)$id,['excerpt'=>(function_exists('mb_substr')?mb_substr($body,0,180):substr($body,0,180))]);
    return ['id'=>$id,'body_text'=>$body,'created_at'=>$now,'display_name'=>(string)($c['display_name']??'Fan')];
}
function sf_community_delete(int $userId,int $postId): bool {
    sf_crm_ensure_schema();$q=sf_db()->prepare("UPDATE community_posts SET status='deleted',updated_at=? WHERE id=? AND user_id=? AND status<>'deleted'");$q->execute([gmdate('c'),$postId,$userId]);return $q->rowCount()>0;
}
function sf_crm_agent_log(int $contactId,?int $userId,string $channel,string $trigger,string $dedupe,string $message,string $reason,array $details=[]): int {
    sf_crm_ensure_schema();$now=gmdate('c');$q=sf_db()->prepare('INSERT INTO fan_agent_engagements(contact_id,user_id,channel,trigger_type,dedupe_key,status,message_text,reason,details_json,created_at,delivered_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$contactId,$userId?:null,$channel,$trigger,$dedupe,'delivered',$message,$reason,sf_crm_json($details),$now,$now]);$id=(int)sf_db()->lastInsertId();
    if($userId&&function_exists('sf_agent_brain_ensure_schema')){
        sf_agent_brain_ensure_schema();$d=['trigger'=>$trigger,'contact_id'=>$contactId,'engagement_id'=>$id,'reason'=>$reason,'message'=>$message]+$details;
        $q=sf_db()->prepare('INSERT INTO agent_brain_decisions(user_id,conversation_id,phase,route,action,context_profile,needs_llm,requires_confirmation,request_excerpt,response_excerpt,provider_run_id,provider_status,input_tokens,output_tokens,details_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$userId,'crm_auto','fan_engagement','fan_engagement_'.$trigger,'proactive_message','crm',0,0,$reason,sf_clean_text($message,500),'','local_policy',0,0,sf_crm_json($d),$now]);
    }
    sf_crm_log_event($contactId,$userId,'agent_auto_engagement','Agent proactively engaged fan','agent_engagement',(string)$id,['trigger'=>$trigger,'channel'=>$channel,'reason'=>$reason]);
    return $id;
}
function sf_crm_next_agent_engagement(int $userId): ?array {
    $c=sf_crm_sync_user($userId);if(!$c||empty($c['agent_auto_engage']))return null;$last=(string)($c['last_engaged_at']??'');
    $q=sf_db()->prepare("SELECT created_at FROM fan_agent_engagements WHERE user_id=? AND status='delivered' ORDER BY id DESC LIMIT 1");$q->execute([$userId]);$lastAuto=(string)($q->fetchColumn()?:'');
    if($lastAuto!==''&&strtotime($lastAuto)>time()-20*3600)return null;
    $q=sf_db()->prepare("SELECT id,event_type,title,entity_type,entity_id,created_at FROM user_activity WHERE user_id=? AND created_at>=? ORDER BY id DESC LIMIT 30");$q->execute([$userId,gmdate('c',time()-21*86400)]);$events=$q->fetchAll();
    $trigger='';$reason='';$message='';$eventId=0;$communityEnabled=!empty(sf_site_settings()['fan_community_enabled']);
    foreach($events as $e){
        $type=(string)$e['event_type'];$title=(string)$e['title'];$eventId=(int)$e['id'];
        if($type==='account_created'){$trigger='welcome';$reason='Fan created a Stonefellow account.';$message='Welcome to Stonefellow. I can help you explore the catalog, build a record or playlist, check tour dates, or stay connected through the newsletter.';break;}
        if($communityEnabled&&$type==='community_post'){$trigger='community_post';$reason='Fan just contributed to the community.';$message='I saw your community post. If you want to dig into anything you mentioned, ask me here and I can connect it to the music, archive, shows, or your library.';break;}
        if($type==='purchase'){$trigger='purchase';$reason='Fan recently supported Stonefellow with a purchase.';$message='Thanks for supporting Stonefellow. Your purchase is in My Library, and I can help you find related recordings or build something around it.';break;}
        if(str_contains($type,'playlist')){$trigger='playlist';$reason='Fan recently worked with a playlist.';$message='I noticed you’ve been working with playlists. Want me to help sequence one around a mood, era, or favorite track?';break;}
        if($type==='listen_complete'){$trigger='listening';$reason='Fan recently finished a Stonefellow recording.';$message=$title!==''?$title.'. Want me to take you somewhere related in the catalog or archive?':'You finished a Stonefellow recording. Want another one that connects to it?';break;}
    }
    if($trigger==='')return null;
    $dedupe='fan:auto:'.$userId.':'.$trigger.':'.($eventId?:date('Y-m-d'));$q=sf_db()->prepare('SELECT id FROM fan_agent_engagements WHERE dedupe_key=?');$q->execute([$dedupe]);if($q->fetchColumn())return null;
    $id=sf_crm_agent_log((int)$c['id'],$userId,'in_app',$trigger,$dedupe,$message,$reason,['activity_event_id'=>$eventId]);
    return ['id'=>$id,'message'=>$message,'trigger'=>$trigger,'reason'=>$reason,'contact_id'=>(int)$c['id']];
}
function sf_crm_agent_context(int $userId): string {
    $c=sf_crm_sync_user($userId);if(!$c)return '';$lines=['CRM FAN PROFILE','- stage '.($c['status']??'fan').' | newsletter '.(!empty($c['marketing_opt_in'])?'subscribed':'not subscribed').' | proactive agent '.(!empty($c['agent_auto_engage'])?'enabled':'disabled')];
    $q=sf_db()->prepare('SELECT event_type,title,created_at FROM fan_crm_events WHERE contact_id=? ORDER BY id DESC LIMIT 8');$q->execute([(int)$c['id']]);foreach($q->fetchAll() as $e)$lines[]='- '.($e['created_at']??'').' | '.($e['event_type']??'event').' | '.($e['title']??'');if(function_exists('sf_commerce_purchase_history')){foreach(sf_commerce_purchase_history($userId,(string)$c['email'],6) as $m)$lines[]='- merch | '.($m['title']??'Product').(($m['variant_title']??'')!==''?' · '.$m['variant_title']:'').' | qty '.($m['quantity']??1).' | order '.($m['order_id']??'');}
    if(function_exists('sf_automation_ensure_schema')){sf_automation_ensure_schema();$q=sf_db()->prepare('SELECT s.name FROM lifecycle_segment_memberships m JOIN campaign_segments s ON s.id=m.segment_id WHERE m.contact_id=? AND m.matched=1 ORDER BY s.name LIMIT 12');$q->execute([(int)$c['id']]);$segments=$q->fetchAll(PDO::FETCH_COLUMN);if($segments)$lines[]='- active segments '.implode(', ',$segments);$q=sf_db()->prepare("SELECT a.name,r.status,r.due_at FROM lifecycle_automation_runs r JOIN lifecycle_automations a ON a.id=r.automation_id WHERE r.contact_id=? AND r.status IN ('running','waiting') ORDER BY r.id DESC LIMIT 6");$q->execute([(int)$c['id']]);foreach($q->fetchAll() as $r)$lines[]='- lifecycle '.$r['name'].' | '.$r['status'].(!empty($r['due_at'])?' until '.$r['due_at']:'');}
    return implode("\n",$lines);
}
function sf_crm_admin_summary(): array {
    sf_crm_ensure_schema();$pdo=sf_db();return [
        'contacts'=>(int)$pdo->query('SELECT COUNT(*) FROM fan_contacts')->fetchColumn(),
        'newsletter'=>(int)$pdo->query('SELECT COUNT(*) FROM fan_contacts WHERE marketing_opt_in=1')->fetchColumn(),
        'linked_accounts'=>(int)$pdo->query('SELECT COUNT(*) FROM fan_contacts WHERE user_id IS NOT NULL')->fetchColumn(),
        'customers'=>(int)$pdo->query("SELECT COUNT(*) FROM fan_contacts WHERE status IN ('customer','member')")->fetchColumn(),
        'community_posts'=>(int)$pdo->query("SELECT COUNT(*) FROM community_posts WHERE status='published'")->fetchColumn(),
        'auto_engagements'=>(int)$pdo->query("SELECT COUNT(*) FROM fan_agent_engagements WHERE status='delivered'")->fetchColumn(),
    ];
}
function sf_crm_admin_contacts(int $limit=250,string $q=''): array {
    sf_crm_ensure_schema();$limit=max(1,min(500,$limit));$sql='SELECT c.*,u.role,u.status AS user_status FROM fan_contacts c LEFT JOIN users u ON u.id=c.user_id';$args=[];
    if($q!==''){$sql.=' WHERE c.email LIKE ? OR c.display_name LIKE ? OR c.status LIKE ?';$like='%'.$q.'%';$args=[$like,$like,$like];}$sql.=' ORDER BY COALESCE(c.last_engaged_at,c.updated_at) DESC,c.id DESC LIMIT '.$limit;$st=sf_db()->prepare($sql);$st->execute($args);return $st->fetchAll();
}
function sf_crm_admin_contact_detail(int $contactId): array {
    $c=sf_crm_contact_by_id($contactId);if(!$c)throw new InvalidArgumentException('Fan contact not found.');$uid=(int)($c['user_id']??0);
    $q=sf_db()->prepare('SELECT id,event_type,title,entity_type,entity_id,metadata_json,created_at FROM fan_crm_events WHERE contact_id=? ORDER BY id DESC LIMIT 120');$q->execute([$contactId]);$events=$q->fetchAll();
    $q=sf_db()->prepare('SELECT id,channel,trigger_type,status,message_text,reason,created_at,delivered_at FROM fan_agent_engagements WHERE contact_id=? ORDER BY id DESC LIMIT 80');$q->execute([$contactId]);$engagements=$q->fetchAll();
    $community=[];if($uid){$q=sf_db()->prepare('SELECT id,body_text,status,created_at FROM community_posts WHERE user_id=? ORDER BY id DESC LIMIT 50');$q->execute([$uid]);$community=$q->fetchAll();}
    $merch=function_exists('sf_commerce_purchase_history')?sf_commerce_purchase_history($uid?:null,(string)$c['email'],80):[];
    return ['contact'=>$c,'events'=>$events,'agent_engagements'=>$engagements,'community_posts'=>$community,'account_activity'=>$uid?sf_user_history($uid,60):[],'agent_brain'=>$uid?sf_user_brain_timeline($uid,60):[],'merch_purchases'=>$merch,'membership'=>$uid&&function_exists('sf_membership_state')?sf_membership_state($uid,false):null];
}
