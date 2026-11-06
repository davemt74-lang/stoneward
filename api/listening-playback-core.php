<?php
declare(strict_types=1);

final class SfListeningPlaybackConflict extends RuntimeException {
    public function __construct(string $message,public readonly string $reason='event_conflict'){parent::__construct($message);}
}
function sf_listening_playback_exists():bool {
    $db=sf_db();$q=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name='listening_playbacks'":"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='listening_playbacks'";
    return $db->query($q)->fetchColumn()!==false;
}
function sf_listening_playback_schema():void {
    static $ready=false;if($ready)return;
    $db=sf_db();if($db->inTransaction())throw new LogicException('Initialize listening playback before a transaction.');
    sf_ops_ensure_schema();sf_privacy_ensure_schema();sf_personalization_ensure_schema();sf_crm_ensure_schema();sf_automation_ensure_schema();
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $db->exec("CREATE TABLE IF NOT EXISTS listening_account_state(user_id BIGINT PRIMARY KEY,epoch BIGINT NOT NULL)".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS listening_playbacks(play_id CHAR(32) PRIMARY KEY,actor_hash CHAR(64) NOT NULL,user_id BIGINT NULL,privacy_epoch BIGINT NOT NULL,track_id VARCHAR(180) NOT NULL,session_key VARCHAR(128) NOT NULL,attribution_json TEXT NULL,playback_method VARCHAR(16) NOT NULL,state VARCHAR(16) NOT NULL,last_position DOUBLE NOT NULL,last_reported DOUBLE NOT NULL,listened_seconds DOUBLE NOT NULL,playback_rate DOUBLE NOT NULL,last_at_ms BIGINT NOT NULL,completed INTEGER NOT NULL,created_at VARCHAR(40) NOT NULL)".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS listening_playback_receipts(play_id CHAR(32) NOT NULL,event_id CHAR(32) NOT NULL,payload_hash CHAR(64) NOT NULL,result_json TEXT NOT NULL,PRIMARY KEY(play_id,event_id))".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS listening_crm_outbox(listening_event_id BIGINT PRIMARY KEY,crm_event_id BIGINT NOT NULL,contact_id BIGINT NOT NULL,user_id BIGINT NOT NULL,privacy_epoch BIGINT NOT NULL,event_type VARCHAR(40) NOT NULL,delivered INTEGER NOT NULL DEFAULT 0)".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS listening_automation_steps(run_id BIGINT NOT NULL,step_index INTEGER NOT NULL,state VARCHAR(12) NOT NULL,PRIMARY KEY(run_id,step_index))".$engine);
    $db->exec("CREATE TABLE IF NOT EXISTS listening_legacy_locks(lock_key CHAR(64) PRIMARY KEY,updated_at VARCHAR(40) NOT NULL)".$engine);
    $columns=static fn():array=>$db->query($mysql?'SHOW COLUMNS FROM listening_events':'PRAGMA table_info(listening_events)')->fetchAll(PDO::FETCH_COLUMN,$mysql?0:1);
    $known=$columns();
    foreach(['play_id'=>'CHAR(32) NULL','event_id'=>'CHAR(32) NULL','attribution_json'=>'TEXT NULL','playback_method'=>"VARCHAR(16) NOT NULL DEFAULT 'legacy'",'listened_seconds'=>'DOUBLE NOT NULL DEFAULT 0','evidence'=>"VARCHAR(32) NOT NULL DEFAULT 'legacy_unknown'"] as $field=>$definition){
        if(in_array($field,$known,true))continue;
        try{$db->exec("ALTER TABLE listening_events ADD COLUMN $field $definition");}catch(PDOException $e){if(!in_array($field,$columns(),true))throw $e;}
    }
    foreach(['listening_events'=>['idx_listen_play_instance'=>['play_id']],
        'listening_playbacks'=>['idx_listen_play_owner'=>['user_id']],
        'listening_crm_outbox'=>['idx_listen_outbox_owner'=>['user_id']]] as $table=>$indexes){
        $names=static fn():array=>$db->query($mysql?'SHOW INDEX FROM '.$table:'PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_COLUMN,$mysql?2:1);
        foreach($indexes as $name=>$fields){if(in_array($name,$names(),true))continue;try{$db->exec('CREATE INDEX '.$name.' ON '.$table.'('.implode(',',$fields).')');}catch(PDOException $e){if(!in_array($name,$names(),true))throw $e;}}
    }
    $ready=true;
}
function sf_listening_playback_epoch(int $uid):int {
    if($uid<1||!sf_listening_playback_exists())return 0;
    $q=sf_db()->prepare('SELECT epoch FROM listening_account_state WHERE user_id=?');$q->execute([$uid]);$epoch=(int)$q->fetchColumn();$q->closeCursor();return $epoch;
}
function sf_listening_playback_enabled(int $uid):bool {
    if($uid<1)return true;
    $q=sf_db()->prepare('SELECT listening_history_enabled FROM user_profiles WHERE user_id=?');$q->execute([$uid]);$value=$q->fetchColumn();$q->closeCursor();return $value===false||!empty($value);
}
/** Session credentials are never accepted from canvas code or stored with account identity. */
function sf_listening_playback_session(?array $user):array {
    sf_user_session();$uid=(int)($user['id']??0);sf_privacy_ensure_schema();
    $epoch=sf_listening_playback_epoch($uid);$enabled=sf_listening_playback_enabled($uid);$stamp=[$uid,$epoch,$enabled,$uid>0?sf_agent_scene_history_epoch($uid):0];
    if(($_SESSION['sf_listening_stamp']??null)!==$stamp){$_SESSION['sf_listening_stamp']=$stamp;$_SESSION['sf_listening_token']=bin2hex(random_bytes(16));}
    return ['csrf'=>sf_user_csrf(),'session_token'=>$_SESSION['sf_listening_token'],'privacy_epoch'=>$epoch,'history_enabled'=>$enabled,'user_id'=>$uid];
}
function sf_listening_playback_id(mixed $id):string {
    if(!is_string($id)||preg_match('/\A[a-f0-9]{32}\z/',$id)!==1)throw new InvalidArgumentException('Use a valid playback/event identity.');return $id;
}
function sf_listening_playback_number(mixed $value,float $max=1000000000):float {
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>$max)throw new InvalidArgumentException('Use valid native playback measurements.');return round((float)$value,3);
}
function sf_listening_playback_context(array $user,mixed $context,bool $identified):array {
    if(!is_array($context)||array_is_list($context)||count($context)!==1)throw new InvalidArgumentException('Choose one playback context.');
    if(($context['app']??null)===true)return ['kind'=>'app','schema_version'=>1,'code_hash'=>is_readable(SF_ROOT.'/assets/js/app.js')?hash_file('sha256',SF_ROOT.'/assets/js/app.js'):null];
    require_once __DIR__.'/canvas-access-core.php';
    $resolved=sf_canvas_access_resolve($user,$context);$scene=$resolved['scene'];
    $version=hash('sha256',json_encode([$scene['html'],$scene['css'],$scene['js']??''],JSON_THROW_ON_ERROR));
    if(isset($context['gallery_id']))return ['kind'=>'gallery','gallery_id'=>$context['gallery_id'],'code_hash'=>$version];
    if(!$identified)return ['kind'=>'anonymous'];
    return ['kind'=>'scene','scene_id'=>$scene['id'],'code_hash'=>$version];
}
/** The same normalized payload must replay exactly, independently of JSON key order. */
function sf_listening_playback_request(array $body):array {
    $action=$body['action']??null;if(!in_array($action,['begin','event'],true))throw new InvalidArgumentException('Choose a playback begin or event.');
    $type=$body['event_type']??($action==='begin'?'start':null);
    if(!in_array($type,$action==='begin'?['start','resume']:['resume','pause','progress','complete','skip','seek','buffer','playing'],true))throw new InvalidArgumentException('Use a native playback event.');
    $out=['action'=>$action,'play_id'=>sf_listening_playback_id($body['play_id']??null),'event_id'=>sf_listening_playback_id($body['event_id']??null),'event_type'=>$type,
        'position_seconds'=>sf_listening_playback_number($body['position_seconds']??0),'duration_seconds'=>sf_listening_playback_number($body['duration_seconds']??0),
        'elapsed_played_seconds'=>sf_listening_playback_number($body['elapsed_played_seconds']??0),'playback_rate'=>sf_listening_playback_number($body['playback_rate']??1,4)];
    if($out['playback_rate']<.25)throw new InvalidArgumentException('Use a supported native playback rate.');
    if($action==='begin'){
        if(!is_string($body['track_id']??null)||strlen($body['track_id'])>180)throw new InvalidArgumentException('Choose a public track.');
        if(!in_array($body['playback_method']??null,['main','independent'],true))throw new InvalidArgumentException('Choose a host playback method.');
        $out+=['track_id'=>$body['track_id'],'playback_method'=>$body['playback_method'],'context'=>$body['context']??null];
        if($out['elapsed_played_seconds']!==0.0)throw new InvalidArgumentException('Playback begins with zero measured listening time.');
    }
    return $out;
}
function sf_listening_playback_public_track(string $track):void {
    foreach(sf_public_catalog() as $row)if(($row['id']??'')===$track)return;
    throw new InvalidArgumentException('This track is no longer public.');
}
/** Canonical observed wall-time lower bound: receipt latency can conservatively clip queued progress.
 * Host native-media telemetry is evidence, not cryptographic proof a human heard audio. */
