<?php
declare(strict_types=1);

require_once __DIR__.'/canvas-audio-core.php';

function sf_jukebox_schema(): void {
    static $ready=false;
    if($ready)return;
    $mysql=sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $blob=$mysql?'MEDIUMTEXT':'TEXT';
    $serial=$mysql?'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tables=[
        "jukebox_venues (owner_id BIGINT PRIMARY KEY, name VARCHAR(120) NOT NULL)",
        "jukebox_sessions (id CHAR(32) PRIMARY KEY, owner_id BIGINT NOT NULL, venue_id BIGINT NOT NULL,
            title VARCHAR(120) NOT NULL, listed INTEGER NOT NULL, ended INTEGER NOT NULL DEFAULT 0,
            revision BIGINT NOT NULL DEFAULT 0, player CHAR(64) NOT NULL DEFAULT '',
            heartbeat BIGINT NOT NULL DEFAULT 0, current_id BIGINT NULL, paused INTEGER NOT NULL DEFAULT 0,
            seconds INTEGER NOT NULL DEFAULT 0, created BIGINT NOT NULL)",
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
            sender VARCHAR(8) NOT NULL, body VARCHAR(1000) NOT NULL, image $blob NULL, created BIGINT NOT NULL)"
    ];
    foreach($tables as $table) sf_db()->exec("CREATE TABLE IF NOT EXISTS ".$table.$engine);
    $ready=true;
}

function sf_jukebox_query(string $sql,array $args=[]): PDOStatement {
    $query=sf_db()->prepare($sql);
    $query->execute($args);
    return $query;
}

function sf_jukebox_session(string $id): array {
    $row=sf_jukebox_query('SELECT * FROM jukebox_sessions WHERE id=?',[$id])->fetch();
    if(!$row)throw new OutOfBoundsException('Jukebox session not found.');
    return $row;
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

function sf_jukebox_advance(array $session): void {
    $id=$session['id'];
    $next=sf_jukebox_query("SELECT id FROM jukebox_requests WHERE session_id=? AND status='queued' ORDER BY position,id LIMIT 1",[$id])->fetchColumn();
    if($next!==false)sf_jukebox_query("UPDATE jukebox_requests SET status='current' WHERE id=?",[$next]);
    sf_jukebox_query('UPDATE jukebox_sessions SET current_id=?,paused=0,seconds=0 WHERE id=?',
        [$next===false?null:$next,$id]);
}

function sf_jukebox_state(array $session,?array $phone,bool $host): array {
    $id=$session['id'];
    $state=array_intersect_key($session,array_flip(['id','title','ended','venue_id','paused','seconds','revision']));
    $state['phone']=$phone?array_intersect_key($phone,array_flip(['id','name','status'])):null;
    if(!$host&&((int)$session['ended']||(!$session['listed']&&($phone['status']??'')!=='approved')))return $state;
    $state['online']=(int)$session['heartbeat']>=time()-12;
    $state['current']=null;
    $state['queue']=[];
    $rows=sf_jukebox_query("SELECT id,track_id,status,played FROM jukebox_requests WHERE session_id=? AND status IN ('queued','current') ORDER BY position,id",[$id])->fetchAll();
    foreach($rows as $row){
        if($row['status']==='current')$state['current']=$row;
        else $state['queue'][]=$row;
    }
    if($host){
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
    if(($body['event']??'')==='claim'){
        if((int)$session['heartbeat']>=time()-12&&$session['player']!==$hash&&empty($body['takeover']))
            throw new DomainException('A session player is already connected.');
        sf_jukebox_query('UPDATE jukebox_sessions SET player=?,heartbeat=? WHERE id=?',[$hash,time(),$id]);
        return;
    }
    if(!hash_equals($session['player'],$hash))throw new DomainException('Another player owns this session.');
    sf_jukebox_query('UPDATE jukebox_sessions SET heartbeat=? WHERE id=?',[time(),$id]);
    if($session['current_id']===null||(string)$session['current_id']!==(string)($body['item']??''))return;
    $event=$body['event']??'';
    if($event==='playing')sf_jukebox_query('UPDATE jukebox_requests SET played=COALESCE(played,?) WHERE id=?',[time(),$session['current_id']]);
    if(in_array($event,['playing','progress'],true))sf_jukebox_query('UPDATE jukebox_sessions SET seconds=? WHERE id=?',
        [max(0,min(86400,(int)($body['seconds']??0))),$id]);
    if(in_array($event,['ended','failed'],true)){
        sf_jukebox_query('UPDATE jukebox_requests SET status=? WHERE id=?',[$event,$session['current_id']]);
        sf_jukebox_advance($session);
    }
}

function sf_jukebox_controls(array $session,array $body): void {
    $id=$session['id'];
    $event=$body['event']??'';
    if($event==='end'){
        sf_jukebox_query('UPDATE jukebox_sessions SET ended=1,heartbeat=0 WHERE id=?',[$id]);
    }elseif(in_array($event,['pause','resume'],true)){
        sf_jukebox_query('UPDATE jukebox_sessions SET paused=? WHERE id=?',[(int)($event==='pause'),$id]);
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

function sf_jukebox_thread(string $sessionId,string $phoneId): array {
    return array_reverse(sf_jukebox_query('SELECT id,sender,body,image IS NOT NULL AS attachment,created
        FROM jukebox_messages WHERE session_id=? AND phone_id=? ORDER BY id DESC LIMIT 50',[$sessionId,$phoneId])->fetchAll());
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
        'phones'=>array_map('intval',$phones),'totals'=>$totals,'tracks'=>$tracks];
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
