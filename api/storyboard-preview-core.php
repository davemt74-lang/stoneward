<?php
declare(strict_types=1);
/**
 * 88D3F — private, metadata-only storyboard and rendering-readiness preflight.
 * Explicitly NO video render, media streaming, download or publication actions.
 */
function sf_storyboard_schema():void {
 static $ready=false;if($ready)return;
 sf_assembly_schema();sf_capture_rights_schema();
 $db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
 if($sqlite){
  $db->exec("CREATE TABLE IF NOT EXISTS story_track_license_reviews(
    assembly_id INTEGER NOT NULL,track_id TEXT NOT NULL,license_type TEXT NOT NULL,
    reviewed_revision INTEGER NOT NULL,status TEXT NOT NULL,basis TEXT NOT NULL,
    reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
    PRIMARY KEY(assembly_id,track_id,license_type),
    FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
    FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_track_license_reviews(
    assembly_id BIGINT UNSIGNED NOT NULL,track_id VARCHAR(128) NOT NULL,
    license_type VARCHAR(16) NOT NULL,reviewed_revision INT NOT NULL,
    status VARCHAR(24) NOT NULL,basis TEXT NOT NULL,
    reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
    PRIMARY KEY(assembly_id,track_id,license_type),
    CONSTRAINT fk_storyboard_license_assembly FOREIGN KEY(assembly_id)
     REFERENCES story_assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_storyboard_license_reviewer FOREIGN KEY(reviewed_by)
     REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
/** A license decision is per-assembly, per-track, per-use and per-revision. */
function sf_storyboard_license_review(int $editor,int $assemblyId,string $trackId,
  string $type,string $decision,string $basis,int $revision):void {
 sf_assembly_editor($editor);sf_storyboard_schema();
 if(!in_array($type,['sync','master'],true)||!in_array($decision,['cleared','restricted'],true))
  throw new InvalidArgumentException('Unknown music license purpose or decision.');
 $basis=sf_capture_clean($basis,1000);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document independently reviewed permission or restriction (25+ characters).');
 $assembly=sf_assembly_read($editor,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft'||(int)$assembly['revision']!==$revision)
  throw new RuntimeException('Assembly was updated; refresh before reviewing music permissions.');
 $used=false;foreach($assembly['items'] as $item)
  if($item['kind']==='track'&&(string)$item['source_id']===$trackId)$used=true;
 if(!$used)throw new InvalidArgumentException('Track is not in the current private assembly.');
 $catalog=false;foreach(sf_assembly_tracks() as $track)
  if((string)$track['source_id']===$trackId)$catalog=true;
 if(!$catalog)throw new RuntimeException('Track no longer exists in the canonical music catalog.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested music clearance transaction unsupported.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision,status FROM story_assemblies WHERE id=?");
  $q->execute([$assemblyId]);$a=$q->fetch(PDO::FETCH_ASSOC);
  if(!$a||(int)$a['revision']!==$revision||$a['status']!=='draft')
   throw new RuntimeException('Assembly changed before license decision.');
  // A stored decision is a documented operational gate, not proof of title.
  $q=$db->prepare("SELECT status FROM story_track_license_reviews
   WHERE assembly_id=? AND track_id=? AND license_type=?");
  $q->execute([$assemblyId,$trackId,$type]);
  if($q->fetchColumn()===false){
   $q=$db->prepare("INSERT INTO story_track_license_reviews
    (assembly_id,track_id,license_type,reviewed_revision,status,basis,reviewed_by,reviewed_at)
    VALUES(?,?,?,?,?,?,?,?)");
   $q->execute([$assemblyId,$trackId,$type,$revision,$decision,$basis,$editor,gmdate('c')]);
  }else{
   $q=$db->prepare("UPDATE story_track_license_reviews SET reviewed_revision=?,status=?,
      basis=?,reviewed_by=?,reviewed_at=? WHERE assembly_id=? AND track_id=? AND license_type=?");
   $q->execute([$revision,$decision,$basis,$editor,gmdate('c'),$assemblyId,$trackId,$type]);
  }
  sf_team_audit($editor,'capture.music_license_reviewed','story_assembly',(string)$assemblyId,
   'Independent '.$type.' music license decision '.$decision.' for existing catalog track');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_storyboard_track_rights(int $editor,int $assemblyId,int $revision):array {
 sf_assembly_editor($editor);sf_storyboard_schema();
 $q=sf_db()->prepare('SELECT track_id,license_type,reviewed_revision,status FROM story_track_license_reviews WHERE assembly_id=?');
 $q->execute([$assemblyId]);$out=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){
  $id=(string)$r['track_id'];$scope=(string)$r['license_type'];
  if(!in_array($scope,['sync','master'],true))continue;
  $out[$id][$scope]=((int)$r['reviewed_revision']===$revision)
     ?(string)$r['status']:'stale_revision';
 }
 return $out;
}
/** Local asset presence check. Does NOT replace hash verification at a real render boundary. */
function sf_storyboard_asset_present(int $assetId):bool {
 if($assetId<1||!function_exists('sf_media_asset')||!defined('SF_ROOT'))return false;
 $asset=sf_media_asset($assetId);
 if(!$asset||!isset($asset['stored_path'])||!is_string($asset['stored_path']))return false;
 $base=realpath(SF_ROOT.'/storage/media');
 $file=realpath(SF_ROOT.'/'.ltrim($asset['stored_path'],'/'));
 if(!$base||!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)
    ||!is_readable($file))return false;
 $size=@filesize($file);
 return $size!==false&&$size>0&&$size===(int)($asset['size_bytes']??0);
}
/** Scene/transcript evidence points to an existing private Media Library asset. */
function sf_storyboard_source_asset(string $kind,string $sourceId):int {
 if(!ctype_digit($sourceId))return 0;
 $db=sf_db();
 if($kind==='scene'){
  $q=$db->prepare('SELECT asset_id FROM story_scene_annotations WHERE id=?');
 }elseif($kind==='transcript'){
  $q=$db->prepare('SELECT s.asset_id FROM story_transcripts t
   JOIN story_capture_submissions s ON s.id=t.submission_id WHERE t.id=?');
 }else return 0;
 $q->execute([(int)$sourceId]);return (int)$q->fetchColumn();
}
/** Applied Composer transitions are advice, not baked into source files. */
function sf_storyboard_transitions(int $editor,int $assemblyId,int $revision,int $count):array {
 sf_assembly_editor($editor);
 if(!function_exists('sf_composer_schema'))return [];
 sf_composer_schema();
 $q=sf_db()->prepare("SELECT suggestion_json FROM story_composer_proposals
  WHERE assembly_id=? AND applied_revision=? AND status='applied' ORDER BY id DESC LIMIT 1");
 $q->execute([$assemblyId,$revision]);$json=$q->fetchColumn();
 if(!is_string($json)||$json==='')return [];
 $doc=json_decode($json,true);if(!is_array($doc)||count((array)($doc['beats']??[]))!==$count)return [];
 $allowed=['cut','fade','dissolve','hold','audio_bridge'];$transitions=[];
 foreach($doc['beats'] as $i=>$beat){
  $t=(string)($beat['transition']??'');
  $transitions[]=['type'=>in_array($t,$allowed,true)?$t:'cut',
    'reason'=>sf_capture_clean($beat['reason']??'',180)];
 }
 return $transitions;
}
/**
 * Generates frame/cue cards and a linear virtual playhead from current
 * canonical metadata. There is intentionally NO private media URL or player.
 */
function sf_storyboard_preview(int $editor,int $assemblyId):array{
 sf_assembly_editor($editor);sf_storyboard_schema();
 $assembly=sf_assembly_read($editor,$assemblyId);
 if(!$assembly)throw new InvalidArgumentException('Private story assembly not found.');
 $revision=(int)$assembly['revision'];$items=$assembly['items'];
 $candidates=sf_assembly_candidates($editor);
 $known=[];foreach($candidates as $c)$known[$c['kind'].':'.$c['source_id']]=$c;
 $music=sf_storyboard_track_rights($editor,$assemblyId,$revision);
 $transitions=sf_storyboard_transitions($editor,$assemblyId,$revision,count($items));
 $cards=[];$cursor=0;$blockers=[];$warnings=[];
 if(!$items)$blockers[]=['code'=>'empty_timeline','item'=>null,'message'=>'Add verified source scenes, dialogue, or music before production.'];
 if(count($items)>50)$blockers[]=['code'=>'item_limit','item'=>null,'message'=>'Timeline exceeds the supported segment limit.'];
 foreach($items as $i=>$r){
  $kind=(string)$r['kind'];$source=(string)$r['source_id'];
  $duration=max(0,(int)$r['duration_ms']);
  $candidate=$known[$kind.':'.$source]??null;
  $valid=true;
  try{sf_assembly_validate_item([
   'kind'=>$kind,'source_id'=>$source,'in_ms'=>(int)$r['in_ms'],
   'out_ms'=>(int)$r['out_ms'],'duration_ms'=>$duration,'notes'=>''
  ]);}catch(Throwable $e){$valid=false;}
  if(!$valid)$blockers[]=['code'=>'source_not_verified','item'=>$i,
   'message'=>'Source #'.($i+1).' is missing, changed or no longer independently verified.'];
  $label=$candidate?sf_capture_clean((string)$candidate['label'],160):'Unavailable source';
  $transition=$transitions[$i]['type']??'cut';
  $assetPresent=null;$captureClearance='not_applicable';$sync='not_applicable';$master='not_applicable';
  if($kind==='track'){
   $sync=$music[$source]['sync']??'unreviewed';$master=$music[$source]['master']??'unreviewed';
   foreach(['sync'=>$sync,'master'=>$master] as $purpose=>$status)
    if($status!=='cleared')$blockers[]=['code'=>'music_'.$purpose.'_not_cleared','item'=>$i,
      'message'=>'Music cue #'.($i+1).' needs current independent '.$purpose.' license clearance.'];
   $warnings[]=['code'=>'track_media_not_attested','item'=>$i,
    'message'=>'A render worker must separately certify playback-master media for music cue #'.($i+1).'.'];
  }elseif($kind==='scene'||$kind==='transcript'){
   $submission=(int)($r['source_submission_id']??0);
   $captureClearance=sf_capture_rights_status($submission,'publication');
   if($captureClearance!=='cleared')
    $blockers[]=['code'=>'publication_rights_not_cleared','item'=>$i,
      'message'=>'Source #'.($i+1).' lacks independent publication clearance.'];
   $assetPresent=sf_storyboard_asset_present(sf_storyboard_source_asset($kind,$source));
   if(!$assetPresent)$blockers[]=['code'=>'source_file_missing','item'=>$i,
      'message'=>'Private source file for item #'.($i+1).' is missing or fails the stored byte-size check.'];
  }else{
   $blockers[]=['code'=>'unknown_item','item'=>$i,'message'=>'Unsupported timeline source.'];
  }
  $cards[]=['position'=>$i,'kind'=>$kind,'source_id'=>$source,'label'=>$label,
   'start_ms'=>$cursor,'end_ms'=>$cursor+$duration,'duration_ms'=>$duration,
   'source_in_ms'=>(int)$r['in_ms'],'source_out_ms'=>(int)$r['out_ms'],
   'category'=>(string)($candidate['category']??($kind==='track'?'music':'audio')),
   'transition'=>$transition,'transition_note'=>$transitions[$i]['reason']??'',
   'notes'=>sf_capture_clean((string)($r['notes']??''),300),
   'source_verified'=>$valid,'asset_present'=>$assetPresent,
   'publication_clearance'=>$captureClearance,
   'sync_clearance'=>$sync,'master_clearance'=>$master];
  $cursor+=$duration;
 }
 if($cursor>3600000)$blockers[]=['code'=>'duration_limit','item'=>null,'message'=>'Timeline exceeds one hour.'];
 // Removing duplicate source warnings helps editors quickly work a gate.
 $unique=[];foreach($blockers as $b)$unique[$b['code'].':'.($b['item']??'all')]=$b;
 $blockers=array_values($unique);
 return ['assembly_id'=>$assemblyId,'revision'=>$revision,'title'=>sf_capture_clean($assembly['title'],180),
  'cards'=>$cards,'total_ms'=>$cursor,'blockers'=>$blockers,'warnings'=>$warnings,
  'rights_and_local_assets_ready'=>count($blockers)===0,
  'preflight_type'=>'metadata_plus_file_presence',
  'hash_verification_at_render'=>false,
  'render_engine_available'=>false,'render_allowed'=>false,
  'publication_allowed'=>false,'private_only'=>true];
}
