<?php
declare(strict_types=1);

require_once __DIR__.'/agent-canvas-music-core.php';
require_once __DIR__.'/canvas-resource-core.php';

class SfCanvasProjectApprovalRequired extends RuntimeException {}
class SfCanvasProjectConflict extends RuntimeException {}

function sf_canvas_project_table_exists(string $table): bool {
    if(!in_array($table,['canvas_project_preferences','canvas_projects'],true))return false;
    $db=sf_db();
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);
    }else{$q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);}
    $exists=$q->fetchColumn()!==false;$q->closeCursor();return $exists;
}
function sf_canvas_project_ensure_schema(): void {
    static $ready=false;if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas projects before opening a transaction.');
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        $db->exec('CREATE TABLE IF NOT EXISTS canvas_project_preferences (user_id INTEGER PRIMARY KEY,ideas_enabled INTEGER NOT NULL DEFAULT 1,auto_approve INTEGER NOT NULL DEFAULT 0,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec('CREATE TABLE IF NOT EXISTS canvas_projects (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,title TEXT NOT NULL,concept TEXT NOT NULL,project_json TEXT NOT NULL,fingerprint TEXT NOT NULL,source TEXT NOT NULL,status TEXT NOT NULL,approval_mode TEXT NULL,request_id TEXT NULL,scene_id INTEGER NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_canvas_projects_owner ON canvas_projects(user_id,id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_canvas_projects_request ON canvas_projects(user_id,request_id)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_project_preferences (user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,ideas_enabled TINYINT(1) NOT NULL DEFAULT 1,auto_approve TINYINT(1) NOT NULL DEFAULT 0,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_canvas_project_preferences_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS canvas_projects (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(240) NOT NULL,concept TEXT NOT NULL,project_json MEDIUMTEXT NOT NULL,fingerprint CHAR(64) NOT NULL,source VARCHAR(16) NOT NULL,status VARCHAR(24) NOT NULL,approval_mode VARCHAR(12) NULL,request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,scene_id BIGINT UNSIGNED NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_canvas_projects_owner(user_id,id),UNIQUE KEY idx_canvas_projects_request(user_id,request_id),CONSTRAINT fk_canvas_projects_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_canvas_project_preferences(int $uid): array {
    if($uid<1)throw new InvalidArgumentException('Sign in to manage canvas projects.');
    sf_canvas_project_ensure_schema();
    $q=sf_db()->prepare('SELECT ideas_enabled,auto_approve FROM canvas_project_preferences WHERE user_id=?');$q->execute([$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
    return ['ideas_enabled'=>$row?(bool)$row['ideas_enabled']:true,'auto_approve'=>$row?(bool)$row['auto_approve']:false];
}
function sf_canvas_project_preferences_save(int $uid,array $changes): array {
    if($uid<1||!$changes||array_diff(array_keys($changes),['ideas_enabled','auto_approve']))throw new InvalidArgumentException('Choose canvas idea or approval preferences.');
    foreach($changes as $value)if(!is_bool($value))throw new InvalidArgumentException('Canvas preferences must be on or off.');
    sf_canvas_project_ensure_schema();$db=sf_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id FROM users WHERE id=? AND status=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid,'active']);$active=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$active)throw new InvalidArgumentException('Your account is no longer available.');
        $prefs=array_replace(['ideas_enabled'=>true,'auto_approve'=>false],$changes);$now=gmdate('c');$sqlite=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $updates=[];foreach(array_keys($changes) as $field)$updates[]=$field.'='.($sqlite?'excluded.'.$field:'VALUES('.$field.')');
        $updates[]='updated_at='.($sqlite?'excluded.updated_at':'VALUES(updated_at)');
        $sql='INSERT INTO canvas_project_preferences(user_id,ideas_enabled,auto_approve,updated_at) VALUES(?,?,?,?) '.($sqlite?'ON CONFLICT(user_id) DO UPDATE SET ':'ON DUPLICATE KEY UPDATE ').implode(',',$updates);
        $db->prepare($sql)->execute([$uid,(int)$prefs['ideas_enabled'],(int)$prefs['auto_approve'],$now]);
        if(array_key_exists('auto_approve',$changes)&&!$changes['auto_approve'])$db->prepare("UPDATE canvas_projects SET status='awaiting_approval',approval_mode=NULL,updated_at=? WHERE user_id=? AND status='approved' AND approval_mode='automatic'")->execute([$now,$uid]);
        if($own)$db->commit();return sf_canvas_project_preferences($uid);
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_project_source_ids(mixed $raw): array {
    if(!is_array($raw)||!array_is_list($raw))throw new InvalidArgumentException('Choose saved canvas sources.');
    foreach($raw as $id)if(!is_int($id)||$id<1)throw new InvalidArgumentException('Choose saved canvas sources.');
    return array_values(array_unique($raw,SORT_REGULAR));
}
function sf_canvas_project_text(mixed $raw,int $maximum,string $message): string {
    if(!is_string($raw)||preg_match('//u',$raw)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$raw)!==0)throw new InvalidArgumentException($message);
    $raw=trim(str_replace(["\r\n","\r"],"\n",$raw));if(strlen($raw)>$maximum)throw new InvalidArgumentException($message);return $raw;
}
/** Canonical creative inputs only. Operational request/approval IDs are not part of the approved design. */
function sf_canvas_project_normalize(array $raw,bool $parseResourceTokens=true): array {
    $allowed=['action','canvas_kind','brief','track_id','context_track_id','previous_id','lyric_section','coding_model','image_reference_ids','image_count','review_images','music_inputs','source_scene_ids','artist_inputs','playlist_inputs','playlist_manifest'];
    if(array_is_list($raw)||array_diff(array_keys($raw),$allowed))throw new InvalidArgumentException('Choose supported canvas project inputs.');
    if(!in_array($raw['action']??'create',['create','revise'],true))throw new InvalidArgumentException('Canvas projects build or revise canvas code.');
    $kind=$raw['canvas_kind']??'custom';if(!in_array($kind,['custom','listening_room','space_eq'],true))throw new InvalidArgumentException('Choose a supported canvas type.');
    $brief=sf_canvas_project_text($raw['brief']??'',2*1024*1024,'Describe the canvas in 1 to 1800 characters.');
    $rawSources=array_key_exists('source_scene_ids',$raw)?sf_canvas_project_source_ids($raw['source_scene_ids']):null;
    if($parseResourceTokens)[$brief,$artist,$artistProvided,$sources]=sf_canvas_resource_selection($brief,$raw['artist_inputs']??null,array_key_exists('artist_inputs',$raw),$rawSources);
    else{$artistProvided=array_key_exists('artist_inputs',$raw);$artist=$artistProvided?sf_canvas_resource_artist_inputs($raw['artist_inputs']):null;$sources=$rawSources;}
    [$brief,$playlists,$playlistsProvided]=sf_canvas_playlists_selection($brief,$raw['playlist_inputs']??null,array_key_exists('playlist_inputs',$raw));
    $playlistsProvided=$playlistsProvided&&$playlists!==null;
    [$clean,$music,$musicProvided]=sf_agent_canvas_music_selection($brief,$raw['music_inputs']??null,array_key_exists('music_inputs',$raw));
    if(strlen($clean)>1800)throw new InvalidArgumentException('Describe the canvas in 1 to 1800 characters.');
    $project=['action'=>'create','canvas_kind'=>$kind,'brief'=>$brief];
    foreach(['track_id','context_track_id'] as $field){
        $value=$raw[$field]??'';if(!is_string($value)||strlen($value)>180||($value!==''&&preg_match('/\A[A-Za-z0-9_-]+\z/',$value)!==1))throw new InvalidArgumentException('Choose an available song context.');
        $project[$field]=trim($value);
    }
    if(isset($raw['previous_id'])){if(!is_int($raw['previous_id'])||$raw['previous_id']<1)throw new InvalidArgumentException('Choose a saved canvas to revise.');$project['previous_id']=$raw['previous_id'];}
    if($brief===''&&$kind!=='space_eq')throw new InvalidArgumentException('Describe the canvas you want to build.');
    $section=$raw['lyric_section']??null;if($section!==null&&!in_array($section,['whole','verse','chorus','bridge'],true))throw new InvalidArgumentException('Choose an available lyric section.');
    if($section!==null)$project['lyric_section']=$section;
    $model=$raw['coding_model']??null;
    if($model!==null){if(!is_array($model)||count($model)!==2||!in_array($model['provider']??null,['openai','anthropic'],true)||!is_string($model['model']??null)||$model['model']===''||strlen($model['model'])>160)throw new InvalidArgumentException('Choose an available coding model.');$project['coding_model']=['provider'=>$model['provider'],'model'=>$model['model']];}
    $refs=$raw['image_reference_ids']??[];
    if(!is_array($refs)||!array_is_list($refs)||count($refs)>4)throw new InvalidArgumentException('Choose up to four image references.');
    foreach($refs as $id)if(!is_string($id)||preg_match('/\A(?:ref-)?[a-f0-9]{32}\z/',$id)!==1)throw new InvalidArgumentException('Choose an available image reference.');
    $project['image_reference_ids']=array_values(array_unique($refs));
    $count=$raw['image_count']??null;if($count!==null&&(!is_int($count)||$count<0||$count>4))throw new InvalidArgumentException('Create up to four images.');
    if($count!==null)$project['image_count']=$count;
    $review=$raw['review_images']??false;if(!is_bool($review)||($review&&$count!==null&&$count<2))throw new InvalidArgumentException('Choose valid image review options.');$project['review_images']=$review;
    if($musicProvided)$project['music_inputs']=$music;
    if($playlistsProvided)$project['playlist_inputs']=$playlists;
    if(array_key_exists('playlist_manifest',$raw))$project['playlist_manifest']=sf_canvas_playlists_manifest_validate($raw['playlist_manifest']);
    if($artistProvided)$project['artist_inputs']=$artist;
    $project['source_scene_ids']=$sources??[];
    if($kind!=='custom'&&($project['context_track_id']!==''||$musicProvided||$refs||$count!==null||$review||$project['source_scene_ids']||$artistProvided||$playlistsProvided||isset($project['playlist_manifest'])))throw new InvalidArgumentException('Choose a custom canvas for creative resources.');
    if(($kind==='listening_room'||$section!==null)&&$project['track_id']==='')throw new InvalidArgumentException('Choose a published song.');
    return $project;
}
/** Preserve scoped live resources while leaving unrelated artist inputs alone. */
function sf_canvas_project_inherit_resource_scopes(array $project,?array $from): array {
    // Keep the legacy reset marker so the worker can clear artist selections.
    // Its immutable parent still supplies any existing account-data restriction.
    if(array_key_exists('artist_inputs',$project)&&$project['artist_inputs']===null)return $project;
    $inherited=array_intersect_key($from??[],['stories'=>true,'lyrics_ids'=>true,'me_tools'=>true,'data_sources'=>true]);
    if(!$inherited)return $project;
    $project['artist_inputs']=($project['artist_inputs']??[])+$inherited;
    return sf_canvas_project_normalize($project,false);
}
/** Bind inherited scopes and selected or inherited playlist membership before approval or a model call. */
function sf_canvas_project_bind_playlists(int $uid,array $project):array {
    $parent=null;
    if(isset($project['previous_id'])){require_once __DIR__.'/agent-scene-history.php';$parent=sf_agent_scene_history_get($uid,$project['previous_id']);if(!$parent)throw new InvalidArgumentException('That canvas is unavailable for your account.');}
    $project=sf_canvas_project_inherit_resource_scopes($project,$parent['artist_inputs']??null);
    if(!array_key_exists('playlist_inputs',$project)&&isset($parent['playlist_inputs'])){$project['playlist_inputs']=$parent['playlist_inputs'];$project['playlist_manifest']=$parent['playlist_manifest']??null;}
    if(!array_key_exists('playlist_inputs',$project)){if(isset($project['playlist_manifest']))throw new InvalidArgumentException('Choose playlist references for this manifest.');return $project;}
    $compiled=sf_canvas_playlists_compile($uid,$project['playlist_inputs'],$project['playlist_manifest']??null);$project['playlist_manifest']=$compiled['manifest'];
    if(!array_key_exists('music_inputs',$project)&&$parent!==null)$project['music_inputs']=array_key_exists('direct_music_inputs',$parent)?$parent['direct_music_inputs']:($parent['music_inputs']??null);
    // Match persisted/read normalization order before computing the approval fingerprint.
    return sf_canvas_project_normalize($project,false);
}
function sf_canvas_project_validate_sources(int $uid,array $ids): void {
    require_once __DIR__.'/agent-scene-history.php';
    foreach($ids as $id)if(sf_agent_scene_history_get($uid,$id)===null)throw new InvalidArgumentException('A selected canvas source is no longer available.');
}
function sf_canvas_project_fingerprint(array $project): string {return hash('sha256',json_encode($project,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
function sf_canvas_project_row(array $row): array {
    $project=sf_canvas_project_normalize(json_decode($row['project_json'],true,8,JSON_THROW_ON_ERROR),false);
    return ['id'=>(int)$row['id'],'status'=>$row['status'],'title'=>$row['title'],'concept'=>$row['concept'],'project'=>$project,
        'source_scene_ids'=>$project['source_scene_ids'],'source'=>$row['source'],'approval_mode'=>$row['approval_mode'],'request_id'=>$row['request_id'],
        'scene_id'=>$row['scene_id']===null?null:(int)$row['scene_id'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
}
function sf_canvas_project_get(int $uid,int $id): ?array {
    if($uid<1||$id<1)return null;sf_canvas_project_ensure_schema();
    $q=sf_db()->prepare('SELECT * FROM canvas_projects WHERE user_id=? AND id=?');$q->execute([$uid,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?sf_canvas_project_row($row):null;
}
function sf_canvas_project_list(int $uid): array {
    if($uid<1)return [];sf_canvas_project_ensure_schema();
    $q=sf_db()->prepare('SELECT * FROM canvas_projects WHERE user_id=? ORDER BY id DESC LIMIT 30');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();return array_map('sf_canvas_project_row',$rows);
}
function sf_canvas_project_reuse(int $uid,int $id,array $project): ?array {
    $proposal=sf_canvas_project_get($uid,$id);if(!$proposal)throw new InvalidArgumentException('Canvas project not found.');
    if(in_array($proposal['status'],['awaiting_approval','approved'],true)&&hash_equals(sf_canvas_project_fingerprint($proposal['project']),sf_canvas_project_fingerprint($project))){
        sf_canvas_resource_validate_scopes($project['artist_inputs']??null);
        return $proposal;
    }
    return null;
}
function sf_canvas_project_propose(array $user,array $request,string $source='user'): array {
    $uid=(int)($user['id']??0);if($uid<1||!in_array($source,['user','agent'],true))throw new InvalidArgumentException('Sign in to plan a canvas.');
    sf_canvas_project_ensure_schema();
    $raw=$request['project']??$request['build_request']??null;if(!is_array($raw))throw new InvalidArgumentException('Describe a canvas project.');
    if(isset($request['source_scene_ids'])&&!isset($raw['source_scene_ids']))$raw['source_scene_ids']=$request['source_scene_ids'];
    $project=sf_canvas_project_bind_playlists($uid,sf_canvas_project_normalize($raw));
    sf_canvas_resource_prepare($project['artist_inputs']??null);
    sf_canvas_resource_validate($uid,$project['artist_inputs']??null,$project['source_scene_ids']);
    if(isset($project['previous_id']))sf_canvas_project_validate_sources($uid,[$project['previous_id']]);
    if(isset($request['reuse_id'])){
        if(!is_int($request['reuse_id'])||$request['reuse_id']<1)throw new InvalidArgumentException('Choose a canvas project.');
        $reuse=sf_canvas_project_reuse($uid,$request['reuse_id'],$project);if($reuse!==null)return $reuse;
    }
    $replaceId=$request['replace_id']??null;
    if($replaceId!==null&&(!is_int($replaceId)||$replaceId<1||sf_canvas_project_get($uid,$replaceId)===null))throw new InvalidArgumentException('Canvas project not found.');
    $title=sf_canvas_project_text($request['title']??'Canvas project',240,'Use a short canvas project title.');
    $concept=sf_canvas_project_text($request['concept']??'',1800,'Keep the project concept short.');
    $prefs=sf_canvas_project_preferences($uid);$status=$prefs['auto_approve']?'approved':'awaiting_approval';$now=gmdate('c');$db=sf_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id FROM users WHERE id=? AND status=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));$q->execute([$uid,'active']);$available=$q->fetchColumn()!==false;$q->closeCursor();
        if(!$available)throw new InvalidArgumentException('Your account is no longer available.');
        sf_canvas_resource_validate_scopes($project['artist_inputs']??null);
        if($replaceId!==null)$db->prepare("UPDATE canvas_projects SET status='canceled',updated_at=? WHERE user_id=? AND id=? AND status IN ('awaiting_approval','approved')")->execute([$now,$uid,$replaceId]);
        $q=$db->prepare('INSERT INTO canvas_projects(user_id,title,concept,project_json,fingerprint,source,status,approval_mode,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$uid,$title,$concept,json_encode($project,JSON_THROW_ON_ERROR),sf_canvas_project_fingerprint($project),$source,$status,$prefs['auto_approve']?'automatic':null,$now,$now]);
        $id=(int)$db->lastInsertId();if($own)$db->commit();return sf_canvas_project_get($uid,$id);
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_project_approve(int $uid,int $id): array {
    $proposal=sf_canvas_project_get($uid,$id);if(!$proposal)throw new InvalidArgumentException('Canvas project not found.');
    if(!in_array($proposal['status'],['awaiting_approval','approved'],true))throw new SfCanvasProjectConflict('This canvas project cannot be approved now.');
    $bound=sf_canvas_project_bind_playlists($uid,$proposal['project']);
    sf_canvas_resource_validate_scopes($bound['artist_inputs']??null);
    sf_db()->prepare("UPDATE canvas_projects SET status='approved',approval_mode='manual',updated_at=? WHERE user_id=? AND id=? AND status IN ('awaiting_approval','approved')")->execute([gmdate('c'),$uid,$id]);
    return sf_canvas_project_get($uid,$id);
}
/** Atomic approval claim before model, image or token-generation work. Explicit legacy calls keep their existing contract. */
function sf_canvas_project_begin(array $user,array $request): ?array {
    if(array_key_exists('project_managed',$request)&&!is_bool($request['project_managed']))throw new InvalidArgumentException('Choose a valid canvas project approval.');
    if(($request['project_managed']??false)!==true){if(isset($request['proposal_id']))throw new InvalidArgumentException('Use the canvas project approval flow.');return null;}
    $uid=(int)($user['id']??0);$id=$request['proposal_id']??null;
    if($uid<1||!is_int($id)||$id<1)throw new SfCanvasProjectApprovalRequired('Review and approve this canvas project before building.');
    $creative=array_intersect_key($request,array_flip(['action','canvas_kind','brief','track_id','context_track_id','previous_id','lyric_section','coding_model','image_reference_ids','image_count','review_images','music_inputs','source_scene_ids','artist_inputs','playlist_inputs','playlist_manifest']));
    sf_canvas_project_ensure_schema();$requestId=$request['request_id']??null;
    if(!is_string($requestId)||preg_match('/\A[a-f0-9]{32}\z/',$requestId)!==1)throw new InvalidArgumentException('Use a valid canvas request ID.');
    $existing=sf_canvas_project_get($uid,$id);$project=sf_canvas_project_normalize($creative);
    if($existing&&in_array($existing['status'],['building','built'],true)&&$existing['request_id']===$requestId){
        // Replaying an already claimed exact request returns its saved result;
        // later source edits must neither expand it nor turn recovery into a new build.
        foreach(['music_inputs','playlist_inputs','playlist_manifest'] as $field)if(!array_key_exists($field,$project)&&array_key_exists($field,$existing['project']))$project[$field]=$existing['project'][$field];
        $project=sf_canvas_project_inherit_resource_scopes($project,$existing['project']['artist_inputs']??null);
        $project=sf_canvas_project_normalize($project,false);
    }else $project=sf_canvas_project_bind_playlists($uid,$project);
    // Approval and the later worker claim share the same privacy epoch.
    // Initialize migrations before the account/project lock transaction.
    require_once __DIR__.'/agent-scene-history.php';
    if(!function_exists('sf_privacy_ensure_schema'))require_once __DIR__.'/privacy-core.php';
    sf_agent_scene_history_ensure_schema();sf_privacy_ensure_schema();
    sf_canvas_resource_prepare($project['artist_inputs']??null);
    $db=sf_db();$own=!$db->inTransaction();if($own)$db->beginTransaction();
    try{
        // Match privacy clearing's account-before-project lock order.
        sf_agent_scene_history_lock_account($db,$uid);
        $historyEpoch=sf_agent_scene_history_epoch($uid);
        $sql='SELECT * FROM canvas_projects WHERE user_id=? AND id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'');
        $q=$db->prepare($sql);$q->execute([$uid,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$row)throw new SfCanvasProjectApprovalRequired('Review and approve this canvas project before building.');
        if(!hash_equals($row['fingerprint'],sf_canvas_project_fingerprint($project)))throw new SfCanvasProjectApprovalRequired('The canvas inputs changed. Review and approve the updated project.');
        $prefs=sf_canvas_project_preferences($uid);
        if($row['status']==='awaiting_approval'&&$prefs['auto_approve']){$row['status']='approved';$row['approval_mode']='automatic';}
        if($row['status']==='awaiting_approval'||($row['approval_mode']==='automatic'&&!$prefs['auto_approve']&&$row['status']==='approved'))throw new SfCanvasProjectApprovalRequired('Review and approve this canvas project before building.');
        if($row['status']==='canceled')throw new SfCanvasProjectConflict('This canvas project was canceled.');
        if(in_array($row['status'],['building','built'],true)&&$row['request_id']!==$requestId)throw new SfCanvasProjectConflict('This canvas project already has a build request.');
        if($row['status']==='approved'&&isset($project['playlist_inputs']))sf_canvas_playlists_compile($uid,$project['playlist_inputs'],$project['playlist_manifest']);
        if($row['status']==='approved')sf_canvas_resource_validate($uid,$project['artist_inputs']??null,$project['source_scene_ids']);
        if($row['status']==='approved')$db->prepare("UPDATE canvas_projects SET status='building',approval_mode=?,request_id=?,updated_at=? WHERE user_id=? AND id=? AND status IN ('awaiting_approval','approved')")->execute([$row['approval_mode'],$requestId,gmdate('c'),$uid,$id]);
        if($own)$db->commit();
        $proposal=sf_canvas_project_get($uid,$id);
        if($proposal===null)throw new SfCanvasProjectApprovalRequired('This canvas project was cleared before building.');
        return $proposal+['_history_epoch'=>$historyEpoch];
    }catch(Throwable $error){if($own&&$db->inTransaction())$db->rollBack();throw $error;}
}
function sf_canvas_project_finish(int $uid,string $requestId,array $result): void {
    if(!sf_canvas_project_table_exists('canvas_projects'))return;
    $sceneId=$result['scene']['id']??null;$state=$result['state']??null;
    if(is_int($sceneId)&&$sceneId>0)sf_db()->prepare("UPDATE canvas_projects SET status='built',scene_id=?,updated_at=? WHERE user_id=? AND request_id=? AND status='building'")->execute([$sceneId,gmdate('c'),$uid,$requestId]);
    elseif($state==='canceled')sf_db()->prepare("UPDATE canvas_projects SET status='canceled',updated_at=? WHERE user_id=? AND request_id=? AND status='building'")->execute([gmdate('c'),$uid,$requestId]);
    elseif($state==='failed')sf_canvas_project_fail($uid,$requestId);
}
function sf_canvas_project_fail(int $uid,string $requestId): void {
    if(sf_canvas_project_table_exists('canvas_projects'))sf_db()->prepare("UPDATE canvas_projects SET status='approved',request_id=NULL,updated_at=? WHERE user_id=? AND request_id=? AND status='building'")->execute([gmdate('c'),$uid,$requestId]);
}
function sf_canvas_project_cancel(int $uid,int $id): array {
    $proposal=sf_canvas_project_get($uid,$id);if(!$proposal)throw new InvalidArgumentException('Canvas project not found.');
    if($proposal['status']==='built')throw new SfCanvasProjectConflict('This canvas project has already been built.');
    if($proposal['status']==='building'&&is_string($proposal['request_id'])){
        require_once __DIR__.'/agent-scene-core.php';
        sf_agent_scene_request_cancel($uid,$proposal['request_id']);
        $result=sf_agent_scene_request_result($uid,$proposal['request_id']);
        if(is_array($result)&&isset($result['scene'])){sf_canvas_project_finish($uid,$proposal['request_id'],$result);return sf_canvas_project_get($uid,$id);}
    }
    sf_db()->prepare("UPDATE canvas_projects SET status='canceled',updated_at=? WHERE user_id=? AND id=? AND status IN ('awaiting_approval','approved','building')")->execute([gmdate('c'),$uid,$id]);return sf_canvas_project_get($uid,$id);
}
/** Privacy helpers inspect existing tables; no DDL occurs inside privacy transactions. */
function sf_canvas_project_export(int $uid): array {
    $out=['preferences'=>['ideas_enabled'=>true,'auto_approve'=>false],'proposals'=>[]];
    if(sf_canvas_project_table_exists('canvas_project_preferences')){$q=sf_db()->prepare('SELECT ideas_enabled,auto_approve FROM canvas_project_preferences WHERE user_id=?');$q->execute([$uid]);if($row=$q->fetch(PDO::FETCH_ASSOC))$out['preferences']=['ideas_enabled'=>(bool)$row['ideas_enabled'],'auto_approve'=>(bool)$row['auto_approve']];$q->closeCursor();}
    if(sf_canvas_project_table_exists('canvas_projects')){$q=sf_db()->prepare('SELECT * FROM canvas_projects WHERE user_id=? ORDER BY id ASC');$q->execute([$uid]);$out['proposals']=array_map('sf_canvas_project_row',$q->fetchAll(PDO::FETCH_ASSOC));$q->closeCursor();}
    return $out;
}
function sf_canvas_project_clear(int $uid,bool $includePreferences=false): array {
    $counts=['canvas_projects'=>0];
    if(sf_canvas_project_table_exists('canvas_projects')){$q=sf_db()->prepare('DELETE FROM canvas_projects WHERE user_id=?');$q->execute([$uid]);$counts['canvas_projects']=$q->rowCount();}
    if($includePreferences){$counts['canvas_project_preferences']=0;if(sf_canvas_project_table_exists('canvas_project_preferences')){$q=sf_db()->prepare('DELETE FROM canvas_project_preferences WHERE user_id=?');$q->execute([$uid]);$counts['canvas_project_preferences']=$q->rowCount();}}
    return $counts;
}

/** Fresh owner-scoped source code and only actually saved prompts, within a shared input budget. */
function sf_canvas_project_source_context(int $uid,array $ids,int $byteBudget=64000): array {
    $ids=sf_canvas_project_source_ids($ids);sf_canvas_project_validate_sources($uid,$ids);$sources=[];$used=0;$omitted=0;
    foreach($ids as $index=>$id){
        $scene=sf_agent_scene_history_get($uid,$id);
        if($scene===null)throw new InvalidArgumentException('A selected canvas source is no longer available.');
        $entry=['id'=>$id,'title'=>$scene['title'],'canvas_kind'=>$scene['canvas_kind']];
        if(isset($scene['design_brief']))$entry['design_brief']=$scene['design_brief'];
        $base=strlen(json_encode($entry,JSON_THROW_ON_ERROR));
        if($used+$base>$byteBudget){$omitted++;continue;}
        $remaining=max(0,$byteBudget-$used-$base-240);$share=(int)floor($remaining/max(1,count($ids)-$index));
        $code=[];$truncated=false;
        foreach(['html','css','js'] as $field){
            $value=$scene[$field]??'';$budget=(int)floor($share/max(1,3-count($code)));$excerpt=substr($value,0,$budget);
            while($excerpt!==''&&preg_match('//u',$excerpt)!==1)$excerpt=substr($excerpt,0,-1);
            $truncated=$truncated||strlen($excerpt)<strlen($value);$code[$field]=$excerpt;$share-=strlen($excerpt);
        }
        $entry['code']=$code;$entry['code_truncated']=$truncated;
        $size=strlen(json_encode($entry,JSON_THROW_ON_ERROR));
        if($used+$size>$byteBudget){unset($entry['code']);$entry['code_truncated']=true;$size=strlen(json_encode($entry,JSON_THROW_ON_ERROR));}
        if($used+$size>$byteBudget){$omitted++;continue;}
        $used+=$size;$sources[]=$entry;
    }
    return ['sources'=>$sources,'omitted_source_count'=>$omitted];
}
