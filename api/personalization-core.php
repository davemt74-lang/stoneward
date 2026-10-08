<?php
declare(strict_types=1);

function sf_personalization_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_favorites (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,item_type TEXT NOT NULL,item_id TEXT NOT NULL,created_at TEXT NOT NULL,UNIQUE(user_id,item_type,item_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_favorites_user_created ON user_favorites(user_id,created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_listening_progress (user_id INTEGER NOT NULL,track_id TEXT NOT NULL,position_seconds INTEGER NOT NULL DEFAULT 0,duration_seconds INTEGER NOT NULL DEFAULT 0,completed INTEGER NOT NULL DEFAULT 0,last_event_type TEXT NOT NULL DEFAULT '',updated_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_progress_updated ON user_listening_progress(user_id,completed,updated_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_favorites (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,item_type VARCHAR(24) NOT NULL,item_id VARCHAR(180) NOT NULL,created_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_user_favorite(user_id,item_type,item_id),INDEX idx_user_favorites_user_created(user_id,created_at),CONSTRAINT fk_user_favorites_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_listening_progress (user_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(180) NOT NULL,position_seconds INT NOT NULL DEFAULT 0,duration_seconds INT NOT NULL DEFAULT 0,completed TINYINT(1) NOT NULL DEFAULT 0,last_event_type VARCHAR(24) NOT NULL DEFAULT '',updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,track_id),INDEX idx_user_progress_updated(user_id,completed,updated_at),CONSTRAINT fk_user_progress_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_release_rows(): array {$path=SF_ROOT.'/data/releases.json';$rows=json_decode((string)@file_get_contents($path),true);return is_array($rows)?$rows:[];}
function sf_release_map(): array {$out=[];foreach(sf_release_rows() as $r)if(isset($r['id']))$out[(string)$r['id']]=$r;return $out;}
function sf_personalization_item_exists(string $type,string $id): bool {if($type==='track')return isset(sf_track_map()[$id]);if($type==='release')return isset(sf_release_map()[$id]);return false;}
function sf_personalization_set_favorite(int $userId,string $type,string $id,bool $favorite): bool {
    sf_personalization_ensure_schema();$type=strtolower(sf_clean_text($type,24));$id=sf_clean_text($id,180);
    if(!in_array($type,['track','release'],true)||$id===''||!sf_personalization_item_exists($type,$id))throw new InvalidArgumentException('Unknown favorite item.');
    $pdo=sf_db();if(!$favorite){$q=$pdo->prepare('DELETE FROM user_favorites WHERE user_id=? AND item_type=? AND item_id=?');$q->execute([$userId,$type,$id]);return false;}
    try{$q=$pdo->prepare('INSERT INTO user_favorites(user_id,item_type,item_id,created_at) VALUES(?,?,?,?)');$q->execute([$userId,$type,$id,gmdate('c')]);}catch(Throwable $e){/* duplicate favorite is idempotent */}
    return true;
}
function sf_personalization_favorites(int $userId): array {
    sf_personalization_ensure_schema();$q=sf_db()->prepare('SELECT item_type,item_id,created_at FROM user_favorites WHERE user_id=? ORDER BY id DESC');$q->execute([$userId]);$tracks=sf_track_map();$releases=sf_release_map();$out=['tracks'=>[],'releases'=>[]];
    foreach($q->fetchAll() as $r){$type=(string)$r['item_type'];$id=(string)$r['item_id'];if($type==='track'&&isset($tracks[$id])){$t=$tracks[$id];$out['tracks'][]=['id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','duration'=>(int)($t['duration']??0),'favorited_at'=>$r['created_at']];}elseif($type==='release'&&isset($releases[$id])){$x=$releases[$id];$out['releases'][]=['id'=>$id,'title'=>$x['title']??$id,'type'=>$x['type']??'release','artwork'=>$x['artwork']['cover']??'','favorited_at'=>$r['created_at']];}}
    return $out;
}
function sf_personalization_record_progress(int $userId,string $trackId,string $eventType,int $position,int $duration): void {
    sf_personalization_ensure_schema();if($userId<1||!isset(sf_track_map()[$trackId]))return;$position=max(0,$position);$duration=max(0,$duration);$completed=$eventType==='complete'?1:0;$now=gmdate('c');$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite')$sql="INSERT INTO user_listening_progress(user_id,track_id,position_seconds,duration_seconds,completed,last_event_type,updated_at) VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id,track_id) DO UPDATE SET position_seconds=excluded.position_seconds,duration_seconds=CASE WHEN excluded.duration_seconds>0 THEN excluded.duration_seconds ELSE user_listening_progress.duration_seconds END,completed=excluded.completed,last_event_type=excluded.last_event_type,updated_at=excluded.updated_at";
    else $sql="INSERT INTO user_listening_progress(user_id,track_id,position_seconds,duration_seconds,completed,last_event_type,updated_at) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE position_seconds=VALUES(position_seconds),duration_seconds=IF(VALUES(duration_seconds)>0,VALUES(duration_seconds),duration_seconds),completed=VALUES(completed),last_event_type=VALUES(last_event_type),updated_at=VALUES(updated_at)";
    sf_db()->prepare($sql)->execute([$userId,$trackId,$position,$duration,$completed,$eventType,$now]);
}
function sf_personalization_continue_listening(int $userId,int $limit=12): array {
    sf_personalization_ensure_schema();$limit=max(1,min(50,$limit));$q=sf_db()->prepare('SELECT track_id,position_seconds,duration_seconds,last_event_type,updated_at FROM user_listening_progress WHERE user_id=? AND completed=0 AND position_seconds>=10 ORDER BY updated_at DESC LIMIT '.$limit);$q->execute([$userId]);$tracks=sf_track_map();$out=[];
    foreach($q->fetchAll() as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$dur=max((int)$r['duration_seconds'],(int)($t['duration']??0));$pos=(int)$r['position_seconds'];if($dur>0&&$dur-$pos<10)continue;$out[]=['track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','position_seconds'=>$pos,'duration_seconds'=>$dur,'progress_percent'=>$dur?round($pos/$dur*100,1):0,'updated_at'=>$r['updated_at']];}
    return $out;
}
function sf_personalization_history(int $userId,int $limit=80): array {
    sf_ops_ensure_schema();$limit=max(1,min(200,$limit));$q=sf_db()->prepare("SELECT id,track_id,event_type,position_seconds,duration_seconds,source,created_at FROM listening_events WHERE user_id=? AND event_type IN ('start','resume','pause','complete') ORDER BY id DESC LIMIT ".$limit);$q->execute([$userId]);$tracks=sf_track_map();$out=[];
    foreach($q->fetchAll() as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$out[]=['id'=>(int)$r['id'],'track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','event_type'=>$r['event_type'],'position_seconds'=>(int)$r['position_seconds'],'duration_seconds'=>(int)$r['duration_seconds'],'created_at'=>$r['created_at']];}
    return $out;
}
function sf_personalization_clear_history(int $userId): array {
    sf_personalization_ensure_schema();sf_ops_ensure_schema();$pdo=sf_db();$q=$pdo->prepare('UPDATE listening_events SET user_id=NULL WHERE user_id=?');$q->execute([$userId]);$events=$q->rowCount();$pdo->prepare('DELETE FROM user_listening_progress WHERE user_id=?')->execute([$userId]);$pdo->prepare("DELETE FROM user_activity WHERE user_id=? AND event_type IN ('listen_start','listen_complete')")->execute([$userId]);sf_log_user_activity($userId,'listening_history_cleared','Cleared personal listening history','account',(string)$userId,['anonymized_events'=>$events]);return ['anonymized_events'=>$events];
}
function sf_personalization_state(int $userId): array {return ['favorites'=>sf_personalization_favorites($userId),'continue_listening'=>sf_personalization_continue_listening($userId),'history'=>sf_personalization_history($userId)];}

function sf_personalization_history_summary(int $userId): array {
    sf_ops_ensure_schema();$pdo=sf_db();
    $q=$pdo->prepare("SELECT COUNT(*) AS events,SUM(CASE WHEN event_type='start' THEN 1 ELSE 0 END) AS starts,SUM(CASE WHEN event_type='complete' THEN 1 ELSE 0 END) AS completes,COUNT(DISTINCT track_id) AS tracks,COUNT(DISTINCT session_key) AS sessions,MIN(created_at) AS first_at,MAX(created_at) AS last_at FROM listening_events WHERE user_id=? AND event_type IN ('start','resume','pause','complete')");
    $q->execute([$userId]);$r=$q->fetch()?:[];
    return ['events'=>(int)($r['events']??0),'starts'=>(int)($r['starts']??0),'completes'=>(int)($r['completes']??0),'tracks'=>(int)($r['tracks']??0),'sessions'=>(int)($r['sessions']??0),'first_at'=>$r['first_at']??null,'last_at'=>$r['last_at']??null];
}
function sf_personalization_history_page(int $userId,int $limit=80,int $beforeId=0): array {
    sf_personalization_ensure_schema();sf_ops_ensure_schema();$limit=max(10,min(200,$limit));$args=[$userId];$where="user_id=? AND event_type IN ('start','resume','pause','complete')";if($beforeId>0){$where.=' AND id<?';$args[]=$beforeId;}
    $q=sf_db()->prepare("SELECT id,session_key,track_id,event_type,position_seconds,duration_seconds,source,created_at FROM listening_events WHERE $where ORDER BY id DESC LIMIT ".$limit);$q->execute($args);$raw=$q->fetchAll();$tracks=sf_track_map();$progress=[];
    $pq=sf_db()->prepare('SELECT track_id,position_seconds,duration_seconds,completed,updated_at FROM user_listening_progress WHERE user_id=?');$pq->execute([$userId]);foreach($pq->fetchAll() as $r)$progress[(string)$r['track_id']]=$r;
    $rows=[];$sessions=[];
    foreach($raw as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$pr=$progress[$id]??null;$resume=$pr&&!$pr['completed']&&(int)$pr['position_seconds']>=10?(int)$pr['position_seconds']:0;$row=['id'=>(int)$r['id'],'session_key'=>(string)$r['session_key'],'track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','event_type'=>$r['event_type'],'position_seconds'=>(int)$r['position_seconds'],'duration_seconds'=>(int)$r['duration_seconds'],'source'=>$r['source'],'created_at'=>$r['created_at'],'resume_position'=>$resume,'can_resume'=>$resume>0];$rows[]=$row;$sk=(string)$r['session_key'];if(!isset($sessions[$sk]))$sessions[$sk]=['session_key'=>$sk,'started_at'=>$r['created_at'],'ended_at'=>$r['created_at'],'sources'=>[],'tracks'=>[],'event_count'=>0];$sessions[$sk]['started_at']=min($sessions[$sk]['started_at'],$r['created_at']);$sessions[$sk]['ended_at']=max($sessions[$sk]['ended_at'],$r['created_at']);$sessions[$sk]['sources'][(string)$r['source']]=true;$sessions[$sk]['tracks'][$id]=['track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??''];$sessions[$sk]['event_count']++;}
    $sessionRows=[];foreach($sessions as $s){$s['sources']=array_keys($s['sources']);$s['tracks']=array_values($s['tracks']);$sessionRows[]=$s;}usort($sessionRows,fn($a,$b)=>strcmp((string)$b['ended_at'],(string)$a['ended_at']));
    return ['rows'=>$rows,'sessions'=>$sessionRows,'summary'=>sf_personalization_history_summary($userId),'next_before_id'=>count($raw)===$limit?(int)end($raw)['id']:null];
}
function sf_personalization_clear_track_history(int $userId,string $trackId): array {
    sf_personalization_ensure_schema();sf_ops_ensure_schema();if(!isset(sf_track_map()[$trackId]))throw new InvalidArgumentException('Unknown track.');$pdo=sf_db();$q=$pdo->prepare('UPDATE listening_events SET user_id=NULL WHERE user_id=? AND track_id=?');$q->execute([$userId,$trackId]);$events=$q->rowCount();$pdo->prepare('DELETE FROM user_listening_progress WHERE user_id=? AND track_id=?')->execute([$userId,$trackId]);$pdo->prepare("DELETE FROM user_activity WHERE user_id=? AND entity_type='track' AND entity_id=? AND event_type IN ('listen_start','listen_complete')")->execute([$userId,$trackId]);sf_log_user_activity($userId,'listening_track_history_cleared','Removed a track from personal listening history','track',$trackId,['anonymized_events'=>$events]);return ['anonymized_events'=>$events];
}
