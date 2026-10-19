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
/** Canonical per-cut plan used by FFmpeg, the EDL and licensed dialogue ducking. */
function sf_render_transition_cuts(array $plan,array $settings):array{
 $items=$plan['items']??[];$count=count($items);
 if($count<2||$count>6||(int)($plan['total_ms']??0)>30000)
  throw new RuntimeException('Crossfade pilot accepts 2–6 clips and 30 seconds.');
 $baseStyle=$settings['style']??null;$baseOverlap=$settings['overlap_ms']??null;
 if(!in_array($baseStyle,['fade','wipeleft'],true)
   ||!in_array($baseOverlap,[250,500],true))
  throw new InvalidArgumentException('Only approved global transition presets are supported.');
 $cuts=$settings['cuts']??array_fill(0,$count-1,
  ['style'=>$baseStyle,'overlap_ms'=>$baseOverlap]);
 if(!is_array($cuts)||!array_is_list($cuts)||count($cuts)!==$count-1)
  throw new InvalidArgumentException('Per-cut plan must contain exactly one entry per boundary.');
 $out=[];
 foreach($cuts as $cut){
  if(!is_array($cut)||array_keys($cut)!==['style','overlap_ms']
    ||!in_array($cut['style'],['fade','wipeleft'],true)
    ||!in_array($cut['overlap_ms'],[250,500],true))
   throw new InvalidArgumentException('Unsafe per-cut visual or audio overlap.');
  $out[]=['style'=>$cut['style'],'overlap_ms'=>$cut['overlap_ms']];
 }
 foreach($items as $i=>$item){
  $before=$i===0?0:$out[$i-1]['overlap_ms'];
  $after=$i===$count-1?0:$out[$i]['overlap_ms'];
  if((int)$item['duration_ms']<$before+$after+500)
   throw new RuntimeException('A shot is too short for adjacent audio and video handles.');
 }
 return $out;
}
function sf_render_transition_validate(array $plan,string $style,int $overlap,array $settings=[]):void{
 $settings=['style'=>$style,'overlap_ms'=>$overlap]+$settings;
 sf_render_transition_cuts($plan,$settings);
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
  if(function_exists('sf_render_cut_clear'))sf_render_cut_clear($assembly);
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
 $settings=['style'=>(string)$v['style'],'overlap_ms'=>(int)$v['overlap_ms']];
 if(function_exists('sf_render_cut_settings')){
  $cuts=sf_render_cut_settings($editor,$assembly,$revision);
  if($cuts!==null)$settings['cuts']=$cuts;
 }
 return $settings;
}
/** For a subsequent music bed, correct start/duck times for overlapping clips. */
function sf_render_transition_effective_manifest(array $plan,array $settings):array{
 $cuts=sf_render_transition_cuts($plan,$settings);
 $effective=$plan;$shorten=array_sum(array_column($cuts,'overlap_ms'));
 $effective['total_ms']=(int)$plan['total_ms']-$shorten;
 if(function_exists('sf_render_edl_build')){
  $edl=sf_render_edl_build($plan,$settings);
  $effective['edl_duck_windows']=$edl['dialogue_duck_windows'];
  if($edl['output_total_ms']!==$effective['total_ms'])
   throw new RuntimeException('Crossfade EDL duration mismatch.');
 }
 foreach($effective['items'] as $i=>&$item)
  if($i>0)$item['duration_ms']-=$cuts[$i-1]['overlap_ms'];
 unset($item);
 return $effective;
}
/** Only generated private segment names can enter the FFmpeg plan. */
function sf_render_transition_args(array $segments,array $plan,array $setting,string $out):array {
 $cuts=sf_render_transition_cuts($plan,$setting);
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
 for($i=1;$i<count($segments);$i++){
  $cut=$cuts[$i-1];$time=sprintf('%.3f',$cut['overlap_ms']/1000);
  $offset=sprintf('%.3f',($durationMs-$cut['overlap_ms'])/1000);
  $nv='vx'.$i;$na='ax'.$i;
  $filters[]='['.$v.'][v'.$i.']xfade=transition='.$cut['style'].':duration='.$time.':offset='.$offset.'['.$nv.']';
  $filters[]='['.$a.'][a'.$i.']acrossfade=d='.$time.':c1=tri:c2=tri['.$na.']';
  $v=$nv;$a=$na;
  $durationMs+=(int)$plan['items'][$i]['duration_ms']-$cut['overlap_ms'];
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
