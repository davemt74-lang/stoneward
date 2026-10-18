<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-g2-app-'.bin2hex(random_bytes(5));
$private=sys_get_temp_dir().'/sf-g2-private-'.bin2hex(random_bytes(5));
mkdir($root,0700,true);
define('SF_ROOT',$root);define('SF_RENDER_PRIVATE_DIR',$private);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
$db->exec('CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO story_assemblies(id) VALUES(1),(2),(3),(4)');
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT,original_name TEXT,title TEXT,duration_seconds REAL,size_bytes INTEGER)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_assembly_editor(int $id):void{if($id!==1)throw new RuntimeException('Forbidden');}
function sf_team_ensure_schema():void{}
function sf_team_audit(int $editor,string $type,string $entity,string $id,string $msg):void{}
function sf_render_source_schema():void{}
function sf_ai_meta_get(string $key,string $default=''):string{global $enabled;return $enabled?'1':'0';}
function sf_ai_meta_set(string $key,string $v):void{global $enabled;$enabled=$v==='1';}
$enabled=false;$rightsOkay=true;
$video=$root.'/video.mp4';$audio=$root.'/audio.wav';$bytes=[];
require dirname(__DIR__).'/api/story-render-worker-core.php';
function sf_render_source_manifest(int $editor,int $assembly,int $revision):array{
 global $video,$audio,$rightsOkay;
 sf_assembly_editor($editor);
 if(!$rightsOkay||$revision!==1)throw new RuntimeException('Rights or revision invalid.');
 $files=[['path'=>$video,'category'=>'video','mime'=>'video/mp4','kind'=>'scene','source_id'=>'1'],
  ['path'=>$audio,'category'=>'audio','mime'=>'audio/x-wav','kind'=>'track','source_id'=>'song']];
 $items=[];foreach($files as $i=>$f){
  if(!is_file($f['path']))throw new RuntimeException('Source file missing.');
  $digest=hash_file('sha256',$f['path']);
  global $bytes;
  if(!isset($bytes[$i])||!hash_equals($bytes[$i],$digest))
   throw new RuntimeException('Source fingerprint changed.');
  $items[]=$f+['asset_id'=>$i+1,'sha256'=>$digest,'byte_size'=>filesize($f['path']),
   'start_ms'=>0,'end_ms'=>1000,'duration_ms'=>1000,'source_duration_ms'=>1000];
 }
 return ['assembly_id'=>$assembly,'revision'=>$revision,'total_ms'=>2000,'items'=>$items,
  'source_hashes_verified'=>true];
}
$n=0;
function checkG2(bool $pass,string $name):void{
 global $n;if(!$pass)throw new RuntimeException('FAIL '.$name);
 $n++;echo "PASS $name\n";
}
function denyG2(callable $f,string $name):void{
 try{$f();throw new LogicException('Expected denial');}
 catch(RuntimeException|InvalidArgumentException $e){checkG2(true,$name);}
}
sf_render_exec([sf_render_ffmpeg(),'-nostdin','-loglevel','error','-y',
 '-f','lavfi','-i','color=c=red:s=320x180:r=24','-t','1',
 '-c:v','libx264','-pix_fmt','yuv420p',$video],20);
sf_render_exec([sf_render_ffmpeg(),'-nostdin','-loglevel','error','-y',
 '-f','lavfi','-i','sine=frequency=220:sample_rate=48000',
 '-t','1','-c:a','pcm_s16le',$audio],20);
$bytes=[hash_file('sha256',$video),hash_file('sha256',$audio)];
checkG2(is_file($video)&&is_file($audio),'Real H.264 video and PCM audio fixture generated with installed FFmpeg');
denyG2(fn()=>sf_render_enqueue(1,1,1),'Render disabled by default');
denyG2(fn()=>sf_render_configure(2,true),'Contributor cannot enable private renderer');
sf_render_configure(1,true);checkG2(sf_render_enabled(),'Privileged editor explicitly enables renderer');
denyG2(fn()=>sf_render_jobs(2,1),'Contributor cannot inspect private render jobs');
denyG2(fn()=>sf_render_enqueue(2,1,1),'Contributor cannot queue a private render');
$one=sf_render_enqueue(1,1,1);
checkG2($one['status']==='queued'&&!$one['existing'],'Fingerprint-certified private render queued');
$dup=sf_render_enqueue(1,1,1);
checkG2($dup['existing']&&$dup['job_id']===$one['job_id'],'Duplicate source revision never queues a second render');
$job=sf_render_run_once(1);
checkG2($job['status']==='private_review'&&$job['output_bytes']>1000,
 'Supervised worker creates real H.264/AAC private output requiring review');
$list=sf_render_jobs(1,1);
checkG2(count($list)===1&&$list[0]['status']==='private_review'
  &&!isset($list[0]['output_name'])&&!isset($list[0]['output_sha256']),
 'Browser-status data hides all private output names and SHA-256 fingerprints');
$record=$db->query('SELECT output_name,output_sha256,output_bytes FROM story_render_jobs WHERE id=1')->fetch();
$out=$private.'/'.$record['output_name'];
checkG2(is_file($out)&&hash_equals($record['output_sha256'],hash_file('sha256',$out))
  &&filesize($out)===(int)$record['output_bytes'],'Private output matches persisted SHA-256 and bytes');
checkG2(!str_starts_with(realpath($out),realpath(SF_ROOT).DIRECTORY_SEPARATOR),
 'Output storage is outside the public application tree');
sf_render_exec([sf_render_ffprobe(),'-v','error','-select_streams','v:0',
 '-show_entries','stream=codec_name','-of','csv=p=0',$out],12);
checkG2(sf_render_has_audio($out),'Real rendered MP4 contains an audio stream');
checkG2(sf_render_run_once(1)['status']==='empty','Already completed private render is not replayed');
$two=sf_render_enqueue(1,2,1);
$rightsOkay=false;
$stopped=sf_render_run_once(1);
checkG2($stopped['status']==='interrupted','Rights lost before execution blocks new render');
checkG2(sf_render_run_once(1)['status']==='empty','Interrupted job never retries unattended');
$rightsOkay=true;
$three=sf_render_enqueue(1,3,1);
file_put_contents($audio,'tampered');
$stopped=sf_render_run_once(1);
checkG2($stopped['status']==='interrupted','Source SHA mismatch blocks worker before FFmpeg');
checkG2(count(glob($private.'/*.mp4'))===1,'Failed job left no additional private output');
denyG2(fn()=>sf_render_enqueue(1,4,1),'Daily three-render cap rejects further jobs');
sf_render_configure(1,false);
checkG2(!sf_render_enabled(),'Explicit disable stops future renders');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
checkG2(!str_contains($api,'sf_render_run_once(')
 &&str_contains($api,'configure_render')&&str_contains($api,'enqueue_render'),
 'HTTP endpoint can authorize/queue but cannot execute render or return video');
foreach((array)glob($private.'/*') as $p)if(is_file($p))unlink($p);
rmdir($private);unlink($video);unlink($audio);rmdir($root);
echo "88D3G2: $n functional assertions passed\n";
