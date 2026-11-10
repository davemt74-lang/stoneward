<?php
declare(strict_types=1);

require_once __DIR__.'/canvas-audio-core.php';
require_once __DIR__.'/jukebox-listener-metrics.php';

function sf_jukebox_schema(): void {
    static $ready=false;
    if($ready)return;
    $mysql=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $blob=$mysql?'MEDIUMTEXT':'TEXT';
    $serial=$mysql?'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $threadIndex=$mysql?', INDEX jukebox_messages_thread (session_id,phone_id,id,sender)':'';
    $directoryIndex=$mysql?', INDEX jukebox_sessions_owner_directory (owner_id,ended,created,id)':'';
    $tables=[
        "jukebox_venues (owner_id BIGINT PRIMARY KEY, name VARCHAR(120) NOT NULL)",
        "jukebox_sessions (id CHAR(32) PRIMARY KEY, owner_id BIGINT NOT NULL, venue_id BIGINT NOT NULL,
            title VARCHAR(120) NOT NULL, listed INTEGER NOT NULL, ended INTEGER NOT NULL DEFAULT 0,
            revision BIGINT NOT NULL DEFAULT 0, player CHAR(64) NOT NULL DEFAULT '',
            heartbeat BIGINT NOT NULL DEFAULT 0, current_id BIGINT NULL, paused INTEGER NOT NULL DEFAULT 0,
            seconds INTEGER NOT NULL DEFAULT 0, created BIGINT NOT NULL$directoryIndex)",
        "jukebox_schedule (session_id CHAR(32) PRIMARY KEY, scheduled_at BIGINT NOT NULL, started_at BIGINT NULL)",
        "jukebox_remote (session_id CHAR(32) PRIMARY KEY, enabled INTEGER NOT NULL,
            status VARCHAR(12) NOT NULL, position_ms BIGINT NOT NULL, sampled_at_ms BIGINT NOT NULL,
            sample_seq BIGINT NOT NULL, control_revision BIGINT NOT NULL)",
        "jukebox_guests (id CHAR(32) PRIMARY KEY, venue_id BIGINT NOT NULL, token CHAR(64) NOT NULL,
            name VARCHAR(80) NOT NULL, UNIQUE(venue_id,token))",
        "jukebox_phones (id CHAR(32) PRIMARY KEY, session_id CHAR(32) NOT NULL, token CHAR(64) NOT NULL,
            guest_id CHAR(32) NULL, name VARCHAR(80) NOT NULL, status VARCHAR(12) NOT NULL,
            approved BIGINT NULL, created BIGINT NOT NULL, UNIQUE(session_id,token))",
        "jukebox_requests (id $serial, session_id CHAR(32) NOT NULL, phone_id CHAR(32) NULL,
            nonce CHAR(32) NOT NULL, track_id VARCHAR(180) NOT NULL, position BIGINT NOT NULL,
            status VARCHAR(12) NOT NULL, requested BIGINT NOT NULL, played BIGINT NULL,
            UNIQUE(session_id,nonce))",
        "jukebox_messages (id $serial, session_id CHAR(32) NOT NULL, phone_id CHAR(32) NOT NULL,
            sender VARCHAR(8) NOT NULL, body VARCHAR(1000) NOT NULL, image $blob NULL, created BIGINT NOT NULL$threadIndex)"
    ];
    $db=sf_db();
    foreach($tables as $table) $db->exec("CREATE TABLE IF NOT EXISTS ".$table.$engine);
    foreach(['jukebox_messages_thread'=>['jukebox_messages','session_id,phone_id,id,sender'],
        'jukebox_sessions_owner_directory'=>['jukebox_sessions','owner_id,ended,created,id']] as $index=>[$table,$columns]){
        if(!$mysql)$db->exec("CREATE INDEX IF NOT EXISTS $index ON $table ($columns)");
        elseif(!$db->query("SHOW INDEX FROM $table WHERE Key_name='$index'")->fetch()){
            // Existing installations need these indexes. Another first request may install one first.
            try{$db->exec("CREATE INDEX $index ON $table ($columns)");}
            catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1061)throw $error;}
        }
    }
    sf_jukebox_listener_schema($mysql,$engine);
    $ready=true;
}

function sf_jukebox_query(string $sql,array $args=[]): PDOStatement {
    $query=sf_db()->prepare($sql);
    $query->execute($args);
    return $query;
}

