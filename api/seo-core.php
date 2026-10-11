<?php
declare(strict_types=1);

function sf_seo_json(array $v): string { return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?: '{}'; }
function sf_seo_base_url(): string { return rtrim(sf_public_base_url(),'/'); }
function sf_seo_abs(string $path): string {
    $path=trim($path);if($path==='')return sf_seo_base_url().'/';
    if(preg_match('#^https://#i',$path))return $path;
    if(preg_match('#^http://#i',$path))return '';
    return sf_seo_base_url().'/'.ltrim($path,'/');
}
function sf_seo_https_url(string $url): bool { return $url!==''&&(bool)filter_var($url,FILTER_VALIDATE_URL)&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https'; }
function sf_seo_web_url(string $url): bool { if($url===''||!filter_var($url,FILTER_VALIDATE_URL))return false;return in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true); }
function sf_seo_excerpt(string $text,int $max=180): string {
    $text=preg_replace('/\\s+/u',' ',trim(strip_tags($text)))??'';if($text==='')return '';
    if(function_exists('mb_strlen')&&mb_strlen($text)>$max)return rtrim(mb_substr($text,0,$max-1)).'…';
    return strlen($text)>$max?rtrim(substr($text,0,$max-1)).'…':$text;
}
function sf_seo_slug(string $v,string $fallback='item'): string { $v=strtolower(trim($v));$v=preg_replace('/[^a-z0-9]+/','-',$v)??'';$v=trim(substr($v,0,140),'-');return $v!==''?$v:$fallback; }

function sf_seo_release_rows(): array {
    if(function_exists('sf_release_rows')){try{$rows=sf_release_rows();if(is_array($rows))return $rows;}catch(Throwable $e){}}
    $path=SF_ROOT.'/data/releases.json';if(!is_file($path))return [];$rows=json_decode((string)file_get_contents($path),true);return is_array($rows)?array_values($rows):[];
}
function sf_seo_public_release(string $id): ?array {
    foreach(sf_seo_release_rows() as $r){if((string)($r['id']??'')!==$id)continue;if(($r['state']??'published')!=='published'||($r['public_visible']??true)===false)return null;return $r;}return null;
}
function sf_seo_public_track(string $id): ?array {
    $t=sf_track_map()[$id]??null;if(!$t||($t['public_visible']??true)===false||in_array((string)($t['state']??''),['draft','private','archived'],true))return null;
    $releaseId=(string)($t['release_id']??'');$release=null;
    if($releaseId!==''){$release=sf_seo_public_release($releaseId);if(!$release)return null;}
    if(!$release&&($t['release']??'')!==''){foreach(sf_seo_release_rows() as $r)if((string)($r['title']??'')===(string)$t['release']&&($r['state']??'published')==='published'&&($r['public_visible']??true)!==false){$release=$r;break;}}
    $t['_release']=$release;return $t;
}
function sf_seo_public_show(string $id): ?array { if(!function_exists('sf_live_show_by_id'))return null;$s=sf_live_show_by_id($id);if(!$s||($s['public_visible']??true)===false)return null;return $s; }
function sf_seo_public_product(string $key): ?array {
    if(!function_exists('sf_commerce_products'))return null;foreach(sf_commerce_products(true) as $p){if((string)($p['id']??'')===$key||(string)($p['slug']??'')===$key)return $p;}return null;
}
function sf_seo_public_campaign(string $slug): ?array { if(!function_exists('sf_campaign_by_slug'))return null;$c=sf_campaign_by_slug($slug,true);return $c&&sf_campaign_is_live($c)?$c:null; }
function sf_seo_public_press(string $slug): ?array {
    if(!function_exists('sf_press_kit'))return null;$k=sf_press_kit($slug);if(!$k||($k['status']??'')!=='published'||empty($k['readiness']['ready']))return null;return $k;
}
function sf_seo_public_media(string $type,string $id,array $roles=[]): ?array {
    if(!function_exists('sf_media_public_links'))return null;$rows=sf_media_public_links($type,$id);
    foreach($roles as $role)foreach($rows as $m)if(($m['role']??'')===$role)return $m;
    foreach($rows as $m)if(($m['category']??'')==='image')return $m;return null;
}
function sf_seo_image_url(?array $m,string $fallback=''): string {
    $u=(string)($m['url']??$fallback);$a=sf_seo_abs($u);return sf_seo_https_url($a)?$a:'';
}
function sf_seo_duration_iso(int|float $seconds): string { $s=max(0,(int)round((float)$seconds));return 'PT'.intdiv($s,3600).'H'.intdiv($s%3600,60).'M'.($s%60).'S'; }

