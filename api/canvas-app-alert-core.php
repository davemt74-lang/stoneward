<?php
declare(strict_types=1);

require_once __DIR__.'/canvas-app-core.php';
require_once __DIR__.'/web-push-core.php';

const SF_CANVAS_APP_ALERT_LIFETIME = 86400;
const SF_CANVAS_APP_ALERT_LEASE = 300;
const SF_CANVAS_APP_ALERT_ATTEMPTS = 5;

function sf_canvas_app_alert_query(string $sql,array $args=[]): PDOStatement {
    $q=sf_db()->prepare($sql);
    $q->execute($args);
    return $q;
}

function sf_canvas_app_alert_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas alerts before a transaction.');
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $serial=$mysql?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $appKey=$mysql?'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin':'TEXT';
    $userKey=$mysql?'BIGINT UNSIGNED':'INTEGER';
    $indexes=$mysql?', INDEX canvas_app_alerts_due (state,available_at,id), INDEX canvas_app_alerts_expiry (expires_at)':'';
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_app_alerts (
        id $serial,app_id $appKey NOT NULL,gallery_id CHAR(32) NOT NULL,user_id $userKey NOT NULL,consent_key CHAR(32) NOT NULL,
        available_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,state VARCHAR(10) NOT NULL DEFAULT 'pending',
        lease_token CHAR(32) NOT NULL DEFAULT '',attempts INTEGER NOT NULL DEFAULT 0,
        in_app_done INTEGER NOT NULL DEFAULT 0,push_receipts TEXT NOT NULL,
        UNIQUE(app_id,gallery_id,user_id),FOREIGN KEY(app_id) REFERENCES canvas_apps(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE$indexes)$engine");
    if(!$mysql){
        $db->exec('CREATE INDEX IF NOT EXISTS canvas_app_alerts_due ON canvas_app_alerts (state,available_at,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS canvas_app_alerts_expiry ON canvas_app_alerts (expires_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS canvas_app_alerts_user ON canvas_app_alerts (user_id)');
    }
    $ready=true;
}

/** Called only when the reviewed revision is made current in the publication transaction. */
function sf_canvas_app_alert_enqueue(string $appId,string $entryId): void {
    $db=sf_db();
    if(!$db->inTransaction())throw new LogicException('Enqueue canvas alerts with the publication transaction.');
    $sql="INSERT INTO canvas_app_alerts(app_id,gallery_id,user_id,consent_key,available_at,expires_at,push_receipts)
        SELECT i.app_id,?,i.user_id,i.consent_key,?,?,'[]' FROM canvas_app_installations i
        JOIN users u ON u.id=i.user_id AND u.status='active'
        WHERE i.app_id=? AND i.enabled=1 AND i.consent_key<>''";
    $sql.=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ?' ON DUPLICATE KEY UPDATE id=canvas_app_alerts.id':' ON CONFLICT(app_id,gallery_id,user_id) DO NOTHING';
    $now=time();
    sf_canvas_app_alert_query($sql,[$entryId,$now,$now+SF_CANVAS_APP_ALERT_LIFETIME,$appId]);
}

