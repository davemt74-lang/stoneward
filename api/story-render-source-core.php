<?php
declare(strict_types=1);
/**
 * 88D3G1: immutable, source-bound render certification. Does not launch FFmpeg.
 * All paths remain server-side. Recheck immediately before any future render.
 */
function sf_render_source_schema():void {
 static $ready=false;if($ready)return;
 sf_storyboard_schema();sf_evidence_schema();
 $db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_track_masters(
   assembly_id INTEGER NOT NULL,track_id TEXT NOT NULL,asset_id INTEGER NOT NULL,
   revision INTEGER NOT NULL,sha256 TEXT NOT NULL,byte_size INTEGER NOT NULL,
   reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
   PRIMARY KEY(assembly_id,track_id),
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
   FOREIGN KEY(asset_id) REFERENCES media_assets(id) ON DELETE RESTRICT,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_track_masters(
   assembly_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(128) NOT NULL,
   asset_id BIGINT UNSIGNED NOT NULL,revision INT NOT NULL,
   sha256 CHAR(64) NOT NULL,byte_size BIGINT UNSIGNED NOT NULL,
   reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
   PRIMARY KEY(assembly_id,track_id),
   CONSTRAINT fk_render_master_assembly FOREIGN KEY(assembly_id)
     REFERENCES story_assemblies(id) ON DELETE CASCADE,
   CONSTRAINT fk_render_master_asset FOREIGN KEY(asset_id)
     REFERENCES media_assets(id) ON DELETE RESTRICT,
   CONSTRAINT fk_render_master_reviewer FOREIGN KEY(reviewed_by)
     REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_render_source_asset(int $assetId,array $allowedCategories):array {
 if($assetId<1)throw new RuntimeException('Media reference missing.');
 $media=sf_media_asset($assetId);
 if(!$media||!in_array((string)($media['category']??''),$allowedCategories,true))
  throw new RuntimeException('Media category invalid for this render source.');
 $size=(int)($media['size_bytes']??0);$expected=(string)($media['sha256']??'');
 if($size<1||$size>250*1024*1024||!preg_match('/^[a-f0-9]{64}$/',$expected))
  throw new RuntimeException('Incomplete immutable master metadata.');
 $stored=(string)($media['stored_path']??'');
 if($stored===''||str_contains($stored,"\0"))
  throw new RuntimeException('Media master storage path unavailable.');
 $root=realpath(SF_ROOT.'/storage/media');
 $real=realpath(SF_ROOT.'/'.ltrim($stored,'/'));
 if(!$root||!$real||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)
   ||!is_file($real)||!is_readable($real))
  throw new RuntimeException('Master is not contained in private media storage.');
 $actualSize=filesize($real);
 if(!is_int($actualSize)||$actualSize!==$size)
  throw new RuntimeException('Render source byte length mismatch.');
 $actualHash=hash_file('sha256',$real);
 if(!is_string($actualHash)||!hash_equals($expected,$actualHash))
  throw new RuntimeException('Render source SHA-256 mismatch.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($real);
 if(!is_string($mime))throw new RuntimeException('Source MIME cannot be determined.');
 $allowed=match((string)$media['category']){
  'image'=>['image/jpeg','image/png','image/webp'],
  'video'=>['video/mp4','video/webm','video/quicktime'],
  'audio'=>['audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/flac','audio/ogg','audio/webm','audio/mp4','audio/x-m4a'],
  default=>[]
 };
 if(!in_array($mime,$allowed,true))
  throw new RuntimeException('Source signature is not a supported render media format.');
 return ['asset_id'=>$assetId,'path'=>$real,'sha256'=>$expected,'byte_size'=>$size,
   'category'=>(string)$media['category'],'mime'=>$mime,
   'duration_ms'=>(int)round((float)($media['duration_seconds']??0)*1000)];
}
function sf_render_master_bind(int $editor,int $assemblyId,int $revision,string $trackId,int $assetId):void {
 sf_assembly_editor($editor);sf_render_source_schema();
 $assembly=sf_assembly_read($editor,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft'||(int)$assembly['revision']!==$revision)
  throw new RuntimeException('Story revision is no longer current.');
 if(!array_filter($assembly['items'],fn($v)=>$v['kind']==='track'&&(string)$v['source_id']===$trackId))
  throw new InvalidArgumentException('Track is not used in this editorial assembly.');
 if(!array_filter(sf_assembly_tracks(),fn($v)=>(string)$v['source_id']===$trackId))
  throw new RuntimeException('Unknown canonical Stonefellow track.');
 $master=sf_render_source_asset($assetId,['audio']);
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested render master transaction unsupported.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([$assemblyId]);
  if((int)$q->fetchColumn()!==$revision)
   throw new RuntimeException('Assembly revision changed before audio master assignment.');
  $q=$db->prepare('SELECT track_id FROM story_render_track_masters WHERE assembly_id=? AND track_id=?');
  $q->execute([$assemblyId,$trackId]);
  if($q->fetchColumn()===false){
   $q=$db->prepare('INSERT INTO story_render_track_masters
    (assembly_id,track_id,asset_id,revision,sha256,byte_size,reviewed_by,reviewed_at)
    VALUES(?,?,?,?,?,?,?,?)');
   $q->execute([$assemblyId,$trackId,$assetId,$revision,$master['sha256'],$master['byte_size'],$editor,gmdate('c')]);
  }else{
   $q=$db->prepare('UPDATE story_render_track_masters
    SET asset_id=?,revision=?,sha256=?,byte_size=?,reviewed_by=?,reviewed_at=?
    WHERE assembly_id=? AND track_id=?');
   $q->execute([$assetId,$revision,$master['sha256'],$master['byte_size'],$editor,gmdate('c'),$assemblyId,$trackId]);
  }
  sf_team_audit($editor,'capture.render_master_bound','story_assembly',(string)$assemblyId,
   'Private canonical track assigned fingerprinted Media Library audio master');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** Internal manifest contains private paths. Never put this result in a public or Admin JSON reply. */
function sf_render_source_manifest(int $editor,int $assemblyId,int $revision):array {
 sf_assembly_editor($editor);sf_render_source_schema();
 $preview=sf_storyboard_preview($editor,$assemblyId);
 if($preview['revision']!==$revision||!$preview['rights_and_local_assets_ready']
   ||$preview['total_ms']<500||count($preview['cards'])<1)
  throw new RuntimeException('Production rights or storyboard readiness not certified.');
 $items=sf_assembly_read($editor,$assemblyId)['items'];
 $db=sf_db();$out=[];
 foreach($items as $i=>$item){
  $kind=(string)$item['kind'];$src=(string)$item['source_id'];
  sf_assembly_validate_item(['kind'=>$kind,'source_id'=>$src,
   'in_ms'=>(int)$item['in_ms'],'out_ms'=>(int)$item['out_ms'],
   'duration_ms'=>(int)$item['duration_ms'],'notes'=>'']);
  if($kind==='track'){
   $q=$db->prepare('SELECT * FROM story_render_track_masters WHERE assembly_id=? AND track_id=?');
   $q->execute([$assemblyId,$src]);$binding=$q->fetch(PDO::FETCH_ASSOC);
   if(!$binding||(int)$binding['revision']!==$revision)
    throw new RuntimeException('Track master has not been independently bound for this revision.');
   $master=sf_render_source_asset((int)$binding['asset_id'],['audio']);
   if(!hash_equals((string)$binding['sha256'],$master['sha256'])
     ||(int)$binding['byte_size']!==$master['byte_size'])
    throw new RuntimeException('Music master no longer matches its approved fingerprint.');
   $licenses=sf_storyboard_track_rights($editor,$assemblyId,$revision);
   if(($licenses[$src]['sync']??'')!=='cleared'||($licenses[$src]['master']??'')!=='cleared')
    throw new RuntimeException('Both music use licenses must still be independently cleared.');
  }else{
   $submission=(int)($item['source_submission_id']??0);
   if(!sf_capture_rights_may_use($submission,'publication'))
    throw new RuntimeException('Publication permission changed.');
   $assetId=sf_storyboard_source_asset($kind,$src);
   $master=sf_render_source_asset($assetId,['audio','video','image']);
   $q=$db->prepare('SELECT asset_id,sha256,byte_size FROM story_media_evidence WHERE submission_id=?');
   $q->execute([$submission]);$evidence=$q->fetch(PDO::FETCH_ASSOC);
   if(!$evidence||(int)$evidence['asset_id']!==$assetId
      ||(int)$evidence['byte_size']!==$master['byte_size']
      ||!hash_equals((string)$evidence['sha256'],$master['sha256']))
    throw new RuntimeException('Original capture has no matching immutable server-observed evidence.');
  }
  $out[]=['ordinal'=>$i,'kind'=>$kind,'source_id'=>$src,
   'asset_id'=>$master['asset_id'],'path'=>$master['path'],'sha256'=>$master['sha256'],
   'byte_size'=>$master['byte_size'],'category'=>$master['category'],'mime'=>$master['mime'],
   'start_ms'=>(int)$item['in_ms'],'end_ms'=>(int)$item['out_ms'],
   'duration_ms'=>(int)$item['duration_ms'],'source_duration_ms'=>$master['duration_ms']];
 }
 return ['assembly_id'=>$assemblyId,'revision'=>$revision,
  'total_ms'=>$preview['total_ms'],'items'=>$out,
  'public_output_allowed'=>false,'render_started'=>false,'source_hashes_verified'=>true];
}
function sf_render_source_admin_status(int $editor,int $assemblyId,int $revision):array {
 sf_assembly_editor($editor);
 try{
  $manifest=sf_render_source_manifest($editor,$assemblyId,$revision);
  return ['eligible'=>true,'checks'=>'full_sha256_verified','item_count'=>count($manifest['items']),
   'total_ms'=>$manifest['total_ms'],'render_started'=>false,'publication_allowed'=>false];
 }catch(Throwable $e){
  return ['eligible'=>false,'checks'=>'blocked','item_count'=>null,
   'render_started'=>false,'publication_allowed'=>false,
   'reason'=>'Source certification requires reviewed rights, current masters, immutable evidence and supported local bytes.'];
 }
}