function sf_seo_default_meta(): array {
    $site=sf_site_settings();$base=sf_seo_base_url();$image=sf_seo_abs((string)($site['media_social_share']??$site['media_hero']??''));
    return [
      'title'=>(string)($site['seo_title']??'Stonefellow'),
      'description'=>(string)($site['seo_description']??'Stonefellow music, releases, shows and direct-to-fan experiences.'),
      'canonical'=>$base.'/','image'=>sf_seo_https_url($image)?$image:'',
      'type'=>'website','robots'=>'index,follow,max-image-preview:large','view'=>'home','id'=>'','slug'=>'','json_ld'=>[],
      'server_title'=>'Stonefellow','server_body'=>(string)($site['seo_description']??'Stonefellow music, releases, shows and direct-to-fan experiences.'),
    ];
}
function sf_seo_artist_schema(): array {
    $site=sf_site_settings();$sameAs=array_values(array_filter([(string)($site['social_instagram']??''),(string)($site['social_youtube']??''),(string)($site['social_bandcamp']??''),(string)($site['social_spotify']??'')],fn($u)=>sf_seo_https_url($u)));
    $photo=sf_seo_abs((string)($site['media_artist_photo']??$site['media_social_share']??''));
    $o=['@context'=>'https://schema.org','@type'=>'MusicGroup','@id'=>sf_seo_base_url().'/#artist','name'=>'Stonefellow','url'=>sf_seo_base_url().'/'];
    if(sf_seo_https_url($photo))$o['image']=$photo;if($sameAs)$o['sameAs']=$sameAs;return $o;
}
function sf_seo_resolve(?string $view=null,string $id='',string $slug=''): array {
    $m=sf_seo_default_meta();$view=$view!==null?$view:sf_clean_text($_GET['view']??'',40);$id=$id!==''?$id:sf_clean_text($_GET['id']??'',160);$slug=$slug!==''?$slug:sf_clean_text($_GET['slug']??'',160);
    $m['view']=$view?:'home';$m['id']=$id;$m['slug']=$slug;$site=sf_site_settings();$socialFallback=(string)($site['media_social_share']??$site['media_hero']??'');
    if($view===''||$view==='home'){
      $m['title']='Stonefellow — Official Artist Site';$m['description']=sf_seo_excerpt((string)($site['seo_description']??''),180)?:'Stonefellow music, releases, shows, stories, merch and direct fan experiences.';$m['canonical']=sf_seo_base_url().'/';$m['json_ld']=[sf_seo_artist_schema()];$m['server_title']='Stonefellow';$m['server_body']=$m['description'];return $m;
    }
    if($view==='release'&&$id!==''){
      $r=sf_seo_public_release($id);if(!$r)return sf_seo_noindex($m,'Release unavailable.');
      $title=(string)($r['title']??$id);$desc=sf_seo_excerpt((string)($r['description']??$r['liner_notes']??''),180)?:$title.' by Stonefellow.';$cover=sf_seo_public_media('release',$id,['cover']);$img=sf_seo_image_url($cover,(string)($r['artwork']['cover']??$socialFallback));$url=sf_seo_base_url().'/release/'.rawurlencode($id);
      $tracks=[];foreach((array)($r['track_ids']??[]) as $tid){$t=sf_seo_public_track((string)$tid);if(!$t)continue;$tracks[]=['@type'=>'MusicRecording','name'=>(string)($t['title']??$tid),'url'=>sf_seo_base_url().'/track/'.rawurlencode((string)$tid),'duration'=>sf_seo_duration_iso((int)($t['duration']??0))];}
      $schema=['@context'=>'https://schema.org','@type'=>'MusicAlbum','name'=>$title,'url'=>$url,'byArtist'=>['@id'=>sf_seo_base_url().'/#artist'],'datePublished'=>(string)($r['release_date']??''),'numTracks'=>count($tracks),'track'=>$tracks];if($img)$schema['image']=$img;if($desc)$schema['description']=$desc;
      return array_merge($m,['title'=>$title.' — Stonefellow','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'music.album','json_ld'=>[sf_seo_artist_schema(),$schema],'server_title'=>$title,'server_body'=>$desc]);
    }
    if($view==='track'&&$id!==''){
      $t=sf_seo_public_track($id);if(!$t)return sf_seo_noindex($m,'Track unavailable.');$title=(string)($t['title']??$id);$desc=sf_seo_excerpt((string)($t['story']??$t['metadata']['recording_notes']??''),180)?:$title.' by Stonefellow.';$art=sf_seo_public_media('track',$id,['primary_artwork','artwork']);$release=(array)($t['_release']??[]);$img=sf_seo_image_url($art,(string)($t['artwork']??$release['artwork']['cover']??$socialFallback));$url=sf_seo_base_url().'/track/'.rawurlencode($id);
      $schema=['@context'=>'https://schema.org','@type'=>'MusicRecording','name'=>$title,'url'=>$url,'byArtist'=>['@id'=>sf_seo_base_url().'/#artist'],'duration'=>sf_seo_duration_iso((int)($t['duration']??0))];if($img)$schema['image']=$img;if($desc)$schema['description']=$desc;if(!empty($t['metadata']['isrc']))$schema['isrcCode']=(string)$t['metadata']['isrc'];if($release)$schema['inAlbum']=['@type'=>'MusicAlbum','name'=>(string)($release['title']??$t['release']??''),'url'=>sf_seo_base_url().'/release/'.rawurlencode((string)($release['id']??''))];
      return array_merge($m,['title'=>$title.' — Stonefellow','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'music.song','json_ld'=>[sf_seo_artist_schema(),$schema],'server_title'=>$title,'server_body'=>$desc]);
    }
    if($view==='show'&&$id!==''){
      $s=sf_seo_public_show($id);if(!$s)return sf_seo_noindex($m,'Show unavailable.');$name=(string)($s['title']??$s['venue']??'Stonefellow Live');$desc=sf_seo_excerpt((string)($s['description']??$s['archive_notes']??''),180)?:'Stonefellow live at '.(string)($s['venue']??'a live venue').'.';$poster=sf_seo_public_media('show',$id,['poster']);$img=sf_seo_image_url($poster,(string)($s['poster']??$socialFallback));$url=sf_seo_base_url().'/show/'.rawurlencode($id);$loc=function_exists('sf_live_location')?sf_live_location($s):trim(implode(', ',array_filter([$s['city']??'',$s['region']??'',$s['country']??''])));
      $schema=['@context'=>'https://schema.org','@type'=>'MusicEvent','name'=>$name,'url'=>$url,'startDate'=>(string)($s['date']??''),'performer'=>['@id'=>sf_seo_base_url().'/#artist'],'location'=>['@type'=>'Place','name'=>(string)($s['venue']??''),'address'=>$loc]];if($img)$schema['image']=$img;if($desc)$schema['description']=$desc;$ticket=(string)($s['ticket_url']??'');if(sf_seo_https_url($ticket))$schema['offers']=['@type'=>'Offer','url'=>$ticket,'availability'=>'https://schema.org/InStock'];
      return array_merge($m,['title'=>$name.' — Stonefellow Live','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'event','json_ld'=>[sf_seo_artist_schema(),$schema],'server_title'=>$name,'server_body'=>$desc]);
    }
    if($view==='store'&&$slug!==''){
      $p=sf_seo_public_product($slug);if(!$p)return sf_seo_noindex($m,'Product unavailable.');$name=(string)$p['title'];$desc=sf_seo_excerpt((string)($p['description']??''),180)?:$name.' from Stonefellow.';$img=sf_seo_abs((string)($p['image']??$socialFallback));if(!sf_seo_https_url($img))$img='';$canonSlug=(string)($p['slug']??$p['id']);$url=sf_seo_base_url().'/store/'.rawurlencode($canonSlug);
      $schema=['@context'=>'https://schema.org','@type'=>'Product','name'=>$name,'url'=>$url,'description'=>$desc,'brand'=>['@type'=>'Brand','name'=>'Stonefellow'],'offers'=>['@type'=>'Offer','priceCurrency'=>(string)(sf_store_config()['currency']??'USD'),'price'=>number_format(((int)($p['base_price_cents']??0))/100,2,'.',''),'availability'=>!empty($p['available'])?'https://schema.org/InStock':'https://schema.org/OutOfStock']];if($img)$schema['image']=$img;
      return array_merge($m,['title'=>$name.' — Stonefellow Store','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'product','json_ld'=>[$schema],'server_title'=>$name,'server_body'=>$desc]);
    }
    if($view==='campaign'&&$slug!==''){
      $c=sf_seo_public_campaign($slug);if(!$c)return sf_seo_noindex($m,'Campaign unavailable.');$landing=(array)($c['landing']??[]);$name=(string)($landing['headline']??$c['name']??'Stonefellow');$desc=sf_seo_excerpt((string)($landing['body']??$c['description']??''),180)?:'Stonefellow direct fan campaign.';$img=sf_seo_abs((string)($c['artwork']??$socialFallback));if(!sf_seo_https_url($img))$img='';$url=sf_seo_base_url().'/campaign/'.rawurlencode($slug);
      return array_merge($m,['title'=>$name.' — Stonefellow','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'website','json_ld'=>[['@context'=>'https://schema.org','@type'=>'WebPage','name'=>$name,'url'=>$url,'description'=>$desc]],'server_title'=>$name,'server_body'=>$desc]);
    }
    if($view==='press'&&$slug!==''){
      $k=sf_seo_public_press($slug);if(!$k)return sf_seo_noindex($m,'Press kit unavailable.');$name=(string)($k['headline']??$k['title']??'Stonefellow Press Kit');$desc=sf_seo_excerpt((string)($k['summary']??$k['bio']??''),180)?:'Official Stonefellow electronic press kit.';$hero=sf_seo_public_media('press_kit',(string)$k['id'],['hero']);$img=sf_seo_image_url($hero,$socialFallback);$url=sf_seo_base_url().'/press/'.rawurlencode($slug);
      return array_merge($m,['title'=>$name.' — Stonefellow Press Kit','description'=>$desc,'canonical'=>$url,'image'=>$img,'type'=>'profile','json_ld'=>[sf_seo_artist_schema(),['@context'=>'https://schema.org','@type'=>'ProfilePage','name'=>$name,'url'=>$url,'mainEntity'=>['@id'=>sf_seo_base_url().'/#artist']]],'server_title'=>$name,'server_body'=>$desc]);
    }
    $publicViews=['music'=>'Music','releases'=>'Releases','shows'=>'Shows & Live','store'=>'Store','about'=>'About Stonefellow','archive'=>'Music Archive'];
    if(isset($publicViews[$view])){$name=$publicViews[$view];$m['title']=$name.' — Stonefellow';$m['description']='Explore '.$name.' from Stonefellow.';$m['canonical']=sf_seo_base_url().'/'.($view==='music'?'music':$view);$m['server_title']=$name;$m['server_body']=$m['description'];$m['json_ld']=[sf_seo_artist_schema()];return $m;}
    return sf_seo_noindex($m,'This Stonefellow page is not intended for search indexing.');
}
function sf_seo_noindex(array $m,string $body=''): array { $m['robots']='noindex,nofollow,noarchive';$m['canonical']='';$m['json_ld']=[];$m['server_title']='Stonefellow';$m['server_body']=$body;return $m; }
function sf_seo_server_html(array $m): string {
    $title=htmlspecialchars((string)($m['server_title']??'Stonefellow'),ENT_QUOTES,'UTF-8');$body=htmlspecialchars((string)($m['server_body']??''),ENT_QUOTES,'UTF-8');
    return '<article class="seo-server-snapshot" data-seo-snapshot><div class="module-kicker">STONEFELLOW</div><h1>'.$title.'</h1>'.($body!==''?'<p>'.$body.'</p>':'').'</article>';
}

function sf_seo_sitemap_entries(): array {
    $out=[];$add=function(string $url,string $lastmod='',string $priority='0.7')use(&$out){if(!sf_seo_web_url($url))return;$out[$url]=['loc'=>$url,'lastmod'=>$lastmod,'priority'=>$priority];};
    $base=sf_seo_base_url();foreach([''=>'1.0','music'=>'0.8','releases'=>'0.9','shows'=>'0.8','store'=>'0.8','about'=>'0.6','archive'=>'0.7'] as $path=>$priority)$add($base.'/'.($path!==''?$path:''),'',$priority);
    foreach(sf_seo_release_rows() as $r){if(($r['state']??'published')!=='published'||($r['public_visible']??true)===false||empty($r['id']))continue;$id=(string)$r['id'];$add($base.'/release/'.rawurlencode($id),(string)($r['updated_at']??$r['release_date']??''),'0.9');foreach((array)($r['track_ids']??[]) as $tid){if(sf_seo_public_track((string)$tid))$add($base.'/track/'.rawurlencode((string)$tid),(string)($r['updated_at']??$r['release_date']??''),'0.8');}}
    foreach(sf_catalog() as $t){$tid=(string)($t['id']??'');if($tid!==''&&sf_seo_public_track($tid))$add($base.'/track/'.rawurlencode($tid),(string)($t['updated_at']??$t['metadata']['release_date']??''),'0.8');}
    if(function_exists('sf_live_public_shows'))foreach(sf_live_public_shows() as $s)if(!empty($s['id']))$add($base.'/show/'.rawurlencode((string)$s['id']),(string)($s['updated_at']??$s['date']??''),'0.7');
    if(function_exists('sf_commerce_products'))foreach(sf_commerce_products(true) as $p)if(!empty($p['slug']??$p['id']))$add($base.'/store/'.rawurlencode((string)($p['slug']??$p['id'])),(string)($p['updated_at']??''),'0.6');
    if(function_exists('sf_campaign_list'))foreach(sf_campaign_list(500) as $c)if(!empty($c['slug'])&&sf_campaign_is_live($c))$add($base.'/campaign/'.rawurlencode((string)$c['slug']),(string)($c['updated_at']??''),'0.5');
    if(function_exists('sf_press_kits'))foreach(sf_press_kits() as $k)if(($k['status']??'')==='published'&&!empty($k['slug'])&&!empty($k['readiness']['ready']))$add($base.'/press/'.rawurlencode((string)$k['slug']),(string)($k['updated_at']??''),'0.5');
    return array_values($out);
}

function sf_seo_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){$pdo->exec("CREATE TABLE IF NOT EXISTS discovery_events (id INTEGER PRIMARY KEY AUTOINCREMENT,event_type TEXT NOT NULL,source TEXT NOT NULL DEFAULT '',medium TEXT NOT NULL DEFAULT '',route_type TEXT NOT NULL DEFAULT '',route_id TEXT NOT NULL DEFAULT '',user_id INTEGER NULL,detail_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");$pdo->exec("CREATE INDEX IF NOT EXISTS idx_discovery_events_created ON discovery_events(created_at,event_type,source)");}
    else{$pdo->exec("CREATE TABLE IF NOT EXISTS discovery_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,event_type VARCHAR(50) NOT NULL,source VARCHAR(120) NOT NULL DEFAULT '',medium VARCHAR(80) NOT NULL DEFAULT '',route_type VARCHAR(50) NOT NULL DEFAULT '',route_id VARCHAR(160) NOT NULL DEFAULT '',user_id BIGINT UNSIGNED NULL,detail_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_discovery_events_created(created_at,event_type,source),CONSTRAINT fk_discovery_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}
    $done=true;
}
function sf_seo_normalize_source(string $referrer,string $utmSource=''): array {
    $source=sf_seo_slug($utmSource,'');$medium='';
    if($source!=='')$medium='campaign';
    else if($referrer===''){$source='direct';$medium='direct';}
    else{$host=strtolower((string)parse_url($referrer,PHP_URL_HOST));$own=strtolower((string)parse_url(sf_seo_base_url(),PHP_URL_HOST));if($host===''||$host===$own){$source='internal';$medium='internal';}elseif(str_contains($host,'google.')||str_contains($host,'bing.')||str_contains($host,'duckduckgo.')||str_contains($host,'yahoo.')){$source='search';$medium='organic';}elseif(str_contains($host,'instagram.')||str_contains($host,'facebook.')||str_contains($host,'tiktok.')||str_contains($host,'x.com')||str_contains($host,'twitter.')||str_contains($host,'youtube.')){$source='social';$medium='social';}else{$source=sf_clean_text($host,120);$medium='referral';}}
    return ['source'=>sf_clean_text($source,120),'medium'=>sf_clean_text($medium,80)];
}
function sf_seo_record_event(string $eventType,array $raw,int $userId=0): void {
    sf_seo_ensure_schema();$allowed=['landing','share'];if(!in_array($eventType,$allowed,true))return;$src=sf_seo_normalize_source((string)($raw['referrer']??''),(string)($raw['utm_source']??''));$routeType=sf_clean_text($raw['route_type']??'',50);$routeId=sf_clean_text($raw['route_id']??'',160);$detail=['utm_medium'=>sf_clean_text($raw['utm_medium']??'',80),'utm_campaign'=>sf_clean_text($raw['utm_campaign']??'',120),'share_target'=>sf_clean_text($raw['share_target']??'',60)];
    sf_db()->prepare('INSERT INTO discovery_events(event_type,source,medium,route_type,route_id,user_id,detail_json,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$eventType,$src['source'],$src['medium'],$routeType,$routeId,$userId?:null,sf_seo_json($detail),gmdate('c')]);
}
function sf_seo_discovery_summary(int $days=30): array {
    sf_seo_ensure_schema();$since=gmdate('c',time()-max(1,min(3650,$days))*86400);$q=sf_db()->prepare("SELECT source,medium,COUNT(*) total FROM discovery_events WHERE created_at>=? AND event_type='landing' GROUP BY source,medium ORDER BY total DESC LIMIT 30");$q->execute([$since]);$sources=$q->fetchAll();$q=sf_db()->prepare("SELECT route_type,COUNT(*) total FROM discovery_events WHERE created_at>=? AND event_type='landing' GROUP BY route_type ORDER BY total DESC LIMIT 30");$q->execute([$since]);return ['days'=>$days,'sources'=>$sources,'routes'=>$q->fetchAll()];
}
