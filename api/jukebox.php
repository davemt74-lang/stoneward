<?php
declare(strict_types=1);

@ini_set('display_errors','0');
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/jukebox-follow-core.php';

$method=$_SERVER['REQUEST_METHOD']??'GET';
$action=$_GET['action']??'state';
$audioRequest=$action==='audio';
$coverRequest=$action==='host_cover';
$artworkRequest=$action==='host_logo'||$coverRequest;
if(!in_array($method,$audioRequest||$artworkRequest?['GET','HEAD']:['GET','POST'],true)){
    header('Allow: '.($audioRequest||$artworkRequest?'GET, HEAD':'GET, POST'));
    sf_json_response(['ok'=>false,'message'=>'Method unavailable.'],405);
}
if($audioRequest){
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
}
if(!sf_installed())sf_json_response(['ok'=>false,'message'=>'Stonefellow installation required.'],503);
$hostMode=($_GET['host']??'')==='1';
$post=$method==='POST';
$followRequest=$post&&$action==='follow';
$guestState=!$post&&!$hostMode&&$action==='state';
$guestDirectory=!$post&&!$hostMode&&$action==='directory';
if($post&&(int)($_SERVER['CONTENT_LENGTH']??0)>(in_array($action,['listener','follow'],true)?2048:1_450_000))
    sf_json_response(['ok'=>false,'message'=>'Request is too large.'],413);
if($post&&in_array($action,['branding','cover'],true)){
    // Bound the raw upload even when a chunked request omits Content-Length.
    $raw=file_get_contents('php://input',false,null,0,1_450_001);
    if($raw===false||strlen($raw)>1_450_000)sf_json_response(['ok'=>false,'message'=>'Request is too large.'],413);
    $body=trim($raw)===''?[]:json_decode($raw,true);
    if(!is_array($body))sf_json_response(['ok'=>false,'error'=>'invalid_json'],400);
}else $body=$post?sf_request_json():[];
$user=$hostMode||$followRequest?sf_require_user(false,$post)
    :(($guestState||$guestDirectory)&&sf_auth_cookie_value()!==''?sf_current_user():null);
if($post&&!$hostMode&&!$followRequest)sf_require_csrf();
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$uid=(int)($user['id']??0);
$admin=($user['role']??'')==='admin';
$alertControl=$post&&$hostMode&&$action==='control'&&in_array($body['event']??null,['start','reschedule','cancel','end'],true);
$settingsWrite=$post&&$hostMode&&$action==='settings';
$db=sf_db();

