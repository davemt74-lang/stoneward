<?php
declare(strict_types=1);
/**
 * 88D3D: private, source-bound Artist World editorial assemblies.
 * Editorial timelines only: no public media URLs, provider transfers or rendering.
 */
function sf_assembly_schema():void{
 static $ready=false;if($ready)return;
 sf_scene_schema();sf_transcript_schema();sf_capture_rights_schema();
 $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
 if($sqlite){
  $db->exec("CREATE TABLE IF NOT EXISTS story_assemblies(
   id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,brief TEXT NOT NULL,
   revision INTEGER NOT NULL DEFAULT 1,status TEXT NOT NULL DEFAULT 'draft',
   created_by INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_items(
   id INTEGER PRIMARY KEY AUTOINCREMENT,assembly_id INTEGER NOT NULL,
   ordinal INTEGER NOT NULL,kind TEXT NOT NULL,source_id TEXT NOT NULL,
   source_submission_id INTEGER NULL,in_ms INTEGER NOT NULL,out_ms INTEGER NOT NULL,
   duration_ms INTEGER NOT NULL,notes TEXT NOT NULL,created_at TEXT NOT NULL,
   UNIQUE(assembly_id,ordinal),
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_assemblies(
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
   title VARCHAR(180) NOT NULL,brief TEXT NOT NULL,
   revision INT NOT NULL DEFAULT 1,status VARCHAR(24) NOT NULL DEFAULT 'draft',
   created_by BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
   CONSTRAINT fk_assembly_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_items(
   id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,assembly_id BIGINT UNSIGNED NOT NULL,
   ordinal INT NOT NULL,kind VARCHAR(20) NOT NULL,source_id VARCHAR(128) NOT NULL,
   source_submission_id BIGINT UNSIGNED NULL,in_ms BIGINT UNSIGNED NOT NULL,
   out_ms BIGINT UNSIGNED NOT NULL,duration_ms BIGINT UNSIGNED NOT NULL,
   notes TEXT NOT NULL,created_at VARCHAR(40) NOT NULL,
   UNIQUE KEY uq_assembly_item_order(assembly_id,ordinal),
   CONSTRAINT fk_assembly_item_parent FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_assembly_editor(int $editor):void{
 if($editor<1||!sf_team_can($editor,'team.assign_roles'))
  throw new RuntimeException('Only a production editor can manage private story assemblies.');
}
function sf_assembly_tracks():array{
 if(!function_exists('sf_catalog'))return [];
 $all=sf_catalog();$rows=[];
 foreach($all as $t){
  if(!is_array($t))continue;
  $id=(string)($t['id']??'');$title=trim((string)($t['title']??''));
  if($id===''||strlen($id)>128||$title==='')continue;
  require_once __DIR__.'/story-stonefellow-music-auto-core.php';
  $authorization=sf_stonefellow_music_auto_status($id);
  $rows[]=['kind'=>'track','source_id'=>$id,'label'=>sf_capture_clean($title,180),
   'duration_ms'=>max(0,min(86400000,(int)round((float)($t['duration']??0)*1000))),
   'publication_clearance'=>!empty($authorization['eligible'])?'original_music_auto_authorized':
     'requires_music_license_review',
   'music_auto_authorized'=>!empty($authorization['eligible']),
   'authorization_reason'=>(string)$authorization['reason'],'submission_id'=>null];
  if(count($rows)>=300)break;
 }
 return $rows;
}
/** Existing verified scene/transcript references only; no source payloads returned. */
function sf_assembly_candidates(int $editor):array{
 sf_assembly_editor($editor);sf_assembly_schema();$db=sf_db();
 $q=$db->query("SELECT n.id,n.submission_id,n.description,n.start_ms,n.end_ms,
   a.category,COALESCE(r.status,'unreviewed') AS rights
   FROM story_scene_annotations n
   JOIN story_capture_submissions s ON s.id=n.submission_id
   JOIN media_assets a ON a.id=n.asset_id AND a.id=s.asset_id
   LEFT JOIN story_capture_rights_reviews r
     ON r.submission_id=s.id AND r.purpose='publication'
   WHERE n.review_status='verified' AND s.review_status='approved_for_edit'
   ORDER BY n.id DESC LIMIT 100");
 $out=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $s){
  $still=$s['category']==='image';
  $out[]=['kind'=>'scene','source_id'=>(string)$s['id'],
   'label'=>sf_capture_clean($s['description'],160),
   'category'=>$s['category'],'submission_id'=>(int)$s['submission_id'],
   'in_ms'=>(int)$s['start_ms'],'out_ms'=>(int)$s['end_ms'],
   'duration_ms'=>$still?3500:(int)$s['end_ms']-(int)$s['start_ms'],
   'publication_clearance'=>$s['rights']];
 }
 $q=$db->query("SELECT t.id,t.submission_id,
     MIN(g.start_ms) AS start_ms,MAX(g.end_ms) AS end_ms,COUNT(g.id) AS segment_count,
     COALESCE(r.status,'unreviewed') AS rights
   FROM story_transcripts t
   JOIN story_capture_submissions s ON s.id=t.submission_id
   JOIN story_transcript_segments g ON g.transcript_id=t.id
   LEFT JOIN story_capture_rights_reviews r
     ON r.submission_id=s.id AND r.purpose='publication'
   WHERE t.review_status='verified' AND s.review_status='approved_for_edit'
   GROUP BY t.id,t.submission_id,r.status ORDER BY t.id DESC LIMIT 100");
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t){
  $out[]=['kind'=>'transcript','source_id'=>(string)$t['id'],
    'label'=>'Verified dialogue #'.$t['id'].' ('.$t['segment_count'].' segments)',
    'submission_id'=>(int)$t['submission_id'],'in_ms'=>(int)$t['start_ms'],
    'out_ms'=>(int)$t['end_ms'],
    'duration_ms'=>(int)$t['end_ms']-(int)$t['start_ms'],
    'publication_clearance'=>$t['rights']];
 }
 return array_merge($out,sf_assembly_tracks());
}
/** Validates the actual source on every revision, not just the UI-supplied label. */
function sf_assembly_validate_item(array $item):array{
 $kind=(string)($item['kind']??'');$src=(string)($item['source_id']??'');
 $in=$item['in_ms']??null;$out=$item['out_ms']??null;$duration=$item['duration_ms']??null;
 if(!in_array($kind,['scene','transcript','track'],true)||$src===''||strlen($src)>128
   ||!is_int($in)||!is_int($out)||!is_int($duration)
   ||$in<0||$out<0||$in>86400000||$out>86400000||$duration<500||$duration>600000)
  throw new InvalidArgumentException('Invalid story segment or source range.');
 $notes=sf_capture_clean($item['notes']??'',600);
 if($kind==='track'){
  $track=null;foreach(sf_assembly_tracks() as $t)if($t['source_id']===$src){$track=$t;break;}
  if(!$track||$track['duration_ms']<=0||$out<=$in||$out>$track['duration_ms']+100)
   throw new InvalidArgumentException('Track segment must reference an existing song and its known duration.');
  if(abs($duration-($out-$in))>100)
   throw new InvalidArgumentException('Track placement duration must match its trimmed source range.');
  return ['kind'=>$kind,'source_id'=>$src,'source_submission_id'=>null,
   'in_ms'=>$in,'out_ms'=>$out,'duration_ms'=>$duration,'notes'=>$notes];
 }
 if(!ctype_digit($src)||(int)$src<1)
  throw new InvalidArgumentException('Verified source ID required.');
 $table=$kind==='scene'?'story_scene_annotations':'story_transcripts';
 $db=sf_db();
 if($kind==='scene'){
  $q=$db->prepare("SELECT n.submission_id,n.start_ms,n.end_ms,a.category,
    n.review_status,s.review_status AS capture_review
    FROM story_scene_annotations n
    JOIN story_capture_submissions s ON s.id=n.submission_id
    JOIN media_assets a ON a.id=n.asset_id AND a.id=s.asset_id WHERE n.id=?");
 }else{
  $q=$db->prepare("SELECT t.submission_id,MIN(g.start_ms) AS start_ms,
   MAX(g.end_ms) AS end_ms,t.review_status,s.review_status AS capture_review,
   'audio' AS category FROM story_transcripts t
   JOIN story_capture_submissions s ON s.id=t.submission_id
   JOIN story_transcript_segments g ON g.transcript_id=t.id
   WHERE t.id=? GROUP BY t.id,t.submission_id,t.review_status,s.review_status");
 }
 $q->execute([(int)$src]);$r=$q->fetch(PDO::FETCH_ASSOC);
 if(!$r||$r['review_status']!=='verified'||$r['capture_review']!=='approved_for_edit')
  throw new RuntimeException('Unverified, rejected, or missing evidence cannot enter an assembly.');
 if($kind==='scene'&&$r['category']==='image'){
  if($in!==0||$out!==0||$duration>30000)
   throw new InvalidArgumentException('Still image requires zero source timecodes and a placement up to 30 seconds.');
 }else{
  if($out<=$in||$in<(int)$r['start_ms']||$out>(int)$r['end_ms']
    ||abs($duration-($out-$in))>100)
   throw new InvalidArgumentException('Trim exceeds verified source interval.');
 }
 return ['kind'=>$kind,'source_id'=>$src,'source_submission_id'=>(int)$r['submission_id'],
  'in_ms'=>$in,'out_ms'=>$out,'duration_ms'=>$duration,'notes'=>$notes];
}
function sf_assembly_create(int $editor,string $title,string $brief=''):int{
 sf_assembly_editor($editor);sf_assembly_schema();
 $title=sf_capture_clean($title,180);$brief=sf_capture_clean($brief,1500);
 if(strlen($title)<3)throw new InvalidArgumentException('Title must contain at least 3 characters.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 sf_assembly_history_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested assembly creation unsupported.');
 $db->beginTransaction();
 try{
  $now=gmdate('c');$q=$db->prepare("INSERT INTO story_assemblies
    (title,brief,revision,status,created_by,created_at,updated_at)
    VALUES(?,?,1,'draft',?,?,?)");
  $q->execute([$title,$brief,$editor,$now,$now]);$id=(int)$db->lastInsertId();
  if(function_exists('sf_assembly_history_archive'))sf_assembly_history_archive($editor,$id,1,[]);
  sf_team_audit($editor,'capture.assembly_created','story_assembly',(string)$id,'Private editorial assembly created');
  $db->commit();return $id;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_assembly_list(int $editor):array{
 sf_assembly_editor($editor);sf_assembly_schema();
 return sf_db()->query("SELECT a.id,a.title,a.brief,a.revision,a.status,a.updated_at,
  (SELECT COUNT(*) FROM story_assembly_items i WHERE i.assembly_id=a.id) AS item_count
  FROM story_assemblies a ORDER BY a.updated_at DESC,a.id DESC LIMIT 80")->fetchAll(PDO::FETCH_ASSOC);
}
function sf_assembly_read(int $editor,int $assemblyId):?array{
 sf_assembly_editor($editor);sf_assembly_schema();
 $q=sf_db()->prepare('SELECT id,title,brief,revision,status,created_at,updated_at FROM story_assemblies WHERE id=?');
 $q->execute([$assemblyId]);$project=$q->fetch(PDO::FETCH_ASSOC);
 if(!$project)return null;
 $q=sf_db()->prepare('SELECT ordinal,kind,source_id,source_submission_id,in_ms,out_ms,duration_ms,notes FROM story_assembly_items WHERE assembly_id=? ORDER BY ordinal');
 $q->execute([$assemblyId]);$project['items']=$q->fetchAll(PDO::FETCH_ASSOC);
 $project['director']=sf_assembly_director_plan($editor,$project['items']);
 $project['private_only']=true;$project['rendered_video']=false;
 return $project;
}
/** Rules-based Director editorial guidance, with no LLM, media transfer or mutation. */
function sf_assembly_director_plan(int $editor,array $items):array{
 sf_assembly_editor($editor);
 $visual=0;$dialogue=0;$music=0;$unreleased=0;$invalid=0;
 foreach($items as $row){
  $k=$row['kind']??'';
  try{
   sf_assembly_validate_item([
    'kind'=>$k,'source_id'=>(string)($row['source_id']??''),
    'in_ms'=>(int)($row['in_ms']??0),'out_ms'=>(int)($row['out_ms']??0),
    'duration_ms'=>(int)($row['duration_ms']??0),'notes'=>''
   ]);
  }catch(Throwable $e){$invalid++;continue;}
  if($k==='scene')$visual++;
  if($k==='transcript')$dialogue++;
  if($k==='track')$music++;
  if($k!=='track'&&!sf_capture_rights_may_use((int)($row['source_submission_id']??0),'publication'))$unreleased++;
 }
 $gaps=[];
 if($visual<2)$gaps[]=['code'=>'visual_coverage','target_role'=>'videographer','shot_type'=>'video',
    'brief'=>'Capture additional establishing and closing shots with verified provenance.','missing'=>2-$visual];
 if($dialogue<1)$gaps[]=['code'=>'dialogue_beat','target_role'=>'band_member','shot_type'=>'dialogue',
    'brief'=>'Record a short approved story or dialogue beat.','missing'=>1];
 if($music<1)$gaps[]=['code'=>'music_bed','target_role'=>'band_member','shot_type'=>'audio',
    'brief'=>'Select a cleared Stonefellow track for the story music bed.','missing'=>1];
 $tips=[];
 if($invalid)$tips[]=$invalid.' timeline source(s) are no longer independently verified or available; review the sequence.';
 if($items&&($items[0]['kind']??'')!=='scene')$tips[]='Consider starting with a verified establishing scene.';
 if($items&&($items[count($items)-1]['kind']??'')!=='scene')$tips[]='Consider ending with a verified visual scene.';
 if($unreleased)$tips[]='Some source captures lack independent publication clearance; private assembly is not a distribution license.';
 if($music){
  require_once __DIR__.'/story-stonefellow-music-auto-core.php';
  $auto=0;
  foreach($items as $musicItem)
   if(($musicItem['kind']??'')==='track'&&
     !empty(sf_stonefellow_music_auto_status((string)$musicItem['source_id'])['eligible']))$auto++;
  if($auto)$tips[]=$auto.' Stonefellow original music cue(s) use standing artist authorization; master media certification is separate.';
  if($auto<$music)$tips[]='Some music cues still require individual sync and master rights review.';
 }
 return ['gaps'=>$gaps,'notes'=>$tips,'verified_visuals'=>$visual,'verified_dialogue'=>$dialogue,
   'music_references'=>$music,'publication_rights_pending'=>$unreleased,
   'invalid_source_items'=>$invalid,
   'recommendation_origin'=>'deterministic_rules','automatic_changes'=>false,'ready_to_render'=>false];
}
/** All-or-nothing optimistic revision update with a unique ordered sequence. */
function sf_assembly_replace(int $editor,int $assemblyId,int $revision,array $items,?callable $onApplied=null):array{
 // A revision without immutable history is never a valid editor change.
 // Load archive support even for direct API/test callers, not only the Agent bridge.
 require_once __DIR__.'/story-assembly-history-core.php';
 sf_assembly_editor($editor);sf_assembly_schema();
 if($assemblyId<1||$revision<1||count($items)>50)
  throw new InvalidArgumentException('Invalid assembly revision or more than 50 timeline items.');
 $prepared=[];$sum=0;
 foreach($items as $i=>$item){
  if(!is_array($item))throw new InvalidArgumentException('Invalid timeline item.');
  $v=sf_assembly_validate_item($item);$sum+=$v['duration_ms'];
  if($sum>3600000)throw new InvalidArgumentException('Assembly runtime exceeds one hour.');
  $prepared[]=$v+['ordinal'=>$i];
 }
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 if(function_exists('sf_assembly_history_schema'))sf_assembly_history_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested assembly transaction unsupported.');
 $db->beginTransaction();
 try{
  // Repeat source verification inside the transaction. Sources may have been
  // reviewed or revoked between the client's preview and this save.
  foreach($prepared as $v)sf_assembly_validate_item($v);
  $oldQ=$db->prepare('SELECT kind,source_id,in_ms,out_ms,duration_ms,notes
      FROM story_assembly_items WHERE assembly_id=? ORDER BY ordinal');
  $oldQ->execute([$assemblyId]);
  sf_assembly_history_archive($editor,$assemblyId,$revision,$oldQ->fetchAll(PDO::FETCH_ASSOC));
  $q=$db->prepare("UPDATE story_assemblies SET revision=revision+1,updated_at=?
   WHERE id=? AND revision=? AND status='draft'");
  $q->execute([gmdate('c'),$assemblyId,$revision]);
  if($q->rowCount()!==1)throw new RuntimeException('Assembly changed or is unavailable. Refresh before saving.');
  $db->prepare('DELETE FROM story_assembly_items WHERE assembly_id=?')->execute([$assemblyId]);
  $q=$db->prepare('INSERT INTO story_assembly_items
   (assembly_id,ordinal,kind,source_id,source_submission_id,in_ms,out_ms,duration_ms,notes,created_at)
   VALUES(?,?,?,?,?,?,?,?,?,?)');
  foreach($prepared as $v)$q->execute([$assemblyId,$v['ordinal'],$v['kind'],$v['source_id'],
    $v['source_submission_id'],$v['in_ms'],$v['out_ms'],$v['duration_ms'],$v['notes'],gmdate('c')]);
  sf_assembly_history_archive($editor,$assemblyId,$revision+1,$prepared);
  if($onApplied!==null)$onApplied($revision+1);
  sf_team_audit($editor,'capture.assembly_revised','story_assembly',(string)$assemblyId,
    'Revised private editorial timeline from revision '.$revision.' to '.($revision+1));
  $db->commit();return ['assembly_id'=>$assemblyId,'revision'=>$revision+1,'item_count'=>count($prepared),'duration_ms'=>$sum];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
