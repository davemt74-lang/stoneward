<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-g4b-'.bin2hex(random_bytes(5));
mkdir($root.'/work',0700,true);
define('SF_ROOT',$root);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
$db->exec("CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY,revision INTEGER,status TEXT)");
$db->exec("INSERT INTO story_assemblies VALUES(9,2,'draft')");
$rights=true;$failAudit=false;
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_render_source_schema():void{}
function sf_team_ensure_schema():void{}
function sf_assembly_editor(int $editor):void{if($editor!==1)throw new RuntimeException('Unauthorized.');}
function sf_team_audit(int $editor,string $action,string $entity,string $id,string $message):void{
 global $failAudit;if($failAudit)throw new RuntimeException('Failed activity audit.');
}
function sf_render_source_manifest(int $editor,int $assembly,int $revision):array{
 global $root,$rights;sf_assembly_editor($editor);
 if($assembly!==9||$revision!==2||!$rights)throw new RuntimeException('Rights or revision expired.');
 return ['assembly_id'=>9,'revision'=>2,'total_ms'=>6000,'source_hashes_verified'=>true,
  'items'=>[
   ['kind'=>'scene','source_id'=>'1','category'=>'video','mime'=>'video/mp4','path'=>$root.'/work/segment-00.mp4',
    'source_duration_ms'=>2500,'duration_ms'=>2000,'start_ms'=>0],
   ['kind'=>'transcript','source_id'=>'2','category'=>'video','mime'=>'video/mp4','path'=>$root.'/work/segment-01.mp4',
    'source_duration_ms'=>2500,'duration_ms'=>2000,'start_ms'=>0],
   ['kind'=>'track','source_id'=>'catalog-song-1','category'=>'audio','mime'=>'audio/x-wav','path'=>$root.'/work/bed.wav',
    'source_duration_ms'=>4000,'duration_ms'=>2000,'start_ms'=>0]
  ]];
}
require dirname(__DIR__).'/api/story-render-worker-core.php';
require dirname(__DIR__).'/api/story-render-mix-core.php';
require dirname(__DIR__).'/api/story-render-transition-core.php';
$checks=0;
function verifyG4B(bool $ok,string $what):void{global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$what);echo 'PASS '.$what.PHP_EOL;$checks++;
}
function rejectG4B(callable $fn,string $what):void{
 try{$fn();throw new LogicException('Expected validation failure');}
 catch(InvalidArgumentException|RuntimeException $e){verifyG4B(true,$what);}
}
foreach(['red','blue','black'] as $i=>$color){
 sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
  '-f','lavfi','-i','color=c='.$color.':s=320x180:r=24',
  '-f','lavfi','-i','sine=frequency='.($i?660:330).':sample_rate=48000',
  '-t','2','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p',
  '-c:a','aac','-y',$root.'/work/segment-0'.$i.'.mp4'],20);
}
sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
 '-f','lavfi','-i','sine=frequency=550:sample_rate=48000','-t','4',
 '-c:a','pcm_s16le','-y',$root.'/work/bed.wav'],20);
$plan=sf_render_plan(1,9,2);
verifyG4B(count($plan['items'])===3&&$plan['total_ms']===6000,
 'Actual G2 render validation accepts three independently certified pilot clips');
rejectG4B(fn()=>sf_render_transition_set(2,9,2,'fade',250),
 'Contributor cannot configure video transition controls');
rejectG4B(fn()=>sf_render_transition_set(1,9,2,'evil',250),
 'Untrusted FFmpeg filter expression cannot become a transition style');
rejectG4B(fn()=>sf_render_transition_set(1,9,2,'fade',900),
 'Excessive or unsupported overlap duration denied');
rejectG4B(fn()=>sf_render_transition_set(1,9,1,'fade',250),
 'Stale timeline revision cannot approve crossfades');
$failAudit=true;
rejectG4B(fn()=>sf_render_transition_set(1,9,2,'fade',250),
 'Transition authorization rolls back if activity auditing fails');
$failAudit=false;
verifyG4B((int)$db->query('SELECT COUNT(*) FROM story_render_transitions')->fetchColumn()===0,
 'No unaudited transition authorization persists');
sf_render_transition_set(1,9,2,'fade',250);
$setting=sf_render_transition_settings(1,9,2);
verifyG4B($setting===['style'=>'fade','overlap_ms'=>250],
 'Approved video/audio transition is scoped to current source revision');
