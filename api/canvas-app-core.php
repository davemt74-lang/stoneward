<?php
declare(strict_types=1);

require_once __DIR__.'/canvas-gallery-core.php';

function sf_canvas_app_table_exists(string $name): bool {
    $db=sf_db();
    $sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
        :'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?';
    return sf_canvas_app_query($sql,[$name])->fetchColumn()!==false;
}

function sf_canvas_app_exists(): bool {
    return sf_canvas_app_table_exists('canvas_app_publications');
}

function sf_canvas_app_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();
    if($db->inTransaction())throw new LogicException('Initialize canvas apps before a transaction.');
    $mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $engine=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
    $id=$mysql?'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin':'TEXT';
    $userId=$mysql?'BIGINT UNSIGNED':'INTEGER';
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_apps (
        id $id PRIMARY KEY,gallery_id $id NOT NULL UNIQUE,user_id $userId NOT NULL,updated_at VARCHAR(40) NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_app_installations (
        app_id $id NOT NULL,user_id $userId NOT NULL,enabled INTEGER NOT NULL DEFAULT 0,
        consent_key CHAR(32) NOT NULL DEFAULT '',installed_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
        PRIMARY KEY(app_id,user_id),FOREIGN KEY(app_id) REFERENCES canvas_apps(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine");
    if(!$mysql)$db->exec('CREATE INDEX IF NOT EXISTS idx_canvas_app_installations_user ON canvas_app_installations(user_id,app_id)');
    // This final table is the readiness marker for read paths that must not create schema.
    $db->exec("CREATE TABLE IF NOT EXISTS canvas_app_publications (
        gallery_id $id PRIMARY KEY,app_id $id NOT NULL,
        FOREIGN KEY(app_id) REFERENCES canvas_apps(id) ON DELETE CASCADE)$engine");
    $ready=true;
}

function sf_canvas_app_query(string $sql,array $parameters): PDOStatement {
    $query=sf_db()->prepare($sql);
    $query->execute($parameters);
    return $query;
}

/** Matches the exact class on sanitized author markup, never script text or a partial token. */
function sf_canvas_app_supports_alerts(string $html): bool {
    preg_match_all('/<span\s+class="([A-Za-z0-9_ -]{1,160})"\s*>/',$html,$matches);
    foreach($matches[1] as $classes)
        if(in_array('sf-action-app-notifications',explode(' ',$classes),true))return true;
    return false;
}

function sf_canvas_app_publication_supports_alerts(array $gallery): bool {
    $snapshot=json_decode($gallery['snapshot_json'],true,16,JSON_THROW_ON_ERROR);
    return sf_canvas_app_supports_alerts((string)($snapshot['html']??''));
}

function sf_canvas_app_id_for_gallery(string $galleryId): string {
    if(!sf_canvas_app_exists())return $galleryId;
    $q=sf_canvas_app_query('SELECT app_id FROM canvas_app_publications WHERE gallery_id=?',[$galleryId]);
    return $q->fetchColumn()?:$galleryId;
}

function sf_canvas_app_row(string $appId): ?array {
    if(!sf_canvas_app_exists())return null;
    $q=sf_canvas_app_query('SELECT id,gallery_id,user_id FROM canvas_apps WHERE id=?',[$appId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function sf_canvas_app_available(array $gallery): bool {
    return $gallery['status']==='approved'&&sf_canvas_gallery_available($gallery['id']);
}

/** Delivery revalidates the exact current approved snapshot and its live public eligibility. */
function sf_canvas_app_current_publication(string $appId): ?array {
    $app=sf_canvas_app_row($appId);
    $gallery=$app?sf_canvas_gallery_row($app['gallery_id']):null;
    return $gallery&&sf_canvas_app_available($gallery)?$gallery:null;
}

function sf_canvas_app_installation(string $appId,int $userId): ?array {
    if($userId<1||!sf_canvas_app_exists())return null;
    $q=sf_canvas_app_query('SELECT enabled,consent_key FROM canvas_app_installations WHERE app_id=? AND user_id=?',[$appId,$userId]);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function sf_canvas_app_dto(array $app,array $gallery,?array $installation,bool $available): array {
    $supportsAlerts=$available&&sf_canvas_app_publication_supports_alerts($gallery);
    return ['id'=>$app['id'],'gallery_id'=>$available?$gallery['id']:null,'title'=>$gallery['title'],
        'installed'=>$installation!==null,'enabled'=>!empty($installation['enabled']),
        'supports_alerts'=>$supportsAlerts];
}

/** Resolve approved aliases to their latest app publication. Legacy approvals are readable without writes. */
function sf_canvas_app_target(?string $galleryId,?string $appId): array {
    if(($galleryId===null)===($appId===null))throw new InvalidArgumentException('Choose one canvas app.');
    if($galleryId!==null){
        $requested=sf_canvas_gallery_row($galleryId);
        if(!$requested||!sf_canvas_app_available($requested))throw new SfCanvasGalleryNotFound('Canvas app not found.');
        $appId=sf_canvas_app_id_for_gallery($galleryId);
    }elseif(sf_canvas_app_id_for_gallery($appId)!==$appId){
        throw new SfCanvasGalleryNotFound('Canvas app not found.');
    }
    $app=sf_canvas_app_row($appId);
    $gallery=isset($requested)&&($app===null||$app['gallery_id']===$galleryId)
        ?$requested:sf_canvas_gallery_row($app['gallery_id']??$appId);
    if(!$gallery)throw new SfCanvasGalleryNotFound('Canvas app not found.');
    $app??=['id'=>$gallery['id'],'gallery_id'=>$gallery['id'],'user_id'=>$gallery['user_id']];
    return [$app,$gallery];
}

function sf_canvas_app_get(int $userId,?string $galleryId,?string $appId): array {
    [$app,$gallery]=sf_canvas_app_target($galleryId,$appId);
    $installation=sf_canvas_app_installation($app['id'],$userId);
    $available=sf_canvas_app_available($gallery);
    if(!$available&&$installation===null)throw new SfCanvasGalleryNotFound('Canvas app not found.');
    return sf_canvas_app_dto($app,$gallery,$installation,$available);
}

function sf_canvas_app_installed(int $userId): array {
    if($userId<1||!sf_canvas_app_exists())return [];
    $q=sf_canvas_app_query('SELECT a.id,a.gallery_id,i.enabled FROM canvas_app_installations i
        JOIN canvas_apps a ON a.id=i.app_id WHERE i.user_id=? ORDER BY i.installed_at DESC,a.id',[$userId]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $items=[];
    foreach($rows as $app){
        $gallery=sf_canvas_gallery_row($app['gallery_id']);
        if($gallery)$items[]=sf_canvas_app_dto($app,$gallery,$app,sf_canvas_app_available($gallery));
    }
    return $items;
}

function sf_canvas_app_export(int $userId): array {
    if($userId<1||!sf_canvas_app_exists())return [];
    $q=sf_canvas_app_query('SELECT app_id,enabled,installed_at,updated_at FROM canvas_app_installations
        WHERE user_id=? ORDER BY installed_at,app_id',[$userId]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row)$row['enabled']=(bool)$row['enabled'];
    return $rows;
}

function sf_canvas_app_create(array $gallery,string $now): array {
    $id=$gallery['id'];
    sf_canvas_app_query('INSERT INTO canvas_apps(id,gallery_id,user_id,updated_at) VALUES(?,?,?,?)',[$id,$id,$gallery['user_id'],$now]);
    // A legacy saved version may already have more than one approved gallery listing.
    sf_canvas_app_query("INSERT INTO canvas_app_publications(gallery_id,app_id)
        SELECT g.id,? FROM canvas_gallery_entries g LEFT JOIN canvas_app_publications p ON p.gallery_id=g.id
        WHERE g.user_id=? AND g.source_scene_id=? AND g.history_epoch=? AND g.status='approved' AND p.gallery_id IS NULL",
        [$id,$gallery['user_id'],$gallery['source_scene_id'],$gallery['history_epoch']]);
    return ['id'=>$id,'gallery_id'=>$id,'user_id'=>$gallery['user_id']];
}

function sf_canvas_app_active_subscriber(int $userId,bool $lock=false): bool {
    $forUpdate=$lock&&sf_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    return sf_canvas_app_query('SELECT status FROM users WHERE id=?'.$forUpdate,[$userId])->fetchColumn()==='active';
}

function sf_canvas_app_change(int $userId,string $action,?string $galleryId,?string $appId,mixed $enabled=null,?string $expectedGalleryId=null): array {
    if($userId<1)throw new DomainException('Sign in to manage canvas apps.');
    if($action==='set_notifications'&&!is_bool($enabled))throw new InvalidArgumentException('Choose whether to receive app notifications.');
    [$target,$preflight]=sf_canvas_app_target($galleryId,$appId);
    require_once __DIR__.'/canvas-gallery-assets.php';
    sf_canvas_gallery_assets_prepare();
    $snapshot=json_decode($preflight['snapshot_json'],true,16,JSON_THROW_ON_ERROR);
    if(isset($snapshot['playlist_inputs']))sf_canvas_playlists_ensure_schema();
    sf_canvas_resource_prepare($snapshot['artist_inputs']??null);
    sf_canvas_app_schema();
    $db=sf_db();
    $db->beginTransaction();
    try{
        // The owner lock coordinates legacy identity assignment with review. The app write lock
        // coordinates preference changes with delivery and serializes SQLite writers.
        sf_agent_scene_history_lock_account($db,(int)$target['user_id']);
        sf_canvas_app_query('UPDATE canvas_apps SET updated_at=updated_at WHERE id=?',[$target['id']]);
        [$app,$gallery]=sf_canvas_app_target($galleryId,$appId);
        if(($action==='install'||($action==='set_notifications'&&$enabled))&&
            $gallery['id']!==($expectedGalleryId??$preflight['id']))
            throw new SfCanvasGalleryConflict('This canvas app was updated. Reopen it before changing your settings.');
        $available=sf_canvas_app_available($gallery);
        if(!sf_canvas_app_active_subscriber($userId,true))throw new DomainException('This account cannot manage canvas apps.');
        $installation=sf_canvas_app_installation($app['id'],$userId);
        if(!$available&&$installation===null)throw new SfCanvasGalleryNotFound('Canvas app not found.');
        $now=gmdate('c');
        if($action==='install'){
            if(!$available)throw new SfCanvasGalleryNotFound('Canvas app not found.');
            if($installation===null){
                if(sf_canvas_app_row($app['id'])===null)$app=sf_canvas_app_create($gallery,$now);
                sf_canvas_app_query('INSERT INTO canvas_app_installations(app_id,user_id,installed_at,updated_at) VALUES(?,?,?,?)',
                    [$app['id'],$userId,$now,$now]);
                $installation=['enabled'=>0];
            }
        }elseif($action==='set_notifications'){
            if($installation===null)throw new DomainException('Install this canvas app before changing notifications.');
            if($enabled&&(!$available||!sf_canvas_app_publication_supports_alerts($gallery)))
                throw new DomainException('Notifications are unavailable for this canvas app.');
            if((bool)$installation['enabled']!==$enabled){
                sf_canvas_app_query('UPDATE canvas_app_installations SET enabled=?,consent_key=?,updated_at=? WHERE app_id=? AND user_id=?',
                    [$enabled?1:0,$enabled?bin2hex(random_bytes(16)):'',$now,$app['id'],$userId]);
                $installation=['enabled'=>$enabled];
            }
        }elseif($action==='uninstall'){
            sf_canvas_app_query('DELETE FROM canvas_app_installations WHERE app_id=? AND user_id=?',[$app['id'],$userId]);
            $installation=null;
        }else throw new InvalidArgumentException('Choose a supported canvas app action.');
        $db->commit();
        return sf_canvas_app_dto($app,$gallery,$installation,$available);
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
}

/** Search owned saved ancestry; an older branch must not replace an app's newer current version. */
function sf_canvas_app_parent_publication(array $gallery): ?array {
    $uid=(int)$gallery['user_id'];
    $parent=sf_db()->prepare('SELECT previous_id FROM agent_scene_history WHERE user_id=? AND id=?');
    $approved=sf_db()->prepare("SELECT g.*,p.app_id,a.gallery_id AS current_gallery_id FROM canvas_gallery_entries g
        LEFT JOIN canvas_app_publications p ON p.gallery_id=g.id LEFT JOIN canvas_apps a ON a.id=p.app_id
        WHERE g.user_id=? AND g.source_scene_id=? AND g.history_epoch=? AND (g.status='approved' OR a.gallery_id=g.id)
        ORDER BY CASE WHEN a.gallery_id=g.id THEN 0 WHEN p.app_id IS NULL THEN 1 ELSE 2 END,g.published_at DESC,g.id DESC LIMIT 1");
    $sceneId=(int)$gallery['source_scene_id'];
    while($sceneId>0){
        $parent->execute([$uid,$sceneId]);
        $previousId=(int)$parent->fetchColumn();
        $parent->closeCursor();
        if($previousId<1||$previousId>=$sceneId)return null;
        $approved->execute([$uid,$previousId,$gallery['history_epoch']]);
        $candidate=$approved->fetch(PDO::FETCH_ASSOC);
        $approved->closeCursor();
        if($candidate)return $candidate['app_id']===null||$candidate['current_gallery_id']===$candidate['id']?$candidate:null;
        $sceneId=$previousId;
    }
    return null;
}

/** Call only within exact gallery approval, after its public state has been written. */
function sf_canvas_app_publish(array $gallery,string $now): void {
    $duplicate=sf_canvas_app_query("SELECT g.*,p.app_id FROM canvas_gallery_entries g
        LEFT JOIN canvas_app_publications p ON p.gallery_id=g.id
        WHERE g.user_id=? AND g.source_scene_id=? AND g.history_epoch=? AND g.id<>?
        AND (g.status='approved' OR p.app_id IS NOT NULL)
        ORDER BY CASE WHEN p.app_id IS NULL THEN 1 ELSE 0 END,g.published_at,g.id LIMIT 1",
        [$gallery['user_id'],$gallery['source_scene_id'],$gallery['history_epoch'],$gallery['id']])->fetch(PDO::FETCH_ASSOC);
    if($duplicate){
        if($duplicate['app_id']===null)sf_canvas_app_create($duplicate,$now);
        else sf_canvas_app_query('INSERT INTO canvas_app_publications(gallery_id,app_id) VALUES(?,?)',[$gallery['id'],$duplicate['app_id']]);
        return;
    }
    $previous=sf_canvas_app_parent_publication($gallery);
    if(!$previous){sf_canvas_app_create($gallery,$now);return;}
    $appId=$previous['app_id']??sf_canvas_app_create($previous,$now)['id'];
    sf_canvas_app_query('UPDATE canvas_apps SET gallery_id=?,updated_at=? WHERE id=?',[$gallery['id'],$now,$appId]);
    sf_canvas_app_query('INSERT INTO canvas_app_publications(gallery_id,app_id) VALUES(?,?)',[$gallery['id'],$appId]);
    if(!sf_canvas_app_publication_supports_alerts($gallery))sf_canvas_app_clear_consent($appId,$now);
    elseif(sf_canvas_app_publication_supports_alerts($previous))sf_canvas_app_alert_enqueue($appId,$gallery['id']);
}

function sf_canvas_app_clear_consent(string $appId,string $now): void {
    sf_canvas_app_query("UPDATE canvas_app_installations SET enabled=0,consent_key='',updated_at=? WHERE app_id=? AND enabled=1",
        [$now,$appId]);
}

/** An obsolete gallery alias cannot disable an app that has already advanced. */
function sf_canvas_app_unpublish(string $galleryId,string $now): void {
    if(!sf_canvas_app_exists())return;
    $app=sf_canvas_app_row(sf_canvas_app_id_for_gallery($galleryId));
    if(!$app||$app['gallery_id']!==$galleryId)return;
    sf_canvas_app_query('UPDATE canvas_apps SET updated_at=? WHERE id=?',[$now,$app['id']]);
    sf_canvas_app_clear_consent($app['id'],$now);
}

/** Owned publications are permanently removed by the existing agent-history reset. */
function sf_canvas_app_clear_owned(int $userId): int {
    if(!sf_canvas_app_exists())return 0;
    return sf_canvas_app_query('DELETE FROM canvas_apps WHERE user_id=?',[$userId])->rowCount();
}

/** Close an account without initializing optional app or delivery storage. */
function sf_canvas_app_clear_installed(int $userId): array {
    $counts=[];
    foreach(['canvas_app_alerts','canvas_app_installations'] as $table){
        if(sf_canvas_app_table_exists($table))
            $counts[$table]=sf_canvas_app_query('DELETE FROM '.$table.' WHERE user_id=?',[$userId])->rowCount();
    }
    return $counts;
}
