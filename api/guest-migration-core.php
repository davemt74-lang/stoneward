<?php
declare(strict_types=1);

/** Section 91B17O: consented, atomic import of browser-only guest music. */
function sf_guest_migration_ensure_schema(): void {
    static $done=false;
    if($done)return;
    $pdo=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_guest_imports (user_id INTEGER NOT NULL,request_id TEXT NOT NULL,playlist_count INTEGER NOT NULL DEFAULT 0,recent_count INTEGER NOT NULL DEFAULT 0,name_updated INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,PRIMARY KEY(user_id,request_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_guest_recent_signals (user_id INTEGER NOT NULL,track_id TEXT NOT NULL,ordinal INTEGER NOT NULL,imported_at TEXT NOT NULL,PRIMARY KEY(user_id,track_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_guest_imports (user_id BIGINT UNSIGNED NOT NULL,request_id VARCHAR(80) NOT NULL,playlist_count INT NOT NULL DEFAULT 0,recent_count INT NOT NULL DEFAULT 0,name_updated TINYINT(1) NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,request_id),CONSTRAINT fk_guest_import_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_guest_recent_signals (user_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(180) NOT NULL,ordinal INT NOT NULL,imported_at VARCHAR(40) NOT NULL,PRIMARY KEY(user_id,track_id),CONSTRAINT fk_guest_recent_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_guest_migration_recent_for_user(int $userId): array {
    sf_guest_migration_ensure_schema();$q=sf_db()->prepare('SELECT track_id,ordinal,imported_at FROM user_guest_recent_signals WHERE user_id=? ORDER BY ordinal ASC LIMIT 30');
    $q->execute([$userId]);return $q->fetchAll();
}
function sf_guest_migration_import(int $userId,array $request): array {
    sf_guest_migration_ensure_schema();sf_playlists_ensure_schema();sf_privacy_ensure_schema();
    $key=(string)($request['request_id']??'');
    if(!preg_match('/^[a-zA-Z0-9_-]{12,80}$/D',$key))throw new InvalidArgumentException('Invalid import request.');
    $nameRequested=($request['import_name']??false)===true;
    $name=$nameRequested?sf_clean_text($request['display_name']??'',48):'';
    if($nameRequested&&($name===''||!preg_match('/^[\p{L}\p{M}][\p{L}\p{M}\x27\x{2019}\- ]*$/u',$name)))
        throw new InvalidArgumentException('Guest name is not valid.');
    $rawPlaylists=$request['playlists']??[];
    $rawRecents=$request['recent_track_ids']??[];
    if(!is_array($rawPlaylists)||count($rawPlaylists)>20||!array_is_list($rawPlaylists)||
       !is_array($rawRecents)||count($rawRecents)>30||!array_is_list($rawRecents))
        throw new InvalidArgumentException('Import exceeds the allowed size.');
    if($rawRecents&&!sf_privacy_personalization_enabled($userId))
        throw new InvalidArgumentException('Enable personalization in Privacy settings before importing recent song preferences.');
    $catalog=sf_track_map();$validTrack=function($id)use($catalog):string{
        if(!is_string($id)||strlen($id)<1||strlen($id)>180)throw new InvalidArgumentException('Invalid song identifier in import.');
        $track=$catalog[$id]??null;
        if(!$track||!sf_track_is_public($track)||empty($track['audio']))throw new InvalidArgumentException('One of the songs is no longer public and playable.');
        return $id;
    };
    $playlists=[];
    foreach($rawPlaylists as $item){
        if(!is_array($item)||!isset($item['name'],$item['track_ids'])||!is_string($item['name'])||!is_array($item['track_ids'])||!array_is_list($item['track_ids'])||!count($item['track_ids'])||count($item['track_ids'])>20)
            throw new InvalidArgumentException('Invalid guest playlist.');
        $title=sf_clean_text($item['name'],120);if($title==='')throw new InvalidArgumentException('A playlist name is required.');
        $ids=array_map($validTrack,$item['track_ids']);
        if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Guest playlist has duplicate songs.');
        $playlists[]=['name'=>$title,'track_ids'=>$ids];
    }
    $recent=array_map($validTrack,$rawRecents);
    if(count(array_unique($recent))!==count($recent))throw new InvalidArgumentException('Recent songs contain duplicates.');
    if(!$nameRequested&&!$playlists&&!$recent)throw new InvalidArgumentException('Choose something to import.');
    $pdo=sf_db();$now=gmdate('c');
    $pdo->beginTransaction();
    try{
        $lookup=$pdo->prepare('SELECT playlist_count,recent_count,name_updated FROM user_guest_imports WHERE user_id=? AND request_id=?');
        $lookup->execute([$userId,$key]);$prior=$lookup->fetch(PDO::FETCH_ASSOC);
        if($prior){$pdo->commit();return ['request_id'=>$key,'playlist_count'=>(int)$prior['playlist_count'],'recent_count'=>(int)$prior['recent_count'],'name_updated'=>(bool)$prior['name_updated'],'already_imported'=>true];}
        if($nameRequested)$pdo->prepare('UPDATE users SET display_name=?,updated_at=? WHERE id=?')->execute([$name,$now,$userId]);
        $insert=$pdo->prepare('INSERT INTO user_playlists(user_id,name,description,visibility,source_type,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
        $add=$pdo->prepare('INSERT INTO user_playlist_tracks(playlist_id,track_id,position,added_at) VALUES(?,?,?,?)');
        foreach($playlists as $playlist){
            $insert->execute([$userId,$playlist['name'],'Imported from this browser after confirmation.','private','user',$now,$now]);
            $playlistId=(int)$pdo->lastInsertId();
            foreach($playlist['track_ids'] as $position=>$trackId)$add->execute([$playlistId,$trackId,$position,$now]);
        }
        $signal=$pdo->prepare((sf_db_config()['driver']??'')==='sqlite'
            ?'INSERT INTO user_guest_recent_signals(user_id,track_id,ordinal,imported_at) VALUES(?,?,?,?) ON CONFLICT(user_id,track_id) DO UPDATE SET ordinal=excluded.ordinal,imported_at=excluded.imported_at'
            :'INSERT INTO user_guest_recent_signals(user_id,track_id,ordinal,imported_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE ordinal=VALUES(ordinal),imported_at=VALUES(imported_at)');
        foreach($recent as $position=>$trackId)$signal->execute([$userId,$trackId,$position,$now]);
        $pdo->prepare('INSERT INTO user_guest_imports(user_id,request_id,playlist_count,recent_count,name_updated,created_at) VALUES(?,?,?,?,?,?)')
            ->execute([$userId,$key,count($playlists),count($recent),$nameRequested?1:0,$now]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // A secondary activity-log failure must never disguise a committed import.
    try{sf_privacy_log($userId,'guest_music_imported',['playlists'=>count($playlists),'recent_signals'=>count($recent),'name_updated'=>$nameRequested]);}catch(Throwable $e){}
    try{sf_log_user_activity($userId,'guest_music_imported','Imported browser guest music','account',(string)$userId,['playlists'=>count($playlists),'recent_signals'=>count($recent)]);}catch(Throwable $e){}
    return ['request_id'=>$key,'playlist_count'=>count($playlists),'recent_count'=>count($recent),'name_updated'=>$nameRequested,'already_imported'=>false];
}
