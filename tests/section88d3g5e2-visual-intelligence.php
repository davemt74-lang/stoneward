<?php
declare(strict_types=1);
/** Real bounded FFmpeg frame fixture; no external provider or image retention. */
function sf_capture_clean($input,int $limit=300):string{return substr(trim((string)$input),0,$limit);}
require dirname(__DIR__).'/api/story-inference-video-core.php';
require dirname(__DIR__).'/api/story-footage-intelligence-core.php';
$total=0;
function visualPass(bool $yes,string $name):void{
 global $total;if(!$yes)throw new RuntimeException('G5E2 FAIL '.$name);
 $total++;echo 'G5E2 PASS '.$name.PHP_EOL;
}
function visualDeny(callable $action,string $name):void{
 $denied=false;try{$action();}catch(Throwable $e){$denied=true;}
 visualPass($denied,$name);
}
visualDeny(fn()=>sf_footage_sample_positions(500),'Videos under 900 ms are not claimed to have three distinct moments.');
visualDeny(fn()=>sf_footage_sample_positions(90000000),'Invalid source duration cannot enter model processing.');
$positions=sf_footage_sample_positions(3000);
visualPass(count($positions)===3&&$positions===[600,1500,2400],
 'Three ordered visual samples derive from source duration, not model guesses.');
$windows=array_map(static fn(int $t):array=>[
 'sampled_frame_ms'=>$t,'start_ms'=>$t-200,'end_ms'=>$t+200],$positions);
$answer=[
 'overall_summary'=>'Three still samples show instruments on a brightly lit stage.',
 'frames'=>[
  ['index'=>1,'description'=>'A guitar is near a microphone stand.','usable'=>true,'retake_note'=>''],
  ['index'=>2,'description'=>'A side view of a guitar amplifier.','usable'=>true,'retake_note'=>'Hold the camera steadier.'],
  ['index'=>3,'description'=>'A wide shot of instruments on the stage.','usable'=>false,'retake_note'=>'Capture a wider establishing view.']
 ],
 'shot_recommendations'=>['Try a steady wide establishing shot.']
];
$draft=sf_footage_validate_draft($answer,$windows);
visualPass(count($draft['frame_observations'])===3&&
 $draft['frame_observations'][2]['sampled_frame_ms']===2400&&
 $draft['frame_observations'][2]['usable_suggestion']===false&&
 !$draft['publication_authorized']&&!$draft['audio_analyzed'],
 'Visual recommendations have accurate server-derived source times and no fake audio/publication approval.');
visualDeny(fn()=>sf_footage_validate_draft(array_replace($answer,['frames'=>[$answer['frames'][0]]]),$windows),
 'Incomplete model JSON cannot invent missing observations.');
$bad=$answer;$bad['frames'][1]['index']=1;
visualDeny(fn()=>sf_footage_validate_draft($bad,$windows),
 'Wrong or duplicated frame positions cannot be trusted.');
$bad=$answer;$bad['frames'][0]['usable']='yes';
visualDeny(fn()=>sf_footage_validate_draft($bad,$windows),
 'Model usability field must be strictly boolean.');
$bin=sf_inference_ffmpeg_binary();
if($bin==='')throw new RuntimeException('Actual FFmpeg required for G5E2 acceptance.');
$folder=sys_get_temp_dir().'/sf-g5e2-'.bin2hex(random_bytes(6));
if(!mkdir($folder,0700))throw new RuntimeException('Private test directory unavailable.');
$video=$folder.'/test.mp4';$proc=null;
try{
 $cmd=[$bin,'-nostdin','-v','error','-f','lavfi','-i','testsrc=size=320x180:rate=5',
   '-t','3','-pix_fmt','yuv420p','-c:v','mpeg4','-y',$video];
 $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,
   null,null,['bypass_shell'=>true]);
 if(!is_resource($proc))throw new RuntimeException('FFmpeg test start failed.');
 fclose($pipes[0]);stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);
 visualPass(proc_close($proc)===0&&filesize($video)>0,
  'Real MP4 source produced for visual evidence certification.');$proc=null;
 $media=['mime'=>'video/mp4','task_type'=>'scene','path'=>$video,'duration_seconds'=>3];
 $seen=0;$snapshots=glob(sys_get_temp_dir().'/sf-private-frame-*')?:[];
 $mock=function(string $url,array $headers,array $body)use(&$seen,$answer):array{
  $seen++;
  if($url!=='https://api.openai.com/v1/responses')
   throw new RuntimeException('Unapproved destination.');
  $inputs=$body['input'][0]['content']??[];
  $images=array_values(array_filter($inputs,
    static fn($i)=>($i['type']??'')==='input_image'));
  if(count($images)!==3)throw new RuntimeException('Expected exactly three real visual frames.');
  foreach($images as $im)if(!str_starts_with($im['image_url'],'data:image/jpeg;base64,'))
   throw new RuntimeException('Expected private JPEG samples.');
  return ['output_text'=>json_encode($answer,JSON_THROW_ON_ERROR)];
 };
 $result=sf_footage_dispatch_video($media,
  ['provider'=>'openai','model'=>'test','api_key'=>'FAKE-KEY'],$mock);
 visualPass($seen===1&&$result['timing']==='three_sampled_frames_only'&&
   count($result['frame_observations'])===3,
  'One supervised provider request receives three actual FFmpeg sampled JPEG images.');
 visualPass((glob(sys_get_temp_dir().'/sf-private-frame-*')?:[])===$snapshots,
   'Frame files are destroyed after successful provider response.');
 $anthropic=function(string $url,array $headers,array $body)use($answer):array{
  if($url!=='https://api.anthropic.com/v1/messages')throw new RuntimeException('Wrong AI route.');
  $images=array_values(array_filter($body['messages'][0]['content']??[],
    static fn($x)=>($x['type']??'')==='image'));
  if(count($images)!==3)throw new RuntimeException('Missing three Anthropic visual samples.');
  return ['content'=>[['type'=>'text','text'=>json_encode($answer)]]];
 };
 $claude=sf_footage_dispatch_video($media,
  ['provider'=>'anthropic','model'=>'test','api_key'=>'FAKE-KEY'],$anthropic);
 visualPass(count($claude['frame_observations'])===3,
  'Supported Anthropic vision route receives the same canonical source moments.');
 visualDeny(fn()=>sf_footage_dispatch_video($media,
    ['provider'=>'openai','model'=>'test','api_key'=>'FAKE-KEY'],
    static fn()=>['output_text'=>'not JSON']),
  'Unstructured model output fails closed rather than creating fabricated scenes.');
 visualPass((glob(sys_get_temp_dir().'/sf-private-frame-*')?:[])===$snapshots,
  'Frames are destroyed after malformed model output and provider errors.');
}finally{
 if(is_resource($proc))proc_terminate($proc,9);
 @unlink($video);@rmdir($folder);
}
$agent=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
$worker=file_get_contents(dirname(__DIR__).'/api/story-inference-worker-core.php');
visualPass(str_contains($agent,"'production_footage_intelligence'")&&
 str_contains($ui,'footageIntelligenceHtml')&&
 str_contains($ui,'data-agent-footage-enqueue')&&
 str_contains($ui,'data-agent-footage-decision')&&
 str_contains($worker,'sf_footage_dispatch_video('),
 'Supervised worker, Agent Chat candidate picker and human review are connected.');
echo "G5E2: $total functional and real FFmpeg assertions passed".PHP_EOL;
