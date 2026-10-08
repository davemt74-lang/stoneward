<?php
declare(strict_types=1);

function sf_listening_sessions_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_track_feedback (user_id INTEGER NOT NULL,track_id TEXT NOT NULL,sentiment TEXT NOT NULL,source TEXT NOT NULL DEFAULT 'agent_session',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_track_feedback_user_sentiment ON user_track_feedback(user_id,sentiment,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_listening_sessions (id TEXT PRIMARY KEY,user_id INTEGER NOT NULL,title TEXT NOT NULL,mode TEXT NOT NULL,prompt TEXT NOT NULL DEFAULT '',release_id TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'active',current_index INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,completed_at TEXT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_agent_listen_sessions_user_status ON agent_listening_sessions(user_id,status,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_listening_session_tracks (id INTEGER PRIMARY KEY AUTOINCREMENT,session_id TEXT NOT NULL,track_id TEXT NOT NULL,position INTEGER NOT NULL,status TEXT NOT NULL DEFAULT 'planned',feedback TEXT NOT NULL DEFAULT '',played_at TEXT NULL,UNIQUE(session_id,position),FOREIGN KEY(session_id) REFERENCES agent_listening_sessions(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_agent_session_tracks_session ON agent_listening_session_tracks(session_id,position)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_track_feedback (user_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(180) NOT NULL,sentiment VARCHAR(16) NOT NULL,source VARCHAR(40) NOT NULL DEFAULT 'agent_session',created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,track_id),INDEX idx_track_feedback_user_sentiment(user_id,sentiment,updated_at),CONSTRAINT fk_track_feedback_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_listening_sessions (id VARCHAR(64) NOT NULL PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(160) NOT NULL,mode VARCHAR(32) NOT NULL,prompt VARCHAR(500) NOT NULL DEFAULT '',release_id VARCHAR(180) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL DEFAULT 'active',current_index INT NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,completed_at VARCHAR(40) NULL,INDEX idx_agent_listen_sessions_user_status(user_id,status,updated_at),CONSTRAINT fk_agent_listen_session_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS agent_listening_session_tracks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,session_id VARCHAR(64) NOT NULL,track_id VARCHAR(180) NOT NULL,position INT NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'planned',feedback VARCHAR(16) NOT NULL DEFAULT '',played_at VARCHAR(40) NULL,UNIQUE KEY uq_agent_session_position(session_id,position),INDEX idx_agent_session_tracks_session(session_id,position),CONSTRAINT fk_agent_session_track_session FOREIGN KEY(session_id) REFERENCES agent_listening_sessions(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_listening_session_id(): string {return 'als-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(6));}
function sf_track_feedback_set(int $userId,string $trackId,string $sentiment,string $source='agent_session'): array {
    sf_listening_sessions_ensure_schema();$trackId=sf_clean_text($trackId,180);$sentiment=strtolower(sf_clean_text($sentiment,16));$source=sf_clean_text($source,40);
    if(!isset(sf_track_map()[$trackId]))throw new InvalidArgumentException('Unknown track.');
    if(!in_array($sentiment,['like','dislike','neutral'],true))throw new InvalidArgumentException('Unknown feedback value.');
    $pdo=sf_db();$now=gmdate('c');
    if($sentiment==='neutral'){$pdo->prepare('DELETE FROM user_track_feedback WHERE user_id=? AND track_id=?')->execute([$userId,$trackId]);return ['track_id'=>$trackId,'sentiment'=>'neutral'];}
    $driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite')$sql="INSERT INTO user_track_feedback(user_id,track_id,sentiment,source,created_at,updated_at) VALUES(?,?,?,?,?,?) ON CONFLICT(user_id,track_id) DO UPDATE SET sentiment=excluded.sentiment,source=excluded.source,updated_at=excluded.updated_at";
    else $sql="INSERT INTO user_track_feedback(user_id,track_id,sentiment,source,created_at,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sentiment=VALUES(sentiment),source=VALUES(source),updated_at=VALUES(updated_at)";
    $pdo->prepare($sql)->execute([$userId,$trackId,$sentiment,$source,$now,$now]);
    return ['track_id'=>$trackId,'sentiment'=>$sentiment,'updated_at'=>$now];
}
function sf_track_feedback_map(int $userId): array {
    sf_listening_sessions_ensure_schema();$q=sf_db()->prepare('SELECT track_id,sentiment,updated_at FROM user_track_feedback WHERE user_id=?');$q->execute([$userId]);$out=[];
    foreach($q->fetchAll() as $r)$out[(string)$r['track_id']]=['sentiment'=>(string)$r['sentiment'],'updated_at'=>$r['updated_at']];
    return $out;
}
function sf_agent_session_release_from_message(string $message): ?array {
    $q=strtolower($message);$best=null;$len=0;
    foreach(sf_release_rows() as $r){if(($r['state']??'published')!=='published')continue;$title=trim((string)($r['title']??''));if($title!==''&&str_contains($q,strtolower($title))&&strlen($title)>$len){$best=$r;$len=strlen($title);}}
    return $best;
}
function sf_agent_session_plan(int $userId,string $message,string $mode='mix',?string $activeTrackId=null): array {
    sf_listening_sessions_ensure_schema();$mode=in_array($mode,['mix','release','guided_release'],true)?$mode:'mix';$message=sf_clean_text($message,500);$release=sf_agent_session_release_from_message($message);
    if($release&&in_array($mode,['release','guided_release'],true)){
        $ids=array_values(array_filter(array_map('strval',(array)($release['track_ids']??[])),fn($id)=>isset(sf_track_map()[$id])));
        if(!$ids)throw new RuntimeException('That release has no playable catalog tracks.');
        return ['mode'=>$mode,'title'=>($mode==='guided_release'?'Guided: ':'').(string)($release['title']??'Stonefellow Release'),'prompt'=>$message,'release_id'=>(string)($release['id']??''),'track_ids'=>$ids];
    }
    $ids=sf_agent_playlist_track_ids($message,$activeTrackId,8,$userId);if(!$ids)throw new RuntimeException('No playable tracks are available for a listening session.');
    $label='Agent Listening Session';$q=strtolower($message);
    foreach(['quiet','mellow','dark','driving','warm','acoustic','reflective','late night','desert'] as $mood)if(str_contains($q,$mood)){$label=ucwords($mood).' Session';break;}
    return ['mode'=>'mix','title'=>$label,'prompt'=>$message,'release_id'=>'','track_ids'=>$ids];
}
function sf_agent_listening_session_create(int $userId,string $message,string $mode='mix',?string $activeTrackId=null): array {
    sf_listening_sessions_ensure_schema();$now=gmdate('c');sf_db()->prepare("UPDATE agent_listening_sessions SET status='ended',updated_at=?,completed_at=? WHERE user_id=? AND status='active'")->execute([$now,$now,$userId]);$plan=sf_agent_session_plan($userId,$message,$mode,$activeTrackId);$id=sf_listening_session_id();$pdo=sf_db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('INSERT INTO agent_listening_sessions(id,user_id,title,mode,prompt,release_id,status,current_index,created_at,updated_at,completed_at) VALUES(?,?,?,?,?,?,?,0,?,?,NULL)');
        $q->execute([$id,$userId,$plan['title'],$plan['mode'],$plan['prompt'],$plan['release_id'],'active',$now,$now]);
        $q=$pdo->prepare('INSERT INTO agent_listening_session_tracks(session_id,track_id,position,status,feedback,played_at) VALUES(?,?,?,?,"",NULL)');
        foreach($plan['track_ids'] as $i=>$trackId)$q->execute([$id,$trackId,$i,'planned']);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    sf_log_user_activity($userId,'agent_listening_session_started','Started '.$plan['title'],'agent_listening_session',$id,['mode'=>$plan['mode'],'track_count'=>count($plan['track_ids'])]);
    return sf_agent_listening_session_get($userId,$id);
}
function sf_agent_listening_session_get(int $userId,string $sessionId): array {
    sf_listening_sessions_ensure_schema();$q=sf_db()->prepare('SELECT * FROM agent_listening_sessions WHERE id=? AND user_id=? LIMIT 1');$q->execute([$sessionId,$userId]);$s=$q->fetch();if(!$s)throw new RuntimeException('Listening session not found.');
    $q=sf_db()->prepare('SELECT id,track_id,position,status,feedback,played_at FROM agent_listening_session_tracks WHERE session_id=? ORDER BY position ASC');$q->execute([$sessionId]);$tracks=sf_track_map();$rows=[];
    foreach($q->fetchAll() as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$rows[]=['item_id'=>(int)$r['id'],'track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','duration'=>(int)($t['duration']??0),'mood'=>array_values((array)($t['mood']??[])),'themes'=>array_values((array)($t['themes']??[])),'position'=>(int)$r['position'],'status'=>(string)$r['status'],'feedback'=>(string)$r['feedback'],'played_at'=>$r['played_at']];
    }
    return ['id'=>(string)$s['id'],'title'=>(string)$s['title'],'mode'=>(string)$s['mode'],'prompt'=>(string)$s['prompt'],'release_id'=>(string)$s['release_id'],'status'=>(string)$s['status'],'current_index'=>(int)$s['current_index'],'created_at'=>$s['created_at'],'updated_at'=>$s['updated_at'],'completed_at'=>$s['completed_at'],'tracks'=>$rows];
}
function sf_agent_listening_session_active(int $userId): ?array {
    sf_listening_sessions_ensure_schema();$q=sf_db()->prepare("SELECT id FROM agent_listening_sessions WHERE user_id=? AND status='active' ORDER BY updated_at DESC LIMIT 1");$q->execute([$userId]);$id=$q->fetchColumn();return $id?sf_agent_listening_session_get($userId,(string)$id):null;
}
function sf_agent_listening_session_advance(int $userId,string $sessionId,string $trackId,string $result='complete'): array {
    sf_listening_sessions_ensure_schema();$s=sf_agent_listening_session_get($userId,$sessionId);if($s['status']!=='active')return $s;$result=in_array($result,['complete','skip'],true)?$result:'complete';$idx=(int)$s['current_index'];$current=$s['tracks'][$idx]??null;
    if($current&&$trackId!==''&&(string)$current['track_id']!==$trackId){foreach($s['tracks'] as $n=>$row)if((string)$row['track_id']===$trackId){$idx=$n;$current=$row;break;}}
    $pdo=sf_db();$now=gmdate('c');if($current)$pdo->prepare('UPDATE agent_listening_session_tracks SET status=?,played_at=? WHERE session_id=? AND position=?')->execute([$result==='skip'?'skipped':'played',$now,$sessionId,$idx]);
    $next=$idx+1;$done=$next>=count($s['tracks']);$pdo->prepare('UPDATE agent_listening_sessions SET current_index=?,status=?,updated_at=?,completed_at=? WHERE id=? AND user_id=?')->execute([$done?max(0,count($s['tracks'])-1):$next,$done?'completed':'active',$now,$done?$now:null,$sessionId,$userId]);
    if($done)sf_log_user_activity($userId,'agent_listening_session_completed','Completed '.$s['title'],'agent_listening_session',$sessionId,['track_count'=>count($s['tracks'])]);
    return sf_agent_listening_session_get($userId,$sessionId);
}
function sf_agent_listening_session_feedback(int $userId,string $sessionId,string $trackId,string $sentiment): array {
    $feedback=sf_track_feedback_set($userId,$trackId,$sentiment,'agent_session');sf_listening_sessions_ensure_schema();$q=sf_db()->prepare('UPDATE agent_listening_session_tracks SET feedback=? WHERE session_id=? AND track_id=?');$q->execute([$feedback['sentiment'],$sessionId,$trackId]);sf_log_user_activity($userId,'track_feedback_'.$feedback['sentiment'],ucfirst($feedback['sentiment']).'d a session track','track',$trackId,['session_id'=>$sessionId]);return ['feedback'=>$feedback,'session'=>sf_agent_listening_session_get($userId,$sessionId)];
}
function sf_agent_listening_session_end(int $userId,string $sessionId): array {
    sf_listening_sessions_ensure_schema();$s=sf_agent_listening_session_get($userId,$sessionId);$now=gmdate('c');sf_db()->prepare("UPDATE agent_listening_sessions SET status='ended',updated_at=?,completed_at=? WHERE id=? AND user_id=? AND status='active'")->execute([$now,$now,$sessionId,$userId]);sf_log_user_activity($userId,'agent_listening_session_ended','Ended '.$s['title'],'agent_listening_session',$sessionId);return sf_agent_listening_session_get($userId,$sessionId);
}
function sf_agent_listening_session_save_playlist(int $userId,string $sessionId,string $name=''): array {
    $s=sf_agent_listening_session_get($userId,$sessionId);$ids=array_values(array_map(fn($r)=>(string)$r['track_id'],$s['tracks']));if(!$ids)throw new RuntimeException('This listening session has no tracks to save.');
    $name=trim(sf_clean_text($name,120));if($name==='')$name=$s['title'];
    return sf_playlist_create($userId,$name,'Saved from Stonefellow Agent listening session '.$sessionId.'.','private','agent_session',$ids);
}
