<?php
declare(strict_types=1);
/** G4E private actual-video multi-cut test: 2 different styles and different lengths. */
$root=sys_get_temp_dir().'/sf-g4e-'.bin2hex(random_bytes(5));
mkdir($root.'/work',0700,true);define('SF_ROOT',$root);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
$db->exec('CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY,revision INTEGER,status TEXT)');
$db->exec("INSERT INTO story_assemblies VALUES(9,2,'draft')");
$rights=true;$auditFailure=false;
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_render_source_schema():void{}
function sf_team_ensure_schema():void{}
function sf_assembly_editor(int $e):void{if($e!==1)throw new RuntimeException('Noneditor.');}
function sf_team_audit(int $e,string $action,string $type,string $id,string $detail):void{
 global $auditFailure;
 if($auditFailure)throw new RuntimeException('Failed audit.');
}
function sf_render_source_manifest(int $e,int $a,int $v):array{
 global $root,$rights;
 sf_assembly_editor($e);
 if($a!==9||$v!==2||!$rights)throw new RuntimeException('Rights or revision unavailable.');
 return ['assembly_id'=>9,'revision'=>2,'source_hashes_verified'=>true,'total_ms'=>6000,
  'items'=>[
   ['kind'=>'scene','source_id'=>'1','category'=>'video','mime'=>'video/mp4',
    'path'=>$root.'/work/segment-00.mp4','source_duration_ms'=>2500,'duration_ms'=>2000,'start_ms'=>0],
   ['kind'=>'transcript','source_id'=>'2','category'=>'video','mime'=>'video/mp4',
    'path'=>$root.'/work/segment-01.mp4','source_duration_ms'=>2500,'duration_ms'=>2000,'start_ms'=>0],
   ['kind'=>'track','source_id'=>'catalog','category'=>'audio','mime'=>'audio/x-wav',
    'path'=>$root.'/work/bed.wav','source_duration_ms'=>4000,'duration_ms'=>2000,'start_ms'=>0]
  ]];
}
require dirname(__DIR__).'/api/story-render-worker-core.php';
require dirname(__DIR__).'/api/story-render-mix-core.php';
require dirname(__DIR__).'/api/story-render-transition-core.php';
require dirname(__DIR__).'/api/story-render-edl-core.php';
require dirname(__DIR__).'/api/story-render-cut-core.php';
$n=0;
function yesG4E(bool $yes,string $name):void{
 global $n;if(!$yes)throw new RuntimeException('FAIL '.$name);
 $n++;echo "PASS $name\n";
}
function noG4E(callable $fn,string $name):void{
 try{$fn();throw new LogicException('Expected rejection: '.$name);}
 catch(InvalidArgumentException|RuntimeException $e){yesG4E(true,$name);}
}
$plan=sf_render_plan(1,9,2);
$cuts=[['style'=>'fade','overlap_ms'=>250],['style'=>'wipeleft','overlap_ms'=>500]];
sf_render_transition_set(1,9,2,'fade',250);
noG4E(fn()=>sf_render_cut_set(2,9,2,$cuts),'Contributor cannot authorize cuts.');
noG4E(fn()=>sf_render_cut_set(1,9,1,$cuts),'Invalid current revision cannot authorize cuts.');
noG4E(fn()=>sf_render_cut_set(1,9,2,[['style'=>'fade','overlap_ms'=>250]]),'Must configure every cut.');
noG4E(fn()=>sf_render_cut_set(1,9,2,[['style'=>'evil;filter','overlap_ms'=>250],$cuts[1]]),'Only approved FFmpeg effect names.');
noG4E(fn()=>sf_render_cut_set(1,9,2,[['style'=>'fade','overlap_ms'=>250],['style'=>'wipeleft','overlap_ms'=>800]]),'Only approved overlaps.');
$auditFailure=true;
noG4E(fn()=>sf_render_cut_set(1,9,2,$cuts),'Cut audit failure rolls back ledger.');
$auditFailure=false;
yesG4E(sf_render_cut_settings(1,9,2)===null,'No unaudited cut preset persists.');
sf_render_cut_set(1,9,2,$cuts);
$selected=sf_render_transition_settings(1,9,2);
yesG4E($selected['cuts']===$cuts,'Exact-revision cut customization persists unchanged.');
yesG4E(sf_render_cut_settings(1,9,1)===null,'Other revisions cannot read old cut controls.');
$edl=sf_render_edl_build($plan,$selected);
yesG4E($edl['output_total_ms']===5250,'Distinct cut overlaps shorten timeline by 750 ms.');
yesG4E($edl['cuts'][0]['start_ms']===1750&&$edl['cuts'][1]['start_ms']===3250
 &&$edl['cuts'][1]['style']==='wipeleft','Each cut has its own style, exact offset and duration.');
