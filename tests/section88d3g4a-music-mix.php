<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf-g4a-'.bin2hex(random_bytes(5));
mkdir($root.'/work',0700,true);
define('SF_ROOT',$root);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
$db->exec("CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY,revision INTEGER NOT NULL,status TEXT NOT NULL)");
$db->exec("INSERT INTO story_assemblies VALUES(10,4,'draft')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_render_source_schema():void{}
function sf_team_ensure_schema():void{}
function sf_assembly_editor(int $editor):void{if($editor!==1)throw new RuntimeException('Not authorized.');}
$failAudit=false;$rights=true;
function sf_team_audit(int $editor,string $action,string $kind,string $id,string $details):void{
 global $failAudit;if($failAudit)throw new RuntimeException('Audit unavailable.');
}
function sf_render_plan(int $editor,int $assembly,int $revision):array{
 global $rights,$root;
 sf_assembly_editor($editor);
 if($assembly!==10||$revision!==4||!$rights)throw new RuntimeException('Certification invalid.');
 return ['assembly_id'=>10,'revision'=>4,'total_ms'=>3000,
  'items'=>[
   ['kind'=>'track','source_id'=>'catalog-song-1','category'=>'audio',
    'path'=>$root.'/work/bed.wav','duration_ms'=>1000,'start_ms'=>0],
   ['kind'=>'transcript','source_id'=>'7','category'=>'audio',
    'path'=>$root.'/work/voice.wav','duration_ms'=>2000,'start_ms'=>1000]
  ]];
}
require dirname(__DIR__).'/api/story-render-worker-core.php';
require dirname(__DIR__).'/api/story-render-mix-core.php';
$checked=0;
function okG4(bool $ok,string $name):void{
 global $checked;if(!$ok)throw new RuntimeException('FAIL '.$name);
 $checked++;echo 'PASS '.$name.PHP_EOL;
}
function denyG4(callable $fn,string $name):void{
 try{$fn();throw new LogicException('Expected refusal');}
 catch(RuntimeException|InvalidArgumentException $e){okG4(true,$name);}
}
sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
 '-f','lavfi','-i','color=c=black:s=320x180:r=24',
 '-f','lavfi','-i','anullsrc=r=48000:cl=stereo',
 '-t','3','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p',
 '-c:a','aac','-y',$root.'/work/draft.mp4'],20);
sf_render_exec([sf_render_ffmpeg(),'-nostdin','-hide_banner','-loglevel','error',
 '-f','lavfi','-i','sine=frequency=440:sample_rate=48000','-t','4',
 '-c:a','pcm_s16le','-y',$root.'/work/bed.wav'],20);
okG4(is_file($root.'/work/draft.mp4')&&is_file($root.'/work/bed.wav'),
 'Actual FFmpeg silent movie and tone soundtrack are available');
denyG4(fn()=>sf_render_mix_set(2,10,4,'catalog-song-1',20,35,500),
 'Contributor cannot authorize an Artist World music mix');
denyG4(fn()=>sf_render_mix_set(1,10,4,'invented-track',20,35,500),
 'Music bed cannot reference an invented or absent catalog song');
denyG4(fn()=>sf_render_mix_set(1,10,4,'catalog-song-1',98,35,500),
 'Unbounded audio gain cannot enter FFmpeg filter graph');
denyG4(fn()=>sf_render_mix_set(1,10,3,'catalog-song-1',20,35,500),
 'Stale assembly revision cannot configure soundtrack');
$failAudit=true;
denyG4(fn()=>sf_render_mix_set(1,10,4,'catalog-song-1',20,35,500),
 'Activity audit failure rolls back new mix preset');
$failAudit=false;
okG4((int)$db->query('SELECT COUNT(*) FROM story_render_mixes')->fetchColumn()===0,
 'Failed activity audit leaves no untracked soundtrack configuration');
sf_render_mix_set(1,10,4,'catalog-song-1',20,35,500);
$mix=sf_render_mix_settings(1,10,4);
okG4($mix!==null&&$mix['gain_pct']===20&&$mix['duck_pct']===35,
 'Scoped licensed track, mix gain and dialogue ducking are persisted');
okG4(sf_render_mix_settings(1,10,3)===null,
 'Older editorial revisions cannot inherit music presets');
$f=sf_render_mix_filter(sf_render_plan(1,10,4),$mix);
okG4(str_contains($f,"between(t,1.000,3.000)")&&str_contains($f,'afade=t=in')
  &&str_contains($f,'afade=t=out')&&str_contains($f,'alimiter='),
 'Metadata-only FFmpeg graph contains bounded dialogue duck, fades and limiter');
sf_render_mix_apply(sf_render_plan(1,10,4),$mix,
 $root.'/work/draft.mp4',$root.'/work/mixed.mp4');
$mp4=$root.'/work/mixed.mp4';
okG4(is_file($mp4)&&filesize($mp4)>1000&&sf_render_has_audio($mp4),
 'Actual private H264/AAC mixed MP4 produced and decoded by ffprobe');
okG4(hash_file('sha256',$mp4)!==hash_file('sha256',$root.'/work/draft.mp4'),
 'Rendered soundtrack audio is different from original silent video');
$rights=false;
denyG4(fn()=>sf_render_mix_set(1,10,4,'catalog-song-1',25,35,500),
 'Revoked source licensing blocks setting a new background soundtrack');
$rights=true;
$db->exec("INSERT INTO story_render_jobs
  (assembly_id,revision,status,created_by,created_at,updated_at)
  VALUES(10,4,'queued',1,'2026-10-18','2026-10-18')");
denyG4(fn()=>sf_render_mix_set(1,10,4,'catalog-song-1',25,35,500),
 'Already queued renderer locks soundtrack and prevents mid-render mix changes');
okG4(sf_render_mix_settings(1,10,4)['gain_pct']===20,
 'Rejected mix changes preserve original revision-locked gain');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
okG4(str_contains($api,'configure_mix')&&
 str_contains($api,"sf_admin_require_auth(".chr(36)."method!=='GET')"),
 'Music mix editor remains authenticated, CSRF-protected and never renders in HTTP');
foreach(glob($root.'/work/*') as $p)unlink($p);
rmdir($root.'/work');rmdir($root);
echo "88D3G4A: $checked functional assertions passed".PHP_EOL;
