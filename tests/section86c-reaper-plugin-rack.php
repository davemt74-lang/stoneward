<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86c-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Synthetic FX Test']];}
require dirname(__DIR__).'/api/stem-studio-core.php';
$passed=0;
function ok86c(bool $ok,string $what):void {
    global $passed;
    if(!$ok)throw new RuntimeException('86C: '.$what);
    $passed++;echo 'PASS '.$what.PHP_EOL;
}
function denies86c(callable $fn):bool {
    try{$fn();return false;}catch(Throwable){return true;}
}
$raw='<REAPER_PROJECT 0.1 "7.09" 1'."\n  TEMPO 122 4 4\n  SAMPLERATE 44100 0 0\n";
$raw.="  <MASTERFXLIST\n    BYPASS 0 0 0\n    <JS loser/masterLimiter \"\"\n      -8.3 200 100 0 250 -0.1 0 - - -\n    >\n    FXID {MASTER-LIMITER}\n";
$raw.="    BYPASS 0 0 0\n    <VST \"VST: ReaEQ (Cockos)\" reaeq.dll 0 \"\" abc\n      cWVlcu5e7f4CAAAAAQ==\n    >\n    FXID {MASTER-EQ}\n";
$raw.="    BYPASS 0 0 0\n    <VST \"VST: ReaComp (Cockos)\" reacomp.dll 0 \"\" abc\n      bWNlcu9e7f4EAAAA\n    >\n    PRESETNAME \"stock - Master Bus Glue\"\n    FXID {MASTER-COMP}\n  >\n";
$raw.="  <TRACK {STEM-GUID}\n    NAME \"Synthetic Vocals\"\n    <FXCHAIN\n";
$raw.="      BYPASS 0 0 0\n      <VST \"VST: ReaComp (Cockos)\" reacomp.dll 0 \"\" abc\n        ABCD0000\n      >\n      PRESETNAME \"stock - Modern Vocal\"\n      FXID {VOCAL-COMP}\n";
$raw.="      BYPASS 1 0 0\n      <VST \"VST: ReaDelay (Cockos)\" readelay.dll 0 \"\" abc\n        DELAY123\n      >\n      FXID {VOCAL-DELAY}\n    >\n";
$raw.="    <ITEM\n      POSITION 0\n      LENGTH 12\n      <SOURCE WAV\n        FILE \"stem.wav\"\n      >\n    >\n  >\n>\n";
$parsed=sf_stem_rpp_parse($raw);
ok86c($parsed['plugin_import']['plugin_count']===5,'Five imported native plugin slots counted accurately');
ok86c(count($parsed['master_fx'])===3&&count($parsed['tracks'][0]['fx'])===2,
  'Master FX separated from track FX without duplication');
ok86c($parsed['master_fx'][0]['type']==='limiter'&&$parsed['master_fx'][0]['format']==='JS',
  'JS loser/masterLimiter recognized in master rack');
ok86c($parsed['master_fx'][0]['js_slider_tokens'][0]==='-8.3'
    &&$parsed['master_fx'][0]['js_slider_tokens'][5]==='-0.1',
  'Original JS master limiter slider tokens preserved exactly');
ok86c($parsed['master_fx'][2]['preset_name']==='stock - Master Bus Glue',
  'Actual REAPER master comp preset name imported');
ok86c($parsed['tracks'][0]['fx'][0]['preset_name']==='stock - Modern Vocal',
  'Per-track named preset imported');
ok86c($parsed['tracks'][0]['fx'][1]['source_bypass']===1
  &&$parsed['tracks'][0]['fx'][1]['enabled']===false,
  'Source bypass and initial browser state faithfully match');
ok86c($parsed['tracks'][0]['fx'][1]['fx_guid']==='{VOCAL-DELAY}'&&$parsed['tracks'][0]['fx'][1]['slot']===1,
  'Original FX GUID and position retained');
ok86c($parsed['master_fx'][1]['native_state_sha256']===hash('sha256','cWVlcu5e7f4CAAAAAQ=='),
  'Opaque VST state recorded by exact content hash, not invented parameters');
ok86c($parsed['master_fx'][1]['browser_settings_origin']==='default-not-decoded',
  'Binary REAPER VST state is never falsely reported as translated');