function sf_jukebox_session(string $id): array {
    $row=sf_jukebox_query('SELECT s.*, COALESCE(r.enabled,0) AS remote_enabled,
        r.status AS remote_status,r.position_ms,r.sampled_at_ms,r.sample_seq,r.control_revision,
        j.scheduled_at,j.started_at FROM jukebox_sessions s
        LEFT JOIN jukebox_remote r ON r.session_id=s.id
        LEFT JOIN jukebox_schedule j ON j.session_id=s.id WHERE s.id=?',[$id])->fetch();
    if(!$row)throw new OutOfBoundsException('Jukebox session not found.');
    return $row;
}

function sf_jukebox_phase(array $session): string {
    $waiting=isset($session['scheduled_at'])&&!isset($session['started_at']);
    if((int)($session['ended']??0))return $waiting?'canceled':'ended';
    return $waiting?'upcoming':'live';
}

function sf_jukebox_schedule_time(mixed $timestamp): int {
    if(!is_int($timestamp)||$timestamp<=time()||$timestamp>253402300799)
        throw new InvalidArgumentException('Choose a scheduled time in the future.');
    return $timestamp;
}

function sf_jukebox_schedule_create(string $id,int $timestamp): void {
    sf_jukebox_query('INSERT INTO jukebox_schedule (session_id,scheduled_at) VALUES (?,?)',[$id,$timestamp]);
}

function sf_jukebox_schedule_details(array $session): array {
    $scheduled=isset($session['scheduled_at'])?(int)$session['scheduled_at']:null;
    // An unscheduled session opens when created. A scheduled one opens when the host starts it.
    $started=$session['started_at']??($scheduled===null?($session['created']??null):null);
    return ['phase'=>sf_jukebox_phase($session),'scheduled_at'=>$scheduled,
        'started_at'=>$started===null?null:(int)$started];
}

/** Persisted configuration, independent of player heartbeats and transport state. */
function sf_jukebox_settings(array $session): array {
    return ['title'=>(string)$session['title'],'listed'=>(bool)$session['listed'],
        'remote_listening'=>(bool)$session['remote_enabled'],
        'scheduled_at'=>isset($session['scheduled_at'])?(int)$session['scheduled_at']:null,
        'phase'=>sf_jukebox_phase($session)];
}

/** Called after taking the session write lock. Never copies or resets playback. */
function sf_jukebox_settings_update(array $session,array $body,bool $admin): array {
    $current=sf_jukebox_settings($session);
    $expected=$body['expected']??null;
    if(!is_array($expected)||count($expected)!==count($current)
        ||array_diff_key($current,$expected)||array_diff_key($expected,$current)
        ||!is_string($expected['title'])||!is_bool($expected['listed'])||!is_bool($expected['remote_listening'])
        ||($expected['scheduled_at']!==null&&!is_int($expected['scheduled_at']))
        ||!in_array($expected['phase'],['live','upcoming','ended','canceled'],true))
        throw new InvalidArgumentException('Reload the session settings before saving.');
    foreach($current as $key=>$value){
        if($expected[$key]!==$value)
            throw new DomainException('These session settings changed. Reopen Edit session to review the latest settings.');
    }
    $changes=array_diff_key($body,['expected'=>true]);
    if(!$changes||array_diff_key($changes,$current)||array_key_exists('phase',$changes))
        throw new InvalidArgumentException('Choose valid session settings to update.');
    $next=$current;
    if(array_key_exists('title',$body)){
        if(!is_string($body['title'])||!preg_match('/\A[^\x00-\x1F\x7F]{1,120}\z/u',trim($body['title'])))
            throw new InvalidArgumentException('Enter a session name of 1 to 120 characters.');
        $next['title']=trim($body['title']);
    }
    foreach(['listed','remote_listening'] as $key){
        if(!array_key_exists($key,$body))continue;
        if(!is_bool($body[$key]))throw new InvalidArgumentException('Choose whether '.($key==='listed'?'the session is public.':'remote listening is enabled.'));
        $next[$key]=$body[$key];
    }
    if(array_key_exists('scheduled_at',$body)){
        if($body['scheduled_at']!==null&&!is_int($body['scheduled_at']))
            throw new InvalidArgumentException('Choose a valid scheduled time.');
        $next['scheduled_at']=$body['scheduled_at'];
    }
    if($next['listed']&&!$current['listed']&&!$admin)
        throw new DomainException('An administrator must make a session public.');
    if(in_array($current['phase'],['ended','canceled'],true)){
        foreach(['listed','remote_listening','scheduled_at'] as $key){
            if($next[$key]!==$current[$key])throw new DomainException('Only the name of an archived session can be changed.');
        }
    }
    if($next['scheduled_at']!==$current['scheduled_at']){
        if($current['phase']!=='upcoming')throw new DomainException('Only an upcoming session can be rescheduled.');
        $next['scheduled_at']=sf_jukebox_schedule_time($next['scheduled_at']);
    }
    if($next['title']!==$current['title']||$next['listed']!==$current['listed'])
        sf_jukebox_query('UPDATE jukebox_sessions SET title=?,listed=? WHERE id=?',[$next['title'],(int)$next['listed'],$session['id']]);
    if($next['remote_listening']!==$current['remote_listening'])
        sf_jukebox_controls($session,['event'=>'remote_listening','enabled'=>$next['remote_listening']]);
    if($next['scheduled_at']!==$current['scheduled_at'])
        sf_jukebox_controls($session,['event'=>'reschedule','scheduled_at'=>$next['scheduled_at']]);
    return sf_jukebox_session($session['id']);
}

/** Keyset pagination keeps older sessions reachable as new sessions are created. */
function sf_jukebox_directory_page(int $owner,string $scope,mixed $cursor,mixed $limit): array {
    if(!in_array($scope,['active','archive'],true))throw new InvalidArgumentException('Choose Active or Archive sessions.');
    if(!is_string($limit)||!preg_match('/\A(?:[1-9]|1[0-9]|2[0-5])\z/',$limit))
        throw new InvalidArgumentException('Choose a session page size from 1 to 25.');
    $size=(int)$limit;
    $args=[$owner,$scope==='archive'?1:0];
    $older='';
    if($cursor!==null){
        if(!is_string($cursor)||!preg_match('/\A[A-Za-z0-9_-]{1,256}\z/',$cursor))
            throw new InvalidArgumentException('This session page link is invalid.');
        $decoded=base64_decode(strtr($cursor,'-_','+/'),true);
        $position=$decoded===false?null:json_decode($decoded,true);
        if(!is_array($position)||($position['scope']??null)!==$scope||!is_int($position['created']??null)
            ||$position['created']<0||!is_string($position['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/',$position['id']))
            throw new InvalidArgumentException('This session page link is invalid.');
        $older=' AND (s.created<? OR (s.created=? AND s.id<?))';
        array_push($args,$position['created'],$position['created'],$position['id']);
    }
    $rows=sf_jukebox_query('SELECT s.id,s.title,s.listed,s.ended,s.created,s.heartbeat,
        COALESCE(r.enabled,0) AS remote_enabled,t.scheduled_at,t.started_at
        FROM jukebox_sessions s LEFT JOIN jukebox_schedule t ON t.session_id=s.id
        LEFT JOIN jukebox_remote r ON r.session_id=s.id WHERE s.owner_id=? AND s.ended=?'.$older.
        ' ORDER BY s.created DESC,s.id DESC LIMIT '.($size+1),$args)->fetchAll();
    $next=null;
    if(count($rows)>$size){
        array_pop($rows);
        $last=$rows[count($rows)-1];
        $next=rtrim(strtr(base64_encode(json_encode(['scope'=>$scope,'created'=>(int)$last['created'],'id'=>$last['id']])),'+/','-_'),'=');
    }
    return ['sessions'=>$rows,'next_cursor'=>$next];
}

function sf_jukebox_token(mixed $token): string {
    return is_string($token)&&preg_match('/\A[a-f0-9]{64}\z/',$token)?$token:'';
}

function sf_jukebox_cookie(string $name,string $value,int $expires=0): void {
    setcookie($name,$value,['expires'=>$expires,'path'=>'/','secure'=>sf_cookie_secure(),'httponly'=>true,'samesite'=>'Lax']);
}

function sf_jukebox_phone(array $session): ?array {
    $secret=sf_jukebox_token($_COOKIE['sf_jb_'.$session['id']]??null);
    if($secret==='')return null;
    return sf_jukebox_query('SELECT * FROM jukebox_phones WHERE session_id=? AND token=?',
        [$session['id'],hash('sha256',$secret)])->fetch()?:null;
}

function sf_jukebox_owner(array $session,?array $user): bool {
    return $user&&(($user['role']??'')==='admin'||(int)$session['owner_id']===(int)$user['id']);
}

function sf_jukebox_guest_key(array $session): string {
    return 'sf_jb_venue_'.$session['venue_id'];
}

function sf_jukebox_guest(array $session): ?array {
    if(!(int)$session['venue_id'])return null;
    $token=sf_jukebox_token($_COOKIE[sf_jukebox_guest_key($session)]??null);
    if($token==='')return null;
    return sf_jukebox_query('SELECT id,name FROM jukebox_guests WHERE venue_id=? AND token=?',
        [$session['venue_id'],hash('sha256',$token)])->fetch()?:null;
}

function sf_jukebox_player_online(array $session): bool {
    return sf_jukebox_phase($session)==='live'&&(int)$session['heartbeat']>=time()-12;
}

function sf_jukebox_remote_access(array $session,?array $phone): bool {
    $phoneStatus=$phone['status']??null;
    return (bool)$session['remote_enabled']&&sf_jukebox_phase($session)==='live'&&$phoneStatus!=='revoked'
        &&((bool)$session['listed']||$phoneStatus==='approved');
}

function sf_jukebox_remote_source(array $session,?array $phone,string $item): ?array {
    if(!sf_jukebox_remote_access($session,$phone)||!sf_jukebox_player_online($session)
        ||$session['current_id']===null||(string)$session['current_id']!==$item)return null;
    $track=sf_jukebox_query("SELECT track_id FROM jukebox_requests WHERE session_id=? AND id=?
        AND status='current' AND played IS NOT NULL",[$session['id'],$item])->fetchColumn();
    return $track===false?null:sf_canvas_audio_source((string)$track);
}

function sf_jukebox_transport(array $session,?array $item,bool $online): array {
    $now=(int)floor(microtime(true)*1000);
    $sampledAt=(int)$session['sampled_at_ms'];
    $status=match(true){
        !$online=>'offline',
        $item===null=>'idle',
        (bool)$session['paused']=>'paused',
        $session['remote_status']==='playing'&&$item['played']===null=>'starting',
        $session['remote_status']==='playing'&&$now-$sampledAt>5000=>'buffering',
        default=>$session['remote_status'],
    };
    return ['status'=>$status,'position_ms'=>(int)$session['position_ms'],'sampled_at_ms'=>$sampledAt,
        'server_time_ms'=>$now,'generation'=>(int)$session['control_revision']];
}

function sf_jukebox_remote_toggle(array $session,mixed $enabled): void {
    if(!is_bool($enabled))throw new InvalidArgumentException('Choose whether remote listening is enabled.');
    if($enabled===(bool)$session['remote_enabled'])return;
    if(!$enabled)sf_jukebox_listener_invalidate($session['id']);
    $mysql=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $sql='INSERT INTO jukebox_remote (session_id,enabled,status,position_ms,sampled_at_ms,sample_seq,control_revision)
        VALUES (?,?,?,?,0,-1,?)';
    $sql.=$mysql
        ?' ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),status=VALUES(status),position_ms=VALUES(position_ms),
            sampled_at_ms=0,control_revision=VALUES(control_revision)'
        :' ON CONFLICT(session_id) DO UPDATE SET enabled=excluded.enabled,status=excluded.status,position_ms=excluded.position_ms,
            sampled_at_ms=0,control_revision=excluded.control_revision';
    sf_jukebox_query($sql,[$session['id'],(int)$enabled,'starting',(int)$session['seconds']*1000,$session['revision']]);
}

function sf_jukebox_advance(array $session): void {
    $id=$session['id'];
    $next=sf_jukebox_query("SELECT id FROM jukebox_requests WHERE session_id=? AND status='queued' ORDER BY position,id LIMIT 1",[$id])->fetchColumn();
    if($next!==false)sf_jukebox_query("UPDATE jukebox_requests SET status='current' WHERE id=?",[$next]);
    sf_jukebox_query('UPDATE jukebox_sessions SET current_id=?,paused=0,seconds=0 WHERE id=?',
        [$next===false?null:$next,$id]);
    if($session['remote_enabled']){
        sf_jukebox_listener_invalidate($id);
        sf_jukebox_query("UPDATE jukebox_remote SET status='starting',position_ms=0,
            sampled_at_ms=0,control_revision=? WHERE session_id=?",[$session['revision'],$id]);
    }
}

function sf_jukebox_state(array $session,?array $phone,bool $host): array {
    $id=$session['id'];
    $schedule=sf_jukebox_schedule_details($session);
    $phase=$schedule['phase'];
    if($phase==='upcoming'||$phase==='canceled'){
        $state=array_intersect_key($session,array_flip(['id','title','ended']))+$schedule;
        if($host&&$phase==='upcoming'){
            $state['remote_listening']=(bool)$session['remote_enabled'];
            $state['listed']=(bool)$session['listed'];
        }
        return $state;
    }
    $state=array_intersect_key($session,array_flip(['id','title','ended','venue_id','paused','seconds','revision']))+$schedule;
    $state['phone']=$phone?array_intersect_key($phone,array_flip(['id','name','status'])):null;
    $state['remote_listening']=$host
        ?(bool)$session['remote_enabled']&&!(int)$session['ended']
        :sf_jukebox_remote_access($session,$phone);
    if(!$host&&((int)$session['ended']||(!$session['listed']&&($phone['status']??'')!=='approved')))return $state;
    $state['online']=sf_jukebox_player_online($session);
    $state['current']=null;
    $state['queue']=[];
    $rows=sf_jukebox_query("SELECT id,track_id,status,played FROM jukebox_requests WHERE session_id=? AND status IN ('queued','current') ORDER BY position,id",[$id])->fetchAll();
    foreach($rows as $row){
        if($row['status']==='current')$state['current']=$row;
        else $state['queue'][]=$row;
    }
    if($phase==='live'&&($host||($phone['status']??'')==='approved'))
        $state['chat_threads']=sf_jukebox_chat_threads($id,$host?null:(string)$phone['id']);
    if($state['remote_listening'])$state['transport']=sf_jukebox_transport($session,$state['current'],$state['online']);
    if($host){
        $state['listener_metrics']=sf_jukebox_listener_metrics($session,$state['transport']??null);
        $state['phones']=sf_jukebox_query("SELECT p.id,p.name,p.status,
            (SELECT MAX(prior.approved) FROM jukebox_phones prior JOIN jukebox_guests g ON g.id=prior.guest_id
             WHERE prior.guest_id=p.guest_id AND prior.session_id<>p.session_id AND g.venue_id=?) AS last_visit
            FROM jukebox_phones p WHERE p.session_id=? ORDER BY p.created,p.id",[$session['venue_id'],$id])->fetchAll();
        $state['history']=sf_jukebox_query('SELECT r.id,r.track_id,r.played,p.name FROM jukebox_requests r
            LEFT JOIN jukebox_phones p ON p.id=r.phone_id WHERE r.session_id=? AND r.played IS NOT NULL ORDER BY r.played DESC,r.id DESC LIMIT 50',[$id])->fetchAll();
    }
    return $state;
}

function sf_jukebox_join(array $session,array $body): array {
    $id=$session['id'];
    $existing=sf_jukebox_phone($session);
    if($existing)return ['status'=>$existing['status']];
    $pending=sf_jukebox_query("SELECT COUNT(*) FROM jukebox_phones WHERE session_id=? AND status='pending'",[$id])->fetchColumn();
    if((int)$pending>=50)throw new DomainException('The host needs to review pending requests before more phones can join.');
    $name=sf_clean_text($body['name']??'',80);
    $guest=sf_jukebox_guest($session);
    if(!$guest&&!empty($body['remember'])&&(int)$session['venue_id']){
        $remember=bin2hex(random_bytes(32));
        $guest=['id'=>bin2hex(random_bytes(16)),'name'=>$name];
        sf_jukebox_query('INSERT INTO jukebox_guests (id,venue_id,token,name) VALUES (?,?,?,?)',
            [$guest['id'],$session['venue_id'],hash('sha256',$remember),$name]);
        sf_jukebox_cookie(sf_jukebox_guest_key($session),$remember,time()+180*86400);
    }
    if($name===''&&$guest)$name=$guest['name'];
    if($guest&&$name!==$guest['name'])sf_jukebox_query('UPDATE jukebox_guests SET name=? WHERE id=?',[$name,$guest['id']]);
    $phone=bin2hex(random_bytes(16));
    $secret=bin2hex(random_bytes(32));
    sf_jukebox_query("INSERT INTO jukebox_phones (id,session_id,token,guest_id,name,status,created) VALUES (?,?,?,?,?,'pending',?)",
        [$phone,$id,hash('sha256',$secret),$guest['id']??null,$name?:'Guest '.strtoupper(substr($phone,0,4)),time()]);
    sf_jukebox_cookie('sf_jb_'.$id,$secret,time()+86400);
    return ['status'=>'pending'];
}

function sf_jukebox_request(array $session,?array $phone,array $body): void {
    $nonce=$body['nonce']??null;
    if(!is_string($nonce)||!preg_match('/\A[a-f0-9]{32}\z/',$nonce))throw new InvalidArgumentException('A request ID is required.');
    $id=$session['id'];
    $existing=sf_jukebox_query('SELECT phone_id FROM jukebox_requests WHERE session_id=? AND nonce=?',[$id,$nonce])->fetch();
    if($existing){
        if($existing['phone_id']!==($phone['id']??null))throw new DomainException('This request ID is already in use.');
        return;
    }
    $track=sf_clean_text($body['track']??'',180);
    if(sf_canvas_audio_source($track)===null)throw new InvalidArgumentException('This track is unavailable.');
    $count=sf_jukebox_query("SELECT COUNT(*) FROM jukebox_requests WHERE session_id=? AND status='queued'",[$id])->fetchColumn();
    if((int)$count>=100)throw new DomainException('The jukebox queue is full.');
    sf_jukebox_query("INSERT INTO jukebox_requests (session_id,phone_id,nonce,track_id,position,status,requested) VALUES (?,?,?,?,?,'queued',?)",
        [$id,$phone['id']??null,$nonce,$track,$session['revision'],time()]);
    if($session['current_id']===null)sf_jukebox_advance($session);
}

function sf_jukebox_player(array $session,array $body): void {
    $token=sf_jukebox_token($body['player']??null);
    if($token==='')throw new InvalidArgumentException('Player identity is required.');
    $hash=hash('sha256',$token);
    $id=$session['id'];
    $event=$body['event']??'';
    if($event==='claim'){
        if(sf_jukebox_player_online($session)&&$session['player']!==$hash&&empty($body['takeover']))
            throw new DomainException('A session player is already connected.');
        sf_jukebox_query('UPDATE jukebox_sessions SET player=?,heartbeat=? WHERE id=?',[$hash,time(),$id]);
        if($session['remote_enabled'])sf_jukebox_listener_invalidate($id);
        if($session['sample_seq']!==null)sf_jukebox_query("UPDATE jukebox_remote SET status='starting',
            sampled_at_ms=0,sample_seq=-1,control_revision=? WHERE session_id=?",[$session['revision'],$id]);
        return;
    }
    if(!hash_equals($session['player'],$hash))throw new DomainException('Another player owns this session.');
    sf_jukebox_query('UPDATE jukebox_sessions SET heartbeat=? WHERE id=?',[time(),$id]);
    if($session['current_id']===null||(string)$session['current_id']!==(string)($body['item']??''))return;
    if($event==='playing')sf_jukebox_query('UPDATE jukebox_requests SET played=COALESCE(played,?) WHERE id=?',[time(),$session['current_id']]);
    if(in_array($event,['ended','failed'],true)){
        sf_jukebox_query('UPDATE jukebox_requests SET status=? WHERE id=?',[$event,$session['current_id']]);
        sf_jukebox_advance($session);
        return;
    }
    $progress=in_array($event,['playing','progress'],true);
    if(!$progress&&$event!=='heartbeat')return;
    $seconds=max(0,min(86400,(int)($body['seconds']??0)));
    if($session['remote_enabled']){
        $sequence=isset($body['sample_seq'])?filter_var($body['sample_seq'],FILTER_VALIDATE_INT):null;
        $observed=isset($body['observed_revision'])?filter_var($body['observed_revision'],FILTER_VALIDATE_INT):null;
        if($sequence===false||($sequence!==null&&$sequence<0)||$observed===false)return;
        // Host commands are serialized with reports. A report may arrive late
        // even when its sequence number is newer than the last accepted report.
        if($observed!==null&&($observed<(int)$session['control_revision']||$observed>(int)$session['revision']))return;
        $previous=(int)$session['sample_seq'];
        if($previous>=0&&($sequence===null||$sequence<=$previous))return;
        $status=$body['transport']??($progress?'playing':null);
        if(!in_array($status,['playing','buffering','paused','starting'],true))return;
        if($session['paused']&&$status!=='paused')return;
        if(!$session['paused']&&$status==='paused')$status='starting';
        $position=isset($body['position_ms'])?filter_var($body['position_ms'],FILTER_VALIDATE_INT):null;
        if($position===false)return;
        $position=max(0,min(86400000,$position??$seconds*1000));
        $now=(int)floor(microtime(true)*1000);
        // Do not revive pre-buffer activity when transport starts moving again.
        if($session['remote_status']==='playing'&&($status!=='playing'||$now-(int)$session['sampled_at_ms']>5000))
            sf_jukebox_listener_invalidate($id);
        else sf_jukebox_listener_clear_expired($id,$now);
        sf_jukebox_query('UPDATE jukebox_remote SET status=?,position_ms=?,sampled_at_ms=?,sample_seq=? WHERE session_id=?',
            [$status,$position,$now,$sequence??-1,$id]);
        $seconds=intdiv($position,1000);
        $progress=$progress||$status==='paused';
    }
    if($progress)sf_jukebox_query('UPDATE jukebox_sessions SET seconds=? WHERE id=?',[$seconds,$id]);
}

function sf_jukebox_controls(array $session,array $body): void {
    $id=$session['id'];
    $event=$body['event']??'';
    $phase=sf_jukebox_phase($session);
    if($event==='start'){
        if($phase==='live'&&isset($session['started_at']))return;
        if($phase!=='upcoming')throw new DomainException('Only an upcoming scheduled session can start.');
        sf_jukebox_query('UPDATE jukebox_schedule SET started_at=? WHERE session_id=? AND started_at IS NULL',[time(),$id]);
        return;
    }
    if($event==='reschedule'){
        if($phase!=='upcoming')throw new DomainException('Only an upcoming session can be rescheduled.');
        $timestamp=sf_jukebox_schedule_time($body['scheduled_at']??null);
        sf_jukebox_query('UPDATE jukebox_schedule SET scheduled_at=? WHERE session_id=? AND started_at IS NULL',[$timestamp,$id]);
        return;
    }
    if($event==='cancel'&&$phase!=='upcoming')throw new DomainException('Only an upcoming session can be canceled.');
    if($phase==='upcoming'&&!in_array($event,['cancel','remote_listening'],true))
        throw new DomainException('This jukebox session has not started.');
    if(in_array($event,['end','cancel'],true)){
        sf_jukebox_query('UPDATE jukebox_sessions SET ended=1,heartbeat=0 WHERE id=?',[$id]);
        // The recap needs only aggregate totals, never player or phone identifiers.
        sf_jukebox_query('DELETE FROM jukebox_listener_players WHERE session_id=?',[$id]);
    }elseif($event==='remote_listening'){
        sf_jukebox_remote_toggle($session,$body['enabled']??null);
    }elseif(in_array($event,['pause','resume'],true)){
        $paused=$event==='pause';
        sf_jukebox_query('UPDATE jukebox_sessions SET paused=? WHERE id=?',[(int)$paused,$id]);
        if($session['remote_enabled']&&$paused!==(bool)$session['paused']){
            sf_jukebox_listener_invalidate($id);
            sf_jukebox_query("UPDATE jukebox_remote SET status='starting',sampled_at_ms=0,control_revision=? WHERE session_id=?",
                [$session['revision'],$id]);
        }
    }elseif($event==='skip'){
        sf_jukebox_query("UPDATE jukebox_requests SET status='skipped' WHERE id=? AND session_id=?",[$session['current_id'],$id]);
        sf_jukebox_advance($session);
    }elseif($event==='remove'){
        sf_jukebox_query("UPDATE jukebox_requests SET status='removed' WHERE id=? AND session_id=? AND status='queued'",[(int)($body['item']??0),$id]);
    }elseif($event==='up'){
        $rows=sf_jukebox_query("SELECT id,position FROM jukebox_requests WHERE session_id=? AND status='queued' ORDER BY position,id",[$id])->fetchAll();
        foreach($rows as $index=>$row){
            if($index===0||(int)$row['id']!==(int)($body['item']??0))continue;
            $prior=$rows[$index-1];
            sf_jukebox_query('UPDATE jukebox_requests SET position=? WHERE id=?',[$prior['position'],$row['id']]);
            sf_jukebox_query('UPDATE jukebox_requests SET position=? WHERE id=?',[$row['position'],$prior['id']]);
            break;
        }
    }else throw new InvalidArgumentException('Unknown player control.');
}

function sf_jukebox_chat_threads(string $sessionId,?string $phoneId): array {
    $args=[$phoneId===null?'guest':'host',$sessionId];
    $phoneFilter='';
    if($phoneId!==null){
        $phoneFilter=' AND m.phone_id=?';
        $args[]=$phoneId;
    }
    $rows=sf_jukebox_query("SELECT m.phone_id,MAX(m.id) AS latest_id,
        SUM(CASE WHEN m.sender=? THEN 1 ELSE 0 END) AS incoming_count
        FROM jukebox_messages m JOIN jukebox_phones p ON p.id=m.phone_id AND p.session_id=m.session_id
        WHERE m.session_id=? AND p.status='approved'$phoneFilter GROUP BY m.phone_id",$args)->fetchAll();
    return array_map(static fn(array $row):array=>['phone_id'=>(string)$row['phone_id'],
        'latest_id'=>(string)$row['latest_id'],'incoming_count'=>(int)$row['incoming_count']],$rows);
}

function sf_jukebox_thread(string $sessionId,string $phoneId,bool $full=false): array {
    $limit=$full?200:50;
    $rows=sf_jukebox_query('SELECT id,sender,body,image IS NOT NULL AS attachment,created
        FROM jukebox_messages WHERE session_id=? AND phone_id=? ORDER BY id DESC LIMIT '.$limit,[$sessionId,$phoneId])->fetchAll();
    if($full)foreach($rows as $i=>$row)$rows[$i]['id']=(string)$row['id'];
    return array_reverse($rows);
}

function sf_jukebox_message(string $sessionId,string $phoneId,string $sender,array $body): void {
    $message=sf_clean_text($body['text']??'',1000);
    $image=null;
    if(!empty($body['image'])){
        $encoded=$body['image'];
        if(!is_string($encoded)||strlen($encoded)>1_400_000)throw new InvalidArgumentException('Use an image under 1 MB.');
        $bytes=base64_decode($encoded,true);
        $info=$bytes===false?false:@getimagesizefromstring($bytes);
        if(!$info||strlen($bytes)>1_048_576||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)
            ||$info[0]*$info[1]>20_000_000)throw new InvalidArgumentException('Choose a JPEG, PNG, or WebP image under 1 MB.');
        $image=$info['mime'].';base64,'.base64_encode($bytes);
    }
    if($message===''&&$image===null)throw new InvalidArgumentException('Enter a message or choose an image.');
    $count=sf_jukebox_query('SELECT COUNT(*) FROM jukebox_messages WHERE session_id=? AND phone_id=?',[$sessionId,$phoneId])->fetchColumn();
    if((int)$count>=200)throw new DomainException('This conversation is full.');
    sf_jukebox_query('INSERT INTO jukebox_messages (session_id,phone_id,sender,body,image,created) VALUES (?,?,?,?,?,?)',
        [$sessionId,$phoneId,$sender,$message,$image,time()]);
}

function sf_jukebox_recap(array $session): array {
    if(sf_jukebox_phase($session)==='canceled')throw new DomainException('This scheduled session was canceled.');
    if(!(int)$session['ended'])throw new DomainException('The recap is available after the session ends.');
    $id=$session['id'];
    $phones=sf_jukebox_query('SELECT COUNT(*) AS connections, COUNT(approved) AS approved
        FROM jukebox_phones WHERE session_id=?',[$id])->fetch();
    $trackKey=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'CAST(track_id AS BINARY)':'track_id';
    $rows=sf_jukebox_query("SELECT $trackKey AS track_id, COUNT(*) AS requests,
        SUM(CASE WHEN phone_id IS NOT NULL THEN 1 ELSE 0 END) AS guest_requests,
        SUM(CASE WHEN phone_id IS NULL THEN 1 ELSE 0 END) AS host_requests,
        COUNT(played) AS confirmed_plays,
        SUM(CASE WHEN status='ended' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) AS skipped,
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status='removed' THEN 1 ELSE 0 END) AS removed,
        SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END) AS left_in_queue,
        SUM(CASE WHEN status='current' THEN 1 ELSE 0 END) AS current_at_close
        FROM jukebox_requests WHERE session_id=? GROUP BY $trackKey",[$id])->fetchAll();

    // The first confirmed playback and the eventual request outcome are independent.
    $totals=['requests'=>0,'guest_requests'=>0,'host_requests'=>0,'confirmed_plays'=>0,
        'completed'=>0,'skipped'=>0,'failed'=>0,'removed'=>0,'left_in_queue'=>0,'current_at_close'=>0];
    $titles=[];
    try{
        foreach($rows as $row){
            $trackId=(string)$row['track_id'];
            $track=sf_canvas_audio_public_track($trackId);
            $titles[$trackId]=sf_agent_public_artist_text($track['title']??'')?:$trackId;
        }
    }catch(RuntimeException){
        // The historical totals remain available if the current catalog cannot be read.
    }
    $tracks=[];
    foreach($rows as $row){
        $trackId=(string)$row['track_id'];
        $counts=array_map('intval',array_intersect_key($row,$totals));
        foreach($counts as $key=>$count)$totals[$key]+=$count;
        $tracks[]=['track_id'=>$trackId,'title'=>$titles[$trackId]??$trackId]+$counts;
    }
    usort($tracks,static fn(array $a,array $b): int =>
        ($b['requests']<=>$a['requests'])?:($b['confirmed_plays']<=>$a['confirmed_plays'])?:strcmp($a['track_id'],$b['track_id']));

    return ['session_id'=>$id,'title'=>$session['title'],'created'=>(int)$session['created'],
        'phones'=>array_map('intval',$phones),'totals'=>$totals,'tracks'=>$tracks,
        'listener_metrics'=>sf_jukebox_listener_metrics($session)]+sf_jukebox_schedule_details($session);
}

function sf_jukebox_crm(int $venue): array {
    if(!$venue)return [];
    $guests=sf_jukebox_query('SELECT g.id,g.name,MIN(p.approved) AS first_visit,MAX(p.approved) AS last_visit,
        COUNT(DISTINCT p.session_id) AS visits FROM jukebox_guests g JOIN jukebox_phones p ON p.guest_id=g.id
        WHERE g.venue_id=? AND p.approved IS NOT NULL GROUP BY g.id,g.name ORDER BY last_visit DESC LIMIT 100',[$venue])->fetchAll();
    foreach($guests as &$guest){
        $guest['history']=sf_jukebox_query('SELECT s.title AS session_title,p.approved,r.track_id,r.requested,r.played
            FROM jukebox_phones p JOIN jukebox_sessions s ON s.id=p.session_id
            LEFT JOIN jukebox_requests r ON r.phone_id=p.id WHERE p.guest_id=? AND p.approved IS NOT NULL
            ORDER BY p.approved DESC,r.id DESC LIMIT 50',[$guest['id']])->fetchAll();
    }
    return $guests;
}
