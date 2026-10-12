<?php
declare(strict_types=1);

function sf_gallery_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '[]'; }
function sf_gallery_slug(string $v,string $fallback='photos'): string { $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';$v=trim(substr($v,0,140),'-');return $v!==''?$v:$fallback; }

function sf_gallery_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS photo_galleries (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,title TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'draft',summary TEXT NOT NULL DEFAULT '',event_date TEXT NULL,location TEXT NOT NULL DEFAULT '',release_id TEXT NOT NULL DEFAULT '',show_id TEXT NOT NULL DEFAULT '',story_id INTEGER NULL,featured INTEGER NOT NULL DEFAULT 0,sort_order INTEGER NOT NULL DEFAULT 0,notes TEXT NOT NULL DEFAULT '',created_by INTEGER NULL,published_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_photo_galleries_public ON photo_galleries(status,featured,sort_order,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS photo_gallery_events (id INTEGER PRIMARY KEY AUTOINCREMENT,gallery_id INTEGER NOT NULL,event_type TEXT NOT NULL,user_id INTEGER NULL,detail_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(gallery_id) REFERENCES photo_galleries(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_photo_gallery_events ON photo_gallery_events(gallery_id,event_type,created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS photo_galleries (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(160) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,status VARCHAR(32) NOT NULL DEFAULT 'draft',summary TEXT NOT NULL,event_date VARCHAR(40) NULL,location VARCHAR(255) NOT NULL DEFAULT '',release_id VARCHAR(160) NOT NULL DEFAULT '',show_id VARCHAR(160) NOT NULL DEFAULT '',story_id BIGINT UNSIGNED NULL,featured TINYINT(1) NOT NULL DEFAULT 0,sort_order INT NOT NULL DEFAULT 0,notes LONGTEXT NOT NULL,created_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_photo_galleries_public(status,featured,sort_order,updated_at),CONSTRAINT fk_photo_gallery_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS photo_gallery_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,gallery_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(40) NOT NULL,user_id BIGINT UNSIGNED NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_photo_gallery_events(gallery_id,event_type,created_at),CONSTRAINT fk_photo_gallery_event_gallery FOREIGN KEY(gallery_id) REFERENCES photo_galleries(id) ON DELETE CASCADE,CONSTRAINT fk_photo_gallery_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }$done=true;
}
function sf_gallery_media(int $id,bool $publicOnly=false): array {
    if(!function_exists('sf_media_links'))return [];$rows=sf_media_links('photo_gallery',(string)$id);$out=[];
    foreach($rows as $r){if($publicOnly&&empty($r['public_visible']))continue;if(($r['category']??'')!=='image')continue;$asset=sf_media_asset((int)$r['asset_id']);if(!$asset)continue;$out[]=[
      'link_id'=>(int)$r['id'],'asset_id'=>(int)$r['asset_id'],'uuid'=>(string)$r['uuid'],'role'=>(string)$r['role'],'title'=>(string)$r['title'],'caption'=>(string)$r['caption'],'alt_text'=>(string)$r['alt_text'],'credit'=>(string)$r['credit'],'copyright'=>(string)$r['copyright_text'],'featured'=>!empty($r['featured']),'public_visible'=>!empty($r['public_visible']),'download_allowed'=>!empty($r['download_allowed']),'width'=>(int)$r['width'],'height'=>(int)$r['height'],'url'=>sf_media_managed_url($asset),'thumb_url'=>sf_media_managed_url($asset,!empty($asset['variants']['thumb'])?'thumb':'original')
    ];}
    return $out;
}
function sf_gallery_entity_refs(array $row): array {
    $refs=[];$releaseId=(string)($row['release_id']??'');$showId=(string)($row['show_id']??'');$storyId=(int)($row['story_id']??0);
    if($releaseId!==''){$r=function_exists('sf_seo_public_release')?sf_seo_public_release($releaseId):null;if($r)$refs[]=['type'=>'release','id'=>$releaseId,'title'=>(string)($r['title']??$releaseId)];}
    if($showId!==''){$s=function_exists('sf_seo_public_show')?sf_seo_public_show($showId):null;if($s)$refs[]=['type'=>'show','id'=>$showId,'title'=>(string)($s['title']??$s['venue']??$showId)];}
    if($storyId>0&&function_exists('sf_story_get')){$st=sf_story_get($storyId,true);if($st)$refs[]=['type'=>'story','id'=>(string)$storyId,'slug'=>(string)($st['slug']??''),'title'=>(string)($st['title']??('Story '.$storyId))];}
    return $refs;
}
function sf_gallery_readiness(array $row): array {
    $media=sf_gallery_media((int)($row['id']??0),true);$cover=array_values(array_filter($media,fn($m)=>$m['role']==='cover'));$photos=array_values(array_filter($media,fn($m)=>$m['role']==='photo'));$checks=[];$warnings=[];$add=function(string $key,bool $ok,string $detail)use(&$checks){$checks[]=['key'=>$key,'ok'=>$ok,'detail'=>$detail];};
    $add('title',trim((string)($row['title']??''))!=='','Gallery title is required.');
    $add('summary',trim((string)($row['summary']??''))!=='','Gallery summary is required.');
    $add('cover',count($cover)===1,'Publish exactly one public cover image.');
    $coverSize=count($cover)===1&&max((int)$cover[0]['width'],(int)$cover[0]['height'])>=1200&&min((int)$cover[0]['width'],(int)$cover[0]['height'])>=600;$add('cover_size',$coverSize,'Gallery cover must be at least 1200×600 in either orientation.');
    $add('photos',count($photos)>=3,'Publish at least three gallery photos.');
    $photoSize=count($photos)>=3;foreach($photos as $p)if(max((int)$p['width'],(int)$p['height'])<1200||min((int)$p['width'],(int)$p['height'])<600)$photoSize=false;$add('photo_size',$photoSize,'Every published gallery photo must be at least 1200×600 in either orientation.');
    $altOk=count($photos)>=3&&count($cover)===1;foreach(array_merge($cover,$photos) as $p)if(trim((string)$p['alt_text'])==='')$altOk=false;$add('alt_text',$altOk,'Every public cover/photo requires alt text.');
    foreach(array_merge($cover,$photos) as $p)if(trim((string)$p['credit'])==='')$warnings[]='One or more public photos have no photographer/creator credit.';
    $validLinks=true;$releaseId=(string)($row['release_id']??'');$showId=(string)($row['show_id']??'');$storyId=(int)($row['story_id']??0);
    if($releaseId!==''&&(!function_exists('sf_seo_public_release')||!sf_seo_public_release($releaseId)))$validLinks=false;
    if($showId!==''&&(!function_exists('sf_seo_public_show')||!sf_seo_public_show($showId)))$validLinks=false;
    if($storyId>0&&(!function_exists('sf_story_get')||!sf_story_get($storyId,true)))$validLinks=false;
    $add('entity_links',$validLinks,'Linked release, show and story must currently be public.');
    $passed=count(array_filter($checks,fn($c)=>!empty($c['ok'])));$blockers=array_values(array_map(fn($c)=>(string)$c['detail'],array_filter($checks,fn($c)=>empty($c['ok']))));return ['ready'=>!$blockers,'score'=>count($checks)?(int)round($passed*100/count($checks)):0,'checks'=>$checks,'blockers'=>$blockers,'warnings'=>array_values(array_unique($warnings)),'photo_count'=>count($photos)];
}
function sf_gallery_get(int|string $id,bool $publicOnly=false): ?array {
    sf_gallery_ensure_schema();$col=is_int($id)||ctype_digit((string)$id)?'id':'slug';$q=sf_db()->prepare("SELECT * FROM photo_galleries WHERE $col=? LIMIT 1");$q->execute([$id]);$r=$q->fetch();if(!$r)return null;$r['featured']=!empty($r['featured']);$r['media']=sf_gallery_media((int)$r['id'],$publicOnly);$r['readiness']=sf_gallery_readiness($r);$r['entities']=sf_gallery_entity_refs($r);if($publicOnly&&(($r['status']??'')!=='published'||empty($r['readiness']['ready'])))return null;return $r;
}
function sf_gallery_list(bool $publicOnly=false): array {
    sf_gallery_ensure_schema();$sql='SELECT id FROM photo_galleries'.($publicOnly?" WHERE status='published'":'').' ORDER BY featured DESC,sort_order ASC,COALESCE(event_date,published_at,created_at) DESC,id DESC';$ids=sf_db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);$out=[];foreach($ids as $id){$r=sf_gallery_get((int)$id,$publicOnly);if($r)$out[]=$r;}return $out;
}
function sf_gallery_save(array $raw,int $adminId): array {
    sf_gallery_ensure_schema();$id=max(0,(int)($raw['id']??0));$title=sf_clean_text($raw['title']??'',255);if($title==='')throw new InvalidArgumentException('Gallery title is required.');$slug=sf_gallery_slug((string)($raw['slug']??$title));$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','published','archived'],true))throw new InvalidArgumentException('Invalid gallery status.');
    $eventDate=trim((string)($raw['event_date']??''));if($eventDate!==''&&strtotime($eventDate)===false)throw new InvalidArgumentException('Gallery date is invalid.');$releaseId=sf_clean_text($raw['release_id']??'',160);$showId=sf_clean_text($raw['show_id']??'',160);$storyId=max(0,(int)($raw['story_id']??0));
    if($releaseId!==''&&(!function_exists('sf_seo_public_release')||!sf_seo_public_release($releaseId)))throw new InvalidArgumentException('Linked release must be public.');if($showId!==''&&(!function_exists('sf_seo_public_show')||!sf_seo_public_show($showId)))throw new InvalidArgumentException('Linked show must be public.');if($storyId>0&&(!function_exists('sf_story_get')||!sf_story_get($storyId,true)))throw new InvalidArgumentException('Linked story must be public.');
    $existing=$id?sf_gallery_get($id,false):null;$candidate=array_merge($existing?:[],['id'=>$id,'slug'=>$slug,'title'=>$title,'status'=>$status,'summary'=>sf_clean_text($raw['summary']??'',12000),'event_date'=>$eventDate!==''?$eventDate:null,'location'=>sf_clean_text($raw['location']??'',255),'release_id'=>$releaseId,'show_id'=>$showId,'story_id'=>$storyId?:null,'featured'=>!empty($raw['featured']),'sort_order'=>max(0,min(9999,(int)($raw['sort_order']??0)))]);
    if($status==='published'){if(!$id)throw new RuntimeException('Save this gallery as Draft before publishing so Media Library photos can be attached.');$ready=sf_gallery_readiness($candidate);if(empty($ready['ready']))throw new RuntimeException('Gallery is blocked: '.implode(' ',array_slice($ready['blockers'],0,6)));}
    $now=gmdate('c');$published=(string)($existing['published_at']??'');if($published===''&&$status==='published')$published=$now;if($published==='')$published=null;$vals=[$slug,$title,$status,$candidate['summary'],$candidate['event_date'],$candidate['location'],$releaseId,$showId,$storyId?:null,!empty($candidate['featured'])?1:0,$candidate['sort_order'],sf_clean_text($raw['notes']??'',8000),$published,$now];
    try{if($id){$q=sf_db()->prepare('UPDATE photo_galleries SET slug=?,title=?,status=?,summary=?,event_date=?,location=?,release_id=?,show_id=?,story_id=?,featured=?,sort_order=?,notes=?,published_at=?,updated_at=? WHERE id=?');$q->execute([...$vals,$id]);}else{$q=sf_db()->prepare('INSERT INTO photo_galleries(slug,title,status,summary,event_date,location,release_id,show_id,story_id,featured,sort_order,notes,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([...array_slice($vals,0,12),$adminId?:null,$published,$now,$now]);$id=(int)sf_db()->lastInsertId();}}catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))throw new InvalidArgumentException('Gallery slug must be unique.');throw $e;}
    if($status==='published'&&!empty($candidate['featured']))sf_db()->prepare("UPDATE photo_galleries SET featured=0,updated_at=? WHERE id<>? AND status='published'")->execute([$now,$id]);
    return sf_gallery_get($id,false)?:throw new RuntimeException('Gallery could not be saved.');
}
function sf_gallery_public_payload(array $r): array {
    $cover=null;$photos=[];foreach((array)$r['media'] as $m){if($m['role']==='cover'&&!$cover)$cover=$m;elseif($m['role']==='photo')$photos[]=$m;}
    return ['id'=>(int)$r['id'],'slug'=>(string)$r['slug'],'title'=>(string)$r['title'],'summary'=>(string)$r['summary'],'event_date'=>$r['event_date'],'location'=>(string)$r['location'],'featured'=>!empty($r['featured']),'published_at'=>$r['published_at'],'cover'=>$cover,'photos'=>$photos,'entities'=>(array)$r['entities']];
}
function sf_gallery_public(string|int $id): ?array { $r=sf_gallery_get($id,true);return $r?sf_gallery_public_payload($r):null; }
function sf_gallery_public_list(): array { return array_map('sf_gallery_public_payload',sf_gallery_list(true)); }
function sf_gallery_featured(): ?array { foreach(sf_gallery_public_list() as $g)if(!empty($g['featured']))return $g;return sf_gallery_public_list()[0]??null; }
function sf_gallery_event(int $id,string $eventType,int $userId=0,array $detail=[]): void {
    $allowed=['view','photo_open','entity_open'];if(!in_array($eventType,$allowed,true)||!sf_gallery_get($id,true))return;$safe=[];foreach(array_slice($detail,0,6,true) as $k=>$v)$safe[sf_clean_text((string)$k,50)]=is_scalar($v)?sf_clean_text((string)$v,160):'';sf_db()->prepare('INSERT INTO photo_gallery_events(gallery_id,event_type,user_id,detail_json,created_at) VALUES(?,?,?,?,?)')->execute([$id,$eventType,$userId?:null,sf_gallery_json($safe),gmdate('c')]);
}
function sf_gallery_metrics(int $days=30): array {
    sf_gallery_ensure_schema();$since=gmdate('c',time()-max(1,min(365,$days))*86400);$q=sf_db()->prepare('SELECT event_type,COUNT(*) total FROM photo_gallery_events WHERE created_at>=? GROUP BY event_type');$q->execute([$since]);$events=[];foreach($q->fetchAll() as $r)$events[(string)$r['event_type']]=(int)$r['total'];return ['days'=>$days,'events'=>$events,'published'=>(int)sf_db()->query("SELECT COUNT(*) FROM photo_galleries WHERE status='published'")->fetchColumn(),'drafts'=>(int)sf_db()->query("SELECT COUNT(*) FROM photo_galleries WHERE status='draft'")->fetchColumn()];
}
function sf_gallery_agent_context(string $query,int $limit=5): string {
    $terms=array_values(array_filter(preg_split('/[^a-z0-9]+/i',strtolower($query))?:[],fn($x)=>strlen($x)>2));$score=function(array $g)use($terms){$text=strtolower($g['title'].' '.$g['summary'].' '.$g['location'].' '.implode(' ',array_column($g['entities'],'title')));$n=0;foreach($terms as $w)if(str_contains($text,$w))$n++;return $n;};$rows=sf_gallery_public_list();usort($rows,fn($a,$b)=>$score($b)<=>$score($a));$out=[];foreach($rows as $g){if($terms&&$score($g)<1)continue;$out[]='- '.$g['title'].' | '.($g['event_date']??'').' | '.$g['location'].' | '.substr($g['summary'],0,500).' | /photos/'.$g['slug'];if(count($out)>=$limit)break;}return $out?"PUBLISHED PHOTO GALLERIES
".implode("
",$out):'';
}