yesG4E($edl['dialogue_duck_windows']===[['start_ms'=>1750,'end_ms'=>3750]],
 'Licensed soundtrack ducking starts with audible incoming dialogue.');
$effective=sf_render_transition_effective_manifest($plan,$selected);
yesG4E($effective['total_ms']===5250&&$effective['items'][2]['duration_ms']===1500,
 'Effective music mix timeline uses exact independent overlaps.');
$bed=['track_id'=>'catalog','gain_pct'=>20,'duck_pct'=>35,'fade_ms'=>500];
$f=sf_render_mix_filter($effective,$bed);
yesG4E(str_contains($f,'between(t,1.750,3.750)'),'Actual FFmpeg mix duck window follows the canonical EDL.');
foreach(['red','blue','black'] as $i=>$color){
 sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
  '-f','lavfi','-i','color=c='.$color.':s=320x180:r=24',
  '-f','lavfi','-i','sine=frequency='.($i?660:330).':sample_rate=48000',
  '-t','2','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p',
  '-c:a','aac','-y',$root.'/work/segment-0'.$i.'.mp4'],20);
}
$segs=array_map(fn($i)=>$root.'/work/segment-0'.$i.'.mp4',range(0,2));
$args=sf_render_transition_args($segs,$plan,$selected,$root.'/work/draft.mp4');
$graph=$args[array_search('-filter_complex',$args,true)+1];
yesG4E(str_contains($graph,'xfade=transition=fade:duration=0.250:offset=1.750')
 &&str_contains($graph,'xfade=transition=wipeleft:duration=0.500:offset=3.250')
 &&str_contains($graph,'acrossfade=d=0.500'),'Actual FFmpeg graph has two independently timed, matched video/audio cuts.');
sf_render_transition_apply($segs,$plan,$selected,$root.'/work/draft.mp4');
$cmd=escapeshellarg(sf_render_ffprobe()).' -v error -show_entries format=duration -of default=nw=1:nk=1 '.escapeshellarg($root.'/work/draft.mp4');
$duration=(float)trim((string)shell_exec($cmd));
yesG4E($duration>4.95&&$duration<5.55&&sf_render_has_audio($root.'/work/draft.mp4'),
 'Real private multi-style FFmpeg output has synchronized A/V and correct duration.');
$db->exec("INSERT INTO story_render_jobs(assembly_id,revision,status,created_by,created_at,updated_at)
 VALUES(9,2,'queued',1,'2026-10-19','2026-10-19')");
noG4E(fn()=>sf_render_cut_set(1,9,2,$cuts),'Queued render freezes exact cut revision.');
$db->exec('DELETE FROM story_render_jobs');
$rights=false;noG4E(fn()=>sf_render_cut_set(1,9,2,$cuts),'Revoked certified source denies new edits.');
$rights=true;sf_render_transition_set(1,9,2,'fade',500);
yesG4E(sf_render_cut_settings(1,9,2)===null,'Global preset replacement atomically clears stale per-cut edits.');
foreach(glob($root.'/work/*') as $p)unlink($p);
rmdir($root.'/work');rmdir($root);
echo "88D3G4E: $n functional and real FFmpeg checks passed\n";
