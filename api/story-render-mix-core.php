<?php
declare(strict_types=1);
/**
 * 88D3G4A: optional, revision-bound licensed Stonefellow music beds
 * and human dialogue ducking in private output only. No public delivery.
 */
function sf_render_mix_schema():void {
 static $ready=false;if($ready)return;
 sf_render_jobs_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_mixes(
   assembly_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,track_id TEXT NOT NULL,
   gain_pct INTEGER NOT NULL,duck_pct INTEGER NOT NULL,fade_ms INTEGER NOT NULL,
   reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_mixes(
    assembly_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,revision INT NOT NULL,
    track_id VARCHAR(128) NOT NULL,gain_pct INT NOT NULL,duck_pct INT NOT NULL,
    fade_ms INT NOT NULL,reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
    CONSTRAINT fk_render_mix_assembly FOREIGN KEY(assembly_id)
      REFERENCES story_assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_render_mix_author FOREIGN KEY(reviewed_by)
      REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
/** No changes to a queued or completed revision — immutable render job intent. */
function sf_render_mix_set(int $editor,int $assembly,int $revision,string $trackId,
 int $gainPct,int $duckPct,int $fadeMs):void{
 sf_assembly_editor($editor);sf_render_mix_schema();
 if($revision<1||$assembly<1||!in_array($gainPct,[10,15,20,25,30],true)
  ||!in_array($duckPct,[25,35,50,65],true)
  ||!in_array($fadeMs,[250,500,1000],true))
  throw new InvalidArgumentException('Music bed gain, ducking or fade outside approved presets.');
 $manifest=sf_render_plan($editor,$assembly,$revision);
 $match=null;
 foreach($manifest['items'] as $item)
  if($item['kind']==='track'&&(string)$item['source_id']===$trackId)$match=$item;
 if(!$match||$match['category']!=='audio')
  throw new InvalidArgumentException('Bed must use a certified canonical Stonefellow song in this assembly.');
 if($manifest['total_ms']<1500)throw new RuntimeException('Music bed requires at least 1.5 seconds.');
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested mix transaction forbidden.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([$assembly]);if((int)$q->fetchColumn()!==$revision)
   throw new RuntimeException('Timeline changed during music mix setup.');
  $q=$db->prepare('SELECT id FROM story_render_jobs WHERE assembly_id=? AND revision=?');
  $q->execute([$assembly,$revision]);
  if($q->fetchColumn()!==false)throw new RuntimeException('Render already queued; mix preset is locked.');
  $q=$db->prepare('SELECT assembly_id FROM story_render_mixes WHERE assembly_id=?');
  $q->execute([$assembly]);$existing=$q->fetchColumn();
  if($existing===false){
   $q=$db->prepare('INSERT INTO story_render_mixes
    (assembly_id,revision,track_id,gain_pct,duck_pct,fade_ms,reviewed_by,reviewed_at)
    VALUES(?,?,?,?,?,?,?,?)');
   $q->execute([$assembly,$revision,$trackId,$gainPct,$duckPct,$fadeMs,$editor,gmdate('c')]);
  }else{
   $q=$db->prepare('UPDATE story_render_mixes SET revision=?,track_id=?,gain_pct=?,duck_pct=?,
      fade_ms=?,reviewed_by=?,reviewed_at=? WHERE assembly_id=?');
   $q->execute([$revision,$trackId,$gainPct,$duckPct,$fadeMs,$editor,gmdate('c'),$assembly]);
  }
  sf_team_audit($editor,'capture.render_mix_reviewed','story_assembly',(string)$assembly,
   'Licensed music bed and dialogue ducking configured at exact editorial revision');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_render_mix_settings(int $editor,int $assembly,int $revision):?array{
 sf_assembly_editor($editor);sf_render_mix_schema();
 $q=sf_db()->prepare('SELECT revision,track_id,gain_pct,duck_pct,fade_ms
  FROM story_render_mixes WHERE assembly_id=?');$q->execute([$assembly]);
 $row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row||(int)$row['revision']!==$revision)return null;
 return ['track_id'=>(string)$row['track_id'],'gain_pct'=>(int)$row['gain_pct'],
  'duck_pct'=>(int)$row['duck_pct'],'fade_ms'=>(int)$row['fade_ms']];
}
/** Bed gain/ducks are constructed from certified timeline metadata only. */
function sf_render_mix_filter(array $manifest,array $mix):string{
 $total=(int)$manifest['total_ms'];
 if($total<1500||$total>60000||!in_array($mix['gain_pct'],[10,15,20,25,30],true)
  ||!in_array($mix['duck_pct'],[25,35,50,65],true)
  ||!in_array($mix['fade_ms'],[250,500,1000],true))
  throw new InvalidArgumentException('Unsafe music mix preset or runtime.');
 $fade=sprintf('%.3f',min($total/4000,$mix['fade_ms']/1000));
 $end=sprintf('%.3f',$total/1000-$fade);
 $parts=['[1:a]aformat=sample_fmts=fltp:channel_layouts=stereo,'.
  'atrim=duration='.sprintf('%.3f',$total/1000).',asetpts=PTS-STARTPTS,'.
  'volume='.sprintf('%.2f',$mix['gain_pct']/100)];
 $edl=function_exists('sf_render_edl_build')?sf_render_edl_build($manifest,null,null):null;
 $offset=0;
 if($edl===null)foreach($manifest['items'] as $item){
  $duration=(int)$item['duration_ms'];
  if($duration<500||$duration>20000)throw new InvalidArgumentException('Invalid mix timing source.');
  if($item['kind']==='transcript'){
   $from=sprintf('%.3f',$offset/1000);$to=sprintf('%.3f',($offset+$duration)/1000);
   $parts[]='volume='.sprintf('%.2f',$mix['duck_pct']/100).":enable='between(t,$from,$to)'";
  }
  $offset+=$duration;
 }
 if($edl!==null){
  foreach($edl['dialogue_duck_windows'] as $window){
   $from=sprintf('%.3f',$window['start_ms']/1000);
   $to=sprintf('%.3f',$window['end_ms']/1000);
   $parts[]='volume='.sprintf('%.2f',$mix['duck_pct']/100).":enable='between(t,$from,$to)'";
  }
  if($edl['output_total_ms']!==$total)
   throw new RuntimeException('EDL timing changed during bed composition.');
 }elseif($offset!==$total)throw new RuntimeException('Timeline timing changed during bed composition.');
 $parts[]='afade=t=in:st=0:d='.$fade;
 $parts[]='afade=t=out:st='.$end.':d='.$fade.'[bed]';
 return implode(',',$parts).';[0:a]aformat=sample_fmts=fltp:channel_layouts=stereo[dialogue];'.
  '[dialogue][bed]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,'.
  'alimiter=limit=0.95[mixed]';
}
/** Verify canonical, scope-cleared, hashed music master again before mixing. */
function sf_render_mix_source(array $manifest,array $mix):array{
 foreach($manifest['items'] as $item)
  if($item['kind']==='track'&&(string)$item['source_id']===$mix['track_id']
    &&$item['category']==='audio')return $item;
 throw new RuntimeException('Licensed bed track not present in current certified timeline.');
}
/** Output is a separate private transient MP4, never exposed directly. */
function sf_render_mix_apply(array $manifest,array $mix,string $draft,string $destination):void{
 $music=sf_render_mix_source($manifest,$mix);
 if(!is_file($draft)||!preg_match('~/draft\.mp4$~',$draft)
   ||!preg_match('~/mixed\.mp4$~',$destination)||dirname($draft)!==dirname($destination))
  throw new RuntimeException('Invalid private mix workspace.');
 $filter=sf_render_mix_filter($manifest,$mix);
 $args=[sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error','-y',
  '-i',$draft,'-stream_loop','-1','-i',$music['path'],
  '-filter_complex',$filter,'-map','0:v:0','-map','[mixed]',
  '-t',sprintf('%.3f',$manifest['total_ms']/1000),
  '-c:v','copy','-c:a','aac','-b:a','160k','-ac','2','-ar','48000',
  '-movflags','+faststart',$destination];
 sf_render_exec($args,45);
 if(!is_file($destination)||filesize($destination)<100)
  throw new RuntimeException('Private mixed video output is missing.');
}
