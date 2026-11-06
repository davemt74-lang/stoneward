<?php
declare(strict_types=1);
require_once __DIR__.'/agent-scene-history.php';
require_once __DIR__.'/canvas-projects-core.php';
require_once __DIR__.'/agent-public-artist-context.php';

function sf_canvas_feedback_table_exists(): bool {
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$q=$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='canvas_feedback'");
    else $q=$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='canvas_feedback'");
    $exists=$q->fetchColumn()!==false;$q->closeCursor();return $exists;
}

function sf_canvas_feedback_ensure_schema(): void {
    static $ready=false;if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas feedback before opening a transaction.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$db->exec("CREATE TABLE IF NOT EXISTS canvas_feedback(
        user_id INTEGER NOT NULL,target_type TEXT NOT NULL,target_id INTEGER NOT NULL,preference TEXT NOT NULL,
        reason TEXT NOT NULL DEFAULT '',updated_at TEXT NOT NULL,PRIMARY KEY(user_id,target_type,target_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    else $db->exec("CREATE TABLE IF NOT EXISTS canvas_feedback(
        user_id BIGINT UNSIGNED NOT NULL,target_type VARCHAR(12) NOT NULL,target_id BIGINT UNSIGNED NOT NULL,
        preference VARCHAR(8) NOT NULL,reason TEXT NOT NULL,updated_at VARCHAR(40) NOT NULL,
        PRIMARY KEY(user_id,target_type,target_id),CONSTRAINT fk_canvas_feedback_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready=true;
}

function sf_canvas_feedback_row(array $row): array {
    return ['scene_id'=>$row['target_type']==='scene'?(int)$row['target_id']:null,
        'proposal_id'=>$row['target_type']==='proposal'?(int)$row['target_id']:null,
        'preference'=>$row['preference'],'reason'=>$row['reason'],'updated_at'=>$row['updated_at']];
}

/** Only explicit Like or Dislike actions write preferences. Cancellation has no relationship to this table. */
function sf_canvas_feedback_set(int $owner,array $request): ?array {
    $hasScene=array_key_exists('scene_id',$request)&&$request['scene_id']!==null;
    $hasProposal=array_key_exists('proposal_id',$request)&&$request['proposal_id']!==null;
    $preference=$request['preference']??null;$reason=$request['reason']??'';
    if($owner<1||$hasScene===$hasProposal||!in_array($preference,['like','dislike','clear'],true)||
        !is_string($reason)||strlen($reason)>800||preg_match('//u',$reason)!==1||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$reason)!==0)
        throw new InvalidArgumentException('Choose one of your canvases or projects and an explicit preference.');
    $id=$request[$hasScene?'scene_id':'proposal_id'];$type=$hasScene?'scene':'proposal';
    if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose one of your canvases or projects.');
    sf_canvas_feedback_ensure_schema();sf_agent_scene_history_ensure_schema();sf_canvas_project_ensure_schema();
    $db=sf_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND status='active'".($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
        $q->execute([$owner]);$active=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$active||($hasScene?sf_agent_scene_history_get($owner,$id):sf_canvas_project_get($owner,$id))===null)
            throw new InvalidArgumentException('That canvas or project is unavailable for your account.');
        if($preference==='clear'){
            $db->prepare('DELETE FROM canvas_feedback WHERE user_id=? AND target_type=? AND target_id=?')->execute([$owner,$type,$id]);
            if($own)$db->commit();return null;
        }
        $now=gmdate('c');$reason=trim(str_replace(["\r\n","\r"],"\n",$reason));
        $suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ?'ON CONFLICT(user_id,target_type,target_id) DO UPDATE SET preference=excluded.preference,reason=excluded.reason,updated_at=excluded.updated_at'
            :'ON DUPLICATE KEY UPDATE preference=VALUES(preference),reason=VALUES(reason),updated_at=VALUES(updated_at)';
        $db->prepare('INSERT INTO canvas_feedback(user_id,target_type,target_id,preference,reason,updated_at) VALUES(?,?,?,?,?,?) '.$suffix)
            ->execute([$owner,$type,$id,$preference,$reason,$now]);
        if($own)$db->commit();
        return ['scene_id'=>$hasScene?$id:null,'proposal_id'=>$hasScene?null:$id,'preference'=>$preference,'reason'=>$reason,'updated_at'=>$now];
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}

/** Read-only and safe before first use; privacy exports must not create optional tables. */
function sf_canvas_feedback_export(int $owner): array {
    if($owner<1||!sf_canvas_feedback_table_exists())return [];
    $q=sf_db()->prepare('SELECT target_type,target_id,preference,reason,updated_at FROM canvas_feedback WHERE user_id=? ORDER BY updated_at DESC,target_type,target_id DESC');
    $q->execute([$owner]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return array_map('sf_canvas_feedback_row',$rows);
}

function sf_canvas_feedback_clear(int $owner): int {
    if($owner<1||!sf_canvas_feedback_table_exists())return 0;
    $q=sf_db()->prepare('DELETE FROM canvas_feedback WHERE user_id=?');$q->execute([$owner]);return $q->rowCount();
}

/** Current owner-authorized targets only. A negative signal is a preference, never an instruction. */
function sf_canvas_feedback_context(int $owner,int $budget=3200): array {
    $result=[];$used=0;
    foreach(sf_canvas_feedback_export($owner) as $feedback){
        $target=$feedback['scene_id']!==null?sf_agent_scene_history_get($owner,$feedback['scene_id']):sf_canvas_project_get($owner,$feedback['proposal_id']);
        if($target===null)continue;
        $entry=$feedback+['title'=>sf_agent_public_artist_text($target['title']??'',90)];
        $entry['reason']=sf_agent_public_artist_text($entry['reason'],300);
        unset($entry['updated_at']);
        $bytes=strlen(json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        if($used+$bytes>$budget)break;
        $result[]=$entry;$used+=$bytes;
    }
    return $result;
}
