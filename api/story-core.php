<?php
declare(strict_types=1);

function sf_story_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}'; }
function sf_story_types(): array { return ['artist_about','essay','release_story','track_story','show_story','milestone']; }
function sf_story_slug(string $v,string $fallback='story'): string { $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';$v=trim(substr($v,0,140),'-');return $v!==''?$v:$fallback; }
function sf_story_clean_long_text(mixed $v,int $max=60000): string { $s=str_replace(["\r\n","\r"],"\n",(string)$v);$s=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$s)??'';$s=trim($s);return function_exists('mb_substr')?mb_substr($s,0,$max):substr($s,0,$max); }

function sf_story_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_entries (id INTEGER PRIMARY KEY AUTOINCREMENT,slug TEXT NOT NULL UNIQUE,entry_type TEXT NOT NULL,title TEXT NOT NULL,eyebrow TEXT NOT NULL DEFAULT '',summary TEXT NOT NULL DEFAULT '',body_text TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'draft',entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',event_date TEXT NULL,era TEXT NOT NULL DEFAULT '',featured INTEGER NOT NULL DEFAULT 0,position INTEGER NOT NULL DEFAULT 0,seo_title TEXT NOT NULL DEFAULT '',seo_description TEXT NOT NULL DEFAULT '',created_by INTEGER NULL,published_at TEXT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_entries_status ON story_entries(status,entry_type,position,updated_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_entries_entity ON story_entries(entity_type,entity_id,status)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_events (id INTEGER PRIMARY KEY AUTOINCREMENT,story_id INTEGER NOT NULL,event_type TEXT NOT NULL,user_id INTEGER NULL,detail_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(story_id) REFERENCES story_entries(id) ON DELETE CASCADE,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_story_events_story ON story_events(story_id,event_type,created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_entries (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(160) NOT NULL UNIQUE,entry_type VARCHAR(40) NOT NULL,title VARCHAR(255) NOT NULL,eyebrow VARCHAR(120) NOT NULL DEFAULT '',summary TEXT NOT NULL,body_text LONGTEXT NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'draft',entity_type VARCHAR(40) NOT NULL DEFAULT '',entity_id VARCHAR(160) NOT NULL DEFAULT '',event_date VARCHAR(40) NULL,era VARCHAR(160) NOT NULL DEFAULT '',featured TINYINT(1) NOT NULL DEFAULT 0,position INT NOT NULL DEFAULT 0,seo_title VARCHAR(255) NOT NULL DEFAULT '',seo_description VARCHAR(500) NOT NULL DEFAULT '',created_by BIGINT UNSIGNED NULL,published_at VARCHAR(40) NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_story_entries_status(status,entry_type,position,updated_at),INDEX idx_story_entries_entity(entity_type,entity_id,status),CONSTRAINT fk_story_entry_admin FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS story_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,story_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(40) NOT NULL,user_id BIGINT UNSIGNED NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_story_events_story(story_id,event_type,created_at),CONSTRAINT fk_story_event_entry FOREIGN KEY(story_id) REFERENCES story_entries(id) ON DELETE CASCADE,CONSTRAINT fk_story_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_story_entity(string $type,string $id): ?array {
    if($type===''||$id==='')return null;
    if($type==='track'){$t=sf_track_map()[$id]??null;if(!$t||($t['public_visible']??true)===false||in_array((string)($t['state']??''),['draft','private','archived'],true))return null;return ['type'=>'track','id'=>$id,'title'=>(string)($t['title']??$id),'url'=>'track/'.$id];}
    if($type==='release'){if(function_exists('sf_seo_public_release'))$r=sf_seo_public_release($id);else{$r=null;foreach(sf_release_rows() as $x)if((string)($x['id']??'')===$id&&($x['state']??'published')==='published'&&($x['public_visible']??true)!==false){$r=$x;break;}}if(!$r)return null;return ['type'=>'release','id'=>$id,'title'=>(string)($r['title']??$id),'url'=>'release/'.$id];}
    if($type==='show'){if(!function_exists('sf_live_show_by_id'))return null;$s=sf_live_show_by_id($id);if(!$s||($s['public_visible']??true)===false)return null;return ['type'=>'show','id'=>$id,'title'=>(string)($s['title']??$s['venue']??$id),'url'=>'show/'.$id];}
    return null;
}
function sf_story_required_entity_type(string $entryType): string { return match($entryType){'release_story'=>'release','track_story'=>'track','show_story'=>'show',default=>''}; }
function sf_story_media(int $id,bool $publicOnly=false): array {
    if(!function_exists($publicOnly?'sf_media_public_links':'sf_media_links'))return [];$fn=$publicOnly?'sf_media_public_links':'sf_media_links';return $fn('story_entry',(string)$id);
}
function sf_story_readiness(array $e): array {
    $checks=[];$blockers=[];$warnings=[];$add=function(string $key,string $label,bool $ok,string $detail,bool $blocking=true)use(&$checks,&$blockers,&$warnings){$checks[]=['key'=>$key,'label'=>$label,'ok'=>$ok,'detail'=>$detail];if(!$ok){if($blocking)$blockers[]=$detail;else $warnings[]=$detail;}};
    $type=(string)($e['entry_type']??'');$add('type','Story type',in_array($type,sf_story_types(),true),'Choose a supported Stonefellow story type.');
    $title=trim((string)($e['title']??''));$body=trim((string)($e['body_text']??''));$add('title','Title',$title!=='','Story title is required.');$add('body','Body',$body!=='','Story body is required.');
    $required=sf_story_required_entity_type($type);$entityType=(string)($e['entity_type']??'');$entityId=(string)($e['entity_id']??'');
    if($required!==''){$ok=$entityType===$required&&$entityId!==''&&sf_story_entity($entityType,$entityId)!==null;$add('entity','Linked '.$required,$ok,'This story must link to a public Stonefellow '.$required.'.');}
    elseif($entityType!==''||$entityId!==''){$ok=in_array($entityType,['track','release','show'],true)&&$entityId!==''&&sf_story_entity($entityType,$entityId)!==null;$add('entity','Linked content',$ok,'Linked Stonefellow content is not public or no longer exists.');}
    if($type==='milestone')$add('date','Timeline date',trim((string)($e['event_date']??''))!==''&&strtotime((string)$e['event_date'])!==false,'Timeline milestones require a valid date.');
    if($type==='artist_about'){$q=sf_db()->prepare("SELECT id FROM story_entries WHERE entry_type='artist_about' AND status='published' AND id<>? LIMIT 1");$q->execute([(int)($e['id']??0)]);$add('single_about','Single public About story',!$q->fetchColumn(),'Only one Artist About story may be published at a time.');}
    $media=(int)($e['id']??0)>0?sf_story_media((int)$e['id'],true):[];$hero=array_values(array_filter($media,fn($m)=>($m['role']??'')==='hero'));if(!$hero)$warnings[]='No public hero image is attached to this story.';
    $score=count($checks)?(int)round(count(array_filter($checks,fn($c)=>!empty($c['ok'])))*100/count($checks)):0;return ['ready'=>$blockers===[],'score'=>$score,'checks'=>$checks,'blockers'=>array_values(array_unique($blockers)),'warnings'=>array_values(array_unique($warnings))];
}
function sf_story_row(array $r,bool $publicMedia=false): array {
    $r['id']=(int)$r['id'];$r['featured']=!empty($r['featured']);$r['entity']=$r['entity_type']!==''?sf_story_entity((string)$r['entity_type'],(string)$r['entity_id']):null;$r['media']=sf_story_media((int)$r['id'],$publicMedia);$r['readiness']=sf_story_readiness($r);return $r;
}
function sf_story_get(int|string $id,bool $publicOnly=false): ?array {
    sf_story_ensure_schema();$col=is_int($id)||ctype_digit((string)$id)?'id':'slug';$sql="SELECT * FROM story_entries WHERE $col=?".($publicOnly?" AND status='published'":'')." LIMIT 1";$q=sf_db()->prepare($sql);$q->execute([$id]);$r=$q->fetch();if(!$r)return null;$r=sf_story_row($r,$publicOnly);if($publicOnly&&empty($r['readiness']['ready']))return null;return $r;
}
function sf_story_list(bool $publicOnly=false,string $type='',int $limit=500): array {
    sf_story_ensure_schema();$w=[];$a=[];if($publicOnly)$w[]="status='published'";if($type!==''){$w[]='entry_type=?';$a[]=$type;}$sql='SELECT * FROM story_entries'.($w?' WHERE '.implode(' AND ',$w):'').' ORDER BY featured DESC,CASE WHEN event_date IS NULL THEN 1 ELSE 0 END,event_date DESC,position,id DESC LIMIT '.max(1,min(1000,$limit));$q=sf_db()->prepare($sql);$q->execute($a);$rows=$q->fetchAll();$out=[];foreach($rows as $r){$x=sf_story_row($r,$publicOnly);if(!$publicOnly||!empty($x['readiness']['ready']))$out[]=$x;}return $out;
}
function sf_story_for_entity(string $type,string $id,bool $publicOnly=true): array {
    sf_story_ensure_schema();if(!in_array($type,['track','release','show'],true)||$id==='')return [];$sql="SELECT * FROM story_entries WHERE entity_type=? AND entity_id=?".($publicOnly?" AND status='published'":'')." ORDER BY featured DESC,position,id DESC";$q=sf_db()->prepare($sql);$q->execute([$type,$id]);$out=[];foreach($q->fetchAll() as $r){$x=sf_story_row($r,$publicOnly);if(!$publicOnly||!empty($x['readiness']['ready']))$out[]=$x;}return $out;
}
function sf_story_about_payload(): array {
    $about=null;foreach(sf_story_list(true,'artist_about',5) as $x){$about=$x;break;}$essays=array_slice(sf_story_list(true,'essay',50),0,8);$timeline=array_slice(sf_story_list(true,'milestone',200),0,80);usort($timeline,fn($a,$b)=>strcmp((string)$a['event_date'],(string)$b['event_date']));return ['about'=>$about,'essays'=>$essays,'timeline'=>$timeline];
}
function sf_story_save(array $raw,int $adminId): array {
    sf_story_ensure_schema();$id=max(0,(int)($raw['id']??0));$type=(string)($raw['entry_type']??'');if(!in_array($type,sf_story_types(),true))throw new InvalidArgumentException('Unsupported story type.');$title=sf_clean_text($raw['title']??'',255);if($title==='')throw new InvalidArgumentException('Story title is required.');$slug=sf_story_slug((string)($raw['slug']??$title));$status=(string)($raw['status']??'draft');if(!in_array($status,['draft','published','archived'],true))throw new InvalidArgumentException('Invalid story status.');
    $required=sf_story_required_entity_type($type);$entityType=sf_clean_text($raw['entity_type']??'',40);$entityId=sf_clean_text($raw['entity_id']??'',160);if($required!=='')$entityType=$required;if($entityType===''||$entityId===''){$entityType='';$entityId='';}
    $eventDate=trim((string)($raw['event_date']??''));if($eventDate!==''&&strtotime($eventDate)===false)throw new InvalidArgumentException('Story date is invalid.');
    $existing=$id?sf_story_get($id,false):null;$body=sf_story_clean_long_text($raw['body_text']??'',60000);$candidate=array_merge($existing?:[],['id'=>$id,'entry_type'=>$type,'title'=>$title,'slug'=>$slug,'status'=>$status,'entity_type'=>$entityType,'entity_id'=>$entityId,'event_date'=>$eventDate!==''?$eventDate:null,'era'=>sf_clean_text($raw['era']??'',160),'summary'=>sf_story_clean_long_text($raw['summary']??'',5000),'body_text'=>$body]);
    if($status==='published'){$ready=sf_story_readiness($candidate);if(empty($ready['ready']))throw new RuntimeException('Story is blocked: '.implode(' ',array_slice($ready['blockers'],0,5)));}
    $now=gmdate('c');$pub=$status==='published'?((string)($existing['published_at']??'')?:$now):null;$vals=[$slug,$type,$title,sf_clean_text($raw['eyebrow']??'',120),sf_story_clean_long_text($raw['summary']??'',5000),$body,$status,$entityType,$entityId,$eventDate!==''?$eventDate:null,sf_clean_text($raw['era']??'',160),!empty($raw['featured'])?1:0,max(0,min(999,(int)($raw['position']??0))),sf_clean_text($raw['seo_title']??'',255),sf_clean_text($raw['seo_description']??'',500),$pub,$now];
    try{if($id){$q=sf_db()->prepare('UPDATE story_entries SET slug=?,entry_type=?,title=?,eyebrow=?,summary=?,body_text=?,status=?,entity_type=?,entity_id=?,event_date=?,era=?,featured=?,position=?,seo_title=?,seo_description=?,published_at=?,updated_at=? WHERE id=?');$q->execute([...$vals,$id]);}else{$q=sf_db()->prepare('INSERT INTO story_entries(slug,entry_type,title,eyebrow,summary,body_text,status,entity_type,entity_id,event_date,era,featured,position,seo_title,seo_description,created_by,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([...array_slice($vals,0,15),$adminId?:null,$pub,$now,$now]);$id=(int)sf_db()->lastInsertId();}}catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','19'],true))throw new InvalidArgumentException('Story slug must be unique.');throw $e;}
    return sf_story_get($id,false)?:throw new RuntimeException('Story could not be saved.');
}
function sf_story_delete(int $id): bool { $e=sf_story_get($id,false);if(!$e)return false;if(($e['status']??'')==='published')throw new RuntimeException('Archive or draft this story before deleting it.');$q=sf_db()->prepare('DELETE FROM story_entries WHERE id=?');$q->execute([$id]);return $q->rowCount()>0; }
function sf_story_event(int $storyId,string $eventType,int $userId=0,array $detail=[]): void {
    sf_story_ensure_schema();if(!in_array($eventType,['view','entity_open','media_open','share'],true))return;if(!sf_story_get($storyId,true))return;$safe=[];foreach(array_slice($detail,0,6,true) as $k=>$v)$safe[sf_clean_text((string)$k,60)]=is_scalar($v)?sf_clean_text((string)$v,180):'';sf_db()->prepare('INSERT INTO story_events(story_id,event_type,user_id,detail_json,created_at) VALUES(?,?,?,?,?)')->execute([$storyId,$eventType,$userId?:null,sf_story_json($safe),gmdate('c')]);
}
function sf_story_metrics(int $days=30): array {
    sf_story_ensure_schema();$since=gmdate('c',time()-max(1,min(365,$days))*86400);$q=sf_db()->prepare('SELECT event_type,COUNT(*) total FROM story_events WHERE created_at>=? GROUP BY event_type');$q->execute([$since]);$events=[];foreach($q->fetchAll() as $r)$events[(string)$r['event_type']]=(int)$r['total'];return ['days'=>$days,'events'=>$events,'published'=>(int)sf_db()->query("SELECT COUNT(*) FROM story_entries WHERE status='published'")->fetchColumn(),'drafts'=>(int)sf_db()->query("SELECT COUNT(*) FROM story_entries WHERE status='draft'")->fetchColumn()];
}
function sf_story_agent_brief(): string {
    $m=sf_story_metrics(30);$lines=['ARTIST STORY + EDITORIAL','Published '.$m['published'].' | drafts '.$m['drafts'].' | views '.($m['events']['view']??0)];foreach(array_slice(sf_story_list(true,'',8),0,8) as $e)$lines[]='- '.$e['entry_type'].' | '.$e['title'].($e['entity']?' | '.$e['entity']['title']:'');return implode("\n",$lines);
}
