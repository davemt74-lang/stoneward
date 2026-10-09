<?php
declare(strict_types=1);

function sf_search_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_search_events (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,session_hash TEXT NOT NULL,event_type TEXT NOT NULL,query_text TEXT NOT NULL DEFAULT '',normalized_query TEXT NOT NULL DEFAULT '',result_count INTEGER NOT NULL DEFAULT 0,result_type TEXT NOT NULL DEFAULT '',result_id TEXT NOT NULL DEFAULT '',filters_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_search_events_created ON catalog_search_events(created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_search_events_query ON catalog_search_events(normalized_query,created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_search_events_user ON catalog_search_events(user_id,created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_search_events_result ON catalog_search_events(result_type,result_id,created_at)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS catalog_search_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,session_hash CHAR(64) NOT NULL,event_type VARCHAR(24) NOT NULL,query_text VARCHAR(300) NOT NULL DEFAULT '',normalized_query VARCHAR(300) NOT NULL DEFAULT '',result_count INT NOT NULL DEFAULT 0,result_type VARCHAR(24) NOT NULL DEFAULT '',result_id VARCHAR(180) NOT NULL DEFAULT '',filters_json LONGTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,INDEX idx_search_events_created(created_at),INDEX idx_search_events_query(normalized_query(191),created_at),INDEX idx_search_events_user(user_id,created_at),INDEX idx_search_events_result(result_type,result_id,created_at),CONSTRAINT fk_search_events_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_search_normalize(string $value): string {
    $value=trim($value);if($value==='')return '';
    $value=function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    $value=preg_replace('/[^\pL\pN]+/u',' ',$value)??$value;
    return trim(preg_replace('/\s+/u',' ',$value)??$value);
}
function sf_search_tokens(string $query): array {
    $q=sf_search_normalize($query);if($q==='')return [];
    $stop=array_fill_keys(['a','an','and','about','by','find','for','from','in','me','music','of','on','or','play','search','show','song','songs','something','the','to','track','tracks','with'],true);
    $out=[];foreach(explode(' ',$q) as $t){if($t===''||isset($stop[$t]))continue;$out[$t]=true;}return array_keys($out);
}
function sf_search_extract_intent_query(string $message): string {
    $q=sf_search_normalize($message);$tokens=sf_search_tokens($q);return $tokens?implode(' ',$tokens):$q;
}
function sf_search_string_values(mixed $value): array {
    if(is_array($value)){ $out=[];foreach($value as $v)$out=array_merge($out,sf_search_string_values($v));return $out; }
    if(is_scalar($value)){ $s=trim((string)$value);return $s!==''?[$s]:[]; }
    return [];
}
function sf_search_track_fields(array $t): array {
    $m=is_array($t['metadata']??null)?$t['metadata']:[];
    return [
        'title'=>(string)($t['title']??''),
        'release'=>(string)($t['release']??''),
        'mood'=>array_values(array_filter(array_map('strval',(array)($t['mood']??[])))),
        'themes'=>array_values(array_filter(array_map('strval',(array)($t['themes']??[])))),
        'story'=>(string)($t['story']??''),
        'lyrics'=>(string)($t['lyrics']??''),
        'credits'=>array_values(array_filter(array_map('strval',(array)($t['credits']??[])))),
        'metadata'=>sf_search_string_values($m),
    ];
}
function sf_search_release_fields(array $r,array $trackMap): array {
    $trackTitles=[];$moods=[];$themes=[];
    foreach((array)($r['track_ids']??[]) as $id){$t=$trackMap[(string)$id]??null;if(!$t)continue;$trackTitles[]=(string)($t['title']??$id);foreach((array)($t['mood']??[]) as $x)$moods[]=(string)$x;foreach((array)($t['themes']??[]) as $x)$themes[]=(string)$x;}
    return [
        'title'=>(string)($r['title']??''),
        'type'=>(string)($r['type']??'release'),
        'description'=>(string)($r['description']??''),
        'notes'=>(string)($r['notes']??$r['liner_notes']??''),
        'credits'=>array_values(array_filter(array_map('strval',(array)($r['credits']??[])))),
        'metadata'=>array_values(array_filter([(string)($r['label']??''),(string)($r['genre']??''),(string)($r['catalog_number']??''),(string)($r['upc_ean']??'')])),
        'tracks'=>$trackTitles,'moods'=>array_values(array_unique($moods)),'themes'=>array_values(array_unique($themes)),
    ];
}
function sf_search_word_fuzzy_score(string $token,string $text): float {
    if(strlen($token)<4||$text==='')return 0.0;$best=99;
    foreach(array_slice(explode(' ',sf_search_normalize($text)),0,80) as $word){if(abs(strlen($word)-strlen($token))>2)continue;$d=levenshtein($token,$word);if($d<$best)$best=$d;if($best===1)break;}
    if($best===1)return 5.0;if($best===2&&strlen($token)>=6)return 2.5;return 0.0;
}
function sf_search_score_document(string $query,array $fields): array {
    $q=sf_search_normalize($query);$tokens=sf_search_tokens($q);if($q===''&&!$tokens)return ['score'=>0.0,'reason'=>''];
    $score=0.0;$reason='';$title=sf_search_normalize((string)($fields['title']??''));$release=sf_search_normalize((string)($fields['release']??''));
    if($q!==''&&$title===$q){$score+=140;$reason='Exact title';}
    elseif($q!==''&&str_starts_with($title,$q)){$score+=90;$reason='Title match';}
    elseif($q!==''&&str_contains($title,$q)){$score+=62;$reason='Title match';}
    if($q!==''&&$release===$q){$score+=48;if($reason==='')$reason='Release match';}
    elseif($q!==''&&$release!==''&&str_contains($release,$q)){$score+=30;if($reason==='')$reason='Release match';}
    $tagSets=['mood'=>38,'themes'=>38,'moods'=>34];
    foreach($tagSets as $key=>$weight){foreach((array)($fields[$key]??[]) as $tag){$n=sf_search_normalize((string)$tag);if($q!==''&&$n===$q){$score+=$weight;if($reason==='')$reason=ucfirst(rtrim($key,'s')).' match';}elseif($q!==''&&str_contains($n,$q)){$score+=$weight*.65;if($reason==='')$reason=ucfirst(rtrim($key,'s')).' match';}}}
    $weighted=[
        'story'=>12,'description'=>12,'notes'=>10,'lyrics'=>8,'credits'=>14,'metadata'=>10,'tracks'=>18,
    ];
    foreach($weighted as $key=>$weight){$vals=(array)($fields[$key]??[]);if(!is_array($fields[$key]??null))$vals=[(string)($fields[$key]??'')];$text=sf_search_normalize(implode(' ',array_map('strval',$vals)));if($q!==''&&$text!==''&&str_contains($text,$q)){$score+=$weight;if($reason==='')$reason=ucfirst($key).' match';}}
    if($tokens){
        $corpus=sf_search_normalize(implode(' ',sf_search_string_values($fields)));$hits=0;
        foreach($tokens as $token){if(str_contains($corpus,$token)){$hits++;$score+=14;}else{$score+=sf_search_word_fuzzy_score($token,$corpus);}}
        if($hits===count($tokens)){$score+=18;if($reason==='')$reason='All terms match';}
        elseif($hits>0&&$reason==='')$reason='Related metadata';
    }
    return ['score'=>round($score,2),'reason'=>$reason];
}
function sf_search_popularity_map(int $days=3650): array {
    sf_ops_ensure_schema();$since=gmdate('c',time()-max(1,min(3650,$days))*86400);$q=sf_db()->prepare("SELECT track_id,SUM(CASE WHEN event_type='start' THEN 1 ELSE 0 END) AS starts,SUM(CASE WHEN event_type='complete' THEN 1 ELSE 0 END) AS completes FROM listening_events WHERE created_at>=? GROUP BY track_id");$q->execute([$since]);$out=[];
    foreach($q->fetchAll() as $r)$out[(string)$r['track_id']]=['starts'=>(int)$r['starts'],'completes'=>(int)$r['completes'],'score'=>(int)$r['starts']+(int)$r['completes']*2];
    return $out;
}
function sf_search_facets(): array {
    $moods=[];$themes=[];$releases=[];$years=[];$energies=[];$trackMap=sf_track_map();
    foreach(sf_catalog() as $t){foreach((array)($t['mood']??[]) as $v){$v=trim((string)$v);if($v!=='')$moods[$v]=($moods[$v]??0)+1;}foreach((array)($t['themes']??[]) as $v){$v=trim((string)$v);if($v!=='')$themes[$v]=($themes[$v]??0)+1;}$rel=trim((string)($t['release']??''));if($rel!=='')$releases[$rel]=($releases[$rel]??0)+1;$year=(int)($t['year']??0);if($year>0)$years[(string)$year]=($years[(string)$year]??0)+1;$energy=(int)($t['energy']??0);if($energy>0)$energies[(string)$energy]=($energies[(string)$energy]??0)+1;}
    $pack=function(array $map): array {uksort($map,'strnatcasecmp');$out=[];foreach($map as $value=>$count)$out[]=['value'=>(string)$value,'count'=>(int)$count];return $out;};
    return ['moods'=>$pack($moods),'themes'=>$pack($themes),'releases'=>$pack($releases),'years'=>array_reverse($pack($years)),'energies'=>$pack($energies),'track_count'=>count($trackMap),'release_count'=>count(array_filter(sf_release_rows(),fn($r)=>($r['state']??'published')==='published'&&($r['public_visible']??true)!==false))];
}
function sf_search_params(array $raw): array {
    $type=in_array((string)($raw['type']??'all'),['all','track','release'],true)?(string)$raw['type']:'all';
    $sort=in_array((string)($raw['sort']??'relevance'),['relevance','popular','newest','title'],true)?(string)$raw['sort']:'relevance';
    return [
        'q'=>sf_clean_text((string)($raw['q']??''),300),'type'=>$type,'mood'=>sf_clean_text((string)($raw['mood']??''),80),'theme'=>sf_clean_text((string)($raw['theme']??''),80),'release'=>sf_clean_text((string)($raw['release']??''),160),
        'year'=>max(0,(int)($raw['year']??0)),'energy'=>max(0,min(5,(int)($raw['energy']??0))),'sort'=>$sort,'limit'=>max(1,min(80,(int)($raw['limit']??40))),
    ];
}
function sf_search_filter_active(array $p): bool {return $p['q']!==''||$p['type']!=='all'||$p['mood']!==''||$p['theme']!==''||$p['release']!==''||$p['year']>0||$p['energy']>0;}
function sf_catalog_search(array $raw,?int $userId=null): array {
    $p=sf_search_params($raw);$q=(string)$p['q'];$trackMap=sf_track_map();$pop=sf_search_popularity_map();$profile=$userId&&$userId>0?sf_personalization_recommendation_profile($userId):null;$rows=[];
    if($p['type']!=='release'){
        foreach(sf_catalog() as $idx=>$t){$id=(string)($t['id']??'');if($id==='')continue;
            if($p['mood']!==''&&!in_array($p['mood'],(array)($t['mood']??[]),true))continue;if($p['theme']!==''&&!in_array($p['theme'],(array)($t['themes']??[]),true))continue;if($p['release']!==''&&(string)($t['release']??'')!==$p['release'])continue;if($p['year']>0&&(int)($t['year']??0)!==$p['year'])continue;if($p['energy']>0&&(int)($t['energy']??0)!==$p['energy'])continue;
            $match=sf_search_score_document($q,sf_search_track_fields($t));if($q!==''&&$match['score']<=0)continue;$popScore=(int)($pop[$id]['score']??0);$personal=0.0;
            if($profile){$personal=min(28,max(-20,(float)($profile['seed_scores'][$id]??0)*.6));if(isset($profile['favorite_track_ids'][$id]))$personal+=12;}
            $score=(float)$match['score']+$personal+min(16,log(1+$popScore,2)*2.5);$reason=(string)$match['reason'];if($reason===''&&$personal>5)$reason='Fits your listening profile';if($reason===''&&$popScore>0)$reason='Popular with listeners';if($reason==='')$reason='Catalog track';
            $rows[]=['type'=>'track','id'=>$id,'title'=>(string)($t['title']??$id),'release'=>(string)($t['release']??''),'year'=>(int)($t['year']??0),'duration'=>(int)($t['duration']??0),'mood'=>array_values((array)($t['mood']??[])),'themes'=>array_values((array)($t['themes']??[])),'energy'=>(int)($t['energy']??0),'artwork'=>(string)($t['artwork']??''),'score'=>round($score,2),'reason'=>$reason,'popularity'=>$popScore,'personalized'=>$personal>0,'catalog_index'=>$idx];
        }
    }
    if($p['type']!=='track'){
        foreach(sf_release_rows() as $idx=>$r){if(($r['state']??'published')!=='published'||($r['public_visible']??true)===false)continue;$id=(string)($r['id']??'');if($id==='')continue;$fields=sf_search_release_fields($r,$trackMap);
            if($p['release']!==''&&(string)($r['title']??'')!==$p['release'])continue;if($p['year']>0&&substr((string)($r['release_date']??''),0,4)!==(string)$p['year'])continue;
            if($p['mood']!==''&&!in_array($p['mood'],$fields['moods'],true))continue;if($p['theme']!==''&&!in_array($p['theme'],$fields['themes'],true))continue;if($p['energy']>0){$ok=false;foreach((array)($r['track_ids']??[]) as $tid){if((int)($trackMap[(string)$tid]['energy']??0)===$p['energy']){$ok=true;break;}}if(!$ok)continue;}
            $match=sf_search_score_document($q,$fields);if($q!==''&&$match['score']<=0)continue;$releasePop=0;foreach((array)($r['track_ids']??[]) as $tid)$releasePop+=(int)($pop[(string)$tid]['score']??0);$personal=0.0;if($profile&&isset($profile['favorite_release_ids'][$id]))$personal+=14;
            $score=(float)$match['score']+$personal+min(14,log(1+$releasePop,2)*2);$art=$r['artwork']??'';$rows[]=['type'=>'release','id'=>$id,'title'=>(string)($r['title']??$id),'release'=>'','release_type'=>(string)($r['type']??'release'),'release_date'=>(string)($r['release_date']??''),'description'=>(string)($r['description']??''),'track_count'=>count((array)($r['track_ids']??[])),'mood'=>$fields['moods'],'themes'=>$fields['themes'],'energy'=>0,'artwork'=>is_array($art)?(string)($art['cover']??''):(string)$art,'score'=>round($score,2),'reason'=>$match['reason']?:($personal>0?'Saved release':($releasePop>0?'Popular release':'Stonefellow release')),'popularity'=>$releasePop,'personalized'=>$personal>0,'catalog_index'=>$idx];
        }
    }
    usort($rows,function($a,$b)use($p){
        if($p['sort']==='title')return strcasecmp((string)$a['title'],(string)$b['title']);
        if($p['sort']==='popular')return ($b['popularity']<=>$a['popularity'])?:($b['score']<=>$a['score'])?:strcasecmp((string)$a['title'],(string)$b['title']);
        if($p['sort']==='newest'){$ad=(string)($a['release_date']??($a['year']??''));$bd=(string)($b['release_date']??($b['year']??''));return strcmp($bd,$ad)?:($b['score']<=>$a['score']);}
        if($p['q']===''&&!sf_search_filter_active($p))return ($b['popularity']<=>$a['popularity'])?:($b['score']<=>$a['score'])?:($a['catalog_index']<=>$b['catalog_index']);
        return ($b['score']<=>$a['score'])?:($b['popularity']<=>$a['popularity'])?:strcasecmp((string)$a['title'],(string)$b['title']);
    });
    $allCount=count($rows);$rows=array_slice($rows,0,$p['limit']);$trackCount=count(array_filter($rows,fn($r)=>$r['type']==='track'));$releaseCount=count($rows)-$trackCount;
    return ['query'=>$p,'normalized_query'=>sf_search_normalize($q),'results'=>$rows,'result_count'=>$allCount,'returned_count'=>count($rows),'track_count'=>$trackCount,'release_count'=>$releaseCount,'facets'=>sf_search_facets(),'suggestions'=>$allCount===0?sf_search_suggestions($q):[],'personalized'=>$userId!==null&&$userId>0,'generated_at'=>gmdate('c')];
}
function sf_search_suggestions(string $query,int $limit=8): array {
    $q=sf_search_normalize($query);if($q==='')return [];$candidates=[];
    foreach(sf_catalog() as $t){$candidates[]=(string)($t['title']??'');$candidates[]=(string)($t['release']??'');foreach((array)($t['mood']??[]) as $v)$candidates[]=(string)$v;foreach((array)($t['themes']??[]) as $v)$candidates[]=(string)$v;}
    foreach(sf_release_rows() as $r)$candidates[]=(string)($r['title']??'');
    $candidates=array_values(array_unique(array_filter(array_map('trim',$candidates))));$rows=[];
    foreach($candidates as $value){$n=sf_search_normalize($value);if($n==='')continue;$d=levenshtein(substr($q,0,120),substr($n,0,120));$contains=str_contains($n,$q)||str_contains($q,$n);if(!$contains&&$d>max(2,(int)floor(strlen($q)*.45)))continue;$rows[]=['value'=>$value,'distance'=>$contains?0:$d];}
    usort($rows,fn($a,$b)=>($a['distance']<=>$b['distance'])?:strlen($a['value'])<=>strlen($b['value']));return array_slice(array_column($rows,'value'),0,max(1,min(12,$limit)));
}
function sf_search_session_hash(string $sessionKey,?int $userId=null): string {
    $sessionKey=trim($sessionKey);if($userId&&$userId>0)return hash('sha256','user|'.$userId.'|'.substr($sessionKey,0,160));
    return hash('sha256','guest|'.sf_auth_ip_hash());
}
function sf_search_event_allowed(string $sessionHash): bool {
    sf_search_ensure_schema();$since=gmdate('c',time()-10*60);$q=sf_db()->prepare('SELECT COUNT(*) FROM catalog_search_events WHERE session_hash=? AND created_at>=?');$q->execute([$sessionHash,$since]);return (int)$q->fetchColumn()<120;
}
function sf_search_record_event(?int $userId,string $sessionKey,string $eventType,array $payload): void {
    sf_search_ensure_schema();$eventType=in_array($eventType,['search','click','clear'],true)?$eventType:'search';$sessionHash=sf_search_session_hash($sessionKey,$userId);if(!sf_search_event_allowed($sessionHash))return;
    $p=sf_search_params((array)($payload['query']??$payload));$query=(string)$p['q'];$resultType=in_array((string)($payload['result_type']??''),['track','release'],true)?(string)$payload['result_type']:'';$resultId=sf_clean_text((string)($payload['result_id']??''),180);$resultCount=max(0,(int)($payload['result_count']??0));$filters=$p;unset($filters['q'],$filters['limit']);
    $q=sf_db()->prepare('INSERT INTO catalog_search_events(user_id,session_hash,event_type,query_text,normalized_query,result_count,result_type,result_id,filters_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$q->execute([$userId?:null,$sessionHash,$eventType,$query,sf_search_normalize($query),$resultCount,$resultType,$resultId,sf_ops_json($filters),gmdate('c')]);
}
function sf_search_recent_for_user(int $userId,int $limit=8): array {
    sf_search_ensure_schema();$limit=max(1,min(30,$limit));$q=sf_db()->prepare("SELECT query_text,normalized_query,MAX(created_at) AS last_at,COUNT(*) AS uses FROM catalog_search_events WHERE user_id=? AND event_type='search' AND normalized_query<>'' GROUP BY query_text,normalized_query ORDER BY last_at DESC LIMIT ".$limit);$q->execute([$userId]);return $q->fetchAll();
}
function sf_search_clear_user_history(int $userId): int {
    sf_search_ensure_schema();$q=sf_db()->prepare('DELETE FROM catalog_search_events WHERE user_id=?');$q->execute([$userId]);return $q->rowCount();
}
function sf_search_analytics(int $days=30,?int $userId=null): array {
    sf_search_ensure_schema();$days=max(1,min(3650,$days));$since=gmdate('c',time()-$days*86400);$args=[$since];$where='created_at>=?';if($userId!==null){$where.=' AND user_id=?';$args[]=$userId;}
    $q=sf_db()->prepare("SELECT id,user_id,event_type,query_text,normalized_query,result_count,result_type,result_id,filters_json,created_at FROM catalog_search_events WHERE $where ORDER BY id ASC");$q->execute($args);$rows=$q->fetchAll();
    $searches=0;$clicks=0;$zero=0;$sessions=[];$users=[];$queries=[];$zeroQueries=[];$selected=[];$filters=[];$recent=[];
    foreach($rows as $r){$ev=(string)$r['event_type'];$sessions[(string)$r['user_id'].'|'.(string)$r['id']]=true;if((int)$r['user_id']>0)$users[(int)$r['user_id']]=true;
        if($ev==='search'){$searches++;$n=(string)$r['normalized_query'];if((int)$r['result_count']===0){$zero++;if($n!=='')$zeroQueries[$n]=($zeroQueries[$n]??0)+1;}if($n!==''){$queries[$n]??=['query'=>(string)$r['query_text'],'searches'=>0,'zero_results'=>0];$queries[$n]['searches']++;if((int)$r['result_count']===0)$queries[$n]['zero_results']++;}$fj=json_decode((string)$r['filters_json'],true);if(is_array($fj))foreach($fj as $k=>$v)if($v!==''&&$v!==0&&$v!=='all'&&$v!=='relevance')$filters[$k.'='.strval($v)]=($filters[$k.'='.strval($v)]??0)+1;
        }elseif($ev==='click'){$clicks++;$key=(string)$r['result_type'].':'.(string)$r['result_id'];if($key!==':')$selected[$key]=($selected[$key]??0)+1;}
    }
    uasort($queries,fn($a,$b)=>$b['searches']<=>$a['searches']);arsort($zeroQueries);arsort($selected);arsort($filters);
    $selectedRows=[];$trackMap=sf_track_map();$releaseMap=sf_release_map();foreach(array_slice($selected,0,15,true) as $key=>$count){[$type,$id]=array_pad(explode(':',$key,2),2,'');$title=$type==='track'?(string)($trackMap[$id]['title']??$id):(string)($releaseMap[$id]['title']??$id);$selectedRows[]=['type'=>$type,'id'=>$id,'title'=>$title,'clicks'=>$count];}
    foreach(array_slice(array_reverse($rows),0,25) as $r)$recent[]=$r;
    return ['days'=>$days,'searches'=>$searches,'clicks'=>$clicks,'click_through_rate'=>sf_analytics_percent($clicks,$searches),'zero_result_searches'=>$zero,'zero_result_rate'=>sf_analytics_percent($zero,$searches),'identified_users'=>count($users),'top_queries'=>array_slice(array_values($queries),0,15),'zero_result_queries'=>array_slice(array_map(fn($q,$c)=>['query'=>$q,'count'=>$c],array_keys($zeroQueries),array_values($zeroQueries)),0,15),'top_results'=>$selectedRows,'filter_usage'=>array_slice(array_map(fn($k,$c)=>['filter'=>$k,'uses'=>$c],array_keys($filters),array_values($filters)),0,15),'recent'=>$recent];
}
