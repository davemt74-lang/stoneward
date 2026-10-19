<?php
declare(strict_types=1);
/** 88D3G4B — revision-locked private editorial video/audio transitions. */
function sf_render_transition_schema():void{
 static $ready=false;if($ready)return;
 sf_render_jobs_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_transitions(
   assembly_id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,style TEXT NOT NULL,
   overlap_ms INTEGER NOT NULL,reviewed_by INTEGER NOT NULL,reviewed_at TEXT NOT NULL,
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
   FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_transitions(
   assembly_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,revision INT NOT NULL,
   style VARCHAR(24) NOT NULL,overlap_ms INT NOT NULL,
   reviewed_by BIGINT UNSIGNED NOT NULL,reviewed_at VARCHAR(40) NOT NULL,
   CONSTRAINT fk_render_transition_assembly FOREIGN KEY(assembly_id)
    REFERENCES story_assemblies(id) ON DELETE CASCADE,
   CONSTRAINT fk_render_transition_editor FOREIGN KEY(reviewed_by)
    REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_render_transition_validate(array $plan,string $style,int $overlap):void{
 if(!in_array($style,['fade','wipeleft'],true)||!in_array($overlap,[250,500],true))
  throw new InvalidArgumentException('Only approved visual transitions and overlaps supported.');
 if(count($plan['items'])<2||count($plan['items'])>6||(int)$plan['total_ms']>30000)
  throw new RuntimeException('Crossfade pilot is limited to 2–6 clips and 30 seconds.');
 foreach($plan['items'] as $item)
  if((int)$item['duration_ms']<$overlap*2+500)
   throw new RuntimeException('Each shot must be long enough for stable video and audio overlaps.');
}
function sf_render_transition_set(int $editor,int $assembly,int $revision,string $style,int $overlap):void{
 sf_assembly_editor($editor);sf_render_transition_schema();
 $plan=sf_render_plan($editor,$assembly,$revision);
 sf_render_transition_validate($plan,$style,$overlap);
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db=sf_db();if($db->inTransaction())throw new RuntimeException('Nested transition transaction forbidden.');
 $db->beginTransaction();
 try{
  $q=$db->prepare("SELECT revision FROM story_assemblies WHERE id=? AND status='draft'");
  $q->execute([$assembly]);
  if((int)$q->fetchColumn()!==$revision)throw new RuntimeException('Timeline revision changed.');
  $q=$db->prepare('SELECT id FROM story_render_jobs WHERE assembly_id=? AND revision=?');
  $q->execute([$assembly,$revision]);
  if($q->fetchColumn()!==false)throw new RuntimeException('Queued render already locks transitions.');
  $q=$db->prepare('SELECT assembly_id FROM story_render_transitions WHERE assembly_id=?');
  $q->execute([$assembly]);
  if($q->fetchColumn()===false){
   $q=$db->prepare('INSERT INTO story_render_transitions
    (assembly_id,revision,style,overlap_ms,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?)');
   $q->execute([$assembly,$revision,$style,$overlap,$editor,gmdate('c')]);
  }else{
   $q=$db->prepare('UPDATE story_render_transitions
    SET revision=?,style=?,overlap_ms=?,reviewed_by=?,reviewed_at=?
    WHERE assembly_id=?');
   $q->execute([$revision,$style,$overlap,$editor,gmdate('c'),$assembly]);
  }
  sf_team_audit($editor,'capture.render_transition_reviewed','story_assembly',(string)$assembly,
    'Private visual and audio crossfade preset scoped to immutable render revision');
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function sf_render_transition_settings(int $editor,int $assembly,int $revision):?array{
 sf_assembly_editor($editor);sf_render_transition_schema();
 $q=sf_db()->prepare('SELECT revision,style,overlap_ms FROM story_render_transitions WHERE assembly_id=?');
 $q->execute([$assembly]);$v=$q->fetch(PDO::FETCH_ASSOC);
 if(!$v||(int)$v['revision']!==$revision)return null;
 return ['style'=>(string)$v['style'],'overlap_ms'=>(int)$v['overlap_ms']];
}
/** For a subsequent music bed, correct start/duck times for overlapping clips. */
function sf_render_transition_effective_manifest(array $plan,array $settings):array{
 sf_render_transition_validate($plan,$settings['style'],$settings['overlap_ms']);
 $effective=$plan;$count=count($plan['items']);$overlap=$settings['overlap_ms'];
 $effective['total_ms']=$plan['total_ms']-($count-1)*$overlap;
 if(function_exists('sf_render_edl_build')){
  $edl=sf_render_edl_build($plan,$settings);
  $effective['edl_duck_windows']=$edl['dialogue_duck_windows'];
  if($edl['output_total_ms']!==$effective['total_ms'])throw new RuntimeException('Crossfade EDL duration mismatch.');
 }
 foreach($effective['items'] as $i=>&$item)
  if($i>0)$item['duration_ms']-=$overlap;
 unset($item);
 return $effective;
}
/** Only generated private segment names can enter the FFmpeg plan. */
function sf_render_transition_args(array $segments,array $plan,array $setting,string $out):array {
 sf_render_transition_validate($plan,$setting['style'],$setting['overlap_ms']);
 if(count($segments)!==count($plan['items']))
  throw new InvalidArgumentException('Segment count does not match certified timeline.');
 if(!preg_match('~/draft\.mp4$~',$out))
  throw new InvalidArgumentException('Crossfade output must be the private draft path.');
 $args=[sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error','-y'];
 foreach($segments as $path){
  if(!preg_match('~/segment-[0-9]{2}\.mp4$~',$path)||dirname($path)!==dirname($out)
    ||!is_file($path))throw new RuntimeException('Video transition input is not a private generated segment.');
  array_push($args,'-i',$path);
 }
 $filters=[];
 foreach($segments as $i=>$p){
  $filters[]='['.$i.':v]setpts=PTS-STARTPTS,settb=AVTB,format=yuv444p[v'.$i.']';
  $filters[]='['.$i.':a]aresample=async=1:first_pts=0,asetpts=PTS-STARTPTS[a'.$i.']';
 }
 $v='v0';$a='a0';$durationMs=(int)$plan['items'][0]['duration_ms'];
 $time=sprintf('%.3f',$setting['overlap_ms']/1000);
 for($i=1;$i<count($segments);$i++){
  $offset=sprintf('%.3f',($durationMs-$setting['overlap_ms'])/1000);
  $nv='vx'.$i;$na='ax'.$i;
  $filters[]='['.$v.'][v'.$i.']xfade=transition='.$setting['style'].':duration='.$time.':offset='.$offset.'['.$nv.']';
  $filters[]='['.$a.'][a'.$i.']acrossfade=d='.$time.':c1=tri:c2=tri['.$na.']';
  $v=$nv;$a=$na;
  $durationMs+=(int)$plan['items'][$i]['duration_ms']-$setting['overlap_ms'];
 }
 $filters[]='['.$v.']format=yuv420p[video]';
 array_push($args,'-filter_complex',implode(';',$filters),
  '-map','[video]','-map','['.$a.']','-c:v','libx264','-preset','ultrafast',
  '-crf','27','-pix_fmt','yuv420p','-c:a','aac','-ar','48000','-ac','2',
  '-b:a','128k','-t',sprintf('%.3f',$durationMs/1000),
  '-movflags','+faststart',$out);
 return $args;
}
function sf_render_transition_apply(array $segments,array $plan,array $settings,string $out):void{
 $args=sf_render_transition_args($segments,$plan,$settings,$out);
 sf_render_exec($args,45);
 if(!is_file($out)||filesize($out)<100)
  throw new RuntimeException('Real crossfaded private MP4 is missing.');
}
