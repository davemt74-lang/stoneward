<?php
declare(strict_types=1);

final class SFSongMomentConflict extends RuntimeException {}

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
    // Additive upgrade for deployed 91B18 tables. Never rewrite old migrations.
    $columnQuery=$db->query($sqlite?'PRAGMA table_info(song_timeline_moments)':'SHOW COLUMNS FROM song_timeline_moments');
    $columns=array_column($columnQuery->fetchAll(PDO::FETCH_ASSOC),$sqlite?'name':'Field');$columnQuery->closeCursor();
    $additions=[
      'end_ms'=>$sqlite?'INTEGER NOT NULL DEFAULT 0':'INT UNSIGNED NOT NULL DEFAULT 0',
      'lyric_state'=>$sqlite?"TEXT NOT NULL DEFAULT 'unverified'":"VARCHAR(20) NOT NULL DEFAULT 'unverified'",
      'confidence'=>$sqlite?'REAL NULL':'DOUBLE NULL',
      'timing_source'=>$sqlite?"TEXT NOT NULL DEFAULT 'manual'":"VARCHAR(24) NOT NULL DEFAULT 'manual'",
      'source_annotation_id'=>$sqlite?'INTEGER NOT NULL DEFAULT 0':'BIGINT UNSIGNED NOT NULL DEFAULT 0',
      'source_fingerprint'=>$sqlite?"TEXT NOT NULL DEFAULT ''":"CHAR(64) NOT NULL DEFAULT ''",
      'revision'=>$sqlite?'INTEGER NOT NULL DEFAULT 1':'INT UNSIGNED NOT NULL DEFAULT 1'];
    foreach($additions as $name=>$definition)if(!in_array($name,$columns,true)){
        try{$db->exec('ALTER TABLE song_timeline_moments ADD COLUMN '.$name.' '.$definition);}
        catch(PDOException $e){
            // Two workers may initialize a deployed table simultaneously.
            // Accept only a column another worker has actually installed.
            $retry=$db->query($sqlite?'PRAGMA table_info(song_timeline_moments)':'SHOW COLUMNS FROM song_timeline_moments');
            $installed=array_column($retry->fetchAll(PDO::FETCH_ASSOC),$sqlite?'name':'Field');$retry->closeCursor();
            if(!in_array($name,$installed,true))throw $e;
        }
    }
    $db->exec($sqlite?"CREATE TABLE IF NOT EXISTS song_timeline_lyric_imports (
      track_id TEXT NOT NULL,source_fingerprint TEXT NOT NULL,source_key TEXT NOT NULL,moment_id INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,
      PRIMARY KEY(track_id,source_fingerprint,source_key))":"CREATE TABLE IF NOT EXISTS song_timeline_lyric_imports (
      track_id VARCHAR(180) NOT NULL,source_fingerprint CHAR(64) NOT NULL,source_key VARCHAR(100) NOT NULL,
      moment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at VARCHAR(40) NOT NULL,
      PRIMARY KEY(track_id,source_fingerprint,source_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
/** Audio identity is shared with the catalog transcription path; no file path leaves the server. */
function sf_song_moments_audio_fingerprint(array $track,bool $current=false): string {
    $releaseId=(string)($track['release_id']??'');
    if($releaseId!==''&&function_exists('sf_seo_public_release')&&!sf_seo_public_release($releaseId))return '';
    $reference=(string)($track['audio']??'');
    $managed='/\Aapi\/media\.php\?id=(M[A-F0-9]{20})(?:&variant=original)?\z/';
    if(!preg_match($managed,$reference,$match))return '';
    if(preg_match($managed,(string)($track['private_master_audio']??''),$master)&&$match[1]===$master[1])return '';
    $asset=sf_media_asset($match[1],$current);
    if(!$asset||($asset['category']??'')!=='audio'||!preg_match('/\A[a-f0-9]{64}\z/',(string)($asset['sha256']??''))
        ||(function_exists('sf_media_protected_master_asset')&&sf_media_protected_master_asset((int)$asset['id'],$current)))return '';
    // The public timeline must not disclose text for an asset whose only links
    // are private or members-only, even if stale catalog metadata still names it.
    $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE asset_id=? AND public_visible=1 AND entity_type<>'member_content' LIMIT 1".($current?sf_media_current_read():''));
    $q->execute([(int)$asset['id']]);$visible=(bool)$q->fetchColumn();$q->closeCursor();if(!$visible)return '';
    return hash('sha256',json_encode([$match[1],$reference,(string)$asset['sha256']],JSON_THROW_ON_ERROR));
}
function sf_song_moments_lyric_duration_ms(array $track): int {
    $reference=(string)($track['audio']??'');
    if(preg_match('/\Aapi\/media\.php\?id=(M[A-F0-9]{20})(?:&variant=original)?\z/',$reference,$match)){
        $asset=sf_media_asset($match[1]);$seconds=(float)($asset['duration_seconds']??0);
        if(($asset['category']??'')==='audio'&&is_finite($seconds)&&$seconds>0)return (int)round($seconds*1000);
    }
    return max(0,(int)round((float)($track['duration']??0)*1000));
}
function sf_song_moments_lyric_range(mixed $start,mixed $end,array $track,?int $verifiedDurationMs=null): array {
    foreach([$start,$end] as $value)if(!is_int($value)&&!(is_string($value)&&ctype_digit($value)))throw new InvalidArgumentException('Lyrics need valid start and end timestamps.');
    $start=(int)$start;$end=(int)$end;$duration=$verifiedDurationMs??sf_song_moments_lyric_duration_ms($track);
    if($start<0||$end<=$start||$end>86400000||($duration>0&&$end>$duration))throw new InvalidArgumentException('Lyric range is outside the song duration.');
    return [$start,$end];
}
function sf_song_moments_lyric_fields(array $request,array $track,?int $verifiedDurationMs=null): array {
    [$start,$end]=sf_song_moments_lyric_range($request['at_ms']??$request['start_ms']??null,$request['end_ms']??null,$track,$verifiedDurationMs);
    $body=sf_song_moments_text($request['body']??$request['text']??'',4200,'lyric text');
    if($body==='')throw new InvalidArgumentException('Enter lyric text.');
    $state=$request['lyric_state']??'unverified';if(!in_array($state,['unverified','corrected','approved'],true))throw new InvalidArgumentException('Invalid lyric review state.');
    $timing=$request['timing_source']??'manual';if(!in_array($timing,['whole_clip_estimate','catalog_chunk_estimate','manual','provider'],true))throw new InvalidArgumentException('Invalid lyric timing source.');
    $confidence=$request['confidence']??null;
    if($confidence!==null&&(!is_int($confidence)&&!is_float($confidence)||!is_finite((float)$confidence)||(float)$confidence<0||(float)$confidence>1))throw new InvalidArgumentException('Lyric confidence must be between zero and one, or unknown.');
    return ['at_ms'=>$start,'end_ms'=>$end,'body'=>$body,'lyric_state'=>$state,'timing_source'=>$timing,'confidence'=>$confidence===null?null:(float)$confidence];
}
function sf_song_moments_lyric_segment(array $row): array {
    return ['id'=>(int)$row['id'],'track_id'=>(string)$row['track_id'],'at_ms'=>(int)$row['at_ms'],
      'start_ms'=>(int)$row['at_ms'],'end_ms'=>(int)$row['end_ms'],'kind'=>'lyric',
      'body'=>(string)$row['body'],'text'=>(string)$row['body'],'lyric_state'=>(string)$row['lyric_state'],
      'status'=>(string)$row['status'],'timing_source'=>(string)$row['timing_source'],
      'confidence'=>$row['confidence']===null?null:(float)$row['confidence'],'revision'=>(int)$row['revision']];
}
/** Worker-only ingestion. Receipt identities survive moderation/removal and never overwrite reviewed lyrics. */
function sf_song_moments_ingest_lyrics(string $trackId,string $fingerprint,array $segments,?int $verifiedDurationMs=null): array {
    $track=sf_song_moments_track($trackId);$current=sf_song_moments_audio_fingerprint($track);
    if(!preg_match('/\A[a-f0-9]{64}\z/',$fingerprint)||$current===''||!hash_equals($current,$fingerprint))throw new RuntimeException('The song audio changed before its lyrics could be saved.');
    if(!$segments||count($segments)>700)throw new InvalidArgumentException('Provide between one and 700 lyric segments.');
    if($verifiedDurationMs!==null&&($verifiedDurationMs<1000||$verifiedDurationMs>900000))throw new InvalidArgumentException('Invalid verified lyric import duration.');
    $valid=[];$keys=[];
    foreach($segments as $segment){
        if(!is_array($segment))throw new InvalidArgumentException('Invalid lyric segment.');
        $key=$segment['source_key']??'';
        if(!is_string($key)||!preg_match('/\A[A-Za-z0-9:._-]{1,100}\z/',$key)||isset($keys[$key]))throw new InvalidArgumentException('Each lyric segment needs a unique import identity.');
        $keys[$key]=true;$fields=sf_song_moments_lyric_fields(array_replace($segment,['lyric_state'=>'unverified','timing_source'=>$segment['timing_source']??'catalog_chunk_estimate']),$track,$verifiedDurationMs);
        $valid[]=$fields+['source_key'=>$key];
    }
    sf_song_moments_schema();$db=sf_db();$created=0;$result=[];$now=gmdate('c');$db->beginTransaction();
    try{
        foreach($valid as $segment){
            $fresh=true;
            try{$db->prepare('INSERT INTO song_timeline_lyric_imports(track_id,source_fingerprint,source_key,moment_id,created_at) VALUES(?,?,?,?,?)')->execute([$trackId,$fingerprint,$segment['source_key'],0,$now]);}
            catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;$fresh=false;}
            if($fresh){
                $db->prepare('INSERT INTO song_timeline_moments(track_id,at_ms,end_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at,lyric_state,confidence,timing_source,source_fingerprint) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                  ->execute([$trackId,$segment['at_ms'],$segment['end_ms'],'lyric','',$segment['body'],'',0,null,'Stonefellow','draft',$now,$now,'unverified',$segment['confidence'],$segment['timing_source'],$fingerprint]);
                $id=(int)$db->lastInsertId();$created++;
                $db->prepare('UPDATE song_timeline_lyric_imports SET moment_id=? WHERE track_id=? AND source_fingerprint=? AND source_key=?')->execute([$id,$trackId,$fingerprint,$segment['source_key']]);
            }else{
                $q=$db->prepare('SELECT moment_id FROM song_timeline_lyric_imports WHERE track_id=? AND source_fingerprint=? AND source_key=?');$q->execute([$trackId,$fingerprint,$segment['source_key']]);$id=(int)$q->fetchColumn();$q->closeCursor();
            }
            $q=$db->prepare("SELECT * FROM song_timeline_moments WHERE id=? AND kind='lyric' AND source_annotation_id=0 AND status<>'removed'");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($row)$result[]=sf_song_moments_lyric_segment($row);
        }
        $db->commit();return ['created'=>$created,'segments'=>$result];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_song_moments_lyric_available(string $trackId,bool $admin=false): int {
    $track=sf_song_moments_track($trackId);$fingerprint=sf_song_moments_audio_fingerprint($track);if($fingerprint==='')return 0;
    sf_song_moments_schema();$scope=$admin?"status IN ('draft','published')":"status='published' AND lyric_state='approved'";
    $q=sf_db()->prepare("SELECT COUNT(*) FROM song_timeline_moments WHERE track_id=? AND source_fingerprint=? AND source_annotation_id=0 AND kind='lyric' AND ".$scope);$q->execute([$trackId,$fingerprint]);$count=(int)$q->fetchColumn();$q->closeCursor();return $count;
}
/** Read stored text only. This function cannot call a provider or debit a listener. */
function sf_song_moments_lyric_section(string $trackId,int $startMs,int $endMs,bool $admin=false): array {
    $track=sf_song_moments_track($trackId);[$startMs,$endMs]=sf_song_moments_lyric_range($startMs,$endMs,$track);
    $fingerprint=sf_song_moments_audio_fingerprint($track);sf_song_moments_schema();
    $scope=$admin?"status IN ('draft','published')":"status='published' AND lyric_state='approved'";
    $q=sf_db()->prepare("SELECT * FROM song_timeline_moments WHERE track_id=? AND source_fingerprint=? AND source_fingerprint<>'' AND source_annotation_id=0 AND kind='lyric' AND at_ms<? AND end_ms>? AND ".$scope.' ORDER BY at_ms,id LIMIT 700');
    $q->execute([$trackId,$fingerprint,$endMs,$startMs]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$segments=[];$texts=[];$review='approved';
    foreach($rows as $row){$segments[]=sf_song_moments_lyric_segment($row);$texts[]=(string)$row['body'];if($row['lyric_state']!=='approved')$review='unverified';}
    if(count($segments)>100)throw new InvalidArgumentException('Choose a shorter lyric section.');
    $text=implode("\n",$texts);if(strlen($text)>4200)throw new InvalidArgumentException('Choose a shorter lyric section.');
    return ['track_id'=>$trackId,'source_fingerprint'=>$fingerprint,'start_ms'=>$startMs,'end_ms'=>$endMs,'segments'=>$segments,'text'=>$text,'review_status'=>$segments?$review:'unavailable','timing'=>'saved_segments'];
}
function sf_song_moments_public_image(string $uuid): ?array {
    if(!preg_match('/^[a-zA-Z0-9-]{8,80}$/D',$uuid))return null;
    $asset=sf_media_asset($uuid);
    if(!$asset||($asset['category']??'')!=='image'||sf_media_protected_master_asset((int)$asset['id']))return null;
    $q=sf_db()->prepare("SELECT 1 FROM media_links WHERE asset_id=? AND public_visible=1 AND entity_type<>'member_content' LIMIT 1");
    $q->execute([(int)$asset['id']]);$visible=(bool)$q->fetchColumn();$q->closeCursor();if(!$visible)return null;
    return ['uuid'=>$uuid,'url'=>sf_media_managed_url($asset,'medium'),'thumb_url'=>sf_media_managed_url($asset,'thumb'),
      'title'=>(string)($asset['title']??''),'caption'=>(string)($asset['caption']??''),'alt'=>(string)($asset['alt_text']??'')];
}
function sf_song_moments_public_row(array $row): ?array {
    $kind=(string)$row['kind'];$entry=[
      'id'=>(int)$row['id'],'track_id'=>(string)$row['track_id'],'at_ms'=>(int)$row['at_ms'],
      'kind'=>$kind,'title'=>(string)$row['title'],'body'=>(string)$row['body'],
      'display_name'=>$kind==='comment'?(string)$row['display_name']:'Stonefellow',
      'created_at'=>(string)$row['created_at'],'story'=>null,'photo'=>null,'orb_eligible'=>false];
    $published=($row['status']??'')==='published'&&(int)($row['source_annotation_id']??0)===0;
    if($kind==='photo'){
        $entry['photo']=sf_song_moments_public_image((string)$row['media_uuid']);
        if(!$entry['photo'])return null;
        $entry['orb_eligible']=$published;
    }
    if($kind==='story'){
        $story=sf_story_get((int)$row['story_id'],true);
        if(!$story)return null;
        $entry['story']=['id'=>(int)$story['id'],'slug'=>(string)$story['slug'],'title'=>(string)$story['title'],'summary'=>(string)$story['summary']];
        if($entry['title']==='')$entry['title']=(string)$story['title'];
        $entry['orb_eligible']=$published&&(($story['entity_type']??'')!=='track'||(string)$story['entity_id']===$entry['track_id']);
    }
    if($kind==='comment')$entry['orb_eligible']=$published&&(int)($row['moderated_by']??0)>0&&trim((string)($row['moderated_at']??''))!=='';
    if($kind==='lyric'){
        if(($row['status']??'')!=='published'||($row['lyric_state']??'')!=='approved'||(int)($row['source_annotation_id']??0)>0)return null;
        foreach(['end_ms','revision'] as $field)$entry[$field]=(int)($row[$field]??0);
        $entry['lyric_state']=(string)$row['lyric_state'];$entry['status']='published';
        $entry['timing_source']=(string)$row['timing_source'];$entry['source_annotation_id']=0;
        $entry['confidence']=$row['confidence']===null?null:(float)$row['confidence'];
    }
    return $entry;
}
function sf_song_moments_public(string $trackId): array {
    $track=sf_song_moments_track($trackId);sf_song_moments_schema();
    $q=sf_db()->prepare("SELECT * FROM song_timeline_moments WHERE track_id=? AND status='published' AND source_annotation_id=0 AND (kind<>'lyric' OR lyric_state='approved') ORDER BY at_ms ASC,id ASC LIMIT 700");
    $q->execute([$trackId]);$rows=$q->fetchAll();$q->closeCursor();$out=[];$fingerprint=null;
    foreach($rows as $row){
        if((string)$row['track_id']!==(string)$track['id'])continue;
        if($row['kind']==='lyric'){
            $fingerprint??=sf_song_moments_audio_fingerprint($track);
            if($fingerprint===''||!hash_equals($fingerprint,(string)$row['source_fingerprint']))continue;
        }
        $moment=sf_song_moments_public_row($row);if($moment)$out[]=$moment;
    }
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
    $tracks=array_map(static function(array $track): array {
        return ['id'=>(string)$track['id'],'title'=>(string)$track['title'],'duration'=>(int)($track['duration']??0),
          'duration_ms'=>sf_song_moments_lyric_duration_ms($track),
          'preview_url'=>sf_song_moments_audio_fingerprint($track)!==''?(string)$track['audio']:null];
    },sf_public_catalog());
    $stories=[];foreach(sf_story_list(true,'',200) as $s)$stories[]=['id'=>(int)$s['id'],'title'=>(string)$s['title'],
      'entity_type'=>(string)($s['entity_type']??''),'entity_id'=>(string)($s['entity_id']??''),'slug'=>(string)$s['slug']];
    sf_media_ensure_schema();
    $photos=[];$q=sf_db()->query("SELECT DISTINCT a.id,a.uuid,a.title FROM media_assets a
      INNER JOIN media_links l ON l.asset_id=a.id AND l.public_visible=1 AND l.entity_type<>'member_content'
      WHERE a.category='image' AND NOT EXISTS (SELECT 1 FROM media_links p WHERE p.asset_id=a.id AND p.role IN ('master_audio','stem_audio'))
      ORDER BY a.id DESC LIMIT 250");
    foreach($q->fetchAll() as $r)$photos[]=['uuid'=>(string)$r['uuid'],'title'=>(string)($r['title']?:'Photo '.$r['id']),
      'cue_title'=>(string)$r['title'],'url'=>'api/media.php?id='.rawurlencode((string)$r['uuid'])];
    return ['tracks'=>$tracks,'stories'=>$stories,'photos'=>$photos];
}
function sf_song_moments_admin_list(string $trackId=''): array {
    sf_song_moments_schema();$sql="SELECT * FROM song_timeline_moments WHERE source_annotation_id=0 AND status<>'private'";$args=[];
    if($trackId!==''){$sql.=' AND track_id=?';$args[]=$trackId;}
    $sql.=' ORDER BY created_at DESC,id DESC LIMIT 1000';$q=sf_db()->prepare($sql);$q->execute($args);
    $rows=$q->fetchAll();$q->closeCursor();return $rows;
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
    if($adminId<1)throw new InvalidArgumentException('Administrator required.');
    if(!in_array($kind,['note','photo','story','lyric'],true))throw new InvalidArgumentException('Artist timeline entries may be notes, photos, stories, or lyrics.');
    $track=sf_song_moments_track((string)($request['track_id']??''));
    $at=$kind==='lyric'?0:sf_song_moments_position($request['at_ms']??null,$track);
    $title=sf_song_moments_text($request['title']??'',180,'title');
    $body=sf_song_moments_text($request['body']??'',$kind==='lyric'?4200:1600,'description');
    $status=(string)($request['status']??'draft');if(!in_array($status,['draft','published'],true))throw new InvalidArgumentException('Invalid publishing state.');
    if($status==='published'&&empty($request['confirmed']))throw new InvalidArgumentException('Confirm publishing this timeline entry.');
    $uuid='';$storyId=0;$lyric=['end_ms'=>0,'lyric_state'=>'unverified','confidence'=>null,'timing_source'=>'manual'];$fingerprint='';
    if($kind==='photo'){
        $uuid=sf_song_moments_text($request['media_uuid']??'',80,'photo identifier',true);
        if(!sf_song_moments_public_image($uuid))throw new InvalidArgumentException('Select an existing public Media Library image.');
    }elseif($kind==='story'){
        $storyId=(int)($request['story_id']??0);
        if($storyId<1||!sf_story_get($storyId,true))throw new InvalidArgumentException('Choose a published story.');
    }elseif($kind==='lyric'){
        $lyric=sf_song_moments_lyric_fields($request,$track);$body=$lyric['body'];$at=$lyric['at_ms'];
        if($status==='published'&&$lyric['lyric_state']!=='approved')throw new InvalidArgumentException('Approve reviewed lyrics before publishing them.');
        if($id<1&&$lyric['timing_source']!=='manual')throw new InvalidArgumentException('New administrator lyric ranges must be marked as manually timed.');
        $fingerprint=sf_song_moments_audio_fingerprint($track);
        if($fingerprint==='')throw new InvalidArgumentException('Lyrics require a managed published listening copy.');
    }
    if($kind==='note'&&$title===''&&$body==='')throw new InvalidArgumentException('Add a title or note.');
    sf_song_moments_schema();$db=sf_db();$now=gmdate('c');$revision=1;
    if($id>0){
        $q=$db->prepare('SELECT kind,track_id,at_ms,end_ms,timing_source,source_annotation_id,status,revision,source_fingerprint FROM song_timeline_moments WHERE id=?');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$existing||$existing['kind']==='comment'||(int)$existing['source_annotation_id']>0||$existing['status']==='private')throw new InvalidArgumentException('Artist timeline entry not found.');
        $isLyric=$kind==='lyric'||$existing['kind']==='lyric';
        if($isLyric){
            $expected=$request['revision']??null;
            if(!is_int($expected)&&!(is_string($expected)&&ctype_digit($expected)))throw new InvalidArgumentException('Reload the lyric before editing it.');
            if((int)$expected!==(int)$existing['revision'])throw new SFSongMomentConflict('This lyric was changed elsewhere. Reload before saving.');
            // A republished song must be reviewed against its new audio, never silently rebound by a stale form.
            if($existing['kind']==='lyric'&&$kind==='lyric'&&!hash_equals((string)$existing['source_fingerprint'],$fingerprint))throw new SFSongMomentConflict('The song audio changed. Remove this old lyric and create one for the current listening copy.');
            if($kind==='lyric'&&$lyric['timing_source']!=='manual'&&($existing['kind']!=='lyric'||$existing['track_id']!==$track['id']
                ||$existing['timing_source']!==$lyric['timing_source']||(int)$existing['at_ms']!==$at||(int)$existing['end_ms']!==$lyric['end_ms']))
                throw new InvalidArgumentException('Mark changed lyric timestamps as manually timed.');
        }
        $q=$db->prepare('UPDATE song_timeline_moments SET track_id=?,at_ms=?,kind=?,title=?,body=?,media_uuid=?,story_id=?,status=?,updated_at=?,moderated_by=?,moderated_at=?,end_ms=?,lyric_state=?,confidence=?,timing_source=?,source_fingerprint=?,revision=revision+1 WHERE id=? AND revision=?');
        $q->execute([(string)$track['id'],$at,$kind,$title,$body,$uuid,$storyId,$status,$now,$adminId,$now,$lyric['end_ms'],$lyric['lyric_state'],$lyric['confidence'],$lyric['timing_source'],$fingerprint,$id,(int)$existing['revision']]);
        if($q->rowCount()!==1)throw new SFSongMomentConflict('This timeline entry was changed elsewhere. Reload before saving.');
        $revision=(int)$existing['revision']+1;
    }else{
        $db->prepare('INSERT INTO song_timeline_moments (track_id,at_ms,kind,title,body,media_uuid,story_id,user_id,display_name,status,created_at,updated_at,moderated_by,moderated_at,end_ms,lyric_state,confidence,timing_source,source_fingerprint,revision) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([(string)$track['id'],$at,$kind,$title,$body,$uuid,$storyId,null,'Stonefellow',$status,$now,$now,$adminId,$now,$lyric['end_ms'],$lyric['lyric_state'],$lyric['confidence'],$lyric['timing_source'],$fingerprint,1]);
        $id=(int)$db->lastInsertId();
    }
    return ['id'=>$id,'status'=>$status,'revision'=>$revision,'lyric_state'=>$lyric['lyric_state']];
}
function sf_song_moments_admin_moderate(int $adminId,int $id,string $action): array {
    if($id<1||!in_array($action,['approve','reject','remove'],true))throw new InvalidArgumentException('Invalid moderation action.');
    sf_song_moments_schema();$db=sf_db();$db->beginTransaction();
    try{
        $q=$db->prepare('SELECT kind,status,source_annotation_id,revision FROM song_timeline_moments WHERE id=?');$q->execute([$id]);$row=$q->fetch();$q->closeCursor();
        if(!$row||(int)$row['source_annotation_id']>0||$row['status']==='private')throw new InvalidArgumentException('Moment not found.');
        if($action!=='remove'&&($row['kind']!=='comment'||$row['status']!=='pending'))throw new InvalidArgumentException('Only pending comments may be approved or rejected.');
        $next=['approve'=>'published','reject'=>'rejected','remove'=>'removed'][$action];
        if($action==='remove'&&$row['status']==='removed')throw new InvalidArgumentException('Moment is already removed.');
        $now=gmdate('c');$db->prepare('UPDATE song_timeline_moments SET status=?,updated_at=?,moderated_by=?,moderated_at=?,revision=revision+1 WHERE id=?')->execute([$next,$now,$adminId,$now,$id]);
        $db->prepare('INSERT INTO song_timeline_moderation(moment_id,admin_id,previous_status,new_status,created_at) VALUES (?,?,?,?,?)')
          ->execute([$id,$adminId,(string)$row['status'],$next,$now]);
        $db->commit();return ['id'=>$id,'from'=>(string)$row['status'],'status'=>$next];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
