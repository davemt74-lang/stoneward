<?php
declare(strict_types=1);

function sf_video_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '[]'; }
function sf_video_decode(mixed $v,array $fallback=[]): array { $d=is_array($v)?$v:json_decode((string)$v,true);return is_array($d)?$d:$fallback; }
function sf_video_slug(string $v,string $fallback='video'): string { $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';$v=trim(substr($v,0,140),'-');return $v!==''?$v:$fallback; }
function sf_video_kinds(): array { return ['music_video','live_session','visualizer','behind_scenes','interview','short_film','other']; }

function sf_video_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
      $pdo->exec("CREATE TABLE IF NOT EXISTS video_features (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,kind TEXT NOT NULL DEFAULT 'music_video',status TEXT NOT NULL DEFAULT 'draft',description TEXT NOT NULL DEFAULT '',track_id TEXT NOT NULL DEFAULT '',release_id TEXT NOT NULL DEFAULT '',show_id TEXT NOT NULL DEFAULT '',featured INTEGER NOT NULL DEFAULT 0,sort_order INTEGER NOT NULL DEFAULT 0,chapters_json TEXT NOT NULL DEFAULT '[]',caption_language TEXT NOT NULL DEFAULT 'en',notes TEXT NOT NULL DEFAULT '',created_by INTEGER NULL,published_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_features_public ON video_features(status,featured,sort_order,updated_at)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS video_events (id INTEGER PRIMARY KEY AUTOINCREMENT,video_id INTEGER NOT NULL,event_type TEXT NOT NULL,user_id INTEGER NULL,detail_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(video_id) REFERENCES video_features(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_video_events_video ON video_events(video_id,event_type,created_at)");
    }else{
      $pdo->exec("CREATE TABLE IF NOT EXISTS video_features (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(160) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,kind VARCHAR(40) NOT NULL DEFAULT 'music_video',status VARCHAR(32) NOT NULL DEFAULT 'draft',description TEXT NOT NULL,track_id VARCHAR(160) NOT NULL DEFAULT '',release_id VARCHAR(160) NOT NULL DEFAULT '',show_id VARCHAR(160) NOT NULL DEFAULT '',featured TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,chapters_json LONGTEXT NOT NULL,caption_language VARCHAR(24) NOT NULL DEFAULT 'en',notes LONGTEXT NOT NULL,created_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_video_features_public(status,featured,sort_order,updated_at),CONSTRAINT fk_video_feature_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      $pdo->exec("CREATE TABLE IF NOT EXISTS video_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,video_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(40) NOT NULL,user_id BIGINT UNSIGNED NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_video_events_video(video_id,event_type,created_at),CONSTRAINT fk_video_event_feature FOREIGN KEY(video_id) REFERENCES video_features(id) ON DELETE CASCADE,CONSTRAINT fk_video_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }$done=true;
}
function sf_video_clean_chapters(array $rows,float $duration=0): array {
    $out=[];$seen=[];foreach($rows as $r){if(!is_array($r))continue;$at=max(0,round((float)($r['seconds']??0),3));$title=sf_clean_text($r['title']??'',160);if($title===''||isset($seen[(string)$at]))continue;if($duration>0&&$at>=$duration)continue;$seen[(string)$at]=true;$out[]=['seconds'=>$at,'title'=>$title];if(count($out)>=100)break;}usort($out,fn($a,$b)=>$a['seconds']<=>$b['seconds']);return $out;
}
function sf_video_media(int $id,bool $publicOnly=false): array {
    if(!function_exists('sf_media_links'))return [];$rows=sf_media_links('video_feature',(string)$id);$out=[];
    foreach($rows as $r){if($publicOnly&&empty($r['public_visible']))continue;$asset=sf_media_asset((int)$r['asset_id']);if(!$asset)continue;$out[]=[
      'link_id'=>(int)$r['id'],'asset_id'=>(int)$r['asset_id'],'uuid'=>(string)$r['uuid'],'category'=>(string)$r['category'],'mime_type'=>(string)$r['mime_type'],'extension'=>(string)$r['extension'],'role'=>(string)$r['role'],'title'=>(string)$r['title'],'caption'=>(string)$r['caption'],'alt_text'=>(string)$r['alt_text'],'credit'=>(string)$r['credit'],'featured'=>!empty($r['featured']),'public_visible'=>!empty($r['public_visible']),'download_allowed'=>!empty($r['download_allowed']),'duration'=>(float)$r['duration_seconds'],'width'=>(int)$r['width'],'height'=>(int)$r['height'],'url'=>sf_media_managed_url($asset)
    ];}
    return $out;
}
function sf_video_entity_refs(array $row): array {
    $refs=[];$trackId=(string)($row['track_id']??'');$releaseId=(string)($row['release_id']??'');$showId=(string)($row['show_id']??'');
    if($trackId!==''){$t=function_exists('sf_seo_public_track')?sf_seo_public_track($trackId):(sf_track_map()[$trackId]??null);if($t)$refs[]=['type'=>'track','id'=>$trackId,'title'=>(string)($t['title']??$trackId)];}
    if($releaseId!==''){$r=function_exists('sf_seo_public_release')?sf_seo_public_release($releaseId):null;if($r)$refs[]=['type'=>'release','id'=>$releaseId,'title'=>(string)($r['title']??$releaseId)];}
    if($showId!==''){$s=function_exists('sf_seo_public_show')?sf_seo_public_show($showId):null;if($s)$refs[]=['type'=>'show','id'=>$showId,'title'=>(string)($s['title']??$s['venue']??$showId)];}
    return $refs;
}
function sf_video_readiness(array $row): array {
    $media=sf_video_media((int)($row['id']??0),true);$checks=[];$add=function(string $key,bool $ok,string $detail)use(&$checks){$checks[]=['key'=>$key,'ok'=>$ok,'detail'=>$detail];};
    $videos=array_values(array_filter($media,fn($m)=>$m['role']==='video'&&$m['category']==='video'));$posters=array_values(array_filter($media,fn($m)=>$m['role']==='poster'&&$m['category']==='image'));$captions=array_values(array_filter($media,fn($m)=>$m['role']==='captions'));
    $add('title',trim((string)($row['title']??''))!=='','Video title is required.');
    $add('video',count($videos)===1,'Publish exactly one managed public video asset.');
    $add('poster',count($posters)===1,'Publish exactly one public poster image.');
    $captionOk=true;foreach($captions as $c)if($c['extension']!=='vtt'||!in_array($c['mime_type'],['text/vtt','text/plain'],true))$captionOk=false;$add('captions',$captionOk,'Caption attachments must be managed WebVTT files.');
    $lang=(string)($row['caption_language']??'en');$add('caption_language',(bool)preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/',$lang),'Use a caption language code such as en or en-US.');
    $links=[['track',(string)($row['track_id']??'')],['release',(string)($row['release_id']??'')],['show',(string)($row['show_id']??'')]];$validLinks=true;foreach($links as [$type,$id]){if($id==='')continue;$ok=$type==='track'?(function_exists('sf_seo_public_track')&&sf_seo_public_track($id)):($type==='release'?(function_exists('sf_seo_public_release')&&sf_seo_public_release($id)):(function_exists('sf_seo_public_show')&&sf_seo_public_show($id)));if(!$ok)$validLinks=false;}$add('entity_links',$validLinks,'Linked song, release and show records must be currently public.');
    $duration=(float)($videos[0]['duration']??0);$chapters=(array)($row['chapters']??sf_video_decode($row['chapters_json']??'',[]));$chapterOk=true;$prev=-1;foreach($chapters as $c){$at=(float)($c['seconds']??-1);if($at<0||$at<=$prev||($duration>0&&$at>=$duration))$chapterOk=false;$prev=$at;}$add('chapters',$chapterOk,'Chapter times must be unique, ascending and inside video duration.');
    $passed=count(array_filter($checks,fn($c)=>!empty($c['ok'])));$blockers=array_values(array_map(fn($c)=>(string)$c['detail'],array_filter($checks,fn($c)=>empty($c['ok']))));return ['ready'=>!$blockers,'score'=>count($checks)?(int)round($passed*100/count($checks)):0,'checks'=>$checks,'blockers'=>$blockers,'duration'=>$duration];
}
function sf_video_get(int|string $id,bool $publicOnly=false): ?array {
    sf_video_ensure_schema();$col=is_int($id)||ctype_digit((string)$id)?'id':'slug';$q=sf_db()->prepare("SELECT * FROM video_features WHERE $col=? LIMIT 1");$q->execute([$id]);$r=$q->fetch();if(!$r)return null;$r['featured']=!empty($r['featured']);$r['chapters']=sf_video_decode($r['chapters_json']??'',[]);unset($r['chapters_json']);$r['media']=sf_video_media((int)$r['id'],$publicOnly);$r['readiness']=sf_video_readiness($r);$r['entities']=sf_video_entity_refs($r);if($publicOnly&&(($r['status']??'')!=='published'||empty($r['readiness']['ready'])))return null;return $r;
}
function sf_video_list(bool $publicOnly=false): array {
    sf_video_ensure_schema();$sql='SELECT id FROM video_features'.($publicOnly?" WHERE status='published'":'').' ORDER BY featured DESC,sort_order ASC,id DESC';$ids=sf_db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);$out=[];foreach($ids as $id){$r=sf_video_get((int)$id,$publicOnly);if($r)$out[]=$r;}return $out;
}
function sf_video_save(array $raw,int $adminId): array {
    sf_video_ensure_schema();$id=max(0,(int)($raw['id']??0));$title=sf_clean_text($raw['title']??'',255);if($title==='')throw new InvalidArgumentException('Video title is required.');$slug=sf_video_slug((string)($raw['slug']??$title));$kind=(string)($raw['kind']??'music_video');if(!in_array($kind,sf_video_kinds(),true))throw new InvalidArgumentException('Invalid video kind.');$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','published','archived'],true))throw new InvalidArgumentException('Invalid video status.');$lang=str_replace('_','-',sf_clean_text($raw['caption_language']??'en',24));if(preg_match('/^([a-zA-Z]{2,3})-([a-zA-Z]{2})$/',$lang,$m))$lang=strtolower($m[1]).'-'.strtoupper($m[2]);else$lang=strtolower($lang);$trackId=sf_clean_text($raw['track_id']??'',160);$releaseId=sf_clean_text($raw['release_id']??'',160);$showId=sf_clean_text($raw['show_id']??'',160);
    $existing=$id?sf_video_get($id,false):null;$duration=(float)($existing['readiness']['duration']??0);$chapters=sf_video_clean_chapters((array)($raw['chapters']??[]),$duration);$candidate=array_merge($existing?:[],['id'=>$id,'title'=>$title,'slug'=>$slug,'kind'=>$kind,'status'=>$status,'description'=>sf_clean_text($raw['description']??'',12000),'track_id'=>$trackId,'release_id'=>$releaseId,'show_id'=>$showId,'featured'=>!empty($raw['featured']),'sort_order'=>max(0,min(9999,(int)($raw['sort_order']??0))),'chapters'=>$chapters,'caption_language'=>$lang]);
    if($status==='published'){if(!$id)throw new RuntimeException('Save this video as Draft before publishing so Media Library assets can be attached.');$ready=sf_video_readiness($candidate);if(empty($ready['ready']))throw new RuntimeException('Video is blocked: '.implode(' ',array_slice($ready['blockers'],0,6)));}
    $now=gmdate('c');$published=(string)($existing['published_at']??'');if($published===''&&$status==='published')$published=$now;if($published==='')$published=null;$vals=[$slug,$title,$kind,$status,$candidate['description'],$trackId,$releaseId,$showId,!empty($candidate['featured'])?1:0,$candidate['sort_order'],sf_video_json($chapters),$lang,sf_clean_text($raw['notes']??'',8000),$published,$now];
    try{if($id){$q=sf_db()->prepare('UPDATE video_features SET slug=?,title=?,kind=?,status=?,description=?,track_id=?,release_id=?,show_id=?,featured=?,sort_order=?,chapters_json=?,caption_language=?,notes=?,published_at=?,updated_at=? WHERE id=?');$q->execute([...$vals,$id]);}else{$q=sf_db()->prepare('INSERT INTO video_features(slug,title,kind,status,description,track_id,release_id,show_id,featured,sort_order,chapters_json,caption_language,notes,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([...array_slice($vals,0,13),$adminId?:null,$published,$now,$now]);$id=(int)sf_db()->lastInsertId();}}catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))throw new InvalidArgumentException('Video slug must be unique.');throw $e;}
    if($status==='published'&&!empty($candidate['featured']))sf_db()->prepare('UPDATE video_features SET featured=0,updated_at=? WHERE id<>? AND status=\'published\'')->execute([$now,$id]);
    return sf_video_get($id,false)?:throw new RuntimeException('Video could not be saved.');
}
function sf_video_public_payload(array $r): array {
    $media=(array)$r['media'];$video=null;$poster=null;$captions=[];$gallery=[];foreach($media as $m){if($m['role']==='video'&&$m['category']==='video'&&!$video)$video=$m;elseif($m['role']==='poster'&&$m['category']==='image'&&!$poster)$poster=$m;elseif($m['role']==='captions'&&$m['extension']==='vtt'&&in_array($m['mime_type'],['text/vtt','text/plain'],true))$captions[]=$m;elseif($m['role']==='photo'&&$m['category']==='image')$gallery[]=$m;}
    return ['id'=>(int)$r['id'],'slug'=>(string)$r['slug'],'title'=>(string)$r['title'],'kind'=>(string)$r['kind'],'description'=>(string)$r['description'],'featured'=>!empty($r['featured']),'chapters'=>(array)$r['chapters'],'caption_language'=>(string)$r['caption_language'],'published_at'=>$r['published_at'],'video'=>$video,'poster'=>$poster,'captions'=>$captions,'gallery'=>$gallery,'entities'=>(array)$r['entities']];
}
function sf_video_public(string|int $id): ?array { $r=sf_video_get($id,true);return $r?sf_video_public_payload($r):null; }
function sf_video_public_list(): array { return array_map('sf_video_public_payload',sf_video_list(true)); }
function sf_video_featured(): ?array { foreach(sf_video_public_list() as $v)if(!empty($v['featured']))return $v;return sf_video_public_list()[0]??null; }
function sf_video_event(int $id,string $eventType,int $userId=0,array $detail=[]): void {
    $allowed=['view','play','pause','progress_25','progress_50','progress_75','complete','caption_on','chapter_seek','entity_open'];if(!in_array($eventType,$allowed,true)||!sf_video_get($id,true))return;$safe=[];foreach(array_slice($detail,0,6,true) as $k=>$v)$safe[sf_clean_text((string)$k,50)]=is_scalar($v)?sf_clean_text((string)$v,160):'';sf_db()->prepare('INSERT INTO video_events(video_id,event_type,user_id,detail_json,created_at) VALUES(?,?,?,?,?)')->execute([$id,$eventType,$userId?:null,sf_video_json($safe),gmdate('c')]);
}
function sf_video_metrics(int $days=30): array {
    sf_video_ensure_schema();$since=gmdate('c',time()-max(1,min(365,$days))*86400);$q=sf_db()->prepare('SELECT event_type,COUNT(*) total FROM video_events WHERE created_at>=? GROUP BY event_type');$q->execute([$since]);$events=[];foreach($q->fetchAll() as $r)$events[(string)$r['event_type']]=(int)$r['total'];return ['days'=>$days,'events'=>$events,'published'=>(int)sf_db()->query("SELECT COUNT(*) FROM video_features WHERE status='published'")->fetchColumn(),'drafts'=>(int)sf_db()->query("SELECT COUNT(*) FROM video_features WHERE status='draft'")->fetchColumn()];
}
function sf_video_agent_context(string $query,int $limit=5): string {
    $terms=array_values(array_filter(preg_split('/[^a-z0-9]+/i',strtolower($query))?:[],fn($x)=>strlen($x)>2));$score=function(array $v)use($terms){$text=strtolower($v['title'].' '.$v['description'].' '.implode(' ',array_column($v['entities'],'title')));$n=0;foreach($terms as $w)if(str_contains($text,$w))$n++;return $n;};$rows=sf_video_public_list();usort($rows,fn($a,$b)=>$score($b)<=>$score($a));$out=[];foreach($rows as $v){if($terms&&$score($v)<1)continue;$out[]='- '.$v['title'].' | '.str_replace('_',' ',$v['kind']).' | '.substr($v['description'],0,500).' | /video/'.$v['slug'];if(count($out)>=$limit)break;}return $out?"PUBLISHED VIDEOS
".implode("
",$out):'';
}