$m=sf_stem_project_import('song-1',$raw,'project.rpp',9);
ok86c($m['source_sha256']===hash('sha256',$raw)&&file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$raw,
  'REAPER original source is preserved byte for byte');
$m=sf_stem_project_update('song-1',['revision'=>1,'action'=>'plugin_settings',
  'scope'=>'master','guid'=>'__master__','fx_index'=>0,'enabled'=>true,
  'settings'=>['threshold'=>-7.0,'ceiling'=>-0.5,'attack'=>0.01,'release'=>0.3]]);
ok86c($m['revision']===2&&$m['master_fx'][0]['settings']['ceiling']===-.5,
  'Master limiter browser overrides persist by exact revision');
ok86c($m['master_fx'][0]['js_slider_tokens'][0]==='-8.3',
  'Browser overrides leave exact imported JSFX slider metadata untouched');
$m=sf_stem_project_update('song-1',['revision'=>2,'action'=>'plugin_add',
  'scope'=>'track','guid'=>'{STEM-GUID}','type'=>'eq5']);
ok86c(count($m['tracks'][0]['fx'])===3&&count($m['tracks'][0]['fx'][2]['settings'])===15,
  'Modular 5-band EQ inserted with 15 bounded browser parameters');
$m=sf_stem_project_update('song-1',['revision'=>3,'action'=>'plugin_move',
  'scope'=>'track','guid'=>'{STEM-GUID}','fx_index'=>2,'direction'=>-1]);
ok86c($m['tracks'][0]['fx'][1]['type']==='eq5'
    &&$m['tracks'][0]['fx'][2]['fx_guid']==='{VOCAL-DELAY}',
  'Reorders browser playback slots without losing native REAPER GUIDs');
$m=sf_stem_project_update('song-1',['revision'=>4,'action'=>'plugin_undo',
  'scope'=>'track','guid'=>'{STEM-GUID}']);
ok86c($m['tracks'][0]['fx'][2]['type']==='eq5','Plugin undo restores last rack position');
$m=sf_stem_project_update('song-1',['revision'=>5,'action'=>'plugin_remove',
  'scope'=>'track','guid'=>'{STEM-GUID}','fx_index'=>2]);
ok86c(count($m['tracks'][0]['fx'])===2,'Browser-only plugin can be removed');
ok86c(denies86c(fn()=>sf_stem_project_update('song-1',['revision'=>6,'action'=>'plugin_remove',
  'scope'=>'track','guid'=>'{STEM-GUID}','fx_index'=>0])),
  'Imported REAPER plugin cannot be destructively removed');
ok86c(denies86c(fn()=>sf_stem_project_update('song-1',['revision'=>6,'action'=>'plugin_settings',
  'scope'=>'master','guid'=>'__master__','fx_index'=>0,'enabled'=>true,
  'settings'=>['ceiling'=>5]])),'Unsafe limiter ceiling value rejected');
ok86c(denies86c(fn()=>sf_stem_project_update('song-1',['revision'=>5,'action'=>'plugin_toggle',
  'scope'=>'master','guid'=>'__master__','fx_index'=>0,'enabled'=>false])),
  'Stale revisions do not overwrite plugin racks');
ok86c(denies86c(fn()=>sf_stem_project_update('song-1',['revision'=>6,'action'=>'plugin_add',
  'scope'=>'master','guid'=>'__master__','type'=>'arbitrary_vst'])),
  'Only supported first-party plugins can be added');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stem-plugin-rack.js');
$studio=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
ok86c(str_contains($ui,'native_state_sha256')&&str_contains($ui,'preset_name')
  &&str_contains($studio,"SFStemPluginRack.render('master'")
  &&str_contains($studio,'this.masterFX=window.SFStemFX.build'),
  'Visible master rack contains exact provenance and routes to Web Audio bus');
foreach(glob($root.'/storage/stem-projects/*')?:[] as $f)@unlink($f);
@rmdir($root.'/storage/stem-projects');@rmdir($root.'/storage');@rmdir($root);
echo "Section 86C exact plugin metadata and rack: $passed checks passed".PHP_EOL;
