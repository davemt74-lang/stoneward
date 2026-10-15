<?php
declare(strict_types=1);
/** Verified native metadata—not unproven REAPER VST DSP decoding. */
$dir=sys_get_temp_dir().'/sf86d-'.bin2hex(random_bytes(7));
mkdir($dir,0700,true);define('SF_ROOT',$dir);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Synthetic REAPER Native State']];}
require dirname(__DIR__).'/api/stem-studio-core.php';
$n=0;
function check86d(bool $condition,string $message):void{
    global $n;
    if(!$condition)throw new RuntimeException('86D: '.$message);
    $n++;echo 'PASS '.$message.PHP_EOL;
}
function reject86d(callable $call):bool{try{$call();return false;}catch(Throwable){return true;}}
$module='loser/masterLimiter';
$tokens=['-8.3','200','100','0','250','-0.1','0'];
$decode=sf_stem_decode_loser_masterlimiter($module,[implode(' ',$tokens)]);
check86d($decode['status']==='verified-source-slider-schema','Known source-qualified JSFX recognized');
check86d($decode['confidence']==='exact-native-slider-values','Slider schema is exact rather than inferred');
check86d($decode['native_parameters']['threshold_db']===-8.3
    &&$decode['native_parameters']['lookahead_ns']===200.0
    &&$decode['native_parameters']['attack_ns']===100.0
    &&$decode['native_parameters']['hold_ms']===0.0
    &&$decode['native_parameters']['release_ms']===250.0
    &&$decode['native_parameters']['ceiling_db']===-0.1,
    'All six original masterLimiter slider values read in documented order');
check86d(abs($decode['browser_mapped_parameters']['attack']-0.0001)<1e-10
    &&abs($decode['browser_mapped_parameters']['release']-.25)<1e-10,
    'Time unit conversions are explicit and correct');
check86d(isset($decode['browser_unmapped_parameters']['lookahead_ns'])
    &&isset($decode['browser_unmapped_parameters']['hold_ms']),
    'Non-emulated lookahead and hold remain visible, not fabricated');
check86d(sf_stem_decode_loser_masterlimiter('other/limiter',[implode(' ',$tokens)])['status']==='unverified-jsfx-module',
    'A similar but different JSFX module cannot use the schema');
check86d(sf_stem_decode_loser_masterlimiter($module,['-30 '.implode(' ',array_slice($tokens,1))])['confidence']==='none',
    'Out-of-range native slider rejects exact decoder status');
check86d(sf_stem_decode_loser_masterlimiter($module,['-8.3 200 NaN 0 250 -.1 0'])['confidence']==='none',
    'Nonnumeric slider values fail closed');
check86d(sf_stem_decode_loser_masterlimiter($module,['-8.3 200 100'])['confidence']==='none',
    'Truncated JSFX slider list fails closed');
$header=base64_encode('qeer'.hex2bin('ee5eedfe').pack('V',2).str_repeat(chr(0),24));
$payload=base64_encode('REAPER-OPAQUE-BINARY');
$container=sf_stem_inspect_reaplugs_state('reaeq.dll',[$header,$payload]);
check86d($container['status']==='vst-container-verified-parameters-opaque'
    &&$container['container']['plugin_fourcc']==='qeer'
    &&$container['container']['container_version']===2,
    'Recognized ReaEQ VST container identity/version verified');
check86d($container['native_parameters']===[]&&$container['confidence']==='container-only',
    'VST opaque binary is not misreported as effect knob values');
check86d(sf_stem_inspect_reaplugs_state('reacomp.dll',[$header,$payload])['confidence']==='none',
    'ReaComp cannot receive ReaEQ container identity');
check86d(sf_stem_inspect_reaplugs_state('reaeq.dll',
    [base64_encode('qeer'.hex2bin('ee5eedfe').pack('V',19).str_repeat(chr(0),24)),$payload])['confidence']==='none',
    'Unknown VST container version fails closed');
