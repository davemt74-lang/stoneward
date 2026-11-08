<?php
declare(strict_types=1);

require_once __DIR__.'/jukebox-core.php';

const SF_JUKEBOX_REMINDER_LEAD = 900;
const SF_JUKEBOX_ALERT_LEASE = 300;

function sf_jukebox_follow_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $userKey=$mysql?'BIGINT UNSIGNED':'INTEGER';
    $serial=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $dueIndex=$mysql?', INDEX jukebox_alerts_due (state,available_at,id)':'';
    $db->exec("CREATE TABLE IF NOT EXISTS jukebox_follows (
        session_id CHAR(32) NOT NULL,user_id $userKey NOT NULL,access_scope VARCHAR(8) NOT NULL,
        PRIMARY KEY(session_id,user_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");
    $db->exec("CREATE TABLE IF NOT EXISTS jukebox_alerts (
        id $serial,session_id CHAR(32) NOT NULL,user_id $userKey NOT NULL,event_type VARCHAR(12) NOT NULL,
        event_key VARCHAR(48) NOT NULL,event_at BIGINT NOT NULL,available_at BIGINT NOT NULL,
        state VARCHAR(10) NOT NULL DEFAULT 'pending',lease_token CHAR(32) NOT NULL DEFAULT '',
        attempts INTEGER NOT NULL DEFAULT 0,in_app_done INTEGER NOT NULL DEFAULT 0,push_receipts TEXT NOT NULL,
        UNIQUE(session_id,user_id,event_key),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE$dueIndex)$engine");
    if(!$mysql){
        $db->exec('CREATE INDEX IF NOT EXISTS jukebox_alerts_due ON jukebox_alerts (state,available_at,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS jukebox_alerts_user ON jukebox_alerts (user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS jukebox_follows_user ON jukebox_follows (user_id,session_id)');
    }
    $ready=true;
}

function sf_jukebox_following(string $id,int $userId): bool {
    return $userId>0&&(bool)sf_jukebox_query('SELECT 1 FROM jukebox_follows WHERE session_id=? AND user_id=?',[$id,$userId])->fetchColumn();
}

function sf_jukebox_follow_clear_user(int $userId): array {
    // Account closure must not initialize optional Jukebox storage.
    $catalog=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME'
        :"SELECT name FROM sqlite_master WHERE type='table' AND name";
    $present=sf_jukebox_query("$catalog IN ('jukebox_alerts','jukebox_follows')")->fetchAll(PDO::FETCH_COLUMN);
    $counts=[];
    foreach(['jukebox_alerts','jukebox_follows'] as $table){
        if(in_array($table,$present,true))$counts[$table]=sf_jukebox_query("DELETE FROM $table WHERE user_id=?",[$userId])->rowCount();
    }
    return $counts;
}

function sf_jukebox_follow_user_active(int $userId): bool {
    // Coordinate with account closure, which locks this user before removing follows.
    $lock=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    return sf_jukebox_query('SELECT status FROM users WHERE id=?'.$lock,[$userId])->fetchColumn()==='active';
}

/** Session mutation callers hold the jukebox session lock. */
function sf_jukebox_follow_set(array $session,int $userId,mixed $following): bool {
    if(!is_bool($following))throw new InvalidArgumentException('Choose whether to follow this session.');
    $id=$session['id'];
    if(!$following){
        sf_jukebox_query('DELETE FROM jukebox_follows WHERE session_id=? AND user_id=?',[$id,$userId]);
        sf_jukebox_query("UPDATE jukebox_alerts SET state='obsolete',lease_token='' WHERE session_id=? AND user_id=? AND state='pending'",
            [$id,$userId]);
        return false;
    }
    if(!sf_jukebox_follow_user_active($userId))throw new DomainException('This account cannot follow sessions.');
    $phase=sf_jukebox_phase($session);
    if(!in_array($phase,['upcoming','live'],true))throw new DomainException('This jukebox session is closed.');
    // The exact unlisted session ID is the invitation, independent of phone pairing.
    $scope=$session['listed']?'listed':'invite';
    $mysql=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $sql='INSERT INTO jukebox_follows (session_id,user_id,access_scope) VALUES (?,?,?)';
    $sql.=$mysql?" ON DUPLICATE KEY UPDATE access_scope=IF(access_scope='invite','invite',VALUES(access_scope))"
        :" ON CONFLICT(session_id,user_id) DO UPDATE SET access_scope=CASE WHEN access_scope='invite' THEN 'invite' ELSE excluded.access_scope END";
    sf_jukebox_query($sql,[$id,$userId,$scope]);
    if($phase==='upcoming')sf_jukebox_alert_reminder($session,$userId);
    return true;
}

function sf_jukebox_alert_enqueue(string $id,string $type,string $key,int $eventAt,int $availableAt,?int $userId=null): void {
    $recipients='session_id=?'.($userId===null?'':' AND user_id=?');
    $recipientArgs=$userId===null?[$id]:[$id,$userId];
    if($type==='reminder'){
        // A reminder can be armed again after re-following or returning to the same time,
        // provided an earlier attempt never created its in-app notification.
        sf_jukebox_query("UPDATE jukebox_alerts SET state='pending',available_at=?,attempts=0
            WHERE session_id=? AND event_key=? AND state='obsolete' AND in_app_done=0
            AND user_id IN (SELECT user_id FROM jukebox_follows WHERE $recipients)",[$availableAt,$id,$key,...$recipientArgs]);
    }
    $sql="INSERT INTO jukebox_alerts (session_id,user_id,event_type,event_key,event_at,available_at,push_receipts)
        SELECT session_id,user_id,?,?,?,?,'[]' FROM jukebox_follows WHERE $recipients";
    $sql.=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?' ON DUPLICATE KEY UPDATE id=jukebox_alerts.id':' ON CONFLICT(session_id,user_id,event_key) DO NOTHING';
    sf_jukebox_query($sql,[$type,$key,$eventAt,$availableAt,...$recipientArgs]);
}

function sf_jukebox_alert_reminder(array $session,?int $userId=null): void {
    $at=(int)$session['scheduled_at'];
    $now=time();
    if($at>$now)sf_jukebox_alert_enqueue($session['id'],'reminder','reminder:'.$at,$at,max($now,$at-SF_JUKEBOX_REMINDER_LEAD),$userId);
}

/** Called with the session write lock, inside the same transaction as the host control. */
function sf_jukebox_alert_session_change(array $before,array $after,string $event): void {
    $id=$after['id'];
    $phase=sf_jukebox_phase($after);
    if($event==='end'||$event==='cancel'){
        sf_jukebox_query("UPDATE jukebox_alerts SET state='obsolete',lease_token='' WHERE session_id=? AND state='pending'",[$id]);
        if($phase==='canceled')sf_jukebox_alert_enqueue($id,'canceled','canceled',(int)$after['scheduled_at'],time());
        return;
    }
    if(sf_jukebox_phase($before)!=='upcoming')return;
    if($event==='reschedule'&&(int)$before['scheduled_at']===(int)$after['scheduled_at'])return;
    sf_jukebox_query("UPDATE jukebox_alerts SET state='obsolete',lease_token='' WHERE session_id=?
        AND state='pending' AND event_type IN ('reminder','rescheduled')",[$id]);
    if($event==='start'&&$phase==='live')sf_jukebox_alert_enqueue($id,'live','live',(int)$after['started_at'],time());
    if($event==='reschedule'&&$phase==='upcoming'){
        sf_jukebox_alert_enqueue($id,'rescheduled','rescheduled:'.$after['revision'],(int)$after['scheduled_at'],time());
        sf_jukebox_alert_reminder($after);
    }
}

function sf_jukebox_alert_context(int $id): ?array {
    return sf_jukebox_query('SELECT a.*,s.title,s.listed,s.ended,t.scheduled_at,t.started_at,f.access_scope,u.status AS user_status
        FROM jukebox_alerts a LEFT JOIN jukebox_sessions s ON s.id=a.session_id
        LEFT JOIN jukebox_schedule t ON t.session_id=a.session_id
        LEFT JOIN jukebox_follows f ON f.session_id=a.session_id AND f.user_id=a.user_id
        LEFT JOIN users u ON u.id=a.user_id WHERE a.id=?',[$id])->fetch()?:null;
}

function sf_jukebox_alert_current(array $job,int $now): bool {
    if($job['state']!=='pending'||$job['title']===null||$job['user_status']!=='active'||$job['access_scope']===null
        ||(!(bool)$job['listed']&&$job['access_scope']!=='invite'))return false;
    $phase=sf_jukebox_phase($job);
    $at=(int)$job['event_at'];
    $sameSchedule=$at===(int)$job['scheduled_at'];
    return match($job['event_type']){
        'reminder'=>$phase==='upcoming'&&$sameSchedule&&$at>$now,
        'rescheduled'=>$phase==='upcoming'&&$sameSchedule,
        'live'=>$phase==='live'&&$at===(int)$job['started_at'],
        'canceled'=>$phase==='canceled'&&$sameSchedule,
        default=>false,
    };
}

function sf_jukebox_alert_payload(array $job,bool $forPush): array {
    $subject=$job['title'];
    $when=gmdate('M j, Y \a\t H:i',(int)$job['event_at']).' UTC';
    [$title,$body]=match($job['event_type']){
        'reminder'=>['Jukebox reminder',$subject.' is scheduled for '.$when.'. The host will open it when ready.'],
        'rescheduled'=>['Jukebox time changed',$subject.' is now scheduled for '.$when.'.'],
        'live'=>['Jukebox is live',$subject.' has opened.'],
        'canceled'=>['Jukebox canceled',$subject.' was canceled.'],
    };
    if($forPush&&!(bool)$job['listed']){
        $title='Stonefellow';
        $body='Open Stonefellow for an update to a jukebox session you follow.';
    }
    $key='jukebox:'.$job['session_id'].':'.$job['event_key'];
    return ['title'=>$title,'body'=>$body,'url'=>'jukebox.php?id='.$job['session_id'],'tag'=>$key];
}

function sf_jukebox_alert_claim(array $candidate): ?array {
    $db=sf_db();
    $db->beginTransaction();
    try{
        // Serialize with Follow, Unfollow and lifecycle changes before the first read.
        sf_jukebox_query('UPDATE jukebox_sessions SET revision=revision WHERE id=?',[$candidate['session_id']]);
        $active=sf_jukebox_follow_user_active((int)$candidate['user_id']);
        $job=sf_jukebox_alert_context((int)$candidate['id']);
        $now=time();
        if(!$job||$job['state']!=='pending'||(int)$job['available_at']>$now){$db->commit();return null;}
        if(!$active||!sf_jukebox_alert_current($job,$now)){
            sf_jukebox_query("UPDATE jukebox_alerts SET state='obsolete',lease_token='' WHERE id=?",[$job['id']]);
            $db->commit();
            return null;
        }
        if(!(int)$job['in_app_done']){
            $payload=sf_jukebox_alert_payload($job,false);
            $userId=(int)$job['user_id'];
            if(sf_notification_dedupe_claim($userId,$payload['tag'])){
                $notification=sf_notify_user($userId,'jukebox',$payload['title'],$payload['body'],$payload['url']);
                sf_notification_dedupe_update($userId,$payload['tag'],$notification);
            }
        }
        $job['lease_token']=bin2hex(random_bytes(16));
        $job['attempts']=(int)$job['attempts']+1;
        sf_jukebox_query('UPDATE jukebox_alerts SET in_app_done=1,lease_token=?,available_at=?,attempts=? WHERE id=?',
            [$job['lease_token'],$now+SF_JUKEBOX_ALERT_LEASE,$job['attempts'],$job['id']]);
        $db->commit();
        return $job;
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
}

function sf_jukebox_alert_finish(array $job,string $state,int $retry=0): void {
    sf_jukebox_query("UPDATE jukebox_alerts SET state=?,lease_token='',available_at=? WHERE id=? AND lease_token=? AND state='pending'",
        [$state,time()+$retry,$job['id'],$job['lease_token']]);
}

function sf_jukebox_alert_send(array $job): string {
    if(!sf_web_push_supported()){
        sf_jukebox_alert_finish($job,'done');
        return 'done';
    }
    $receipt=json_decode($job['push_receipts'],true,8,JSON_THROW_ON_ERROR);
    $retry=0;
    $backoff=min(1800,30*(2**min(6,(int)$job['attempts']-1)));
    $devices=sf_web_push_subscriptions((int)$job['user_id']);
    foreach($devices as $device){
        if(in_array($device['id'],$receipt,true))continue;
        // Opt-out and schedule changes that occur while the worker is running invalidate the lease.
        $current=sf_jukebox_alert_context((int)$job['id']);
        $now=time();
        if(!$current||$current['lease_token']!==$job['lease_token']||!sf_jukebox_alert_current($current,$now)){
            sf_jukebox_alert_finish($job,'obsolete');
            return 'obsolete';
        }
        if((int)$current['available_at']-$now<10){
            sf_jukebox_alert_finish($job,'pending');
            return 'retry';
        }
        $ttl=$current['event_type']==='reminder'?min(SF_JUKEBOX_REMINDER_LEAD,(int)$current['event_at']-$now):180;
        $result=sf_web_push_send($device,sf_jukebox_alert_payload($current,true),$ttl);
        if($result['status']==='retry'){
            $retry=max($retry,$backoff,min(3600,(int)($result['retry_after']??0)));
            continue;
        }
        if($result['status']==='gone')continue;
        $receipt[]=$device['id'];
        sf_jukebox_query("UPDATE jukebox_alerts SET push_receipts=? WHERE id=? AND lease_token=? AND state='pending'",
            [json_encode($receipt,JSON_THROW_ON_ERROR),$job['id'],$job['lease_token']]);
    }
    sf_jukebox_alert_finish($job,$retry?'pending':'done',$retry);
    return $retry?'retry':'done';
}

function sf_jukebox_alert_run(int $limit=100): array {
    require_once __DIR__.'/web-push-core.php';
    // Lazy schema setup must complete before the transaction on MySQL.
    sf_jukebox_schema();
    sf_jukebox_follow_schema();
    sf_ops_ensure_schema();
    sf_notification_ensure_schema();
    $now=time();
    $limit=max(1,min(250,$limit));
    $candidates=sf_jukebox_query("SELECT id,session_id,user_id FROM jukebox_alerts WHERE state='pending' AND available_at<=?
        ORDER BY available_at,id LIMIT $limit",[$now])->fetchAll();
    $result=['selected'=>count($candidates),'done'=>0,'retry'=>0,'obsolete'=>0,'errors'=>0];
    foreach($candidates as $candidate){
        $job=null;
        try{
            $job=sf_jukebox_alert_claim($candidate);
            if($job)$result[sf_jukebox_alert_send($job)]++;
        }catch(Throwable $error){
            // Keep a failed job retryable without exposing its title, invite, or device endpoint.
            error_log('[Stonefellow Jukebox alerts] Delivery retry needed for job '.(int)$candidate['id']);
            if($job)sf_jukebox_alert_finish($job,'pending',60);
            else sf_jukebox_query("UPDATE jukebox_alerts SET available_at=? WHERE id=? AND state='pending' AND available_at<=?",
                [time()+60,$candidate['id'],$now]);
            $result['errors']++;
        }
    }
    return $result;
}
