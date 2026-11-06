<?php
declare(strict_types=1);

require_once __DIR__.'/agent-scene-history.php';

const SF_AGENT_SCENE_REQUEST_TTL=300;

function sf_agent_scene_request_ensure_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize listening room requests before opening a transaction.');
    sf_agent_scene_history_ensure_schema();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS agent_scene_requests (
            user_id INTEGER NOT NULL, request_id TEXT NOT NULL, fingerprint TEXT NOT NULL,
            history_epoch INTEGER NOT NULL, created_at INTEGER NOT NULL, state TEXT NOT NULL, scene_id INTEGER NULL,
            active_tokens_used INTEGER NULL, active_tokens_remaining INTEGER NULL, design_source TEXT NULL,
            PRIMARY KEY(user_id,request_id), UNIQUE(user_id,scene_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(scene_id) REFERENCES agent_scene_history(id) ON DELETE CASCADE)');
    }else{
        $db->exec('CREATE TABLE IF NOT EXISTS agent_scene_requests (
            user_id BIGINT UNSIGNED NOT NULL, request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            history_epoch BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
            state VARCHAR(8) NOT NULL, scene_id BIGINT UNSIGNED NULL,
            active_tokens_used BIGINT UNSIGNED NULL, active_tokens_remaining BIGINT UNSIGNED NULL, design_source VARCHAR(8) NULL,
            PRIMARY KEY(user_id,request_id), UNIQUE KEY uniq_agent_scene_request_scene(user_id,scene_id),
            CONSTRAINT fk_agent_scene_request_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_agent_scene_request_scene FOREIGN KEY(scene_id) REFERENCES agent_scene_history(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    $ready=true;
}

function sf_agent_scene_request_id(mixed $id): string {
    if(!is_string($id)||preg_match('/\A[a-f0-9]{32}\z/',$id)!==1)
        throw new InvalidArgumentException('Invalid listening room request ID.');
    return $id;
}

function sf_agent_scene_request_claimed_input(PDO $db,int $uid,string $id): ?array {
    $q=$db->prepare('SELECT fingerprint,history_epoch FROM agent_scene_requests WHERE user_id=? AND request_id=?');
    $q->execute([$uid,$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function sf_agent_scene_request_claim(int $uid,string $id,?string $fingerprint,?int $expectedEpoch=null): bool {
    sf_agent_scene_request_id($id);
    if($uid<1||($fingerprint!==null&&preg_match('/\A[a-f0-9]{64}\z/',$fingerprint)!==1)||($expectedEpoch!==null&&$expectedEpoch<0))
        throw new InvalidArgumentException('Invalid listening room request.');
    sf_agent_scene_request_ensure_schema();
    sf_privacy_ensure_schema();
    $db=sf_db();
    $sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $epoch="COALESCE((SELECT MAX(e.id) FROM user_privacy_events e
        WHERE e.user_id=u.id AND e.event_type='agent_history_cleared'),0)";
    $sql="INSERT INTO agent_scene_requests(user_id,request_id,fingerprint,history_epoch,created_at,state)
        SELECT u.id,?,?,$epoch,?,? FROM users u WHERE u.id=? AND u.status='active'";
    // An empty fingerprint marks a cancellation received before the build request.
    $args=[$id,$fingerprint??'',time(),$fingerprint===null?'canceled':'pending',$uid];
    if($expectedEpoch!==null){
        $sql.=" AND $epoch=(? + 0)";
        $args[]=$expectedEpoch;
    }
    // SQLite's first write takes its writer lock. MySQL serializes with privacy operations via the account lock.
    if($sqlite)$sql.=' ON CONFLICT(user_id,request_id) DO NOTHING';
    $db->beginTransaction();
    try{
        sf_agent_scene_history_lock_account($db,$uid);
        $row=$sqlite?null:sf_agent_scene_request_claimed_input($db,$uid,$id);
        $claimed=false;
        if($row===null){
            $q=$db->prepare($sql);
            $q->execute($args);
            $claimed=$q->rowCount()===1;
            if(!$claimed&&$sqlite)$row=sf_agent_scene_request_claimed_input($db,$uid,$id);
        }
        if(!$claimed){
            if(!$row||($expectedEpoch!==null&&(int)$row['history_epoch']!==$expectedEpoch))
                throw new InvalidArgumentException('The listening room request is no longer available.');
            if($fingerprint!==null&&$row['fingerprint']!==''&&!hash_equals($row['fingerprint'],$fingerprint))
                throw new InvalidArgumentException('This listening room request ID is already in use.');
        }
        $db->commit();
        return $claimed;
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
}

function sf_agent_scene_request_cancel(int $uid,string $id): void {
    require_once __DIR__.'/agent-canvas-image-review.php';
    sf_agent_canvas_image_review_ensure_schema();
    if(!sf_agent_scene_request_claim($uid,$id,null)){
        $db=sf_db();
        $db->beginTransaction();
        try{
            sf_agent_canvas_image_request_lock($db,$uid,$id);
            $q=$db->prepare("UPDATE agent_scene_requests SET state='canceled' WHERE user_id=? AND request_id=? AND state IN ('pending','review')");
            $q->execute([$uid,$id]);
            if($q->rowCount()===1)$db->prepare('DELETE FROM agent_canvas_image_reviews WHERE user_id=? AND request_id=?')->execute([$uid,$id]);
            $db->commit();
        }catch(Throwable $error){
            if($db->inTransaction())$db->rollBack();
            throw $error;
        }
    }
}

function sf_agent_scene_request_get(int $uid,string $id): ?array {
    sf_agent_scene_request_id($id);
    if($uid<1)return null;
    sf_agent_scene_request_ensure_schema();
    $q=sf_db()->prepare('SELECT state,created_at,scene_id,active_tokens_used,active_tokens_remaining,design_source
        FROM agent_scene_requests WHERE user_id=? AND request_id=?');
    $q->execute([$uid,$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    // The original request is settled, but a client cannot recover the deleted canvas.
    if($row['state']==='deleted')return ['state'=>'failed'];
    if($row['state']==='pending'&&(int)$row['created_at']<=time()-SF_AGENT_SCENE_REQUEST_TTL)return ['state'=>'failed'];
    if($row['state']!=='ready')return ['state'=>$row['state']];
    return ['state'=>'ready','scene_id'=>(int)$row['scene_id'],'active_tokens_used'=>(int)$row['active_tokens_used'],
        'active_tokens_remaining'=>$row['active_tokens_remaining']===null?null:(int)$row['active_tokens_remaining'],
        'design_source'=>$row['design_source']];
}

function sf_agent_scene_request_finish(int $uid,string $id,int $sceneId,int $used,?int $remaining,string $source,string $expectedState='pending'): void {
    sf_agent_scene_request_id($id);
    if($uid<1||$sceneId<1||$used<0||($remaining!==null&&$remaining<0)||!in_array($source,['model','standard'],true)||
        !in_array($expectedState,['pending','review'],true))
        throw new InvalidArgumentException('Invalid listening room result.');
    $db=sf_db();
    if(!$db->inTransaction())throw new LogicException('Complete listening room requests in the scene transaction.');
    $q=$db->prepare("UPDATE agent_scene_requests SET state='ready',scene_id=?,active_tokens_used=?,active_tokens_remaining=?,design_source=?
        WHERE user_id=? AND request_id=? AND state=? AND (?='review' OR created_at>(? + 0))
        AND EXISTS (SELECT 1 FROM agent_scene_history WHERE id=? AND user_id=?)");
    $q->execute([$sceneId,$used,$remaining,$source,$uid,$id,$expectedState,$expectedState,time()-SF_AGENT_SCENE_REQUEST_TTL,$sceneId,$uid]);
    if($q->rowCount()!==1)throw new InvalidArgumentException('The listening room request is no longer available.');
}

function sf_agent_scene_request_fail(int $uid,string $id,?int $expectedEpoch=null): void {
    sf_agent_scene_request_id($id);
    sf_agent_scene_request_ensure_schema();
    $sql="UPDATE agent_scene_requests SET state='failed' WHERE user_id=? AND request_id=? AND state='pending'";
    $args=[$uid,$id];
    if($expectedEpoch!==null){
        $sql.=' AND history_epoch=?';
        $args[]=$expectedEpoch;
    }
    sf_db()->prepare($sql)->execute($args);
}

function sf_agent_scene_request_design_source(int $uid,int $sceneId): string {
    sf_agent_scene_request_ensure_schema();
    $q=sf_db()->prepare("SELECT design_source FROM agent_scene_requests WHERE user_id=? AND scene_id=? AND state='ready'");
    $q->execute([$uid,$sceneId]);
    return $q->fetchColumn()==='standard'?'standard':'model';
}

function sf_agent_scene_request_clear(int $uid): void {
    sf_agent_scene_request_ensure_schema();
    $q=sf_db()->prepare('DELETE FROM agent_scene_requests WHERE user_id=?');
    $q->execute([$uid]);
}
