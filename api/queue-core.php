<?php
declare(strict_types=1);

function sf_queue_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_play_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,track_id TEXT NOT NULL,position INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_play_queue_order ON user_play_queue(user_id,position,id)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_play_queue (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(180) NOT NULL,position INT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,UNIQUE KEY uq_user_play_queue_track(user_id,track_id),INDEX idx_user_play_queue_order(user_id,position,id),CONSTRAINT fk_user_play_queue_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_queue_validate_track(string $trackId): string {
    $trackId=sf_clean_text($trackId,180);if($trackId===''||!isset(sf_track_map()[$trackId]))throw new InvalidArgumentException('Unknown track.');return $trackId;
}
function sf_queue_rows(int $userId): array {
    sf_queue_ensure_schema();$q=sf_db()->prepare('SELECT id,track_id,position,created_at,updated_at FROM user_play_queue WHERE user_id=? ORDER BY position ASC,id ASC');$q->execute([$userId]);return $q->fetchAll();
}
function sf_queue_payload(int $userId): array {
    $tracks=sf_track_map();$items=[];foreach(sf_queue_rows($userId) as $r){$id=(string)$r['track_id'];$t=$tracks[$id]??null;if(!$t)continue;$items[]=['item_id'=>(int)$r['id'],'track_id'=>$id,'title'=>$t['title']??$id,'release'=>$t['release']??'','artwork'=>$t['artwork']??'','duration'=>(int)($t['duration']??0),'mood'=>array_values((array)($t['mood']??[])),'themes'=>array_values((array)($t['themes']??[])),'position'=>(int)$r['position'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at']];}return ['items'=>$items,'count'=>count($items)];
}
function sf_queue_normalize(int $userId): void {
    $rows=sf_queue_rows($userId);$q=sf_db()->prepare('UPDATE user_play_queue SET position=?,updated_at=? WHERE id=? AND user_id=?');$now=gmdate('c');foreach($rows as $i=>$r)if((int)$r['position']!==$i)$q->execute([$i,$now,(int)$r['id'],$userId]);
}
function sf_queue_add(int $userId,string $trackId,string $placement='end'): array {
    sf_queue_ensure_schema();$trackId=sf_queue_validate_track($trackId);$placement=$placement==='next'?'next':'end';$pdo=sf_db();$now=gmdate('c');$pdo->beginTransaction();
    try{
        $pdo->prepare('DELETE FROM user_play_queue WHERE user_id=? AND track_id=?')->execute([$userId,$trackId]);sf_queue_normalize($userId);
        if($placement==='next'){$pdo->prepare('UPDATE user_play_queue SET position=position+1,updated_at=? WHERE user_id=?')->execute([$now,$userId]);$pos=0;}
        else{$q=$pdo->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM user_play_queue WHERE user_id=?');$q->execute([$userId]);$pos=(int)$q->fetchColumn();}
        $pdo->prepare('INSERT INTO user_play_queue(user_id,track_id,position,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$userId,$trackId,$pos,$now,$now]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    sf_log_user_activity($userId,$placement==='next'?'queue_play_next':'queue_added',($placement==='next'?'Queued next ':'Added to queue ').($tracks=sf_track_map())[$trackId]['title'],'track',$trackId);
    return sf_queue_payload($userId);
}
function sf_queue_remove(int $userId,int $itemId=0,string $trackId=''): array {
    sf_queue_ensure_schema();if($itemId<1&&$trackId==='')throw new InvalidArgumentException('Queue item is required.');$pdo=sf_db();
    if($itemId>0){$q=$pdo->prepare('DELETE FROM user_play_queue WHERE user_id=? AND id=?');$q->execute([$userId,$itemId]);}
    else{$trackId=sf_queue_validate_track($trackId);$q=$pdo->prepare('DELETE FROM user_play_queue WHERE user_id=? AND track_id=?');$q->execute([$userId,$trackId]);}
    sf_queue_normalize($userId);return sf_queue_payload($userId);
}
function sf_queue_reorder(int $userId,array $itemIds): array {
    sf_queue_ensure_schema();$existing=array_map(fn($r)=>(int)$r['id'],sf_queue_rows($userId));$requested=array_values(array_map('intval',$itemIds));
    if(count($existing)!==count($requested)||array_diff($existing,$requested)||array_diff($requested,$existing))throw new InvalidArgumentException('Queue order does not match the current queue.');
    $pdo=sf_db();$pdo->beginTransaction();try{$q=$pdo->prepare('UPDATE user_play_queue SET position=?,updated_at=? WHERE user_id=? AND id=?');$now=gmdate('c');foreach($requested as $i=>$id)$q->execute([$i,$now,$userId,$id]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return sf_queue_payload($userId);
}
function sf_queue_clear(int $userId): array {sf_queue_ensure_schema();sf_db()->prepare('DELETE FROM user_play_queue WHERE user_id=?')->execute([$userId]);sf_log_user_activity($userId,'queue_cleared','Cleared Up Next queue','queue',(string)$userId);return ['items'=>[],'count'=>0];}
function sf_queue_pop_next(int $userId): array {
    sf_queue_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();try{$q=$pdo->prepare('SELECT id,track_id FROM user_play_queue WHERE user_id=? ORDER BY position ASC,id ASC LIMIT 1');$q->execute([$userId]);$r=$q->fetch();if(!$r){$pdo->commit();return ['item'=>null,'queue'=>['items'=>[],'count'=>0]];}$pdo->prepare('DELETE FROM user_play_queue WHERE user_id=? AND id=?')->execute([$userId,(int)$r['id']]);sf_queue_normalize($userId);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $t=sf_track_map()[(string)$r['track_id']]??null;return ['item'=>$t?['track_id'=>(string)$r['track_id'],'title'=>$t['title']??$r['track_id'],'release'=>$t['release']??'','artwork'=>$t['artwork']??'','duration'=>(int)($t['duration']??0)]:null,'queue'=>sf_queue_payload($userId)];
}
