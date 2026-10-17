<?php
declare(strict_types=1);
/**
 * Stonefellow Team Workspace: additional MANY-TO-MANY roles on an existing
 * authenticated user. Roles never alter users.role or grant /admin access.
 * No implicit inheritance of ownership, billing or security permissions.
 */
function sf_team_role_catalog():array {
    return [
      'band_member'=>['label'=>'Band Member','permissions'=>['team.enter','team.profile.edit_own','media.read','knowledge.read']],
      'manager'=>['label'=>'Manager','permissions'=>['team.enter','team.profile.edit_own','team.timeline.read','team.roster.read','media.read','media.upload','knowledge.read','tour.read']],
      'website_manager'=>['label'=>'Website Manager','permissions'=>['team.enter','team.profile.edit_own','team.timeline.read','team.roster.read','media.read','media.upload','knowledge.read','content.draft']],
      'road_crew'=>['label'=>'Road Crew','permissions'=>['team.enter','team.profile.edit_own','tour.read','knowledge.read']],
      'sound_crew'=>['label'=>'Sound Crew','permissions'=>['team.enter','team.profile.edit_own','media.read','media.upload','tour.read','knowledge.read']],
      'lighting_crew'=>['label'=>'Lighting Crew','permissions'=>['team.enter','team.profile.edit_own','tour.read','knowledge.read']],
      'photographer'=>['label'=>'Photographer','permissions'=>['team.enter','team.profile.edit_own','media.read','media.upload','knowledge.read']],
      'videographer'=>['label'=>'Videographer','permissions'=>['team.enter','team.profile.edit_own','media.read','media.upload','knowledge.read']],
      'producer'=>['label'=>'Producer','permissions'=>['team.enter','team.profile.edit_own','media.read','media.upload','knowledge.read']],
      'tour_manager'=>['label'=>'Tour Manager','permissions'=>['team.enter','team.profile.edit_own','team.timeline.read','team.roster.read','tour.read','knowledge.read']],
      'merch_manager'=>['label'=>'Merch Manager','permissions'=>['team.enter','team.profile.edit_own','knowledge.read']],
      'publicist'=>['label'=>'Publicist','permissions'=>['team.enter','team.profile.edit_own','knowledge.read','media.read']]
    ];
}
function sf_team_ensure_schema():void {
    static $done=false;if($done)return;
    $db=sf_db();$sqlite=(string)(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS team_role_assignments(
          user_id INTEGER NOT NULL,role_key TEXT NOT NULL,assigned_by INTEGER NULL,
          assigned_at TEXT NOT NULL,revoked_at TEXT NULL,PRIMARY KEY(user_id,role_key),
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
          FOREIGN KEY(assigned_by) REFERENCES users(id) ON DELETE SET NULL)");
        $db->exec("CREATE TABLE IF NOT EXISTS team_activity(
          id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER NULL,event_type TEXT NOT NULL,
          entity_type TEXT NOT NULL,entity_id TEXT NOT NULL,description TEXT NOT NULL,
          detail_json TEXT NOT NULL,created_at TEXT NOT NULL,
          FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_team_activity_at ON team_activity(created_at,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_team_activity_actor ON team_activity(actor_id,created_at)');
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS team_role_assignments(
          user_id BIGINT UNSIGNED NOT NULL,role_key VARCHAR(60) NOT NULL,
          assigned_by BIGINT UNSIGNED NULL,assigned_at VARCHAR(40) NOT NULL,revoked_at VARCHAR(40) NULL,
          PRIMARY KEY(user_id,role_key),
          CONSTRAINT fk_team_role_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
          CONSTRAINT fk_team_role_admin FOREIGN KEY(assigned_by) REFERENCES users(id) ON DELETE SET NULL)
          ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS team_activity(
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,actor_id BIGINT UNSIGNED NULL,
          event_type VARCHAR(90) NOT NULL,entity_type VARCHAR(70) NOT NULL,entity_id VARCHAR(160) NOT NULL,
          description VARCHAR(500) NOT NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,
          INDEX idx_team_activity_at(created_at,id),INDEX idx_team_activity_actor(actor_id,created_at),
          CONSTRAINT fk_team_activity_actor FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL)
          ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_team_roles(int $userId):array {
    if($userId<1)return [];
    sf_team_ensure_schema();
    $q=sf_db()->prepare("SELECT r.role_key FROM team_role_assignments r JOIN users u ON u.id=r.user_id
      WHERE r.user_id=? AND r.revoked_at IS NULL AND u.status='active' ORDER BY r.role_key");
    $q->execute([$userId]);$catalog=sf_team_role_catalog();
    return array_values(array_filter(array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)),
        static fn($r)=>isset($catalog[$r])));
}
function sf_team_permissions(int $userId):array {
    $catalog=sf_team_role_catalog();$permissions=[];
    foreach(sf_team_roles($userId) as $role)
        foreach($catalog[$role]['permissions'] as $permission)$permissions[$permission]=true;
    return array_keys($permissions);
}
function sf_team_can(int $userId,string $permission):bool {
    if($userId<1)return false;
    // No team role confers admin status, but an actual site admin can operate
    // the team workspace without assigning themselves another role.
    $q=sf_db()->prepare("SELECT role,status FROM users WHERE id=?");
    $q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||$user['status']!=='active')return false;
    if($user['role']==='admin')return true;
    return in_array($permission,sf_team_permissions($userId),true);
}
function sf_team_require(string $permission,bool $write=false):array {
    $user=sf_require_user(false,$write);
    if(!sf_team_can((int)$user['id'],$permission))
        sf_json_response(['ok'=>false,'error'=>'team_permission_required'],403);
    return $user;
}
function sf_team_audit(int $actorId,string $eventType,string $entityType,string $entityId,string $description,array $details=[]):void {
    sf_team_ensure_schema();
    // Never accept arbitrary private details: only known searchable metadata.
    $whitelist=['category','filename','track_key','upload_id','duplicate','role','user_id','asset_id','status'];
    $clean=[];foreach($whitelist as $key)if(isset($details[$key])&&(is_scalar($details[$key])||$details[$key]===null))
        $clean[$key]=sf_clean_text((string)$details[$key],180);
    $q=sf_db()->prepare('INSERT INTO team_activity(actor_id,event_type,entity_type,entity_id,description,detail_json,created_at) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$actorId>0?$actorId:null,sf_clean_text($eventType,90),
      sf_clean_text($entityType,70),sf_clean_text($entityId,160),
      sf_clean_text($description,500),json_encode($clean,JSON_UNESCAPED_SLASHES)?:'{}',
      (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP')]);
}
function sf_team_assign_roles(int $targetId,array $requested,int $adminId):array {
    if($targetId<1||!sf_team_can($adminId,'team.assign_roles'))throw new RuntimeException('Administrator authorization required.');
    $catalog=sf_team_role_catalog();$new=[];
    foreach($requested as $r){
        if(!is_string($r)||!isset($catalog[$r]))throw new InvalidArgumentException('Unknown team role.');
        $new[$r]=true;
    }
    if(count($new)>count($catalog))throw new InvalidArgumentException('Too many roles.');
    sf_team_ensure_schema();$db=sf_db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT id,status FROM users WHERE id=?');$q->execute([$targetId]);
        $target=$q->fetch(PDO::FETCH_ASSOC);
        if(!$target)throw new InvalidArgumentException('Account not found.');
        $q=$db->prepare('SELECT role_key FROM team_role_assignments WHERE user_id=? AND revoked_at IS NULL');
        $q->execute([$targetId]);$prior=array_fill_keys($q->fetchAll(PDO::FETCH_COLUMN),true);
        $changed=false;$now=gmdate('c');
        foreach(array_diff(array_keys($prior),array_keys($new)) as $role){
            $db->prepare('UPDATE team_role_assignments SET revoked_at=? WHERE user_id=? AND role_key=?')
              ->execute([$now,$targetId,$role]);$changed=true;
        }
        foreach(array_diff(array_keys($new),array_keys($prior)) as $role){
            if((sf_db_config()['driver']??'')==='sqlite')
                $sql='INSERT INTO team_role_assignments(user_id,role_key,assigned_by,assigned_at,revoked_at) VALUES(?,?,?,?,NULL) ON CONFLICT(user_id,role_key) DO UPDATE SET assigned_by=excluded.assigned_by,assigned_at=excluded.assigned_at,revoked_at=NULL';
            else $sql='INSERT INTO team_role_assignments(user_id,role_key,assigned_by,assigned_at,revoked_at) VALUES(?,?,?,?,NULL) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by),assigned_at=VALUES(assigned_at),revoked_at=NULL';
            $db->prepare($sql)->execute([$targetId,$role,$adminId,$now]);$changed=true;
        }
        if($changed)sf_team_audit($adminId,'team.roles_updated','user',(string)$targetId,
          'Changed team role assignments',['user_id'=>$targetId]);
        $db->commit();
    }catch(Throwable $e){$db->rollBack();throw $e;}
    return sf_team_roles($targetId);
}
function sf_team_roster(int $viewerId):array {
    if(!sf_team_can($viewerId,'team.roster.read'))return [];
    sf_team_ensure_schema();
    $rows=sf_db()->query("SELECT DISTINCT u.id,u.display_name,u.status
      FROM users u JOIN team_role_assignments r ON r.user_id=u.id
      WHERE r.revoked_at IS NULL AND u.status='active' ORDER BY u.display_name LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row)$row['team_roles']=sf_team_roles((int)$row['id']);
    unset($row);return $rows;
}
function sf_team_timeline(int $viewerId,int $limit=80,int $offset=0):array {
    if(!sf_team_can($viewerId,'team.enter'))throw new RuntimeException('Team access required.');
    sf_team_ensure_schema();$limit=max(1,min(150,$limit));$offset=max(0,min(10000,$offset));
    $full=sf_team_can($viewerId,'team.timeline.read');$actualAdmin=false;
    $q=sf_db()->prepare('SELECT role FROM users WHERE id=?');$q->execute([$viewerId]);$actualAdmin=$q->fetchColumn()==='admin';
    $max=$offset+$limit+1;$events=[];
    $sql='SELECT a.id,a.actor_id,u.display_name AS actor_name,a.event_type,a.entity_type,a.entity_id,a.description,a.detail_json,a.created_at
      FROM team_activity a LEFT JOIN users u ON u.id=a.actor_id';
    $args=[];if(!$full){$sql.=' WHERE a.actor_id=?';$args[]=$viewerId;}
    $sql.=' ORDER BY a.created_at DESC,a.id DESC LIMIT '.$max;
    $stmt=sf_db()->prepare($sql);$stmt->execute($args);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r){
        $events[]=['key'=>'team:'.$r['id'],'source'=>'team','actor_id'=>(int)$r['actor_id'],
          'actor_name'=>(string)($r['actor_name']??'Former user'),'event_type'=>$r['event_type'],
          'entity_type'=>$r['entity_type'],'entity_id'=>$r['entity_id'],
          'description'=>$r['description'],'created_at'=>$r['created_at']];
    }
    // Existing admin audit and site event logs are included for actual admins;
    // manager/website_manager roles NEVER access admin-specific audit details.
    if($actualAdmin){
        sf_ops_ensure_schema();
        $rows=sf_db()->query("SELECT a.id,a.admin_user_id AS actor_id,u.display_name AS actor_name,
           a.action,a.entity_type,a.entity_id,a.created_at
           FROM admin_audit_log a LEFT JOIN users u ON u.id=a.admin_user_id
           ORDER BY a.id DESC LIMIT ".$max)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r)$events[]=['key'=>'admin:'.$r['id'],'source'=>'admin',
          'actor_id'=>(int)$r['actor_id'],'actor_name'=>(string)($r['actor_name']??'System'),
          'event_type'=>$r['action'],'entity_type'=>$r['entity_type'],
          'entity_id'=>$r['entity_id'],'description'=>$r['action'],'created_at'=>$r['created_at']];
        $rows=sf_db()->query('SELECT id,severity,event_type,message,created_at FROM site_event_log ORDER BY id DESC LIMIT '.$max)->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r)$events[]=['key'=>'site:'.$r['id'],'source'=>'site','actor_id'=>null,
          'actor_name'=>'Stonefellow','event_type'=>$r['event_type'],
          'entity_type'=>'site_event','entity_id'=>'','description'=>$r['message'],'created_at'=>$r['created_at']];
    }
    usort($events,static fn($a,$b)=>strcmp((string)$b['created_at'],(string)$a['created_at'])
          ?:strcmp((string)$b['key'],(string)$a['key']));
    return ['events'=>array_slice($events,$offset,$limit),
      'has_more'=>count($events)>$offset+$limit,'offset'=>$offset,'next_offset'=>$offset+$limit,
      'scope'=>$actualAdmin?'all_site_operations':($full?'team_activity':'own_activity'),
      'note'=>'Unified operational history from team actions, existing admin audit and site-event records. Private fan listening and confidential audit details are not exposed.'];
}