try{
    if($action==='venue_accounts'){
        if(!$hostMode||!$admin)sf_json_response(['ok'=>false,'message'=>'An administrator must look up venue accounts.'],403);
        if($post){
            header('Allow: GET');
            sf_json_response(['ok'=>false,'message'=>'Method unavailable.'],405);
        }
        $query=$_GET['q']??'';
        $query=is_string($query)?trim($query):'';
        if(!preg_match('/\A[^\x00-\x1F\x7F]{2,120}\z/u',$query))
            throw new InvalidArgumentException('Enter 2 to 120 characters of a name or email.');
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$query).'%';
        $accounts=sf_jukebox_query("SELECT id,display_name,email FROM users WHERE status='active'
            AND (LOWER(display_name) LIKE LOWER(?) ESCAPE '!' OR LOWER(email) LIKE LOWER(?) ESCAPE '!')
            ORDER BY display_name,id LIMIT 20",[$like,$like])->fetchAll(PDO::FETCH_ASSOC);
        foreach($accounts as &$account)$account['id']=(int)$account['id'];
        unset($account);
        sf_json_response(['ok'=>true,'accounts'=>$accounts]);
    }
    sf_jukebox_schema();
    if($artworkRequest){
        if($coverRequest&&!$hostMode)throw new OutOfBoundsException('Hosted events cover not found.');
        $owner=$coverRequest?(string)$uid:($_GET['owner']??null);
        $source=sf_jukebox_branding_source($owner,$_GET['v']??null,$coverRequest);
        header('Content-Type: '.$source['mime']);
        header('Content-Length: '.strlen($source['bytes']));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        if($method==='GET')echo $source['bytes'];
        exit;
    }
    if(in_array($action,['branding','cover'],true)){
        if(!$hostMode)throw new DomainException('Host access is required.');
        $editable=sf_jukebox_branding_can_edit($uid,$admin);
        if($post&&!$editable)throw new DomainException('A host account is required.');
        $artwork=$post?sf_jukebox_branding_update($uid,$body,$admin,$action==='cover')
            :($action==='cover'?sf_jukebox_cover_get($uid):sf_jukebox_branding_get($uid));
        sf_json_response(['ok'=>true,$action=>$artwork,'branding_editable'=>$editable]);
    }
    if($followRequest||(($guestState||$guestDirectory)&&$uid>0)||$alertControl||$settingsWrite)sf_jukebox_follow_schema();
    if(!$post&&$action==='directory'){
        $directoryFields='s.id,s.owner_id,s.title,s.ended,s.created,s.heartbeat,t.scheduled_at,t.started_at';
        $scheduleJoin=' LEFT JOIN jukebox_schedule t ON t.session_id=s.id';
        $followingSessions=[];
        $scope=$_GET['scope']??null;
        if($scope!==null&&(!$hostMode||!is_string($scope)))
            throw new InvalidArgumentException('Session management requires the host directory.');
        $page=$scope!==null?sf_jukebox_directory_page($uid,$scope,$_GET['cursor']??null,$_GET['limit']??'25'):null;
        $sessions=$hostMode
            ?($page['sessions']??sf_jukebox_query("SELECT $directoryFields,s.listed,COALESCE(r.enabled,0) AS remote_enabled,
                a.session_id IS NOT NULL AS auto_approve_phones
                FROM jukebox_sessions s $scheduleJoin LEFT JOIN jukebox_remote r ON r.session_id=s.id
                LEFT JOIN jukebox_auto_approval a ON a.session_id=s.id
                WHERE s.owner_id=? ORDER BY s.created DESC,s.id DESC LIMIT 50",[$uid])->fetchAll())
            :[];
        if(!$hostMode){
            $publicSessions="SELECT $directoryFields,COALESCE(r.enabled,0) AS remote_listening
                FROM jukebox_sessions s $scheduleJoin LEFT JOIN jukebox_remote r ON r.session_id=s.id
                WHERE s.listed=1 AND s.ended=0";
            // Keep the Upcoming and Live groups on the same read snapshot.
            $db->beginTransaction();
            $sessions=array_merge(
                sf_jukebox_query("$publicSessions AND (t.session_id IS NULL OR t.started_at IS NOT NULL)
                    ORDER BY COALESCE(t.started_at,s.created) DESC,s.id DESC LIMIT 50")->fetchAll(),
                sf_jukebox_query("$publicSessions AND t.session_id IS NOT NULL AND t.started_at IS NULL
                    ORDER BY t.scheduled_at,s.id LIMIT 50")->fetchAll()
            );
            if($uid>0)$followingSessions=sf_jukebox_query("SELECT $directoryFields,COALESCE(r.enabled,0) AS remote_listening
                FROM jukebox_follows f JOIN jukebox_sessions s ON s.id=f.session_id $scheduleJoin
                LEFT JOIN jukebox_remote r ON r.session_id=s.id WHERE f.user_id=? AND s.ended=0
                AND (s.listed=1 OR f.access_scope='invite')
                ORDER BY COALESCE(t.started_at,t.scheduled_at,s.created) DESC,s.id DESC LIMIT 100",[$uid])->fetchAll();
            $db->commit();
        }
        $branding=sf_jukebox_branding_map(array_merge([$uid],array_column($sessions,'owner_id'),array_column($followingSessions,'owner_id')));
        $directoryRow=static function(array $session)use($hostMode,$branding): array {
            $row=['id'=>$session['id'],'title'=>$session['title']]+sf_jukebox_schedule_details($session)
                +['branding'=>$branding[(int)$session['owner_id']]??sf_jukebox_branding_metadata(null)];
            if($hostMode)return $row+['ended'=>$session['ended'],'settings'=>sf_jukebox_settings($session)];
            return $row+['remote_listening'=>(bool)$session['remote_listening'],'online'=>sf_jukebox_player_online($session)];
        };
        $venue=$hostMode?sf_jukebox_query('SELECT name FROM jukebox_venues WHERE owner_id=?',[$uid])->fetchColumn():false;
        sf_json_response(['ok'=>true,'sessions'=>array_map($directoryRow,$sessions),'venue'=>$venue===false||$venue===''?null:$venue,'admin'=>$admin]
            +($hostMode?['branding'=>$branding[$uid]??sf_jukebox_branding_metadata(null),
                'cover'=>sf_jukebox_cover_get($uid),'branding_editable'=>sf_jukebox_branding_can_edit($uid,$admin)]:[])
            +($page!==null?['scope'=>$scope,'next_cursor'=>$page['next_cursor']]:[])
            +($guestDirectory?['following_sessions'=>array_map($directoryRow,$followingSessions)]:[]));
    }
    if($post&&$hostMode&&$action==='venue'){
        if(!$admin)throw new DomainException('An administrator must enable venue accounts.');
        $owner=$body['owner']??null;
        $owner=is_int($owner)||is_string($owner)?filter_var($owner,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
        $name=$body['name']??'';
        $name=is_string($name)?sf_clean_text($name,120):'';
        if($name==='')throw new InvalidArgumentException('Enter a venue name.');
        if($owner===false||!sf_jukebox_query("SELECT 1 FROM users WHERE id=? AND status='active'",[$owner])->fetchColumn())
            throw new InvalidArgumentException('Choose an active Stonefellow account.');
        $sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ?'INSERT INTO jukebox_venues (owner_id,name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)'
            :'INSERT INTO jukebox_venues (owner_id,name) VALUES (?,?) ON CONFLICT(owner_id) DO UPDATE SET name=excluded.name';
        sf_jukebox_query($sql,[$owner,$name]);
        sf_json_response(['ok'=>true]);
    }
    if($action==='venue_name'){
        if(!$hostMode)sf_json_response(['ok'=>false,'message'=>'Host access is required.'],403);
        if(!$post){
            header('Allow: POST');
            sf_json_response(['ok'=>false,'message'=>'Method unavailable.'],405);
        }
        $name=is_string($body['name']??null)?preg_replace('/\A\s+|\s+\z/u','',$body['name']):null;
        if(array_diff_key($body,['name'=>true])||$name===null||!preg_match('/\A[^\x00-\x1F\x7F]{1,120}\z/u',$name))
            throw new InvalidArgumentException('Enter a venue name of 1 to 120 characters.');
        $changed=sf_jukebox_query('UPDATE jukebox_venues SET name=? WHERE owner_id=?',[$name,$uid])->rowCount();
        if(!$changed&&!sf_jukebox_query('SELECT 1 FROM jukebox_venues WHERE owner_id=?',[$uid])->fetchColumn())
            throw new DomainException('A venue account is required.');
        sf_json_response(['ok'=>true,'venue'=>$name]);
    }
    if($post&&$hostMode&&$action==='create'){
        $listed=$admin&&!empty($body['listed']);
        $venue=!$listed&&(bool)sf_jukebox_query('SELECT 1 FROM jukebox_venues WHERE owner_id=?',[$uid])->fetchColumn();
        if(!$admin&&!$venue)throw new DomainException('A venue account is required.');
        $title=sf_clean_text($body['title']??'',120);
        if($title==='')throw new InvalidArgumentException('Enter a session name.');
        $remote=array_key_exists('remote_listening',$body)?$body['remote_listening']:false;
        if(!is_bool($remote))throw new InvalidArgumentException('Choose whether remote listening is enabled.');
        $autoApprove=array_key_exists('auto_approve_phones',$body)?$body['auto_approve_phones']:false;
        if(!is_bool($autoApprove))throw new InvalidArgumentException('Choose whether phone connections are approved automatically.');
        $scheduledAt=($body['scheduled_at']??null)===null?null:sf_jukebox_schedule_time($body['scheduled_at']);
        $id=bin2hex(random_bytes(16));
        $db->beginTransaction();
        sf_jukebox_query('INSERT INTO jukebox_sessions (id,owner_id,venue_id,title,listed,created) VALUES (?,?,?,?,?,?)',
            [$id,$uid,$venue?$uid:0,$title,(int)$listed,time()]);
        if($scheduledAt!==null)sf_jukebox_schedule_create($id,$scheduledAt);
        if($remote)sf_jukebox_controls(sf_jukebox_session($id),['event'=>'remote_listening','enabled'=>true]);
        if($autoApprove)sf_jukebox_set_auto_approval($id,true);
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
        if($action==='listener'||$followRequest)sf_jukebox_query('UPDATE jukebox_sessions SET revision=revision WHERE id=?',[$id]);
        else sf_jukebox_query('UPDATE jukebox_sessions SET revision=revision+1 WHERE id=?',[$id]);
    }else $db->beginTransaction();
    $session=sf_jukebox_session($id);
    $host=$hostMode&&sf_jukebox_owner($session,$user);
    if($hostMode&&!$host)throw new OutOfBoundsException('Jukebox session not found.');
    if($action==='settings'){
        if(!$host)throw new DomainException('Host access is required.');
        if($post){
            $updated=sf_jukebox_settings_update($session,$body,$admin);
            if($updated['scheduled_at']!==$session['scheduled_at'])
                sf_jukebox_alert_session_change($session,$updated,'reschedule');
            $session=$updated;
        }
        $settings=sf_jukebox_settings($session);
        $db->commit();
        sf_json_response(['ok'=>true,'settings'=>$settings]);
    }
    if($followRequest){
        if($hostMode)throw new DomainException('Open the guest session to follow it.');
        $following=sf_jukebox_follow_set($session,$uid,$body['following']??null);
        $db->commit();
        sf_json_response(['ok'=>true,'following'=>$following]);
    }
    $phase=sf_jukebox_phase($session);
    if($phase==='upcoming'&&(($post&&$action!=='control')||(!$post&&!in_array($action,['state','audio'],true))))
        throw new DomainException('This jukebox session has not started.');
    if($phase==='canceled'&&($post||!in_array($action,['state','audio'],true)))
        throw new DomainException('This scheduled session was canceled.');
    $phone=$host?null:sf_jukebox_phone($session);
    $approved=($phone['status']??'')==='approved'&&!(int)$session['ended'];
    $thread=$host?(string)($_GET['phone']??$body['phone']??''):(string)($phone['id']??'');
    $hostThreadStatus=$host&&$thread!==''?sf_jukebox_query('SELECT status FROM jukebox_phones
        WHERE id=? AND session_id=?',[$thread,$id])->fetchColumn():false;
    if($host&&$thread!==''&&$hostThreadStatus===false)
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
        $fullThread=$action==='thread'&&($_GET['full']??'')==='1';
        if($fullThread&&$host&&($phase!=='live'||$hostThreadStatus!=='approved'))
            throw new DomainException('Chat requires an approved phone.');
        $payload=match($action){
            'state'=>['state'=>sf_jukebox_state($session,$phone,$host)+($host?['branding_editable'=>
                (int)$session['owner_id']===$uid&&sf_jukebox_branding_can_edit($uid,$admin)]:[]),
                'remembered'=>!$host&&!in_array($phase,['upcoming','canceled'],true)&&sf_jukebox_guest($session)!==null]
                +($guestState?['signed_in'=>$uid>0,'following'=>sf_jukebox_following($id,$uid)]:[]),
            'catalog'=>($host||$approved||(int)$session['listed'])?sf_canvas_audio_catalog():throw new DomainException('Host approval is required.'),
            'thread'=>($host||$approved)&&$thread!==''?['messages'=>sf_jukebox_thread($id,$thread,$fullThread)]:throw new DomainException('Chat access is unavailable.'),
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
            case 'disconnect_all':
                if(!$host)throw new DomainException('Host access is required.');
                $payload['disconnected']=sf_jukebox_query("UPDATE jukebox_phones SET status='revoked'
                    WHERE session_id=? AND status IN ('approved','pending')",[$id])->rowCount();
                break;
            case 'request':
                if(!$host&&!$approved)throw new DomainException('Host approval is required.');
                sf_jukebox_request($session,$phone,$body);
                break;
            case 'listener':
                if($host||!sf_jukebox_remote_access($session,$phone))throw new DomainException('Remote listening access is required.');
                sf_jukebox_listener_report($session,$phone,$body);
                break;
            case 'player':
            case 'control':
                if(!$host)throw new DomainException('Host access is required.');
                if($action==='player')sf_jukebox_player($session,$body);
                else{
                    sf_jukebox_controls($session,$body);
                    if($alertControl)sf_jukebox_alert_session_change($session,sf_jukebox_session($id),$body['event']);
                }
                break;
            case 'message':
                if($host)$approved=$hostThreadStatus==='approved';
                if(!$approved)throw new DomainException('Chat requires an approved phone.');
                sf_jukebox_message($id,$thread,$host?'host':'guest',$body);
                break;
            default:
                throw new OutOfBoundsException('Unknown jukebox action.');
        }
    }
    $db->commit();
    sf_json_response(['ok'=>true]+$payload+($action==='player'?['server_time_ms'=>(int)floor(microtime(true)*1000)]:[]));
}catch(OutOfBoundsException|InvalidArgumentException|DomainException $error){
    if($db->inTransaction())$db->rollBack();
    sf_json_response(['ok'=>false,'message'=>$error->getMessage()],$error instanceof OutOfBoundsException?404:($error instanceof DomainException?409:400));
}catch(Throwable $error){
    if($db->inTransaction())$db->rollBack();
    error_log('[Stonefellow Jukebox] '.$error->getMessage());
    sf_json_response(['ok'=>false,'message'=>'Jukebox temporarily unavailable.'],503);
}
