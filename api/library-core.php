<?php
declare(strict_types=1);

function sf_library_ensure_schema(): void {
    static $done=false;if($done)return;$pdo=sf_db();$driver=(string)(sf_db_config()['driver']??'');
    if($driver==='sqlite'){
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_collections (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,name TEXT NOT NULL,description TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_user_collections_user_updated ON user_collections(user_id,updated_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_collection_items (id INTEGER PRIMARY KEY AUTOINCREMENT,collection_id INTEGER NOT NULL,item_type TEXT NOT NULL,item_key TEXT NOT NULL,position INTEGER NOT NULL DEFAULT 0,added_at TEXT NOT NULL,FOREIGN KEY(collection_id) REFERENCES user_collections(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_collection_item_unique ON user_collection_items(collection_id,item_type,item_key)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_collection_items_order ON user_collection_items(collection_id,position,id)");
    }else{
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_collections (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,description TEXT NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,INDEX idx_user_collections_user_updated(user_id,updated_at),CONSTRAINT fk_collection_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_collection_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,collection_id BIGINT UNSIGNED NOT NULL,item_type VARCHAR(24) NOT NULL,item_key VARCHAR(180) NOT NULL,position INT NOT NULL DEFAULT 0,added_at VARCHAR(40) NOT NULL,UNIQUE KEY idx_collection_item_unique(collection_id,item_type,item_key),INDEX idx_collection_items_order(collection_id,position,id),CONSTRAINT fk_collection_item_collection FOREIGN KEY(collection_id) REFERENCES user_collections(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $done=true;
}
function sf_library_collection_row(int $collectionId): ?array {
    sf_library_ensure_schema();$q=sf_db()->prepare('SELECT id,user_id,name,description,created_at,updated_at FROM user_collections WHERE id=? LIMIT 1');$q->execute([$collectionId]);$r=$q->fetch();return $r?:null;
}
function sf_library_collection_owned(int $userId,int $collectionId): array {
    $r=sf_library_collection_row($collectionId);if(!$r||(int)$r['user_id']!==$userId)throw new RuntimeException('Collection not found.');return $r;
}
function sf_library_purchase_map(int $userId): array {
    $out=[];foreach(sf_account_library($userId) as $row)$out[(string)$row['id']]=$row;return $out;
}
function sf_library_build_map(int $userId): array {
    $out=[];foreach(sf_account_saved_builds($userId) as $row)$out[(string)$row['id']]=$row;return $out;
}
function sf_library_playlist_map(int $userId): array {
    $out=[];foreach(sf_playlist_list($userId) as $row)$out[(string)$row['id']]=$row;return $out;
}
function sf_library_resolve_item(int $userId,string $type,string $key): ?array {
    $type=strtolower(sf_clean_text($type,24));$key=sf_clean_text($key,180);if($key==='')return null;
    if($type==='track'){$t=sf_track_map()[$key]??null;if(!$t)return null;return ['item_type'=>'track','item_key'=>$key,'title'=>(string)($t['title']??$key),'subtitle'=>(string)($t['release']??''),'artwork'=>(string)($t['artwork']??''),'duration'=>(int)($t['duration']??0),'year'=>(int)($t['year']??0)];}
    if($type==='release'){$r=sf_release_map()[$key]??null;if(!$r||($r['state']??'published')!=='published'||($r['public_visible']??true)===false)return null;$art=$r['artwork']??'';return ['item_type'=>'release','item_key'=>$key,'title'=>(string)($r['title']??$key),'subtitle'=>(string)($r['type']??'release'),'artwork'=>is_array($art)?(string)($art['cover']??''):(string)$art,'release_date'=>(string)($r['release_date']??''),'track_count'=>count((array)($r['track_ids']??[]))];}
    if($type==='playlist'){$p=sf_library_playlist_map($userId)[$key]??null;if(!$p)return null;return ['item_type'=>'playlist','item_key'=>$key,'title'=>(string)$p['name'],'subtitle'=>count((array)($p['tracks']??[])).' tracks','artwork'=>(string)($p['tracks'][0]['artwork']??''),'track_count'=>(int)($p['track_count']??0),'duration'=>(int)($p['duration_seconds']??0),'visibility'=>(string)($p['visibility']??'private')];}
    if($type==='purchase'){$p=sf_library_purchase_map($userId)[$key]??null;if(!$p)return null;$meta=(array)($p['metadata']??[]);$trackId=(string)($meta['track_id']??'');$t=$trackId!==''?(sf_track_map()[$trackId]??null):null;return ['item_type'=>'purchase','item_key'=>$key,'title'=>(string)($p['label']??'Purchase'),'subtitle'=>ucfirst(str_replace('_',' ',(string)($p['item_type']??'purchase'))),'artwork'=>(string)($t['artwork']??''),'source_order_id'=>(string)($p['source_order_id']??''),'acquired_at'=>(string)($p['acquired_at']??''),'metadata'=>$meta];}
    if($type==='build'){$b=sf_library_build_map($userId)[$key]??null;if(!$b)return null;return ['item_type'=>'build','item_key'=>$key,'title'=>(string)$b['name'],'subtitle'=>strtoupper((string)($b['format']??'vinyl')),'artwork'=>'','updated_at'=>(string)($b['updated_at']??''),'build'=>(array)($b['build']??[])];}
    return null;
}
function sf_library_collection_items(int $userId,int $collectionId): array {
    sf_library_collection_owned($userId,$collectionId);$q=sf_db()->prepare('SELECT id,item_type,item_key,position,added_at FROM user_collection_items WHERE collection_id=? ORDER BY position ASC,id ASC');$q->execute([$collectionId]);$out=[];
    foreach($q->fetchAll() as $r){$item=sf_library_resolve_item($userId,(string)$r['item_type'],(string)$r['item_key']);if(!$item)continue;$out[]=['collection_item_id'=>(int)$r['id'],'position'=>(int)$r['position'],'added_at'=>(string)$r['added_at']]+$item;}
    return $out;
}
function sf_library_collections(int $userId,bool $includeItems=true): array {
    sf_library_ensure_schema();$q=sf_db()->prepare('SELECT id,user_id,name,description,created_at,updated_at FROM user_collections WHERE user_id=? ORDER BY updated_at DESC,id DESC');$q->execute([$userId]);$out=[];
    foreach($q->fetchAll() as $r){$row=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'description'=>(string)$r['description'],'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at']];$items=$includeItems?sf_library_collection_items($userId,(int)$r['id']):[];$row['items']=$items;$row['item_count']=$includeItems?count($items):(int)sf_db()->query('SELECT COUNT(*) FROM user_collection_items WHERE collection_id='.(int)$r['id'])->fetchColumn();$out[]=$row;}
    return $out;
}
function sf_library_collection_create(int $userId,string $name,string $description=''): array {
    sf_library_ensure_schema();$name=sf_clean_text($name,120);if($name==='')throw new InvalidArgumentException('Collection name is required.');$description=sf_clean_text($description,1000);$now=gmdate('c');$q=sf_db()->prepare('INSERT INTO user_collections(user_id,name,description,created_at,updated_at) VALUES(?,?,?,?,?)');$q->execute([$userId,$name,$description,$now,$now]);$id=(int)sf_db()->lastInsertId();sf_log_user_activity($userId,'collection_created','Created collection “'.$name.'”','collection',(string)$id);return sf_library_collection_payload($userId,$id);
}
function sf_library_collection_payload(int $userId,int $collectionId): array {
    $r=sf_library_collection_owned($userId,$collectionId);$items=sf_library_collection_items($userId,$collectionId);return ['id'=>(int)$r['id'],'name'=>(string)$r['name'],'description'=>(string)$r['description'],'created_at'=>(string)$r['created_at'],'updated_at'=>(string)$r['updated_at'],'item_count'=>count($items),'items'=>$items];
}
function sf_library_collection_update(int $userId,int $collectionId,string $name,string $description=''): array {
    sf_library_collection_owned($userId,$collectionId);$name=sf_clean_text($name,120);if($name==='')throw new InvalidArgumentException('Collection name is required.');$description=sf_clean_text($description,1000);sf_db()->prepare('UPDATE user_collections SET name=?,description=?,updated_at=? WHERE id=? AND user_id=?')->execute([$name,$description,gmdate('c'),$collectionId,$userId]);sf_log_user_activity($userId,'collection_updated','Updated collection “'.$name.'”','collection',(string)$collectionId);return sf_library_collection_payload($userId,$collectionId);
}
function sf_library_collection_delete(int $userId,int $collectionId): void {
    $r=sf_library_collection_owned($userId,$collectionId);sf_db()->prepare('DELETE FROM user_collections WHERE id=? AND user_id=?')->execute([$collectionId,$userId]);sf_log_user_activity($userId,'collection_deleted','Deleted collection “'.(string)$r['name'].'”','collection',(string)$collectionId);
}
function sf_library_collection_add(int $userId,int $collectionId,string $type,string $key): array {
    sf_library_collection_owned($userId,$collectionId);$item=sf_library_resolve_item($userId,$type,$key);if(!$item)throw new InvalidArgumentException('That item is not available to save in this collection.');$q=sf_db()->prepare('SELECT COALESCE(MAX(position),-1)+1 FROM user_collection_items WHERE collection_id=?');$q->execute([$collectionId]);$pos=(int)$q->fetchColumn();$now=gmdate('c');
    try{sf_db()->prepare('INSERT INTO user_collection_items(collection_id,item_type,item_key,position,added_at) VALUES(?,?,?,?,?)')->execute([$collectionId,$item['item_type'],$item['item_key'],$pos,$now]);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','19'],true))throw $e;}
    sf_db()->prepare('UPDATE user_collections SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$collectionId,$userId]);sf_log_user_activity($userId,'collection_item_added','Saved “'.(string)$item['title'].'” to a collection','collection',(string)$collectionId,['item_type'=>$item['item_type'],'item_key'=>$item['item_key']]);return sf_library_collection_payload($userId,$collectionId);
}
function sf_library_collection_remove(int $userId,int $collectionId,int $itemId): array {
    sf_library_collection_owned($userId,$collectionId);$q=sf_db()->prepare('DELETE FROM user_collection_items WHERE id=? AND collection_id=?');$q->execute([$itemId,$collectionId]);$now=gmdate('c');sf_db()->prepare('UPDATE user_collections SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$collectionId,$userId]);sf_library_collection_resequence($collectionId);return sf_library_collection_payload($userId,$collectionId);
}
function sf_library_collection_resequence(int $collectionId): void {
    $q=sf_db()->prepare('SELECT id FROM user_collection_items WHERE collection_id=? ORDER BY position ASC,id ASC');$q->execute([$collectionId]);$u=sf_db()->prepare('UPDATE user_collection_items SET position=? WHERE id=? AND collection_id=?');$i=0;foreach($q->fetchAll() as $r)$u->execute([$i++,(int)$r['id'],$collectionId]);
}
function sf_library_collection_move(int $userId,int $collectionId,int $itemId,int $direction): array {
    sf_library_collection_owned($userId,$collectionId);$direction=$direction<0?-1:1;$q=sf_db()->prepare('SELECT id,position FROM user_collection_items WHERE collection_id=? ORDER BY position ASC,id ASC');$q->execute([$collectionId]);$rows=$q->fetchAll();$idx=-1;foreach($rows as $i=>$r)if((int)$r['id']===$itemId){$idx=$i;break;}if($idx<0)throw new RuntimeException('Collection item not found.');$swap=$idx+$direction;if($swap<0||$swap>=count($rows))return sf_library_collection_payload($userId,$collectionId);$pdo=sf_db();$pdo->beginTransaction();try{$pdo->prepare('UPDATE user_collection_items SET position=? WHERE id=? AND collection_id=?')->execute([(int)$rows[$swap]['position'],(int)$rows[$idx]['id'],$collectionId]);$pdo->prepare('UPDATE user_collection_items SET position=? WHERE id=? AND collection_id=?')->execute([(int)$rows[$idx]['position'],(int)$rows[$swap]['id'],$collectionId]);$pdo->prepare('UPDATE user_collections SET updated_at=? WHERE id=? AND user_id=?')->execute([gmdate('c'),$collectionId,$userId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}return sf_library_collection_payload($userId,$collectionId);
}
function sf_library_purchase_rows(int $userId): array {
    $tracks=sf_track_map();$out=[];foreach(sf_account_library($userId) as $p){$m=(array)($p['metadata']??[]);$trackId=(string)($m['track_id']??'');$t=$trackId!==''?($tracks[$trackId]??null):null;$out[]=['id'=>(string)$p['id'],'item_type'=>(string)$p['item_type'],'item_key'=>(string)$p['item_key'],'title'=>(string)$p['label'],'subtitle'=>ucfirst(str_replace('_',' ',(string)$p['item_type'])),'artwork'=>(string)($t['artwork']??''),'track_id'=>$trackId,'source_order_id'=>(string)$p['source_order_id'],'metadata'=>$m,'saved_at'=>(string)$p['acquired_at']];}return $out;
}
function sf_library_snapshot(int $userId): array {
    sf_library_ensure_schema();$favorites=sf_personalization_favorites($userId);$playlists=sf_playlist_list($userId);$builds=sf_account_saved_builds($userId);$purchases=sf_library_purchase_rows($userId);$continue=sf_personalization_continue_listening($userId,24);$history=sf_personalization_history($userId,160);$collections=sf_library_collections($userId,true);
    $tracks=[];foreach((array)$favorites['tracks'] as $t)$tracks[]=['kind'=>'track','id'=>(string)$t['id'],'title'=>(string)$t['title'],'subtitle'=>(string)($t['release']??''),'artwork'=>(string)($t['artwork']??''),'duration'=>(int)($t['duration']??0),'saved_at'=>(string)($t['favorited_at']??''),'source'=>'favorite'];
    $releases=[];foreach((array)$favorites['releases'] as $r)$releases[]=['kind'=>'release','id'=>(string)$r['id'],'title'=>(string)$r['title'],'subtitle'=>(string)($r['type']??'release'),'artwork'=>(string)($r['artwork']??''),'saved_at'=>(string)($r['favorited_at']??''),'source'=>'favorite'];
    $playlistRows=[];foreach($playlists as $p)$playlistRows[]=['kind'=>'playlist','id'=>(string)$p['id'],'title'=>(string)$p['name'],'subtitle'=>(int)$p['track_count'].' tracks','artwork'=>(string)($p['tracks'][0]['artwork']??''),'track_count'=>(int)$p['track_count'],'duration'=>(int)$p['duration_seconds'],'visibility'=>(string)$p['visibility'],'saved_at'=>(string)$p['updated_at'],'source'=>(string)($p['source_type']??'user'),'tracks'=>(array)($p['tracks']??[])];
    $buildRows=[];foreach($builds as $b)$buildRows[]=['kind'=>'build','id'=>(string)$b['id'],'title'=>(string)$b['name'],'subtitle'=>strtoupper((string)$b['format']),'artwork'=>'','saved_at'=>(string)$b['updated_at'],'source'=>'saved_build','build'=>(array)$b['build']];
    $purchaseRows=[];foreach($purchases as $p)$purchaseRows[]=['kind'=>'purchase']+$p;
    $all=array_merge($tracks,$releases,$playlistRows,$purchaseRows,$buildRows);usort($all,fn($a,$b)=>strcmp((string)($b['saved_at']??''),(string)($a['saved_at']??'')));
    return ['summary'=>['all'=>count($all),'tracks'=>count($tracks),'releases'=>count($releases),'playlists'=>count($playlistRows),'purchases'=>count($purchaseRows),'builds'=>count($buildRows),'collections'=>count($collections),'continue_listening'=>count($continue),'history'=>count($history)],'all'=>$all,'tracks'=>$tracks,'releases'=>$releases,'playlists'=>$playlistRows,'purchases'=>$purchaseRows,'builds'=>$buildRows,'collections'=>$collections,'continue_listening'=>$continue,'history'=>$history,'generated_at'=>gmdate('c')];
}
function sf_library_admin_analytics(?int $userId=null): array {
    sf_library_ensure_schema();$pdo=sf_db();$where=$userId!==null?' WHERE user_id='.(int)$userId:'';$scalar=function(string $sql)use($pdo): int{return (int)$pdo->query($sql)->fetchColumn();};
    $favorites=$scalar("SELECT COUNT(*) FROM user_favorites".$where);$playlists=$scalar("SELECT COUNT(*) FROM user_playlists".$where);$builds=$scalar("SELECT COUNT(*) FROM user_saved_builds".$where);$purchases=$scalar("SELECT COUNT(*) FROM user_library".$where);$collections=$scalar("SELECT COUNT(*) FROM user_collections".$where);
    $collectionItems=$userId!==null?$scalar("SELECT COUNT(*) FROM user_collection_items i INNER JOIN user_collections c ON c.id=i.collection_id WHERE c.user_id=".(int)$userId):$scalar("SELECT COUNT(*) FROM user_collection_items");
    $users=$userId!==null?($favorites+$playlists+$builds+$purchases+$collections>0?1:0):$scalar("SELECT COUNT(DISTINCT user_id) FROM (SELECT user_id FROM user_favorites UNION SELECT user_id FROM user_playlists UNION SELECT user_id FROM user_saved_builds UNION SELECT user_id FROM user_library UNION SELECT user_id FROM user_collections) library_users");
    $topTracks=[];$sql="SELECT item_id AS id,COUNT(*) AS saves FROM user_favorites WHERE item_type='track'".($userId!==null?' AND user_id='.(int)$userId:'')." GROUP BY item_id ORDER BY saves DESC,item_id ASC LIMIT 10";foreach($pdo->query($sql)->fetchAll() as $r){$t=sf_track_map()[(string)$r['id']]??null;if($t)$topTracks[]=['id'=>(string)$r['id'],'title'=>(string)($t['title']??$r['id']),'saves'=>(int)$r['saves']];}
    $topReleases=[];$sql="SELECT item_id AS id,COUNT(*) AS saves FROM user_favorites WHERE item_type='release'".($userId!==null?' AND user_id='.(int)$userId:'')." GROUP BY item_id ORDER BY saves DESC,item_id ASC LIMIT 10";foreach($pdo->query($sql)->fetchAll() as $r){$x=sf_release_map()[(string)$r['id']]??null;if($x)$topReleases[]=['id'=>(string)$r['id'],'title'=>(string)($x['title']??$r['id']),'saves'=>(int)$r['saves']];}
    return ['users_with_library'=>$users,'favorites'=>$favorites,'playlists'=>$playlists,'builds'=>$builds,'purchases'=>$purchases,'collections'=>$collections,'collection_items'=>$collectionItems,'top_tracks'=>$topTracks,'top_releases'=>$topReleases];
}
