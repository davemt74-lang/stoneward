<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86b-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Clip & FX']];}
require dirname(__DIR__).'/api/stem-studio-core.php';
$ok=0;
function check86b(bool $value,string $label):void{
 global $ok;if(!$value)throw new RuntimeException('86B: '.$label);
 $ok++;echo "PASS $label\n";
}
function fail86b(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
$rpp='<REAPER_PROJECT 0.1 "7.09" 1'."\n  TEMPO 120 4 4\n";
$rpp.="  <TRACK {00000000-0000-0000-0000-000000000001}\n";
$rpp.="    NAME \"Vocals\"\n    <FXCHAIN\n";
$rpp.="      <VST \"VST: ReaEQ (Cockos)\" reaeq.dll 0 \"\" 1>\n";
$rpp.="      <VST \"VST: ReaComp (Cockos)\" reacomp.dll 0 \"\" 1>\n";
$rpp.="      <VST \"Unknown vendor FX\" thing.dll 0 \"\" 1>\n    >\n";
$rpp.="    <ITEM\n      POSITION 1\n      LENGTH 12\n      <SOURCE WAV\n        FILE \"voice.wav\"\n      >\n    >\n  >\n>\n";
$m=sf_stem_project_import('song-1',$rpp,'voice.rpp',1);
$guid=$m['tracks'][0]['guid'];$name=$m['tracks'][0]['clips'][0]['file'];
check86b($m['tracks'][0]['fx'][0]['type']==='eq','Imported REAPER EQ identity retained');
$m=sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>1,'guid'=>$guid,
  'clip_index'=>0,'position'=>'2.25','length'=>'9.5','offset'=>'1.25']);
$c=$m['tracks'][0]['clips'][0];
check86b($m['revision']===2&&$c['position']===2.25&&$c['length']===9.5&&$c['offset']===1.25,
 'Edits position, trim length and source offset in stored song project');
check86b($c['file']===$name&&$m['tracks'][0]['duration_seconds']===11.75,
 'Clip source immutable and track extent updated');
check86b(count($m['edit_history']['undo'])===1,'Undo history stores a non-destructive edit');
$m=sf_stem_project_update('song-1',['action'=>'edit_fx','revision'=>2,'guid'=>$guid,
 'fx_index'=>0,'enabled'=>true,'settings'=>['frequency'=>'1700','gain'=>'-5','q'=>'1.4']]);
check86b($m['tracks'][0]['fx'][0]['settings']['frequency']===1700.0,
 'Editable REAPER EQ browser parameters saved');
check86b($m['tracks'][0]['fx'][0]['name']==='VST: ReaEQ (Cockos)',
 'Original native plugin identity remains intact');
$m=sf_stem_project_update('song-1',['action'=>'undo_edit','revision'=>3]);
check86b(empty($m['tracks'][0]['fx'][0]['settings'])
 &&$m['tracks'][0]['clips'][0]['position']===2.25,
 'Undo FX change without losing clip edit');
$m=sf_stem_project_update('song-1',['action'=>'undo_edit','revision'=>4]);
check86b(abs((float)$m['tracks'][0]['clips'][0]['position']-1.0)<0.00001&&count($m['edit_history']['redo'])===2,
 'Undo timeline edit without changing raw REAPER project');
$m=sf_stem_project_update('song-1',['action'=>'redo_edit','revision'=>5]);
check86b($m['tracks'][0]['clips'][0]['position']===2.25,
 'Redo restores edited clip precisely');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>5,'guid'=>$guid,
 'clip_index'=>0,'position'=>3,'length'=>2,'offset'=>0])),'Stale revisions rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>6,'guid'=>$guid,
 'clip_index'=>200,'position'=>3,'length'=>2,'offset'=>0])),'Unknown clip rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>6,'guid'=>$guid,
 'clip_index'=>0,'position'=>'NaN','length'=>2,'offset'=>0])),'Non-finite clip values rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>6,'guid'=>$guid,
 'clip_index'=>0,'position'=>86399,'length'=>10,'offset'=>0])),'Clip exceeding timeline rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_clip','revision'=>6,'guid'=>$guid,
 'clip_index'=>0,'position'=>0,'length'=>3,'offset'=>86399])),'Source offset past limit rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_fx','revision'=>6,'guid'=>$guid,
 'fx_index'=>0,'enabled'=>true,'settings'=>['gain'=>900]])),'Out-of-range EQ rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_fx','revision'=>6,'guid'=>$guid,
 'fx_index'=>0,'enabled'=>true,'settings'=>['evil'=>4]])),'Unknown FX parameters rejected');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'edit_fx','revision'=>6,'guid'=>$guid,
 'fx_index'=>2,'enabled'=>true,'settings'=>[]])),'Unsupported native VST is read-only');
check86b(fail86b(fn()=>sf_stem_project_update('song-1',['action'=>'redo_edit','revision'=>5])),
 'Stale redo cannot overwrite concurrent edits');
$raw=file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp');
check86b($raw===$rpp,'Imported source RPP stays unmodified by browser edits');
check86b(sf_stem_fx_defaults('delay')['time']===.25
 &&sf_stem_fx_bounds('reverb')['wet']===[0,1],
 'Delay and reverb browser controls have declared ranges');
check86b(str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js'),'edit_clip')
 &&str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js'),'undo_edit')
 &&str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js'),'SFStemFX.build'),
 'Existing Stem Studio wiring connects timeline, history and FX engine');
foreach(glob($root.'/storage/stem-projects/*')?:[] as $p)@unlink($p);
@rmdir($root.'/storage/stem-projects');@rmdir($root.'/storage');@rmdir($root);
echo "86B timeline/FX persistence: $ok checks passed\n";
