<?php
declare(strict_types=1);
/** Public regression fixture does not contain user-provided music or projects. */
$dir=sys_get_temp_dir().'/sf-stems-'.bin2hex(random_bytes(6));
mkdir($dir,0700,true);define('SF_ROOT',$dir);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Sample Song']];}
require dirname(__DIR__).'/api/stem-studio-core.php';
$assertions=0;
function check86(bool $ok,string $why):void {global $assertions;if(!$ok)throw new RuntimeException('86A: '.$why);$assertions++;echo 'PASS '.$why.PHP_EOL;}
function denies86(callable $fn):bool{try{$fn();return false;}catch(Throwable){return true;}}
$source='<REAPER_PROJECT 0.1 "7.09/win64" 1' . "\n  TEMPO 122 4 4\n  SAMPLERATE 44100 0 0\n  <FXCHAIN\n    <JS loser/masterLimiter \"\"\n  >\n";
for($i=1;$i<=13;$i++){
    $guid=sprintf('{00000000-0000-0000-0000-%012d}',$i);
    $name=$i<=5?'Synthetic Track '.$i:'';
    $source.="  <TRACK $guid\n    NAME \"$name\"\n    VOLPAN 0.75 -0.25 -1 -1 1\n    <FXCHAIN\n      <VST \"VST: ReaEQ (Cockos)\" reaeq.dll 0 \"\" 1>\n      >\n    >\n";
    for($j=0;$j<($i<=5?11:($i===13?3:($i>=10?2:1)));$j++){
        $filename=$j===0?sprintf('%02d-stem-consolidated.mp3',$i):"C:\\Untrusted\\Raw$i.wav";
        $source.="    <ITEM\n      POSITION ".($j*20)."\n      LENGTH 12.5\n      <SOURCE MP3\n        FILE \"$filename\"\n      >\n    >\n";
    }
    $source.="  >\n";
}
$source.="  >\n";
$p=sf_stem_rpp_parse($source);
check86($p['track_count']===13,'Track count and GUIDs');
check86($p['tempo_bpm']===122.0&&$p['time_signature']==='4/4','Tempo and time signature');
check86($p['sample_rate']===44100,'Project sample rate');
check86($p['tracks'][5]['name']==='Track 6','Unnamed track fallback');
check86($p['tracks'][0]['fx'][0]['type']==='eq','REAPER effect plugin identification');
check86(count($p['media_required'])===13,'Consolidated MP3 inputs de-duplicated');
check86($p['tracks'][2]['source_file']==='03-stem-consolidated.mp3','Consolidated file prioritized over raw takes');
check86(!str_contains(json_encode($p),'C:\\Untrusted'),'Private local drive paths removed from published manifest');
check86(sf_stem_safe_filename('C:\\Users\\Artist\\Stem.mp3')==='Stem.mp3','Never expose original workstation folder');
$m=sf_stem_project_import('song-1',$source,'Example.rpp',7);
check86($m['revision']===1&&$m['format']==='stonefellow.stem-rpp.v1','Protected song-specific import persists');
check86(is_file($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp'),'Unmodified REAPER project retained in private storage');
$g=$m['tracks'][1]['guid'];
$m=sf_stem_project_update('song-1',['action'=>'edit_track','revision'=>1,'guid'=>$g,'name'=>'Edited bass','role'=>'Bass']);
check86($m['tracks'][1]['name']==='Edited bass'&&$m['revision']===2,'Editable track details revisioned');
$m=sf_stem_project_update('song-1',['action'=>'save_mix','revision'=>2,'mix'=>['name'=>'Radio Mix','tracks'=>[$g=>['volume'=>0.75,'pan'=>0.1,'solo'=>true]]]]);
check86(!empty($m['mixes']['Radio Mix']['tracks'][$g]['solo']),'Named mix snapshot persists');
check86(denies86(fn()=>sf_stem_project_update('song-1',['action'=>'save_mix','revision'=>2,'mix'=>['name'=>'stale']])), 'Stale revisions rejected');
check86(denies86(fn()=>sf_stem_project_update('song-1',['action'=>'edit_track','revision'=>3,'guid'=>$g,'name'=>'','role'=>'Bass'])), 'Blank edited stem name rejected');
check86(denies86(fn()=>sf_stem_project_read('../wrong')), 'Track path traversal rejected');
check86(denies86(fn()=>sf_stem_rpp_parse('<UNTRUSTED>')), 'Unexpected project contents rejected');
check86(denies86(fn()=>sf_stem_rpp_parse('<REAPER_PROJECT '.str_repeat('X',9*1024*1024))), 'Overlarge projects rejected');
check86(str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/admin.js'),'SFStemStudio?.section')
    &&str_contains(file_get_contents(dirname(__DIR__).'/admin/index.php'),'stem-studio.js'), 'Existing Catalog song editor embeds the new studio');
check86(str_contains(file_get_contents(dirname(__DIR__).'/api/media-core.php'),"'stem_audio'"), 'Existing media delivery protects private stem assets');
check86(str_contains(file_get_contents(dirname(__DIR__).'/admin/api/stem-studio.php'),'sf_admin_require_auth($method!==\'GET\')'), 'Private stem API retains existing admin and CSRF controls');
foreach(glob($dir.'/storage/stem-projects/*')?:[] as $file)@unlink($file);
@rmdir($dir.'/storage/stem-projects');@rmdir($dir.'/storage');@rmdir($dir);
echo "Section 86A stem import and editor contracts: $assertions checks passed\n";