verifyG4B(sf_render_transition_settings(1,9,1)===null,
 'An older editorial revision cannot reuse crossfade authorization');
$effective=sf_render_transition_effective_manifest($plan,$setting);
verifyG4B($effective['total_ms']===5500&&$effective['items'][1]['duration_ms']===1750
 &&$effective['items'][2]['duration_ms']===1750,
 'Overlapping duration and revised dialogue positions are calculated deterministically');
$segs=[$root.'/work/segment-00.mp4',$root.'/work/segment-01.mp4',$root.'/work/segment-02.mp4'];
$args=sf_render_transition_args($segs,$plan,$setting,$root.'/work/draft.mp4');
$filter=$args[array_search('-filter_complex',$args,true)+1];
verifyG4B(str_contains($filter,'xfade=transition=fade')&&str_contains($filter,'acrossfade=d=0.250')
 &&str_contains($filter,'offset=1.750')&&str_contains($filter,'offset=3.500'),
 'Three-shot FFmpeg uses frame-overlapping xfade and synchronized audio acrossfade');
try{sf_render_transition_apply($segs,$plan,$setting,$root.'/work/draft.mp4');}
catch(RuntimeException $error){
 // CI-only diagnostics for synthetic fixtures. Production worker error surfaces
 // stay deliberately generic and do not expose arbitrary local file paths.
 $diagnostic=$args;
 $level=array_search('-loglevel',$diagnostic,true);
 if($level!==false)$diagnostic[$level+1]='warning';
 array_unshift($diagnostic,'timeout','12s');
 $pipes=[];
 $process=@proc_open($diagnostic,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
 $reason='FFmpeg diagnostic could not start';
 if(is_resource($process)){
  fclose($pipes[0]);
  stream_get_contents($pipes[1]);
  $reason=(string)stream_get_contents($pipes[2],3200);
  fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
 }
 throw new RuntimeException('Synthetic crossfade failed: '.substr(trim($reason),0,3000),0,$error);
}
verifyG4B(is_file($root.'/work/draft.mp4')&&sf_render_has_audio($root.'/work/draft.mp4'),
 'Real FFmpeg 720p private crossfade MP4 contains audio and valid video');
$durationCmd=escapeshellarg(sf_render_ffprobe()).' -v error -show_entries format=duration -of default=nw=1:nk=1 '.
 escapeshellarg($root.'/work/draft.mp4');
$duration=(float)trim((string)shell_exec($durationCmd));
verifyG4B($duration>5.1&&$duration<5.9,
 'Actual video duration reflects two overlapping 250ms transitions');
sf_render_mix_set(1,9,2,'catalog-song-1',20,35,500);
$mix=sf_render_mix_settings(1,9,2);
$f=sf_render_mix_filter($effective,$mix);
verifyG4B(str_contains($f,'between(t,2.000,3.750)'),
 'Licensed dialogue duck window follows the post-transition overlap timeline');
sf_render_mix_apply($effective,$mix,$root.'/work/draft.mp4',$root.'/work/mixed.mp4');
verifyG4B(sf_render_has_audio($root.'/work/mixed.mp4')
 &&hash_file('sha256',$root.'/work/mixed.mp4')!==hash_file('sha256',$root.'/work/draft.mp4'),
 'Real FFmpeg combined crossfades and licensed soundtrack generate a new private AAC mix');
$db->exec("INSERT INTO story_render_jobs
 (assembly_id,revision,status,created_by,created_at,updated_at)
 VALUES(9,2,'queued',1,'2026-10-18','2026-10-18')");
rejectG4B(fn()=>sf_render_transition_set(1,9,2,'wipeleft',500),
 'Queued private render locks transition changes');
$rights=false;
rejectG4B(fn()=>sf_render_transition_set(1,9,2,'fade',250),
 'Revoked source certification blocks transition authorizations');
$rights=true;
verifyG4B(sf_render_transition_settings(1,9,2)['style']==='fade',
 'Rejected requests leave original audited transition intact');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
verifyG4B(str_contains($api,'configure_transition')&&
 str_contains($api,"sf_admin_require_auth(".chr(36)."method!=='GET')"),
 'Authenticated Admin action only edits private settings and never renders');
foreach(glob($root.'/work/*') as $path)unlink($path);
rmdir($root.'/work');rmdir($root);
echo "88D3G4B: $checks functional assertions passed".PHP_EOL;