check86d(sf_stem_inspect_reaplugs_state('reaeq.dll',[$header,'***'])['confidence']==='none',
    'Invalid VST base64 fails closed');
$raw='<REAPER_PROJECT 0.1 "7.09" 1'."\n  TEMPO 122 4 4\n  <MASTERFXLIST\n".
    "    BYPASS 0 0 0\n    <JS loser/masterLimiter \"\"\n      -8.3 200 100 0 250 -0.1 0 - - -\n".
    "    >\n    FXID {EXACT-LIMITER}\n    BYPASS 0 0 0\n    <VST \"VST: ReaEQ (Cockos)\" reaeq.dll 0 \"\" abc\n".
    "      $header\n      $payload\n    >\n    FXID {OPAQUE-EQ}\n  >\n".
    "  <TRACK {GUID-ONE}\n    NAME \"One Stem\"\n  >\n>\n";
$m=sf_stem_project_import('song-1',$raw,'native.rpp',4);
check86d(($m['master_fx'][0]['native_decode']['confidence']??'')==='exact-native-slider-values',
    'Existing RPP importer applies exact source schema');
check86d(($m['master_fx'][1]['native_decode']['confidence']??'')==='container-only',
    'Existing importer marks ReaEQ binary opaque');
check86d($m['master_fx'][0]['settings']['threshold']===-8.3
    &&$m['master_fx'][0]['settings']['ceiling']===-0.1,
    'Browser approximation initialized with verified compatible sliders');
$original=file_get_contents($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp');
check86d($original===$raw,'Original RPP remains byte-exact');
$m=sf_stem_project_update('song-1',[
    'action'=>'plugin_settings','revision'=>1,'scope'=>'master','guid'=>'__master__',
    'fx_index'=>0,'enabled'=>true,
    'settings'=>['threshold'=>-5.0,'ceiling'=>-0.5]
]);
check86d($m['master_fx'][0]['browser_settings_origin']==='user-override',
    'Deliberate browser setting is separately identified');
$m=sf_stem_project_update('song-1',['action'=>'native_decode_refresh','revision'=>2]);
check86d($m['master_fx'][0]['settings']['threshold']===-5.0
    &&$m['master_fx'][0]['settings']['ceiling']===-0.5,
    'Refresh preserves browser overrides instead of replacing them');
check86d($m['master_fx'][0]['native_parameters']['threshold_db']===-8.3
    &&$m['native_decode_refresh']['counts']['exact_jsfx']===1,
    'Refresh reveals original native values independently of overrides');
check86d($m['native_decode_refresh']['counts']['verified_container_only']===1,
    'Report distinguishes exact JSFX from VST header-only verification');
check86d(reject86d(fn()=>sf_stem_project_update('song-1',[
    'action'=>'native_decode_refresh','revision'=>2])),
    'Rechecking with a stale project revision cannot overwrite concurrent work');
check86d($m['plugin_history']['undo']!==[],
    'Native refresh does not wipe existing plugin edit history');
$session=$m;
$session['source_sha256']=str_repeat('0',64);
check86d(reject86d(function()use(&$session,$raw){sf_stem_native_refresh($session,$raw);}),
    'Tampered source checksum rejects native refresh');
check86d(file_get_contents($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$raw,
    'Native refresh cannot change original REAPER file');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stem-plugin-rack.js');
check86d(str_contains($js,'native_decode_refresh')&&str_contains($ui,'native_parameters')
   &&str_contains($ui,'browser_unmapped_parameters'),
   'Existing Stem Studio explains verified vs unsupported native settings');
foreach(glob($dir.'/storage/stem-projects/*')?:[] as $p)@unlink($p);
@rmdir($dir.'/storage/stem-projects');@rmdir($dir.'/storage');@rmdir($dir);
echo "Section 86D verification: $n checks passed".PHP_EOL;
