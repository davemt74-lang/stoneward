<?php
declare(strict_types=1);
require_once __DIR__.'/speech-engine-session-core.php';

function sf_speech_action_schema():void {
    sf_speech_session_schema();$db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS speech_engine_pending_actions(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id TEXT NOT NULL,user_id INTEGER NOT NULL,payload_json TEXT NOT NULL,expires_at INTEGER NOT NULL,created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_speech_actions_session ON speech_engine_pending_actions(conversation_id,user_id,expires_at)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS speech_engine_pending_actions(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,conversation_id VARCHAR(128) NOT NULL,user_id BIGINT UNSIGNED NOT NULL,payload_json TEXT NOT NULL,expires_at BIGINT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_speech_actions_session(conversation_id,user_id,expires_at),CONSTRAINT fk_speech_action_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

/** Voice can trigger only reversible, public-page player/navigation behavior. */
function sf_speech_public_action(array $reply):?array {
    if(!empty($reply['requires_confirmation']))return null;
    $raw=$reply['action']??null;
    if(!is_array($raw))return null;
    $type=(string)($raw['type']??'');
    $player=['play_track','pause_player','resume_player','next_track','previous_track','player_status','set_player_volume'];
    $navigation=['open_view','open_search','open_site_search','open_library','open_notifications','show_track'];
    if(!in_array($type,[...$player,...$navigation],true))return null;
    $action=['type'=>$type];
    if(in_array($type,['play_track','show_track'],true)){
        $id=(string)($raw['track_id']??'');
        if($id===''||strlen($id)>180||!preg_match('/^[a-z0-9_-]+$/i',$id))return null;
        $action['track_id']=$id;
    }
    if($type==='open_view'){
        $view=(string)($raw['view']??'');
        if(!in_array($view,['home','music','releases','account','builder','cart','shows','archive','plans','videos','photos','contact','profile','playlist','track','search','library'],true))return null;
        $action['view']=$view;
    }
    if($type==='set_player_volume'){
        $mode=(string)($raw['mode']??'');
        if(!in_array($mode,['mute','unmute','set','adjust'],true))return null;
        $action['mode']=$mode;
        if(in_array($mode,['set','adjust'],true))$action['percent']=max(-100,min(100,(int)($raw['percent']??0)));
    }
    $fields=['slug'=>180,'id'=>180,'inquiry_type'=>40,'query'=>300,'result_type'=>40,'tab'=>40];
    foreach($fields as $name=>$limit){
        if(isset($raw[$name])&&is_scalar($raw[$name]))$action[$name]=substr(trim((string)$raw[$name]),0,$limit);
    }
    return $action;
}

/** The gateway never receives the action payload; only the browser can claim it. */
function sf_speech_queue_action(int $userId,string $conversationId,array $reply,string $message,int $now=0):bool {
    $action=sf_speech_public_action($reply);
    if(!$action)return false;
    $bound=sf_speech_bound_user($conversationId,$now);
    if(!$bound||(int)$bound['id']!==$userId)return false;
    sf_speech_action_schema();$now=$now?:time();$db=sf_db();
    $db->prepare('DELETE FROM speech_engine_pending_actions WHERE expires_at<?')->execute([$now]);
    $q=$db->prepare('SELECT COUNT(*) FROM speech_engine_pending_actions WHERE user_id=? AND conversation_id=? AND expires_at>?');
    $q->execute([$userId,$conversationId,$now]);
    if((int)$q->fetchColumn()>=5)return false;
    $envelope=['action'=>$action,'message'=>substr($message,0,4000)];
    if(is_array($reply['audio_resolution']??null)){
        $receipt=$reply['audio_resolution'];$envelope['audio_resolution']=[
            'method'=>substr((string)($receipt['method']??''),0,32),
            'status'=>substr((string)($receipt['status']??''),0,24),
            'track_id'=>substr((string)($receipt['track_id']??''),0,180)
        ];
    }
    $db->prepare('INSERT INTO speech_engine_pending_actions(conversation_id,user_id,payload_json,expires_at,created_at) VALUES(?,?,?,?,?)')
        ->execute([$conversationId,$userId,json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$now+75,gmdate('c',$now)]);
    return true;
}

/** CSRF-protected, authenticated POST; one-use delivery bound to this account. */
function sf_speech_take_action(int $userId,string $conversationId,int $now=0):?array {
    $bound=sf_speech_bound_user($conversationId,$now);
    if(!$bound||(int)$bound['id']!==$userId)throw new RuntimeException('Voice action is not available to this account.');
    sf_speech_action_schema();$now=$now?:time();$db=sf_db();$db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id,payload_json FROM speech_engine_pending_actions WHERE conversation_id=? AND user_id=? AND expires_at>? ORDER BY id ASC LIMIT 1');
        $q->execute([$conversationId,$userId,$now]);$row=$q->fetch();
        if(!$row){$db->commit();return null;}
        $remove=$db->prepare('DELETE FROM speech_engine_pending_actions WHERE id=? AND conversation_id=? AND user_id=?');
        $remove->execute([(int)$row['id'],$conversationId,$userId]);
        $db->commit();
        if($remove->rowCount()!==1)return null;
        $envelope=json_decode((string)$row['payload_json'],true);
        return is_array($envelope)?$envelope:null;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}

function sf_speech_clear_actions(string $conversationId):void {
    sf_speech_action_schema();
    sf_db()->prepare('DELETE FROM speech_engine_pending_actions WHERE conversation_id=?')->execute([$conversationId]);
}
