<?php
declare(strict_types=1);
/** Artist World 88A: existing Media Library galleries explicitly approved for vinyl. */
function sf_world_vinyl_ensure_schema():void {
 static $done=false;if($done)return;sf_gallery_ensure_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite')
  $db->exec('CREATE TABLE IF NOT EXISTS artist_world_vinyl_collections(gallery_id INTEGER PRIMARY KEY,enabled INTEGER NOT NULL DEFAULT 0,approved_by INTEGER NULL,updated_at TEXT NOT NULL,FOREIGN KEY(gallery_id) REFERENCES photo_galleries(id) ON DELETE CASCADE)');
 else
  $db->exec('CREATE TABLE IF NOT EXISTS artist_world_vinyl_collections(gallery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,approved_by BIGINT UNSIGNED NULL,updated_at VARCHAR(40) NOT NULL,CONSTRAINT fk_world_vinyl_gallery FOREIGN KEY(gallery_id) REFERENCES photo_galleries(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $done=true;
}
function sf_world_vinyl_enabled_ids():array {
 sf_world_vinyl_ensure_schema();
 return array_fill_keys(array_map('intval',sf_db()->query('SELECT gallery_id FROM artist_world_vinyl_collections WHERE enabled=1')->fetchAll(PDO::FETCH_COLUMN)),true);
}
function sf_world_vinyl_set_enabled(int $galleryId,bool $enabled,int $adminId):void {
 if($galleryId<1||$adminId<1||!sf_gallery_get($galleryId,false))throw new InvalidArgumentException('Gallery not found.');
 sf_world_vinyl_ensure_schema();
 $sql=(sf_db_config()['driver']??'')==='sqlite'
 ?'INSERT INTO artist_world_vinyl_collections(gallery_id,enabled,approved_by,updated_at) VALUES(?,?,?,?) ON CONFLICT(gallery_id) DO UPDATE SET enabled=excluded.enabled,approved_by=excluded.approved_by,updated_at=excluded.updated_at'
 :'INSERT INTO artist_world_vinyl_collections(gallery_id,enabled,approved_by,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),approved_by=VALUES(approved_by),updated_at=VALUES(updated_at)';
 sf_db()->prepare($sql)->execute([$galleryId,$enabled?1:0,$adminId,gmdate('c')]);
}
function sf_world_vinyl_gallery_photos(array $gallery):array {
 $out=[];
 foreach((array)($gallery['photos']??[]) as $m){
  if(!is_array($m)||empty($m['public_visible'])||empty($m['download_allowed']))continue;
  $uuid=(string)($m['uuid']??'');
  if(!preg_match('/^M[A-F0-9]{20}$/D',$uuid))continue;
  if((int)($m['width']??0)<1600||(int)($m['height']??0)<1600)continue;
  $asset=sf_media_asset($uuid);
  if(!is_array($asset)||($asset['category']??'')!=='image'||!in_array(strtolower((string)($asset['extension']??'')),['jpg','jpeg','png'],true))continue;
  $out[]=['asset_id'=>(int)$m['asset_id'],'uuid'=>$uuid,'reference'=>'api/media.php?id='.$uuid,
   'thumb_url'=>(string)($m['thumb_url']??''),'url'=>(string)($m['url']??''),
   'title'=>(string)($m['title']??''),'alt_text'=>(string)($m['alt_text']??''),
   'credit'=>(string)($m['credit']??''),'copyright'=>(string)($m['copyright']??''),
   'width'=>(int)$m['width'],'height'=>(int)$m['height']];
 }
 return $out;
}
function sf_world_vinyl_public_collections():array {
 $enabled=sf_world_vinyl_enabled_ids();$out=[];
 foreach(sf_gallery_public_list() as $g){
  if(!isset($enabled[(int)$g['id']]))continue;
  $photos=[];foreach(array_merge($g['cover']?[$g['cover']]:[],(array)$g['photos']) as $m){
   $m['public_visible']=true;$photos[]=$m;
  }
  $items=sf_world_vinyl_gallery_photos(['photos'=>$photos]);
  if(!$items)continue;
  $out[]=['id'=>(int)$g['id'],'slug'=>(string)$g['slug'],'title'=>(string)$g['title'],
   'summary'=>substr((string)$g['summary'],0,300),'photos'=>array_slice($items,0,100)];
 }
 return array_slice($out,0,60);
}
function sf_world_vinyl_approved_reference(string $reference):bool {
 if(!preg_match('/^api\/media\.php\?id=(M[A-F0-9]{20})$/D',$reference,$m))return false;
 foreach(sf_world_vinyl_public_collections() as $g)
  foreach($g['photos'] as $photo)
   if(hash_equals((string)$photo['uuid'],$m[1]))return true;
 return false;
}
