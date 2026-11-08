<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/jukebox-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
$action=$_GET['action']??'state';
$audioRequest=$action==='audio';
if(!in_array($method,$audioRequest?['GET','HEAD']:['GET','POST'],true)){
    header('Allow: '.($audioRequest?'GET, HEAD':'GET, POST'));
    sf_json_response(['ok'=>false,'message'=>'Method unavailable.'],405);
}
if($audioRequest){
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
}
if(!sf_installed())sf_json_response(['ok'=>false,'message'=>'Stonefellow installation required.'],503);
$hostMode=($_GET['host']??'')==='1';
$post=$method==='POST';
if($post&&(int)($_SERVER['CONTENT_LENGTH']??0)>1_450_000)
    sf_json_response(['ok'=>false,'message'=>'Request is too large.'],413);
$body=$post?sf_request_json():[];
$user=$hostMode?sf_require_user(false,$post):null;
if($post&&!$hostMode)sf_require_csrf();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$uid=(int)($user['id']??0);
$admin=($user['role']??'')==='admin';
$db=sf_db();

try{
    sf_jukebox_schema();
    if(!$post&&$action==='directory'){
        $sessions=$hostMode
            ?sf_jukebox_query('SELECT id,title,ended FROM jukebox_sessions WHERE owner_id=? ORDER BY created DESC LIMIT 50',[$uid])->fetchAll()
            :sf_jukebox_query('SELECT s.id,s.title,s.heartbeat,COALESCE(r.enabled,0) AS remote_listening
                FROM jukebox_sessions s LEFT JOIN jukebox_remote r ON r.session_id=s.id
                WHERE s.listed=1 AND s.ended=0 ORDER BY s.created DESC LIMIT 50')->fetchAll();
        if(!$hostMode){
            $sessions=array_map(static fn(array $session): array => [
                'id'=>$session['id'],'title'=>$session['title'],
                'remote_listening'=>(bool)$session['remote_listening'],'online'=>sf_jukebox_player_online($session)
            ],$sessions);
        }
        $venue=$hostMode?sf_jukebox_query('SELECT name FROM jukebox_venues WHERE owner_id=?',[$uid])->fetchColumn():false;
        sf_json_response(['ok'=>true,'sessions'=>$sessions,'venue'=>$venue?:null,'admin'=>$admin]);
    }
    if($post&&$hostMode&&$action==='venue'){
        if(!$admin)throw new DomainException('An administrator must enable venue accounts.');
        $owner=(int)($body['owner']??0);
        $name=sf_clean_text($body['name']??'',120);
        if($name===''||!sf_jukebox_query("SELECT 1 FROM users WHERE id=? AND status='active'",[$owner])->fetchColumn())
            throw new InvalidArgumentException('Enter an active Stonefellow account ID and venue name.');
        $sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'INSERT INTO jukebox_venues (owner_id,name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)'
            :'INSERT INTO jukebox_venues (owner_id,name) VALUES (?,?) ON CONFLICT(owner_id) DO UPDATE SET name=excluded.name';
        sf_jukebox_query($sql,[$owner,$name]);
        sf_json_response(['ok'=>true]);
    }
    if($post&&$hostMode&&$action==='create'){
        $listed=$admin&&!empty($body['listed']);
        $venue=!$listed&&(bool)sf_jukebox_query('SELECT 1 FROM jukebox_venues WHERE owner_id=?',[$uid])->fetchColumn();
        if(!$admin&&!$venue)throw new DomainException('A venue account is required.');
        $title=sf_clean_text($body['title']??'',120);
        if($title==='')throw new InvalidArgumentException('Enter a session name.');
        $remote=array_key_exists('remote_listening',$body)?$body['remote_listening']:false;
        if(!is_bool($remote))throw new InvalidArgumentException('Choose whether remote listening is enabled.');
        $id=bin2hex(random_bytes(16));
        $db->beginTransaction();
        sf_jukebox_query('INSERT INTO jukebox_sessions (id,owner_id,venue_id,title,listed,created) VALUES (?,?,?,?,?,?)',
            [$id,$uid,$venue?$uid:0,$title,(int)$listed,time()]);
        if($remote)sf_jukebox_controls(sf_jukebox_session($id),['event'=>'remote_listening','enabled'=>true]);
        $db->commit();
        sf_json_response(['ok'=>true,'id'=>$id],201);
    }

    $id=(string)($_GET['id']??'');
    if(!preg_match('/\A[a-f0-9]{32}\z/',$id))throw new OutOfBoundsException('Jukebox session not found.');
    // Managed MP3 resolution uses lazy media schema setup. MySQL DDL must run
    // before the session transaction so it cannot commit queue changes early.
    if(in_array($action,['audio','catalog','request'],true))sf_media_ensure_schema();
    if($post){
        // The first statement is a write on both engines. It serializes changes
        // for this session before reading approvals, the player, or queue order.
        $db->beginTransaction();
        sf_jukebox_query('UPDATE jukebox_sessions SET revision=revision+1 WHERE id=?',[$id]);
    }else $db->beginTransaction();
    $session=sf_jukebox_session($id);
    $host=$hostMode&&sf_jukebox_owner($session,$user);
    if($hostMode&&!$host)throw new OutOfBoundsException('Jukebox session not found.');
    $phone=$host?null:sf_jukebox_phone($session);
    $approved=($phone['status']??'')==='approved'&&!(int)$session['ended'];
    $thread=$host?(string)($_GET['phone']??$body['phone']??''):(string)($phone['id']??'');
    if($host&&$thread!==''&&!sf_jukebox_query('SELECT 1 FROM jukebox_phones WHERE id=? AND session_id=?',[$thread,$id])->fetchColumn())
        throw new OutOfBoundsException('Phone connection not found.');

    if(!$post){
        if($audioRequest){
            $item=$_GET['item']??null;
            $source=is_string($item)&&preg_match('/\A[1-9][0-9]{0,18}\z/',$item)
                ?sf_jukebox_remote_source($session,$phone,$item):null;
            if($source===null)throw new OutOfBoundsException('Session audio is unavailable.');
            $db->commit();
            $status=sf_media_stream_mp3($source,$method);
            sf_json_response(['ok'=>false,'message'=>'Session audio is unavailable.'],$status);
        }
        $payload=match($action){
            'state'=>['state'=>sf_jukebox_state($session,$phone,$host),'remembered'=>!$host&&sf_jukebox_guest($session)!==null],
            'catalog'=>($host||$approved||(int)$session['listed'])?sf_canvas_audio_catalog():throw new DomainException('Host approval is required.'),
            'thread'=>($host||$approved)&&$thread!==''?['messages'=>sf_jukebox_thread($id,$thread)]:throw new DomainException('Chat access is unavailable.'),
            'recap'=>$host?['recap'=>sf_jukebox_recap($session)]:throw new DomainException('Host access is required.'),
            'crm'=>$host?['guests'=>sf_jukebox_crm((int)$session['venue_id'])]:throw new DomainException('Venue access is required.'),
            'image'=>[],
            default=>throw new OutOfBoundsException('Unknown jukebox request.')
        };
        if($action==='image'){
            if((!$host&&!$approved)||$thread==='')throw new DomainException('Chat access is unavailable.');
            $image=sf_jukebox_query('SELECT image FROM jukebox_messages WHERE id=? AND session_id=? AND phone_id=?',
                [(int)($_GET['message']??0),$id,$thread])->fetchColumn();
            if(!$image)throw new OutOfBoundsException('Image not found.');
            [$type,$encoded]=explode(';base64,',$image,2);
            $db->commit();
            header('Content-Type: '.$type);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            echo base64_decode($encoded,true);
            exit;
        }
        $db->commit();
        sf_json_response(['ok'=>true]+$payload);
    }

    $payload=[];
    if($action==='forget'){
        $guest=sf_jukebox_guest($session);
        if($guest)sf_jukebox_query('DELETE FROM jukebox_guests WHERE id=? AND venue_id=?',[$guest['id'],$session['venue_id']]);
        sf_jukebox_cookie(sf_jukebox_guest_key($session),'',time()-3600);
    }elseif($host&&$action==='remove_guest'){
        sf_jukebox_query('DELETE FROM jukebox_guests WHERE id=? AND venue_id=?',[(string)($body['guest']??''),$session['venue_id']]);
    }else{
        if((int)$session['ended'])throw new DomainException('This jukebox session has ended.');
        switch($action){
            case 'join':
                $payload=sf_jukebox_join($session,$body);
                break;
            case 'approve':
            case 'revoke':
                if(!$host)throw new DomainException('Host access is required.');
                if($action==='approve')sf_jukebox_query("UPDATE jukebox_phones SET status='approved',approved=? WHERE id=? AND session_id=? AND status='pending'",[time(),$thread,$id]);
                else sf_jukebox_query("UPDATE jukebox_phones SET status='revoked' WHERE id=? AND session_id=?",[$thread,$id]);
                break;
            case 'request':
                if(!$host&&!$approved)throw new DomainException('Host approval is required.');
                sf_jukebox_request($session,$phone,$body);
                break;
            case 'player':
            case 'control':
                if(!$host)throw new DomainException('Host access is required.');
                if($action==='player')sf_jukebox_player($session,$body);
                else sf_jukebox_controls($session,$body);
                break;
            case 'message':
                if($host)$approved=(bool)sf_jukebox_query("SELECT 1 FROM jukebox_phones WHERE id=? AND session_id=? AND status='approved'",[$thread,$id])->fetchColumn();
                if(!$approved)throw new DomainException('Chat requires an approved phone.');
                sf_jukebox_message($id,$thread,$host?'host':'guest',$body);
                break;
            default:
                throw new OutOfBoundsException('Unknown jukebox action.');
        }
    }
    $db->commit();
    sf_json_response(['ok'=>true]+$payload);
}catch(OutOfBoundsException|InvalidArgumentException|DomainException $error){
    if($db->inTransaction())$db->rollBack();
    sf_json_response(['ok'=>false,'message'=>$error->getMessage()],$error instanceof OutOfBoundsException?404:($error instanceof DomainException?409:400));
}catch(Throwable $error){
    if($db->inTransaction())$db->rollBack();
    error_log('[Stonefellow Jukebox] '.$error->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Jukebox temporarily unavailable.'],503);
}
