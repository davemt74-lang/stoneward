<?php
declare(strict_types=1);

/** Section 91B18: timecoded artist media/stories and moderated fan responses. */
function sf_song_moments_schema(): void {
    static $ready=false;
    if($ready)return;
    $db=sf_db();$sqlite=(string)(sf_db_config()['driver']??'')==='sqlite';
    if($sqlite){
        $db->exec("CREATE TABLE IF NOT EXISTS song_timeline_moments (
          id INTEGER PRIMARY KEY AUTOINCREMENT,track_id TEXT NOT NULL,at_ms INTEGER NOT NULL,
          kind TEXT NOT NULL,title TEXT NOT NULL DEFAULT '',body TEXT NOT NULL DEFAULT '',
          media_uuid TEXT NOT NULL DEFAULT '',story_id INTEGER NOT NULL DEFAULT 0,
          user_id INTEGER NULL,display_name TEXT NOT NULL DEFAULT '',status TEXT NOT NULL,
          created_at TEXT NOT NULL,updated_at TEXT NOT NULL,moderated_by INTEGER NULL,moderated_at TEXT NOT NULL DEFAULT '',
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_song_moments_track ON song_timeline_moments(track_id,status,at_ms,id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_song_moments_review ON song_timeline_moments(kind,status,created_at)');
        $db->exec("CREATE TABLE IF NOT EXISTS song_timeline_moderation (
          id INTEGER PRIMARY KEY AUTOINCREMENT,moment_id INTEGER NOT NULL,admin_id INTEGER NOT NULL,
          previous_status TEXT NOT NULL,new_status TEXT NOT NULL,created_at TEXT NOT NULL)");
    }else{
        $db->exec("CREATE TABLE IF NOT EXISTS song_timeline_moments (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,track_id VARCHAR(180) NOT NULL,at_ms INT UNSIGNED NOT NULL,
          kind VARCHAR(20) NOT NULL,title VARCHAR(180) NOT NULL DEFAULT '',body TEXT NOT NULL,
          media_uuid VARCHAR(80) NOT NULL DEFAULT '',story_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
          user_id BIGINT UNSIGNED NULL,display_name VARCHAR(100) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL,
          created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
          moderated_by BIGINT UNSIGNED NULL,moderated_at VARCHAR(40) NOT NULL DEFAULT '',
          INDEX idx_song_moments_track(track_id,status,at_ms,id),
          INDEX idx_song_moments_review(kind,status,created_at),
          CONSTRAINT fk_song_moment_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS song_timeline_moderation (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,moment_id BIGINT UNSIGNED NOT NULL,
          admin_id BIGINT UNSIGNED NOT NULL,previous_status VARCHAR(24) NOT NULL,
          new_status VARCHAR(24) NOT NULL,created_at VARCHAR(40) NOT NULL,
          INDEX idx_song_moderation_moment(moment_id,id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $ready=true;
}
function sf_song_moments_track(string $trackId): array {
    $trackId=trim($trackId);
    if($trackId===''||strlen($trackId)>180)throw new InvalidArgumentException('Choose a published song.');
    foreach(sf_public_catalog() as $track)if((string)($track['id']??'')===$trackId)return $track;
    throw new InvalidArgumentException('That song is not in the published catalog.');
}
function sf_song_moments_position(mixed $value,array $track): int {
    if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new InvalidArgumentException('Position must be a song timestamp.');
    $at=(int)$value;$duration=(int)($track['duration']??0);
    if($at<0||$at>86400000||($duration>0&&$at>($duration+2)*1000))throw new InvalidArgumentException('Moment is outside the song duration.');
    return $at;
}
function sf_song_moments_text(mixed $value,int $max,string $label,bool $required=false): string {
    if(!is_string($value)||strlen($value)>$max*4)throw new InvalidArgumentException($label.' is too long.');
    $clean=trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)??'');
    if(strlen($clean)>$max||($required&&strlen($clean)<3))throw new InvalidArgumentException('Enter a valid '.$label.'.');
    return $clean;
}
function sf_song_moments_public_image(string $uuid): ?array {
    if(!preg_match('/^[a-zA-Z0-9-]{8,80}$/D',$uuid))return null;
    $asset=sf_media_asset($uuid);
    if(!$asset||($asset['category']??'')!=='image')return null;
    $q=sf_db()->prepare('SELECT 1 FROM media_links WHERE asset_id=? AND public_visible=1 LIMIT 1');
    $q->execute([(int)$asset['id']]);if(!$q->fetchColumn())return null;
    return ['uuid'=>$uuid,'url'=>sf_media_managed_url($asset,'medium'),'thumb_url'=>sf_media_managed_url($asset,'thumb'),'title'=>(string)($asset['title']??'')];
}
function sf_song_moments_public_row(array $row): ?array {
    $kind=(string)$row['kind'];$entry=[
      'id'=>(int)$row['id'],'track_id'=>(string)$row['track_id'],'at_ms'=>(int)$row['at_ms'],
      'kind'=>$kind,'title'=>(string)$row['title'],'body'=>(string)$row['body'],
      'display_name'=>$kind==='comment'?(string)$row['display_name']:'Stonefellow',
      'created_at'=>(string)$row['created_at'],'story'=>null,'photo'=>null];
    if($kind==='photo'){
        $entry['photo']=sf_song_moments_public_image((string)$row['media_uuid']);
        if(!$entry['photo'])return null;
    }
    if($kind==='story'){
        $story=sf_story_get((int)$row['story_id'],true);
        if(!$story)return null;
        $entry['story']=['id'=>(int)$story['id'],'slug'=>(string)$story['slug'],'title'=>(string)$story['title'],'summary'=>(string)$story['summary']];
        if($entry['title']==='')$entry['title']=(string)$story['title'];
    }
    return $entry;
}
function sf_song_moments_public(string $trackId): array {
    sf_song_moments_track($trackId);sf_song_moments_schema();
    $q=sf_db()->prepare("SELECT * FROM song_timeline_moments WHERE track_id=? AND status='published' ORDER BY at_ms ASC,id ASC LIMIT 200");
    $q->execute([$trackId]);$out=[];
    foreach($q->fetchAll() as $row){$moment=sf_song_moments_public_row($row);if($moment)$out[]=$moment;}
    return $out;
}
function sf_song_moments_mine(string $trackId,int $userId): array {
    if($userId<1)return [];
    sf_song_moments_schema();$q=sf_db()->prepare("SELECT id,track_id,at_ms,kind,body,status,created_at FROM song_timeline_moments WHERE track_id=? AND user_id=? AND kind='comment' ORDER BY id DESC LIMIT 20");
    $q->execute([$trackId,$userId]);return $q->fetchAll();
}
function sf_song_moments_comment(int $userId,array $request): array {
    if($userId<1)throw new InvalidArgumentException('Sign in to submit a public song comment.');
    $track=sf_song_moments_track((string)($request['track_id']??''));
    $at=sf_song_moments_position($request['at_ms']??null,$track);
    $body=sf_song_moments_text($request['body']??null,800,'comment',true);
    sf_song_moments_schema();$db=sf_db();
    $limit=$db->prepare("SELECT COUNT(*) FROM song_timeline_moments WHERE user_id=? AND kind='comment' AND created_at>=?");
    $limit->execute([$userId,gmdate('c',time()-86400)]);
    if((int)$limit->fetchColumn()>=12)throw new RuntimeException('Daily comment limit reached. Come back tomorrow.');
    $pending=$db->prepare("SELECT COUNT(*) FROM song_timeline_moments WHERE user_id=? AND kind='comment' AND status='pending'");
    $pending->execute([$userId]);if((int)$pending->fetchColumn()>=8)throw new RuntimeException('You already have eight comments awaiting review.');
    $user=sf_current_user();$name=sf_song_moments_text((string)($user['display_name']??'Listener'),100,'display name');
    $now=gmdate('c');$db->prepare("INSERT INTO song_timeline_moments (track_id,at_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([(string)$track['id'],$at,'comment','',$body,'',0,$userId,$name,'pending',$now,$now]);
    return ['id'=>(int)$db->lastInsertId(),'at_ms'=>$at,'status'=>'pending','message'=>'Submitted for administrator approval. Not public yet.'];
}
function sf_song_moments_admin_refs(): array {
    $tracks=array_map(fn($t)=>['id'=>(string)$t['id'],'title'=>(string)$t['title'],'duration'=>(int)($t['duration']??0)],sf_public_catalog());
    $stories=[];foreach(sf_story_list(true,'',200) as $s)$stories[]=['id'=>(int)$s['id'],'title'=>(string)$s['title'],'entity_id'=>(string)($s['entity_id']??''),'slug'=>(string)$s['slug']];
    sf_media_ensure_schema();
    $photos=[];$q=sf_db()->query("SELECT DISTINCT a.id,a.uuid,a.title FROM media_assets a INNER JOIN media_links l ON l.asset_id=a.id AND l.public_visible=1 WHERE a.category='image' ORDER BY a.id DESC LIMIT 250");
    foreach($q->fetchAll() as $r)$photos[]=['uuid'=>(string)$r['uuid'],'title'=>(string)($r['title']?:'Photo '.$r['id']),'url'=>'api/media.php?id='.rawurlencode((string)$r['uuid'])];
    return ['tracks'=>$tracks,'stories'=>$stories,'photos'=>$photos];
}
function sf_song_moments_admin_list(string $trackId=''): array {
    sf_song_moments_schema();$sql='SELECT * FROM song_timeline_moments';$args=[];
    if($trackId!==''){$sql.=' WHERE track_id=?';$args[]=$trackId;}
    $sql.=' ORDER BY created_at DESC,id DESC LIMIT 350';$q=sf_db()->prepare($sql);$q->execute($args);
    return $q->fetchAll();
}
function sf_song_moments_metrics(): array {
    sf_song_moments_schema();$q=sf_db()->query("SELECT kind,status,COUNT(*) total FROM song_timeline_moments GROUP BY kind,status");
    $stats=['pending_comments'=>0,'published_comments'=>0,'published_artist_moments'=>0,'draft_artist_moments'=>0,'rejected_comments'=>0];
    foreach($q->fetchAll() as $r){$k=(string)$r['kind'];$s=(string)$r['status'];$n=(int)$r['total'];
      if($k==='comment'){$key=$s.'_comments';if(isset($stats[$key]))$stats[$key]+=$n;}
      elseif($s==='published')$stats['published_artist_moments']+=$n;
      elseif($s==='draft')$stats['draft_artist_moments']+=$n;
    }return $stats;
}
function sf_song_moments_admin_save(int $adminId,array $request): array {
    $id=(int)($request['id']??0);$kind=(string)($request['kind']??'note');
    if(!in_array($kind,['note','photo','story'],true))throw new InvalidArgumentException('Artist timeline entries may be notes, photos, or stories.');
    $track=sf_song_moments_track((string)($request['track_id']??''));
    $at=sf_song_moments_position($request['at_ms']??null,$track);
    $title=sf_song_moments_text($request['title']??'',180,'title');
    $body=sf_song_moments_text($request['body']??'',1600,'description');
    $status=(string)($request['status']??'draft');if(!in_array($status,['draft','published'],true))throw new InvalidArgumentException('Invalid publishing state.');
    if($status==='published'&&empty($request['confirmed']))throw new InvalidArgumentException('Confirm publishing this timeline entry.');
    $uuid='';$storyId=0;
    if($kind==='photo'){
        $uuid=sf_song_moments_text($request['media_uuid']??'',80,'photo identifier',true);
        if(!sf_song_moments_public_image($uuid))throw new InvalidArgumentException('Select an existing public Media Library image.');
    }elseif($kind==='story'){
        $storyId=(int)($request['story_id']??0);
        if($storyId<1||!sf_story_get($storyId,true))throw new InvalidArgumentException('Choose a published story.');
    }
    if($kind==='note'&&$title===''&&$body==='')throw new InvalidArgumentException('Add a title or note.');
    sf_song_moments_schema();$db=sf_db();$now=gmdate('c');
    if($id>0){
        $q=$db->prepare("SELECT kind FROM song_timeline_moments WHERE id=?");$q->execute([$id]);$existing=$q->fetchColumn();
        if($existing===false||$existing==='comment')throw new InvalidArgumentException('Artist timeline entry not found.');
        $db->prepare('UPDATE song_timeline_moments SET track_id=?,at_ms=?,kind=?,title=?,body=?,media_uuid=?,story_id=?,status=?,updated_at=?,moderated_by=?,moderated_at=? WHERE id=?')
          ->execute([(string)$track['id'],$at,$kind,$title,$body,$uuid,$storyId,$status,$now,$adminId,$now,$id]);
    }else{
        $db->prepare('INSERT INTO song_timeline_moments (track_id,at_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at,moderated_by,moderated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([(string)$track['id'],$at,$kind,$title,$body,$uuid,$storyId,null,'Stonefellow',$status,$now,$now,$adminId,$now]);
        $id=(int)$db->lastInsertId();
    }
    return ['id'=>$id,'status'=>$status];
}
function sf_song_moments_admin_moderate(int $adminId,int $id,string $action): array {
    if($id<1||!in_array($action,['approve','reject','remove'],true))throw new InvalidArgumentException('Invalid moderation action.');
    sf_song_moments_schema();$db=sf_db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT kind,status FROM song_timeline_moments WHERE id=?');$q->execute([$id]);$row=$q->fetch();
        if(!$row)throw new InvalidArgumentException('Moment not found.');
        if($action!=='remove'&&($row['kind']!=='comment'||$row['status']!=='pending'))throw new InvalidArgumentException('Only pending comments may be approved or rejected.');
        $next=['approve'=>'published','reject'=>'rejected','remove'=>'removed'][$action];
        if($action==='remove'&&$row['status']==='removed')throw new InvalidArgumentException('Moment is already removed.');
        $now=gmdate('c');$db->prepare('UPDATE song_timeline_moments SET status=?,updated_at=?,moderated_by=?,moderated_at=? WHERE id=?')->execute([$next,$now,$adminId,$now,$id]);
        $db->prepare('INSERT INTO song_timeline_moderation(moment_id,admin_id,previous_status,new_status,created_at) VALUES (?,?,?,?,?)')
          ->execute([$id,$adminId,(string)$row['status'],$next,$now]);
        $db->commit();return ['id'=>$id,'from'=>(string)$row['status'],'status'=>$next];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
