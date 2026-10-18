<?php
declare(strict_types=1);
/**
 * 88D3G2: one-shot supervised private 720p FFmpeg render worker.
 * Never streams, distributes, uploads or publishes a finished output.
 * 88D3G3 will add private playback/reviewer approvals.
 */
function sf_render_jobs_schema():void{
 static $ready=false;if($ready)return;
 sf_render_source_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_jobs(
    id INTEGER PRIMARY KEY AUTOINCREMENT,assembly_id INTEGER NOT NULL,
    revision INTEGER NOT NULL,status TEXT NOT NULL,created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,updated_at TEXT NOT NULL,
    output_name TEXT NOT NULL DEFAULT '',output_sha256 TEXT NOT NULL DEFAULT '',
    output_bytes INTEGER NOT NULL DEFAULT 0,error_code TEXT NOT NULL DEFAULT '',
    UNIQUE(assembly_id,revision),
    FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_render_jobs(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,assembly_id BIGINT UNSIGNED NOT NULL,
    revision INT NOT NULL,status VARCHAR(24) NOT NULL,created_by BIGINT UNSIGNED NOT NULL,
    created_at VARCHAR(40) NOT NULL,updated_at VARCHAR(40) NOT NULL,
    output_name VARCHAR(180) NOT NULL DEFAULT '',output_sha256 CHAR(64) NOT NULL DEFAULT '',
    output_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,error_code VARCHAR(40) NOT NULL DEFAULT '',
    UNIQUE KEY uq_render_assembly_revision(assembly_id,revision),
    INDEX idx_render_job_status(status,id),
    CONSTRAINT fk_render_job_assembly FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
    CONSTRAINT fk_render_job_creator FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_render_enabled():bool{
 return function_exists('sf_ai_meta_get')&&sf_ai_meta_get('artist_world.render_enabled','0')==='1';
}
function sf_render_configure(int $editor,bool $enabled):void{
 sf_assembly_editor($editor);
 if(!function_exists('sf_ai_meta_set'))throw new RuntimeException('Render configuration unavailable.');
 sf_ai_meta_set('artist_world.render_enabled',$enabled?'1':'0');
 sf_team_audit($editor,'capture.render_switch','artist_world','render',
  $enabled?'Supervised private rendering enabled':'Supervised private rendering disabled');
}
/** Runtime output lives outside SF_ROOT and is NEVER served through an HTTP route. */
function sf_render_private_root():string{
 if(!defined('SF_ROOT'))throw new RuntimeException('Missing application root.');
 $base=defined('SF_RENDER_PRIVATE_DIR')?(string)SF_RENDER_PRIVATE_DIR:
   dirname(SF_ROOT).'/stonefellow-private-renders';
 if(!str_starts_with($base,'/'))throw new RuntimeException('Private render root must be absolute.');
 if(!is_dir($base)&&!@mkdir($base,0700,true)&&!is_dir($base))
  throw new RuntimeException('Private render directory cannot be created.');
 $root=realpath($base);$app=realpath(SF_ROOT);
 if(!$root||!$app||($root!==false&&($root===$app||str_starts_with($root,$app.DIRECTORY_SEPARATOR))))
  throw new RuntimeException('Private renders must be outside the public application root.');
 @chmod($root,0700);
 return $root;
}
function sf_render_plan(int $editor,int $assemblyId,int $revision):array{
 $manifest=sf_render_source_manifest($editor,$assemblyId,$revision);
 $items=$manifest['items'];$total=(int)$manifest['total_ms'];
 if(count($items)<1||count($items)>12||$total>60000||$total<1000)
  throw new RuntimeException('First supervised pilot accepts 1–12 clips and 1–60 seconds.');
 foreach($items as $i=>$item){
  $category=$item['category'];$kind=$item['kind'];
  if($category==='image'&&!in_array($item['mime'],['image/jpeg','image/png','image/webp'],true))
   throw new RuntimeException('Unsupported still image render format.');
  if($category==='video'&&!in_array($item['mime'],['video/mp4','video/webm','video/quicktime'],true))
   throw new RuntimeException('Unsupported video render format.');
  if($category==='audio'&&!in_array($item['mime'],
    ['audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/flac','audio/ogg','audio/webm','audio/mp4','audio/x-m4a'],true))
   throw new RuntimeException('Unsupported audio render format.');
  if($kind==='track'&&$category!=='audio')throw new RuntimeException('Catalog master must be audio.');
  if($kind==='scene'&&$category==='audio')throw new RuntimeException('Scene must have visual evidence.');
  if($kind==='transcript'&&$category==='image')throw new RuntimeException('Dialogue requires audio or video source.');
  if($category!=='image'&&($item['source_duration_ms']<=0
    ||$item['start_ms']+$item['duration_ms']>$item['source_duration_ms']+250))
   throw new RuntimeException('Source duration cannot support requested clip.');
  if($item['duration_ms']>20000)throw new RuntimeException('Pilot clip exceeds 20 seconds.');
 }
 return $manifest;
}
function sf_render_enqueue(int $editor,int $assemblyId,int $revision):array{
 sf_assembly_editor($editor);sf_render_jobs_schema();
 if(!sf_render_enabled())throw new RuntimeException('Private rendering is disabled by default.');
 sf_render_plan($editor,$assemblyId,$revision);
 $db=sf_db();
 $q=$db->prepare('SELECT id,status FROM story_render_jobs WHERE assembly_id=? AND revision=?');
 $q->execute([$assemblyId,$revision]);$existing=$q->fetch(PDO::FETCH_ASSOC);
 if($existing)return ['job_id'=>(int)$existing['id'],'status'=>$existing['status'],'existing'=>true];
 $q=$db->prepare('SELECT COUNT(*) FROM story_render_jobs WHERE created_at>=?');
 $q->execute([gmdate('Y-m-d').'T00:00:00']);
 if((int)$q->fetchColumn()>=3)throw new RuntimeException('Daily supervised render budget reached.');
 if(function_exists('sf_team_ensure_schema'))sf_team_ensure_schema();
 $db->beginTransaction();
 try{
  $q=$db->prepare("INSERT INTO story_render_jobs
   (assembly_id,revision,status,created_by,created_at,updated_at)
   VALUES(?,?,'queued',?,?,?)");
  $q->execute([$assemblyId,$revision,$editor,gmdate('c'),gmdate('c')]);$id=(int)$db->lastInsertId();
  sf_team_audit($editor,'capture.render_queued','story_render_job',(string)$id,
   'Private supervisor-authorized 720p draft render queued');
  $db->commit();return ['job_id'=>$id,'status'=>'queued','existing'=>false];
 }catch(Throwable $e){
  if($db->inTransaction())$db->rollBack();
  $q=$db->prepare('SELECT id,status FROM story_render_jobs WHERE assembly_id=? AND revision=?');
  $q->execute([$assemblyId,$revision]);$old=$q->fetch(PDO::FETCH_ASSOC);
  if($old)return ['job_id'=>(int)$old['id'],'status'=>$old['status'],'existing'=>true];
  throw $e;
 }
}
function sf_render_jobs(int $editor,int $assemblyId):array{
 sf_assembly_editor($editor);sf_render_jobs_schema();
 $q=sf_db()->prepare('SELECT id,revision,status,created_at,updated_at,output_bytes,error_code
   FROM story_render_jobs WHERE assembly_id=? ORDER BY id DESC LIMIT 30');
 $q->execute([$assemblyId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function sf_render_ffmpeg():string{
 foreach(['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg'] as $p)
  if(is_file($p)&&is_executable($p))return $p;
 throw new RuntimeException('Supervised FFmpeg executable unavailable.');
}
function sf_render_ffprobe():string{
 foreach(['/usr/bin/ffprobe','/usr/local/bin/ffprobe'] as $p)
  if(is_file($p)&&is_executable($p))return $p;
 throw new RuntimeException('Supervised ffprobe executable unavailable.');
}
/** Fixed-argument child process with strict timeout and no shell. */
function sf_render_exec(array $args,int $limit=45):void{
 if(!function_exists('proc_open'))throw new RuntimeException('Subprocess execution disabled.');
 $pipes=[];$handle=@proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
  $pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($handle))throw new RuntimeException('Media process unavailable.');
 $exit=null;
 try{
  fclose($pipes[0]);$pipes[0]=null;
  stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
  $deadline=microtime(true)+$limit;
  do{
   $state=proc_get_status($handle);
   if(!$state['running']){$exit=(int)$state['exitcode'];break;}
   // Bound pipes and avoid deadlock if ffmpeg reports an error.
   stream_get_contents($pipes[1],4096);stream_get_contents($pipes[2],4096);
   if(microtime(true)>$deadline){proc_terminate($handle,9);throw new RuntimeException('Media worker exceeded runtime limit.');}
   usleep(60000);
  }while(true);
 }finally{
  foreach($pipes as $p)if(is_resource($p))fclose($p);
  if($exit===null)proc_terminate($handle,9);
  proc_close($handle);
 }
 if($exit!==0)throw new RuntimeException('Private media process failed.');
}
function sf_render_has_audio(string $path):bool{
 $args=[sf_render_ffprobe(),'-v','error','-select_streams','a:0',
  '-show_entries','stream=index','-of','csv=p=0',$path];
 $pipes=[];$handle=@proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
   $pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($handle))throw new RuntimeException('Audio stream inspection unavailable.');
 fclose($pipes[0]);$out=stream_get_contents($pipes[1],256);
 $err=stream_get_contents($pipes[2],256);fclose($pipes[1]);fclose($pipes[2]);
 $code=proc_close($handle);
 if($code!==0)throw new RuntimeException('Media audio-track inspection failed.');
 return trim((string)$out)!=='';
}
/** Normalization: all source footage, photos and audio become 720p/24fps H.264/AAC. */
function sf_render_segment(array $source,string $output):void{
 $bin=sf_render_ffmpeg();$dur=sprintf('%.3f',$source['duration_ms']/1000);
 $at=sprintf('%.3f',$source['start_ms']/1000);
 $filter='scale=1280:720:force_original_aspect_ratio=decrease,'.
   'pad=1280:720:(ow-iw)/2:(oh-ih)/2,setsar=1,fps=24,format=yuv420p';
 $category=$source['category'];
 $args=[$bin,'-nostdin','-hide_banner','-loglevel','error','-y'];
 if($category==='image'){
  array_push($args,'-loop','1','-framerate','24','-i',$source['path'],
    '-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000',
    '-map','0:v:0','-map','1:a:0');
 }elseif($category==='video'){
  array_push($args,'-ss',$at,'-i',$source['path']);
  if(sf_render_has_audio($source['path']))array_push($args,'-map','0:v:0','-map','0:a:0');
  else array_push($args,'-f','lavfi','-i','anullsrc=channel_layout=stereo:sample_rate=48000',
   '-map','0:v:0','-map','1:a:0');
 }else{
  array_push($args,'-f','lavfi','-i','color=c=black:s=1280x720:r=24',
   '-ss',$at,'-i',$source['path'],'-map','0:v:0','-map','1:a:0');
 }
 array_push($args,'-t',$dur,'-vf',$filter,'-c:v','libx264','-preset','ultrafast',
  '-crf','27','-pix_fmt','yuv420p','-c:a','aac','-ar','48000','-ac','2',
  '-b:a','128k','-movflags','+faststart','-shortest',$output);
 sf_render_exec($args,45);
 if(!is_file($output)||filesize($output)<100)throw new RuntimeException('Rendered segment is empty.');
}
/** Concat demuxer list is created in a private transient directory from generated names only. */
function sf_render_concat(array $segments,string $list,string $dest):void{
 $lines=[];foreach($segments as $path){
  if(!preg_match('~/segment-[0-9]{2}\.mp4$~',$path))
   throw new RuntimeException('Unsafe segment file reference.');
  $lines[]="file '".basename($path)."'";
 }
 if(file_put_contents($list,implode("\n",$lines)."\n",LOCK_EX)===false)
  throw new RuntimeException('Private concat plan cannot be written.');
 sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
  '-f','concat','-safe','1','-i',$list,'-c','copy','-movflags','+faststart',
  '-y',$dest],45);
 if(!is_file($dest)||filesize($dest)<100)throw new RuntimeException('Private draft video is empty.');
}
/** One supervised job per CLI run; no automatic replay of uncertain FFmpeg work. */
function sf_render_run_once(int $editor):array{
 sf_assembly_editor($editor);sf_render_jobs_schema();
 if(!sf_render_enabled())throw new RuntimeException('Owner has not enabled private rendering.');
 $db=sf_db();$q=$db->query("SELECT id,assembly_id,revision FROM story_render_jobs
    WHERE status='queued' ORDER BY id LIMIT 1");
 $job=$q->fetch(PDO::FETCH_ASSOC);if(!$job)return ['status'=>'empty'];
 $jobId=(int)$job['id'];$assembly=(int)$job['assembly_id'];$revision=(int)$job['revision'];
 $q=$db->prepare("UPDATE story_render_jobs SET status='running',updated_at=?
    WHERE id=? AND status='queued'");
 $q->execute([gmdate('c'),$jobId]);if($q->rowCount()!==1)return ['status'=>'busy'];
 $workspace='';$final='';
 try{
  $manifest=sf_render_plan($editor,$assembly,$revision);
  $private=sf_render_private_root();
  $workspace=$private.'/work-'.$jobId.'-'.bin2hex(random_bytes(8));
  if(!mkdir($workspace,0700))throw new RuntimeException('Private worker directory unavailable.');
  $segments=[];
  foreach($manifest['items'] as $i=>$item){
   if(!sf_render_enabled())throw new RuntimeException('Rendering permission revoked.');
   sf_render_plan($editor,$assembly,$revision); // recheck rights and source bytes
   $segment=$workspace.'/segment-'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'.mp4';
   sf_render_segment($item,$segment);$segments[]=$segment;
  }
  $rendered=$workspace.'/draft.mp4';
  sf_render_concat($segments,$workspace.'/cuts.ffconcat',$rendered);
  $fresh=sf_render_plan($editor,$assembly,$revision); // rights and hashes checked after work
  if(!sf_render_enabled()||$fresh['total_ms']!==$manifest['total_ms'])
   throw new RuntimeException('Source certification changed during rendering.');
  $sha=hash_file('sha256',$rendered);$size=filesize($rendered);
  if(!is_string($sha)||!is_int($size)||$size<100||$size>150*1024*1024)
   throw new RuntimeException('Invalid bounded private video output.');
  $name='assembly-'.$assembly.'-rev-'.$revision.'-job-'.$jobId.'-'.bin2hex(random_bytes(8)).'.mp4';
  $final=$private.'/'.$name;
  if(!rename($rendered,$final))throw new RuntimeException('Cannot commit private draft output.');
  chmod($final,0600);
  $q=$db->prepare("UPDATE story_render_jobs SET status='private_review',
   output_name=?,output_sha256=?,output_bytes=?,error_code='',updated_at=?
   WHERE id=? AND status='running'");
  $q->execute([$name,$sha,$size,gmdate('c'),$jobId]);
  if($q->rowCount()!==1)throw new RuntimeException('Render job status changed before commit.');
  return ['status'=>'private_review','job_id'=>$jobId,'output_bytes'=>$size];
 }catch(Throwable $e){
  if($final!==''&&is_file($final))@unlink($final);
  $q=$db->prepare("UPDATE story_render_jobs SET status='interrupted',
   error_code='render_or_source_invalid',updated_at=? WHERE id=? AND status='running'");
  $q->execute([gmdate('c'),$jobId]);
  return ['status'=>'interrupted','job_id'=>$jobId,'error'=>'render_or_source_invalid'];
 }finally{
  if($workspace!==''&&is_dir($workspace)){
   foreach((array)glob($workspace.'/*') as $file)if(is_file($file))@unlink($file);
   @rmdir($workspace);
  }
 }
}