function sf_canvas_app_alert_context(int $id): ?array {
    $q=sf_canvas_app_alert_query("SELECT q.*,i.enabled,i.consent_key AS current_consent,u.status AS user_status,a.gallery_id AS current_gallery_id
        FROM canvas_app_alerts q LEFT JOIN canvas_app_installations i ON i.app_id=q.app_id AND i.user_id=q.user_id
        LEFT JOIN canvas_apps a ON a.id=q.app_id
        LEFT JOIN users u ON u.id=q.user_id WHERE q.id=?",[$id]);
    $job=$q->fetch();
    $q->closeCursor();
    return $job?:null;
}

function sf_canvas_app_alert_publication(array $job,int $now): ?array {
    if($job['state']!=='pending'||$job['user_status']!=='active'||(int)$job['enabled']!==1||
        $job['consent_key']===''||$job['consent_key']!==$job['current_consent']||$job['gallery_id']!==$job['current_gallery_id']||
        (int)$job['expires_at']<=$now)return null;
    $entry=sf_canvas_app_current_publication($job['app_id']);
    if(!$entry||$entry['id']!==$job['gallery_id'])return null;
    $snapshot=json_decode($entry['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
    return sf_canvas_app_supports_alerts((string)($snapshot['html']??''))?$entry:null;
}

function sf_canvas_app_alert_claim(array $candidate): ?array {
    // Some published resources lazily initialize their storage outside transactions.
    $snapshot=json_decode(sf_canvas_gallery_row($candidate['gallery_id'])['snapshot_json']??'{}',true,16,JSON_THROW_ON_ERROR);
    if(isset($snapshot['playlist_inputs'])){sf_canvas_playlists_ensure_schema();sf_media_ensure_schema();}
    sf_canvas_resource_prepare($snapshot['artist_inputs']??null);
    $db=sf_db();
    $db->beginTransaction();
    try{
        // Match the app's mutation order before checking the recipient and current consent.
        sf_canvas_app_alert_query('UPDATE canvas_apps SET updated_at=updated_at WHERE id=?',[$candidate['app_id']]);
        $active=sf_canvas_app_active_subscriber((int)$candidate['user_id'],true);
        $job=sf_canvas_app_alert_context((int)$candidate['id']);
        $now=time();
        if(!$job||$job['state']!=='pending'||(int)$job['available_at']>$now){$db->commit();return null;}
        $entry=$active?sf_canvas_app_alert_publication($job,$now):null;
        if(!$entry||(int)$job['attempts']>=SF_CANVAS_APP_ALERT_ATTEMPTS){
            sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET state='obsolete',lease_token='' WHERE id=?",[$job['id']]);
            $db->commit();
            return ['state'=>'obsolete'];
        }
        if(!(int)$job['in_app_done']){
            $userId=(int)$job['user_id'];
            $key='canvas-app:'.$job['gallery_id'];
            if(!sf_notification_dedupe_claim($userId,$key)){
                sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET state='done',lease_token='' WHERE id=?",[$job['id']]);
                $db->commit();
                return ['state'=>'done'];
            }
            $notice=sf_notify_user($userId,'canvas_app',$entry['title'],
                'An update to this installed canvas is available.','index.php?view=canvas-gallery&id='.$job['gallery_id'].'&open=1');
            sf_notification_dedupe_update($userId,$key,$notice);
        }
        $job['lease_token']=bin2hex(random_bytes(16));
        $job['attempts']=(int)$job['attempts']+1;
        sf_canvas_app_alert_query('UPDATE canvas_app_alerts SET in_app_done=1,lease_token=?,available_at=?,attempts=? WHERE id=?',
            [$job['lease_token'],$now+SF_CANVAS_APP_ALERT_LEASE,$job['attempts'],$job['id']]);
        $db->commit();
        return $job;
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
}

function sf_canvas_app_alert_finish(array $job,string $state,int $retry=0): void {
    sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET state=?,lease_token='',available_at=? WHERE id=? AND lease_token=? AND state='pending'",
        [$state,time()+$retry,$job['id'],$job['lease_token']]);
}

function sf_canvas_app_alert_send(array $job,?callable $send=null): string {
    if($send===null&&!sf_web_push_supported()){
        sf_canvas_app_alert_finish($job,'done');
        return 'done';
    }
    $send??=sf_web_push_send(...);
    $receipts=json_decode($job['push_receipts'],true,8,JSON_THROW_ON_ERROR);
    $retry=0;
    $backoff=30*(2**min(6,(int)$job['attempts']-1));
    $payload=['type'=>'canvas-app','title'=>'Stonefellow','body'=>'An installed canvas has an update.',
        'url'=>'index.php?view=canvas-gallery&id='.$job['gallery_id'].'&open=1','tag'=>'canvas-app:'.$job['gallery_id']];
    foreach(sf_web_push_subscriptions((int)$job['user_id']) as $device){
        if(in_array($device['id'],$receipts,true))continue;
        $current=sf_canvas_app_alert_context((int)$job['id']);
        $now=time();
        if(!$current||$current['lease_token']!==$job['lease_token']||!sf_canvas_app_alert_publication($current,$now)){
            sf_canvas_app_alert_finish($job,'obsolete');
            return 'obsolete';
        }
        if((int)$current['available_at']-$now<10){
            sf_canvas_app_alert_finish($job,'pending');
            return 'retry';
        }
        $result=$send($device,$payload,min(300,(int)$job['expires_at']-$now));
        if(in_array($result['status'],['retry','unavailable'],true)){
            $retry=max($retry,$backoff,min(3600,(int)($result['retry_after']??0)));
            continue;
        }
        $receipts[]=$device['id'];
        sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET push_receipts=? WHERE id=? AND lease_token=? AND state='pending'",
            [json_encode($receipts,JSON_THROW_ON_ERROR),$job['id'],$job['lease_token']]);
    }
    if($retry&&((int)$job['attempts']>=SF_CANVAS_APP_ALERT_ATTEMPTS||time()+$retry>=(int)$job['expires_at'])){
        sf_canvas_app_alert_finish($job,'failed');
        return 'failed';
    }
    sf_canvas_app_alert_finish($job,$retry?'pending':'done',$retry);
    return $retry?'retry':'done';
}

function sf_canvas_app_alert_run(int $limit=100,?callable $send=null): array {
    $result=['selected'=>0,'done'=>0,'retry'=>0,'obsolete'=>0,'failed'=>0,'errors'=>0];
    if(!sf_canvas_app_exists())return $result;
    sf_canvas_app_alert_schema();
    sf_ops_ensure_schema();
    sf_notification_ensure_schema();
    $now=time();
    $limit=max(1,min(250,$limit));
    sf_canvas_app_alert_query('DELETE FROM canvas_app_alerts WHERE expires_at<=?',[$now]);
    $candidates=sf_canvas_app_alert_query("SELECT id,app_id,gallery_id,user_id FROM canvas_app_alerts WHERE state='pending' AND available_at<=?
        ORDER BY available_at,id LIMIT $limit",[$now])->fetchAll();
    $result['selected']=count($candidates);
    if(!$candidates)return $result;
    // Reference authorization may initialize media stores in a fresh worker process.
    require_once __DIR__.'/canvas-gallery-assets.php';
    sf_canvas_gallery_assets_prepare();
    foreach($candidates as $candidate){
        $job=null;
        try{
            $job=sf_canvas_app_alert_claim($candidate);
            if($job)$result[$job['state']==='pending'?sf_canvas_app_alert_send($job,$send):$job['state']]++;
        }catch(Throwable $error){
            error_log('[Stonefellow Canvas app alerts] Delivery retry needed for job '.(int)$candidate['id']);
            if($job)sf_canvas_app_alert_finish($job,'pending',60);
            else sf_canvas_app_alert_query("UPDATE canvas_app_alerts SET available_at=? WHERE id=? AND state='pending' AND available_at<=?",
                [time()+60,$candidate['id'],$now]);
            $result['errors']++;
        }
    }
    return $result;
}