function sf_listening_playback_record(?array $user,array $session,array $body,?int $nowMs=null,int $attempt=0):array {
    $request=sf_listening_playback_request($body);$uid=(int)($user['id']??0);$expectedEpoch=(int)($session['privacy_epoch']??-1);$identified=(bool)($session['history_enabled']??false)&&$uid>0;
    $token=sf_listening_playback_id($session['session_token']??null);$actor=hash('sha256',$token.'|'.$request['play_id']);
    $payload=hash('sha256',json_encode($request,JSON_THROW_ON_ERROR));$nowMs??=(int)floor(microtime(true)*1000);
    if($request['action']==='begin'){
        sf_listening_playback_public_track($request['track_id']);
        $attribution=sf_listening_playback_context($user??[],$request['context'],$identified);
    }
    sf_listening_playback_schema();$db=sf_db();$db->beginTransaction();
    try{
        if($uid>0){
            if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$uid]);
            $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);$active=$q->fetchColumn()!==false;$q->closeCursor();
            if(!$active||sf_listening_playback_epoch($uid)!==$expectedEpoch||sf_listening_playback_enabled($uid)!==($session['history_enabled']??null))throw new SfListeningPlaybackConflict('Playback was canceled by an account or privacy change.','privacy_changed');
        }
        $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $q=$db->prepare('SELECT * FROM listening_playbacks WHERE play_id=?'.$lock);$q->execute([$request['play_id']]);$play=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($play){
            if(!hash_equals($play['actor_hash'],$actor)||($play['user_id']===null?null:(int)$play['user_id'])!==($identified?$uid:null))throw new SfListeningPlaybackConflict('This playback belongs to another session.','session_changed');
            $q=$db->prepare('SELECT payload_hash,result_json FROM listening_playback_receipts WHERE play_id=? AND event_id=?');$q->execute([$request['play_id'],$request['event_id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($receipt){if(!hash_equals($receipt['payload_hash'],$payload))throw new SfListeningPlaybackConflict('This event identity already has different measurements.');$result=json_decode($receipt['result_json'],true,16,JSON_THROW_ON_ERROR);$db->commit();sf_listening_playback_drain_crm();return array_replace($result,['replayed'=>true,'privacy_epoch'=>$expectedEpoch]);}
            if($request['action']==='begin')throw new SfListeningPlaybackConflict('This play instance has already begun.');
            sf_listening_playback_public_track($play['track_id']);
        }else{
            if($request['action']!=='begin')throw new SfListeningPlaybackConflict('Begin playback before reporting events.');
            $q=$db->prepare('SELECT 1 FROM listening_events WHERE play_id=? LIMIT 1');$q->execute([$request['play_id']]);$used=$q->fetchColumn()!==false;$q->closeCursor();if($used)throw new SfListeningPlaybackConflict('This playback identity has been retired.');
            // Revalidate context after the account lock; attribution remains immutable afterward.
            $attribution=sf_listening_playback_context($user??[],$request['context'],$identified);$now=gmdate('c',(int)floor($nowMs/1000));
            $play=['play_id'=>$request['play_id'],'actor_hash'=>$actor,'user_id'=>$identified?$uid:null,'privacy_epoch'=>$identified?$expectedEpoch:0,'track_id'=>$request['track_id'],
                'session_key'=>bin2hex(random_bytes(16)),'attribution_json'=>json_encode($attribution,JSON_THROW_ON_ERROR),'playback_method'=>$request['playback_method'],
                'state'=>'playing','last_position'=>$request['position_seconds'],'last_reported'=>0,'listened_seconds'=>0,'playback_rate'=>$request['playback_rate'],'last_at_ms'=>$nowMs,'completed'=>0,'created_at'=>$now];
            $q=$db->prepare('INSERT INTO listening_playbacks('.implode(',',array_keys($play)).') VALUES('.implode(',',array_fill(0,count($play),'?')).')');$q->execute(array_values($play));
        }
        $increment=0.0;$type=$request['event_type'];$canonical=null;
        if($request['action']==='begin')$canonical=$type;
        elseif($play['state']!=='ended'){
            if($request['elapsed_played_seconds']+0.001<(float)$play['last_reported'])throw new SfListeningPlaybackConflict('Playback measurements must advance in order.');
            if($play['state']==='playing'&&!in_array($type,['seek','playing','resume'],true)){
                $delta=max(0,$request['elapsed_played_seconds']-(float)$play['last_reported']);$wall=max(0,($nowMs-(int)$play['last_at_ms'])/1000);
                $position=max(0,$request['position_seconds']-(float)$play['last_position']);
                $increment=round(min($delta,$wall,$position/max((float)$play['playback_rate'],$request['playback_rate'])),3);
            }
            if($type==='resume'&&$play['state']==='paused')$canonical='resume';
            if(in_array($type,['pause','progress','skip'],true))$canonical=$type;
            if($type==='complete'&&!(int)$play['completed']){$canonical='complete';$play['completed']=1;}
            $play['state']=match($type){'pause'=>'paused','buffer'=>'buffering','seek'=>'seeking','complete','skip'=>'ended','playing','resume'=>'playing',default=>$play['state']};
        }
        $play['last_position']=$request['position_seconds'];$play['last_reported']=$request['elapsed_played_seconds'];$play['last_at_ms']=$nowMs;$play['playback_rate']=$request['playback_rate'];$play['listened_seconds']=round((float)$play['listened_seconds']+$increment,3);
        if($canonical!==null){
            $q=$db->prepare('INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at,play_id,event_id,attribution_json,playback_method,listened_seconds,evidence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$play['user_id'],$play['session_key'],$play['track_id'],$canonical,(int)$request['position_seconds'],(int)$request['duration_seconds'],($attribution['kind']??json_decode($play['attribution_json'],true)['kind']).'_'.$play['playback_method'],gmdate('c',(int)floor($nowMs/1000)),$play['play_id'],$request['event_id'],$play['attribution_json'],$play['playback_method'],$increment,'host_media_report']);$canonicalId=(int)$db->lastInsertId();
            if($play['user_id']!==null)sf_personalization_record_progress((int)$play['user_id'],$play['track_id'],$canonical,(int)$request['position_seconds'],(int)$request['duration_seconds']);
            if($play['user_id']!==null&&in_array($canonical,['start','complete'],true))sf_listening_playback_activity((int)$play['user_id'],$play['track_id'],$canonical,(int)$request['position_seconds'],(int)$request['duration_seconds'],$canonicalId);
        }elseif($increment>0){
            // Control transitions can end a valid segment without becoming a start/completion.
            $q=$db->prepare("INSERT INTO listening_events(user_id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at,play_id,event_id,attribution_json,playback_method,listened_seconds,evidence) VALUES(?,?,?,'progress',?,?,?,?,?,?,?,?,?,?)");
            $q->execute([$play['user_id'],$play['session_key'],$play['track_id'],(int)$request['position_seconds'],(int)$request['duration_seconds'],'host_control',gmdate('c',(int)floor($nowMs/1000)),$play['play_id'],$request['event_id'],$play['attribution_json'],$play['playback_method'],$increment,'host_media_report']);
        }
        $q=$db->prepare('UPDATE listening_playbacks SET state=?,last_position=?,last_reported=?,listened_seconds=?,playback_rate=?,last_at_ms=?,completed=? WHERE play_id=?');$q->execute([$play['state'],$play['last_position'],$play['last_reported'],$play['listened_seconds'],$play['playback_rate'],$play['last_at_ms'],$play['completed'],$play['play_id']]);
        $result=['play_id'=>$play['play_id'],'event_id'=>$request['event_id'],'replayed'=>false,'listened_seconds'=>$play['listened_seconds'],'state'=>$play['state'],'privacy_epoch'=>$identified?$expectedEpoch:0];
        $db->prepare('INSERT INTO listening_playback_receipts(play_id,event_id,payload_hash,result_json) VALUES(?,?,?,?)')->execute([$play['play_id'],$request['event_id'],$payload,json_encode($result,JSON_THROW_ON_ERROR)]);
        $db->commit();sf_listening_playback_drain_crm();return array_replace($result,['privacy_epoch'=>$expectedEpoch]);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();
        if($e instanceof PDOException&&$attempt<3&&in_array((int)($e->errorInfo[1]??0),[5,6,1062,1205,1213],true)){usleep(10000);return sf_listening_playback_record($user,$session,$body,$nowMs,$attempt+1);}
        throw $e;
    }
}

function sf_listening_playback_export(int $uid):array {
    if(!sf_listening_playback_exists())return [];
    $q=sf_db()->prepare('SELECT play_id,track_id,attribution_json,playback_method,state,listened_seconds,created_at FROM listening_playbacks WHERE user_id=? ORDER BY created_at,play_id');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    foreach($rows as &$row){$row['attribution']=$row['attribution_json']===null?null:json_decode($row['attribution_json'],true,16,JSON_THROW_ON_ERROR);unset($row['attribution_json']);$row['listened_seconds']=(float)$row['listened_seconds'];}unset($row);return $rows;
}
/** Must run under the same account lock as privacy changes; no schema initialization here. */
function sf_listening_playback_forget(int $uid,?string $track=null,bool $privateOnly=false):int {
    $versioned=sf_listening_playback_exists();$db=sf_db();if($privateOnly&&!$versioned)return 0;$owns=!$db->inTransaction();if($owns)$db->beginTransaction();
    try{
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$uid]);
        $q=$db->prepare('SELECT id FROM users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);$q->fetchColumn();$q->closeCursor();
        $q=$db->prepare('SELECT id,'.($versioned?'attribution_json':'NULL AS attribution_json').' FROM listening_events WHERE user_id=?'.($track!==null?' AND track_id=?':''));$q->execute($track!==null?[$uid,$track]:[$uid]);$events=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$count=0;$clearedIds=[];
        foreach($events as $row){if($privateOnly&&($row['attribution_json']===null||(json_decode($row['attribution_json'],true)['kind']??'')!=='scene'))continue;
            $db->prepare('UPDATE listening_events SET user_id=NULL,session_key=?'.($versioned?',play_id=NULL,event_id=NULL,attribution_json=NULL':'').' WHERE id=?')->execute([bin2hex(random_bytes(16)),$row['id']]);$count++;$clearedIds[]=(int)$row['id'];
        }
        if($versioned){
        $q=$db->query('SELECT id,status,details_json FROM lifecycle_automation_runs');$runs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($runs as $run){$details=json_decode($run['details_json'],true);if((int)($details['listening_source']['user_id']??0)!==$uid)continue;unset($details['listening_source']);$details['privacy_withdrawn']=true;$status=in_array($run['status'],['running','waiting'],true)?'canceled':$run['status'];$db->prepare('UPDATE lifecycle_automation_runs SET status=?,details_json=? WHERE id=?')->execute([$status,json_encode($details),$run['id']]);}
        if($privateOnly)foreach($clearedIds as $eventId){$q=$db->prepare('SELECT crm_event_id FROM listening_crm_outbox WHERE listening_event_id=? AND user_id=?');$q->execute([$eventId,$uid]);$crmId=$q->fetchColumn();$q->closeCursor();if($crmId!==false)$db->prepare('DELETE FROM fan_crm_events WHERE id=? AND user_id=?')->execute([$crmId,$uid]);}
        $db->prepare('DELETE FROM listening_crm_outbox WHERE user_id=?')->execute([$uid]);
        if(!$privateOnly){$sql="DELETE FROM fan_crm_events WHERE user_id=? AND event_type IN ('listen_start','listen_complete')".($track!==null?' AND entity_id=?':'');$db->prepare($sql)->execute($track!==null?[$uid,$track]:[$uid]);}
        $q=$db->prepare('SELECT play_id FROM listening_playbacks WHERE user_id=?');$q->execute([$uid]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
        foreach($ids as $id){$db->prepare('DELETE FROM listening_playback_receipts WHERE play_id=?')->execute([$id]);$db->prepare('DELETE FROM listening_playbacks WHERE play_id=?')->execute([$id]);}
        $epoch=sf_listening_playback_epoch($uid);$db->prepare('DELETE FROM listening_account_state WHERE user_id=?')->execute([$uid]);$db->prepare('INSERT INTO listening_account_state(user_id,epoch) VALUES(?,?)')->execute([$uid,$epoch+1]);
        }
        if($owns)$db->commit();return $count;
    }catch(Throwable $e){if($owns&&$db->inTransaction())$db->rollBack();throw $e;}
}
/** Follow removal of canonical events; introduce no separate retention age. */
function sf_listening_playback_prune():int {
    if(!sf_listening_playback_exists())return 0;$db=sf_db();
    $q=$db->query('SELECT play_id FROM listening_playbacks WHERE NOT EXISTS(SELECT 1 FROM listening_events e WHERE e.play_id=listening_playbacks.play_id)');$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
    foreach($ids as $id){$db->prepare('DELETE FROM listening_playback_receipts WHERE play_id=?')->execute([$id]);$db->prepare('DELETE FROM listening_playbacks WHERE play_id=?')->execute([$id]);}$db->exec('DELETE FROM listening_crm_outbox WHERE NOT EXISTS(SELECT 1 FROM listening_events e WHERE e.id=listening_crm_outbox.listening_event_id)');$db->exec('DELETE FROM listening_automation_steps WHERE NOT EXISTS(SELECT 1 FROM lifecycle_automation_runs r WHERE r.id=listening_automation_steps.run_id)');$db->prepare('DELETE FROM listening_legacy_locks WHERE updated_at<?')->execute([gmdate('c',time()-20)]);return count($ids);
}

/** Preserve the existing CRM trigger as a durable post-commit delivery. */
function sf_listening_playback_activity(int $uid,string $track,string $type,int $position,int $duration,int $eventId):void {
    $title=sf_track_map()[$track]['title']??$track;$kind=$type==='complete'?'listen_complete':'listen_start';$text=($type==='complete'?'Finished ':'Played ').$title;$meta=['position'=>$position,'duration'=>$duration];
    sf_db()->prepare('INSERT INTO user_activity(user_id,event_type,title,entity_type,entity_id,metadata_json,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$uid,$kind,$text,'track',$track,json_encode($meta),gmdate('c')]);
    $contact=sf_crm_sync_user($uid);if(!$contact)return;$old=$GLOBALS['sf_automation_suppressed']??null;$GLOBALS['sf_automation_suppressed']=true;
    try{$crmId=sf_crm_log_event((int)$contact['id'],$uid,$kind,$text,'track',$track,$meta);}finally{if($old===null)unset($GLOBALS['sf_automation_suppressed']);else $GLOBALS['sf_automation_suppressed']=$old;}
    sf_db()->prepare('INSERT INTO listening_crm_outbox(listening_event_id,crm_event_id,contact_id,user_id,privacy_epoch,event_type,delivered) VALUES(?,?,?,?,?,?,0)')->execute([$eventId,$crmId,(int)$contact['id'],$uid,sf_listening_playback_epoch($uid),$kind]);
}
function sf_listening_playback_drain_crm():int {
    if(!sf_listening_playback_exists()||sf_db()->inTransaction())return 0;$db=sf_db();$count=0;sf_crm_ensure_schema();sf_automation_ensure_schema();
    $q=$db->query('SELECT * FROM listening_crm_outbox WHERE delivered=0 ORDER BY listening_event_id LIMIT 100');$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    foreach($rows as $selected){
        $old=$GLOBALS['sf_listening_automation_guard']??null;
        try{
            $db->beginTransaction();sf_listening_playback_lock_user((int)$selected['user_id']);
            $q=$db->prepare('SELECT * FROM listening_crm_outbox WHERE listening_event_id=? AND delivered=0'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$selected['listening_event_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$row){$db->commit();continue;}
            $guard=['user_id'=>(int)$row['user_id'],'epoch'=>(int)$row['privacy_epoch'],'crm_event_id'=>(int)$row['crm_event_id']];
            if(!sf_listening_automation_source_valid($guard)){$db->prepare('DELETE FROM listening_crm_outbox WHERE listening_event_id=?')->execute([$row['listening_event_id']]);$db->commit();continue;}
            // The automation engine creates durable run intents here and defers all steps.
            $GLOBALS['sf_listening_automation_guard']=$guard;
            sf_automation_process_crm_event((int)$row['contact_id'],$row['event_type'],(int)$row['crm_event_id']);
            $db->prepare('UPDATE listening_crm_outbox SET delivered=1 WHERE listening_event_id=?')->execute([$row['listening_event_id']]);$db->commit();$count++;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('[Listening CRM intent] '.get_class($e));}
        finally{if($old===null)unset($GLOBALS['sf_listening_automation_guard']);else $GLOBALS['sf_listening_automation_guard']=$old;}
    }
    // Recovery also finds an intent committed before a worker lost its response.
    $q=$db->query("SELECT id,details_json FROM lifecycle_automation_runs WHERE status='running' ORDER BY id LIMIT 200");$runs=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
    foreach($runs as $run)if(isset(json_decode($run['details_json'],true)['listening_source']))sf_automation_continue((int)$run['id']);
    return $count;
}
function sf_listening_playback_lock_user(int $uid):void {
    $db=sf_db();if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->prepare('UPDATE users SET id=id WHERE id=?')->execute([$uid]);
    $q=$db->prepare('SELECT id FROM users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid]);$q->fetchColumn();$q->closeCursor();
}
function sf_listening_automation_source_valid(array $guard):bool {
    $uid=(int)($guard['user_id']??0);$event=(int)($guard['crm_event_id']??0);if($uid<1||$event<1)return false;
    $q=sf_db()->prepare("SELECT 1 FROM users WHERE id=? AND status='active'");$q->execute([$uid]);$active=$q->fetchColumn()!==false;$q->closeCursor();
    if(!$active||sf_listening_playback_epoch($uid)!==(int)($guard['epoch']??-1)||!sf_listening_playback_enabled($uid))return false;
    $q=sf_db()->prepare('SELECT 1 FROM fan_crm_events c JOIN listening_crm_outbox o ON o.crm_event_id=c.id JOIN listening_events e ON e.id=o.listening_event_id WHERE c.id=? AND c.user_id=? AND o.user_id=? AND e.user_id=?');$q->execute([$event,$uid,$uid,$uid]);$exists=$q->fetchColumn()!==false;$q->closeCursor();return $exists;
}
/** Claim is the durable authorization boundary; no provider calls run inside it. */
function sf_listening_automation_claim(array $run,int $step):string {
    $guard=json_decode($run['details_json']??'{}',true)['listening_source']??null;if(!is_array($guard))return 'unrelated';
    $db=sf_db();if($db->inTransaction())return 'busy';$db->beginTransaction();
    try{
        sf_listening_playback_lock_user((int)($guard['user_id']??0));
        $q=$db->prepare('SELECT status,details_json FROM lifecycle_automation_runs WHERE id=?');$q->execute([$run['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$current||$current['status']!=='running'||!isset(json_decode($current['details_json'],true)['listening_source'])||!sf_listening_automation_source_valid($guard)){
            if($current&&$current['status']==='running')$db->prepare("UPDATE lifecycle_automation_runs SET status='canceled',details_json=?,updated_at=? WHERE id=?")->execute([json_encode(['privacy_withdrawn'=>true]),gmdate('c'),$run['id']]);
            $db->commit();return 'withdrawn';
        }
        $q=$db->prepare('SELECT state FROM listening_automation_steps WHERE run_id=? AND step_index=?');$q->execute([$run['id'],$step]);$claimed=$q->fetchColumn()!==false;$q->closeCursor();if($claimed){$db->commit();return 'busy';}
        $db->prepare("INSERT INTO listening_automation_steps(run_id,step_index,state) VALUES(?,?,'claimed')")->execute([$run['id'],$step]);$db->commit();return 'claimed';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_listening_automation_complete(int $run,int $step):void {
    if(sf_listening_playback_exists())sf_db()->prepare("UPDATE listening_automation_steps SET state='done' WHERE run_id=? AND step_index=?")->execute([$run,$step]);
}
