<?php
declare(strict_types=1);
/**
 * Optional local video-frame extraction. No upload, shell interpolation, public
 * thumbnail or retained extracted frame. A missing FFmpeg binary fails closed.
 */
function sf_inference_frame_window(int $durationMs):array{
 if($durationMs<150||$durationMs>86400000)
  throw new InvalidArgumentException('Video duration unavailable or outside supported bounds.');
 $sample=min(3000,max(75,(int)floor($durationMs/3)));
 $start=max(0,$sample-250);
 $end=min($durationMs,$sample+250);
 if($end<=$start)throw new RuntimeException('Invalid sampled frame interval.');
 return ['sampled_frame_ms'=>$sample,'start_ms'=>$start,'end_ms'=>$end];
}
function sf_inference_ffmpeg_binary():string{
 foreach(['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg'] as $p)
  if(is_file($p)&&is_executable($p))return $p;
 return '';
}
/**
 * Input must have passed sf_inference_media_path and lease/rights validation.
 * Output temporary image is deleted by sf_inference_dispatch in a finally block.
 */
function sf_inference_extract_video_frame(array $media):array{
 if(($media['mime']??'')!=='video/mp4'||($media['task_type']??'')!=='scene')
  throw new InvalidArgumentException('Only private MP4 scene sources may be sampled.');
 $binary=sf_inference_ffmpeg_binary();
 if($binary===''||!function_exists('proc_open'))
  throw new RuntimeException('Host does not provide supervised FFmpeg frame extraction.');
 $window=sf_inference_frame_window((int)round((float)($media['duration_seconds']??0)*1000));
 $temp=tempnam(sys_get_temp_dir(),'sf-private-frame-');
 if($temp===false)throw new RuntimeException('Private temporary frame unavailable.');
 chmod($temp,0600);
 $proc=null;$pipes=[];
 try{
  $args=[$binary,'-nostdin','-v','error','-ss',sprintf('%.3f',$window['sampled_frame_ms']/1000),
    '-i',(string)$media['path'],'-frames:v','1','-vf','scale=w=768:h=768:force_original_aspect_ratio=decrease',
    '-q:v','5','-f','image2','-y',$temp];
  $proc=@proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],
   $pipes,null,null,['bypass_shell'=>true]);
  if(!is_resource($proc))throw new RuntimeException('FFmpeg could not be started.');
  fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
  $deadline=microtime(true)+12;$exit=-1;
  do{
    $stat=proc_get_status($proc);
    if(!$stat['running']){$exit=(int)$stat['exitcode'];break;}
    if(microtime(true)>$deadline){proc_terminate($proc,9);throw new RuntimeException('Frame extraction timeout.');}
    usleep(50000);
  }while(true);
  fclose($pipes[1]);fclose($pipes[2]);$pipes=[];
  proc_close($proc);$proc=null;
  if($exit!==0||!is_file($temp)||filesize($temp)===0
     ||filesize($temp)>5*1024*1024||(new finfo(FILEINFO_MIME_TYPE))->file($temp)!=='image/jpeg')
    throw new RuntimeException('Video frame extraction failed.');
  return ['path'=>$temp,'mime'=>'image/jpeg']+$window;
 }catch(Throwable $e){
  foreach($pipes as $p)if(is_resource($p))fclose($p);
  if(is_resource($proc)){proc_terminate($proc,9);proc_close($proc);}
  @unlink($temp);
  throw $e;
 }
}
