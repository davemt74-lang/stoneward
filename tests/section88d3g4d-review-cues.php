<?php
declare(strict_types=1);
/** 88D3G4D — private rendered-video QC, revision and media-rights certification. */
$private=sys_get_temp_dir().'/sf-g4d-'.bin2hex(random_bytes(6));
mkdir($private,0700,true);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users VALUES(1),(2)');
$db->exec('CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO story_assemblies VALUES(7)');
$db->exec("CREATE TABLE story_render_jobs(id INTEGER PRIMARY KEY,assembly_id INTEGER NOT NULL,
 revision INTEGER NOT NULL,status TEXT NOT NULL,output_name TEXT NOT NULL,
 output_sha256 TEXT NOT NULL,output_bytes INTEGER NOT NULL,created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL)");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_assembly_editor(int $editor):void{if($editor!==1)throw new RuntimeException('Not an editor.');}
function sf_render_jobs_schema():void{}
function sf_render_private_root():string{global $private;return $private;}
$allowRights=true;$currentRevision=3;
function sf_render_plan(int $editor,int $assembly,int $revision):array{
 global $allowRights,$currentRevision,$private;
 sf_assembly_editor($editor);
 if(!$allowRights||$assembly!==7||$revision!==$currentRevision)
  throw new RuntimeException('Invalid or stale private source certification.');
 return ['revision'=>$revision,'total_ms'=>6000,'items'=>[
  ['kind'=>'scene','source_id'=>'s1','duration_ms'=>2000,'start_ms'=>50,
   'path'=>$private.'/do-not-leak-original-source.mov'],
  ['kind'=>'transcript','source_id'=>'v2','duration_ms'=>2000,'start_ms'=>0,
   'path'=>$private.'/private-dialogue.wav'],
  ['kind'=>'track','source_id'=>'licensed-song','duration_ms'=>2000,'start_ms'=>0,
   'path'=>$private.'/private-master.wav']
 ]];
}
function sf_render_transition_settings(int $editor,int $assembly,int $revision):?array{
 sf_assembly_editor($editor);return ['style'=>'fade','overlap_ms'=>250];
}
function sf_render_mix_settings(int $editor,int $assembly,int $revision):?array{
 sf_assembly_editor($editor);
 return ['track_id'=>'licensed-song','gain_pct'=>20,'duck_pct'=>35,'fade_ms'=>500];
}
require dirname(__DIR__).'/api/story-render-edl-core.php';
require dirname(__DIR__).'/api/story-render-review-core.php';
$n=0;
function checkG4D(bool $v,string $s):void{
 global $n;
 if(!$v)throw new RuntimeException('FAIL '.$s);
 ++$n;echo "PASS $s\n";
}
function denyG4D(callable $fn,string $s):void{
 try{$fn();throw new LogicException('Expected private review denial');}
 catch(InvalidArgumentException|RuntimeException $e){checkG4D(true,$s);}
}
$name='assembly-7-rev-3-job-11-0123456789abcdef.mp4';
$bytes=str_repeat('private G4D film only',250);
file_put_contents($private.'/'.$name,$bytes);
$sha=hash_file('sha256',$private.'/'.$name);$size=strlen($bytes);
$db->prepare("INSERT INTO story_render_jobs VALUES(11,7,3,'private_review',?,?,?,?,?)")
 ->execute([$name,$sha,$size,gmdate('c'),gmdate('c')]);
$cue=sf_render_review_cues(1,11);
checkG4D($cue['duration_ms']===5500&&$cue['job_id']===11&&$cue['revision']===3,
 'Cue sheet is derived from authenticated current source revision and real overlap');
checkG4D(count($cue['cues'])===6,'Three certified clips, two cuts and dialogue windows appear.');
$dialogue=array_values(array_filter($cue['cues'],fn($x)=>$x['type']==='dialogue'));
checkG4D($dialogue===[['type'=>'dialogue','ordinal'=>0,'label'=>'Dialogue/music duck',
 'start_ms'=>1750,'end_ms'=>3750]],'QC dialogue boundaries match actual overlapping sound.');
checkG4D($cue['publication_allowed']===false&&$cue['download_allowed']===false
 &&$cue['private_only']===true,'QC cue metadata never authorizes publication or download.');
$json=json_encode($cue,JSON_THROW_ON_ERROR);
checkG4D(!str_contains($json,$private)&&!str_contains($json,'do-not-leak')
 &&!str_contains($json,$sha)&&!str_contains($json,'licensed-song'),
 'Private filesystem, hash, and source IDs are not transmitted to browser.');
$starts=array_column($cue['cues'],'start_ms');$sorted=$starts;sort($sorted);
checkG4D($starts===$sorted,'Marker order is deterministic for synchronized player seeks.');
denyG4D(fn()=>sf_render_review_cues(2,11),'Contributor cannot enumerate reviewed private film markers.');
$allowRights=false;
denyG4D(fn()=>sf_render_review_cues(1,11),'Music/source rights revocation blocks QC metadata immediately.');
$allowRights=true;$currentRevision=4;
denyG4D(fn()=>sf_render_review_cues(1,11),'Stale assembly revision cannot expose previous private cues.');
$currentRevision=3;
file_put_contents($private.'/'.$name,$bytes.'tampered');
denyG4D(fn()=>sf_render_review_cues(1,11),'Tampered reviewed MP4 digest blocks cue sheet.');
file_put_contents($private.'/'.$name,$bytes);
$db->exec("UPDATE story_render_jobs SET status='rejected' WHERE id=11");
denyG4D(fn()=>sf_render_review_cues(1,11),'Rejected private video cannot reveal QC playback markers.');
$db->exec("UPDATE story_render_jobs SET status='approved_private' WHERE id=11");
checkG4D(sf_render_review_cues(1,11)['duration_ms']===5500,
 'Approved-private review remains authenticated and independently certified.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-render-review.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/story-assembly.js');
checkG4D(str_contains($api,"sf_admin_require_auth(".chr(36)."method!=='GET')")
 &&str_contains($api,"Cache-Control: private, no-store")&&str_contains($api,"sf_render_review_cues("),
 'QC endpoint reuses admin auth and private no-store response policy.');
checkG4D(str_contains($ui,'data-load-review-cues')&&str_contains($ui,'data-private-review-video')
 &&str_contains($ui,"video.currentTime=")&&str_contains($ui,'readyState>=1'),
 'Authenticated player supports accessible seek markers with metadata-ready guard.');
unlink($private.'/'.$name);rmdir($private);
echo "88D3G4D: $n private QC certification checks passed\n";
