<?php
declare(strict_types=1);

function sf_artist_home_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}'; }
function sf_artist_home_decode(mixed $v,array $fallback=[]): array { $d=is_array($v)?$v:json_decode((string)$v,true); return is_array($d)?$d:$fallback; }
function sf_artist_home_keys(): array { return ['hero','featured_release','featured_track','latest_releases','upcoming_show','merch','artist_story','newsletter','personalized']; }
function sf_artist_home_defaults(): array {
    return [
      ['hero',1,10,'STONEFELLOW','Music made to be lived with.','Listen to the songs, follow the stories, see what is next, and make something of your own.',['primary_label'=>'Listen now','primary_view'=>'music','secondary_label'=>'Explore releases','secondary_view'=>'releases']],
      ['featured_release',1,20,'FEATURED RELEASE','Featured release','A record worth spending some time with.',['release_id'=>'']],
      ['featured_track',1,30,'LISTEN','Start with one song','A place to begin.',['track_id'=>'']],
      ['latest_releases',1,40,'MUSIC','Latest releases','The newest Stonefellow releases.',['limit'=>3]],
      ['upcoming_show',1,50,'LIVE','Next show','Upcoming Stonefellow live date.',['show_id'=>'']],
      ['merch',1,60,'DIRECT FROM STONEFELLOW','Featured merch','Music, objects, and limited pieces available directly.',['product_id'=>'']],
      ['artist_story',1,70,'ABOUT STONEFELLOW','One artist. One evolving catalog.','Stonefellow is David Evans’ music project — songs, recordings, live work, and the stories around them.',['button_label'=>'About Stonefellow','button_view'=>'about']],
      ['newsletter',1,80,'STAY CONNECTED','Hear what is next','New music, shows, releases, and selected Stonefellow updates.',['compact'=>false]],
      ['personalized',1,90,'FOR YOU','Your Stonefellow','Continue listening, revisit favorites, and pick up where you left off.',['limit'=>4]],
    ];
}
function sf_artist_home_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
      $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_sections (section_key TEXT PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 1,position INTEGER NOT NULL DEFAULT 0,eyebrow TEXT NOT NULL DEFAULT '',heading TEXT NOT NULL DEFAULT '',body_text TEXT NOT NULL DEFAULT '',config_json TEXT NOT NULL DEFAULT '{}',updated_at TEXT NOT NULL)");
      $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_events (id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT NOT NULL,section_key TEXT NOT NULL DEFAULT '',entity_type TEXT NOT NULL DEFAULT '',entity_id TEXT NOT NULL DEFAULT '',user_id INTEGER NULL,detail_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_artist_home_events_type ON artist_home_events(event_type,created_at)");
    }else{
      $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_sections (section_key VARCHAR(60) NOT NULL PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 1,position INT NOT NULL DEFAULT 0,eyebrow VARCHAR(120) NOT NULL DEFAULT '',heading VARCHAR(255) NOT NULL DEFAULT '',body_text TEXT NOT NULL,config_json LONGTEXT NOT NULL,updated_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      $pdo->exec("CREATE TABLE IF NOT EXISTS artist_home_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(60) NOT NULL,section_key VARCHAR(60) NOT NULL DEFAULT '',entity_type VARCHAR(60) NOT NULL DEFAULT '',entity_id VARCHAR(160) NOT NULL DEFAULT '',user_id BIGINT UNSIGNED NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_artist_home_events_type(event_type,created_at),CONSTRAINT fk_artist_home_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $now=gmdate('c');foreach(sf_artist_home_defaults() as [$key,$enabled,$position,$eyebrow,$heading,$body,$config]){$q=$pdo->prepare('SELECT 1 FROM artist_home_sections WHERE section_key=?');$q->execute([$key]);if(!$q->fetchColumn()){$q=$pdo->prepare('INSERT INTO artist_home_sections(section_key,enabled,position,eyebrow,heading,body_text,config_json,updated_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$key,$enabled,$position,$eyebrow,$heading,$body,sf_artist_home_json($config),$now]);}}
    $done=true;
}
function sf_artist_home_sections(bool $enabledOnly=false): array {
    sf_artist_home_ensure_schema();$sql='SELECT * FROM artist_home_sections'.($enabledOnly?' WHERE enabled=1':'').' ORDER BY position,section_key';$rows=sf_db()->query($sql)->fetchAll();foreach($rows as &$r){$r['enabled']=!empty($r['enabled']);$r['config']=sf_artist_home_decode($r['config_json']??'',[]);unset($r['config_json']);}unset($r);return $rows;
}
function sf_artist_home_section(string $key): ?array { foreach(sf_artist_home_sections(false) as $r)if($r['section_key']===$key)return $r;return null; }
function sf_artist_home_save_section(array $raw): array {
    sf_artist_home_ensure_schema();$key=(string)($raw['section_key']??'');if(!in_array($key,sf_artist_home_keys(),true))throw new InvalidArgumentException('Unknown homepage section.');
    $config=(array)($raw['config']??[]);$clean=[];foreach($config as $k=>$v){$k=sf_clean_text((string)$k,80);if($k==='')continue;$clean[$k]=is_bool($v)||is_int($v)?$v:sf_clean_text((string)$v,500);}
    $q=sf_db()->prepare('UPDATE artist_home_sections SET enabled=?,position=?,eyebrow=?,heading=?,body_text=?,config_json=?,updated_at=? WHERE section_key=?');$q->execute([!empty($raw['enabled'])?1:0,max(0,min(999,(int)($raw['position']??0))),sf_clean_text($raw['eyebrow']??'',120),sf_clean_text($raw['heading']??'',255),sf_clean_text($raw['body_text']??'',6000),sf_artist_home_json($clean),gmdate('c'),$key]);return sf_artist_home_section($key)?:[];
}
function sf_artist_home_reset(): array {
    sf_artist_home_ensure_schema();$pdo=sf_db();$pdo->beginTransaction();try{$pdo->exec('DELETE FROM artist_home_sections');$now=gmdate('c');foreach(sf_artist_home_defaults() as [$key,$enabled,$position,$eyebrow,$heading,$body,$config]){$q=$pdo->prepare('INSERT INTO artist_home_sections(section_key,enabled,position,eyebrow,heading,body_text,config_json,updated_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$key,$enabled,$position,$eyebrow,$heading,$body,sf_artist_home_json($config),$now]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return sf_artist_home_sections(false);
}
function sf_artist_home_public_media(string $type,string $id,array $roles=[]): ?array {
    if(!function_exists('sf_media_public_links'))return null;$rows=sf_media_public_links($type,$id);foreach($roles as $role)foreach($rows as $m)if(($m['role']??'')===$role)return $m;foreach($rows as $m)if(($m['category']??'')==='image')return $m;return null;
}
function sf_artist_home_release(string $preferred=''): ?array {
    $rows=array_values(array_filter(sf_release_rows(),fn($r)=>($r['state']??'published')==='published'&&($r['public_visible']??true)!==false));usort($rows,fn($a,$b)=>strcmp((string)($b['release_date']??''),(string)($a['release_date']??'')));
    $r=null;if($preferred!=='')foreach($rows as $x)if((string)($x['id']??'')===$preferred){$r=$x;break;}if(!$r)$r=$rows[0]??null;if(!$r)return null;$id=(string)$r['id'];$cover=sf_artist_home_public_media('release',$id,['cover']);$d=function_exists('sf_distribution_record')?sf_distribution_record($id,false):null;$links=[];foreach((array)($d['destinations']??[]) as $x)if(($x['status']??'')==='live'&&!empty($x['release_url']))$links[]=['provider'=>(string)$x['provider'],'url'=>(string)$x['release_url']];
    return ['id'=>$id,'title'=>(string)($r['title']??$id),'type'=>(string)($r['type']??'release'),'release_date'=>(string)($r['release_date']??''),'description'=>(string)($r['description']??''),'track_ids'=>array_values((array)($r['track_ids']??[])),'cover'=>$cover?['url'=>$cover['url'],'alt_text'=>$cover['alt_text']??'']:null,'dsp_links'=>$links];
}
function sf_artist_home_track(string $preferred='',?array $release=null): ?array {
    $catalog=sf_catalog();$id=$preferred;if($id==='')$id=(string)($release['track_ids'][0]??'');$track=null;foreach($catalog as $t)if((string)($t['id']??'')===$id){$track=$t;break;}if(!$track){foreach($catalog as $t){$id=(string)($t['id']??'');if($id!==''&&sf_artist_home_public_media('track',$id,['primary_audio'])){$track=$t;break;}}}if(!$track)return null;$id=(string)$track['id'];$audio=sf_artist_home_public_media('track',$id,['primary_audio']);$art=sf_artist_home_public_media('track',$id,['primary_artwork','artwork']);
    return ['id'=>$id,'title'=>(string)($track['title']??$id),'release'=>(string)($track['release']??''),'duration'=>(int)($track['duration']??0),'story'=>(string)($track['story']??''),'mood'=>array_values((array)($track['mood']??[])),'audio'=>$audio?['url'=>$audio['url'],'duration'=>(float)($audio['duration']??0)]:null,'artwork'=>$art?['url'=>$art['url'],'alt_text'=>$art['alt_text']??'']:null];
}
function sf_artist_home_show(string $preferred=''): ?array {
    if(!function_exists('sf_live_public_shows'))return null;$rows=sf_live_public_shows();$today=gmdate('Y-m-d');$show=null;if($preferred!=='')foreach($rows as $x)if((string)($x['id']??'')===$preferred){$show=$x;break;}if(!$show)foreach($rows as $x)if((string)($x['date']??'')>=$today){$show=$x;break;}if(!$show)return null;$id=(string)$show['id'];$poster=sf_artist_home_public_media('show',$id,['poster']);$ticket=(string)($show['ticket_url']??'');if($ticket!==''&&(!filter_var($ticket,FILTER_VALIDATE_URL)||strtolower((string)parse_url($ticket,PHP_URL_SCHEME))!=='https'))$ticket='';
    return ['id'=>$id,'title'=>(string)($show['title']??$show['venue']??'Stonefellow live'),'date'=>(string)($show['date']??''),'venue'=>(string)($show['venue']??''),'location'=>function_exists('sf_live_location')?sf_live_location($show):'','ticket_url'=>$ticket,'poster'=>$poster?['url'=>$poster['url'],'alt_text'=>$poster['alt_text']??'']:null];
}
function sf_artist_home_product(string $preferred=''): ?array {
    if(!function_exists('sf_commerce_products'))return null;$rows=sf_commerce_products(true);$p=null;if($preferred!=='')foreach($rows as $x)if((string)($x['id']??'')===$preferred){$p=$x;break;}if(!$p)foreach($rows as $x)if(!empty($x['featured'])&&!empty($x['available'])){$p=$x;break;}if(!$p)foreach($rows as $x)if(!empty($x['available'])){$p=$x;break;}if(!$p)return null;
    return ['id'=>(string)$p['id'],'slug'=>(string)$p['slug'],'title'=>(string)$p['title'],'description'=>(string)$p['description'],'price_cents'=>(int)$p['base_price_cents'],'compare_at_price_cents'=>(int)$p['compare_at_price_cents'],'available'=>!empty($p['available']),'image'=>(string)($p['image']??'')];
}
function sf_artist_home_latest_releases(int $limit=3): array { $rows=[];foreach(sf_release_rows() as $r){if(($r['state']??'published')!=='published'||($r['public_visible']??true)===false)continue;$x=sf_artist_home_release((string)($r['id']??''));if($x)$rows[]=$x;}usort($rows,fn($a,$b)=>strcmp((string)$b['release_date'],(string)$a['release_date']));return array_slice($rows,0,max(1,min(6,$limit))); }
function sf_artist_home_payload(int $userId=0): array {
    $site=sf_site_settings();$sections=[];$featuredRelease=null;foreach(sf_artist_home_sections(true) as $s){$key=(string)$s['section_key'];$cfg=(array)$s['config'];$data=null;
      if($key==='hero'){$data=['hero_image'=>(string)($site['media_hero']??''),'artist_photo'=>(string)($site['media_artist_photo']??''),'logo'=>(string)($site['media_logo']??''),'primary_label'=>$cfg['primary_label']??'Listen now','primary_view'=>$cfg['primary_view']??'music','secondary_label'=>$cfg['secondary_label']??'Explore releases','secondary_view'=>$cfg['secondary_view']??'releases'];}
      elseif($key==='featured_release'){$featuredRelease=sf_artist_home_release((string)($cfg['release_id']??''));$data=$featuredRelease;}
      elseif($key==='featured_track'){$data=sf_artist_home_track((string)($cfg['track_id']??''),$featuredRelease?:sf_artist_home_release(''));}
      elseif($key==='latest_releases'){$data=sf_artist_home_latest_releases((int)($cfg['limit']??3));}
      elseif($key==='upcoming_show'){$data=sf_artist_home_show((string)($cfg['show_id']??''));}
      elseif($key==='merch'){$data=sf_artist_home_product((string)($cfg['product_id']??''));}
      elseif($key==='artist_story'){$data=['artist_photo'=>(string)($site['media_artist_photo']??''),'button_label'=>$cfg['button_label']??'About Stonefellow','button_view'=>$cfg['button_view']??'about'];}
      elseif($key==='newsletter'){$data=['compact'=>!empty($cfg['compact'])];}
      elseif($key==='personalized'){$data=$userId>0?sf_home_state($userId):null;}
      if($key==='personalized'&&$userId<1)continue;if($data===null&&in_array($key,['featured_release','featured_track','upcoming_show','merch'],true))continue;$sections[]=['section_key'=>$key,'eyebrow'=>$s['eyebrow'],'heading'=>$s['heading'],'body_text'=>$s['body_text'],'data'=>$data];
    }
    return ['enabled'=>!empty($site['artist_homepage_enabled']),'artist'=>'Stonefellow','sections'=>$sections,'generated_at'=>gmdate('c')];
}
function sf_artist_home_readiness(): array {
    $payload=sf_artist_home_payload(0);$checks=[];$blockers=[];$warnings=[];
    foreach((array)$payload['sections'] as $s){$k=(string)$s['section_key'];$d=$s['data']??null;$ok=true;$detail='Ready.';
      if($k==='hero'){$ok=trim((string)$s['heading'])!=='';$detail=$ok?(!empty($d['hero_image'])?'Hero copy + media ready.':'Hero copy ready; no hero image published.'):'Hero heading is missing.';if($ok&&empty($d['hero_image']))$warnings[]='Hero section has no published site hero image.';}
      elseif($k==='featured_release'){$ok=is_array($d)&&!empty($d['id']);$detail=$ok?(!empty($d['cover'])?'Featured release ready.':'Featured release has no public cover.'):'No public featured release is available.';if($ok&&empty($d['cover']))$warnings[]='Featured release has no public cover image.';}
      elseif($k==='featured_track'){$ok=is_array($d)&&!empty($d['id'])&&!empty($d['audio']['url']);$detail=$ok?'Featured track has public audio.':'Featured track needs an explicitly Public primary audio asset.';}
      elseif($k==='latest_releases'){$ok=is_array($d)&&count($d)>0;$detail=$ok?count($d).' public release(s).':'No public releases available.';}
      elseif($k==='upcoming_show'){$ok=$d===null||!empty($d['id']);$detail=$d?'Upcoming show ready.':'No upcoming public show; section will be omitted.';if($d===null)$warnings[]='Upcoming Show is enabled but no future public show is available.';}
      elseif($k==='merch'){$ok=$d===null||!empty($d['id']);$detail=$d?'Featured merch ready.':'No active available merch; section will be omitted.';if($d===null)$warnings[]='Featured Merch is enabled but no active available product is available.';}
      elseif($k==='artist_story'){$ok=trim((string)$s['body_text'])!=='';$detail=$ok?(!empty($d['artist_photo'])?'Story + artist photo ready.':'Story ready; no artist photo published.'):'Artist story copy is empty.';if($ok&&empty($d['artist_photo']))$warnings[]='Artist Story has no published artist photo.';}
      $checks[]=['key'=>$k,'label'=>ucwords(str_replace('_',' ',$k)),'ok'=>$ok,'detail'=>$detail];if(!$ok&&in_array($k,['hero','featured_release','featured_track'],true))$blockers[]=$detail;elseif(!$ok)$warnings[]=$detail;
    }
    $passed=count(array_filter($checks,fn($c)=>!empty($c['ok'])));$score=count($checks)?(int)round($passed*100/count($checks)):0;return ['ready'=>$blockers===[],'score'=>$score,'checks'=>$checks,'blockers'=>array_values(array_unique($blockers)),'warnings'=>array_values(array_unique($warnings))];
}
function sf_artist_home_event(string $eventType,string $sectionKey='',string $entityType='',string $entityId='',int $userId=0,array $detail=[]): void {
    sf_artist_home_ensure_schema();$allowed=['view','hero_primary','hero_secondary','release_open','track_open','track_play','show_open','merch_open','newsletter_open','about_open','personalized_open','agent_open'];if(!in_array($eventType,$allowed,true))return;if($sectionKey!==''&&!in_array($sectionKey,sf_artist_home_keys(),true))return;$safe=[];foreach(array_slice($detail,0,6,true) as $k=>$v)$safe[sf_clean_text((string)$k,60)]=is_scalar($v)?sf_clean_text((string)$v,180):'';
    $q=sf_db()->prepare('INSERT INTO artist_home_events(event_type,section_key,entity_type,entity_id,user_id,detail_json,created_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$eventType,$sectionKey,sf_clean_text($entityType,60),sf_clean_text($entityId,160),$userId?:null,sf_artist_home_json($safe),gmdate('c')]);
}
function sf_artist_home_metrics(): array {
    sf_artist_home_ensure_schema();$rows=sf_db()->query("SELECT event_type,COUNT(*) total FROM artist_home_events WHERE created_at>='".gmdate('c',time()-2592000)."' GROUP BY event_type ORDER BY total DESC")->fetchAll();$events=[];foreach($rows as $r)$events[(string)$r['event_type']]=(int)$r['total'];return ['views'=>$events['view']??0,'events'=>$events,'sections'=>sf_artist_home_sections(false),'readiness'=>sf_artist_home_readiness()];
}
