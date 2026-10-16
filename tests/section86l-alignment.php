<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86l-'.bin2hex(random_bytes(7));mkdir($root,0700,true);
define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'private test song']];}
require dirname(__DIR__).'/api/stem-production-advisor.php';
$checks=0;
function yes86l(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException('FAIL 86L '.$name);$checks++;echo "PASS $name\n";}
function bad86l(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {KICK}\n    NAME \"Kick\"\n  >\n  <TRACK {BASS}\n    NAME \"Bass\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'private.rpp',1);
foreach($p['tracks'] as &$t){
  $t['source_file']=$t['guid']==='{KICK}'?'kick.wav':'bass.wav';
  $t['asset_uuid']='private-'.strtolower(trim($t['guid'],'{}'));
  $t['role']=$t['guid']==='{KICK}'?'drums':'bass';
  $t['clips']=[['file'=>$t['source_file'],'position'=>1.0,'length'=>9.0,'offset'=>0.0]];
}unset($t);
$p=sf_stem_project_write('song-1',$p,1);
$bands=['sub_bass'=>-72.,'bass'=>-12.,'low_mid'=>-60.,'presence'=>-70.,'high'=>-75.];
$points=[];
foreach([1.,1.5,2.,2.5] as $time)$points[]=['time'=>$time,'rms_dbfs'=>-11.,
    'bands_dbfs'=>$bands];
$data=['mode'=>'focus','start_seconds'=>1.,'end_seconds'=>4.,'step_seconds'=>.5,
 'revision'=>2,'tracks'=>[
   ['guid'=>'{KICK}','origin'=>'browser_raw_private_clip','points'=>$points],
   ['guid'=>'{BASS}','origin'=>'browser_raw_private_clip','points'=>$points]
 ]];
yes86l(sf_stem_alignment_summary('song-1',$p)['status']==='not_analyzed','Empty project has no fabricated time-aligned measurements');
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',array_replace($data,['revision'=>1]))),'Stale revision cannot submit evidence');
$wrong=$data;$wrong['tracks'][1]['guid']='{KICK}';
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'Duplicate track GUID rejected');
$wrong=$data;$wrong['tracks'][0]['points'][1]['time']=1.3;
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'Off-grid timestamps cannot simulate simultaneity');
$wrong=$data;$wrong['tracks'][0]['points'][0]['time']=0.5;
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'Out-of-clip observations rejected');
$wrong=$data;$wrong['tracks'][0]['points'][0]['bands_dbfs']['bass']=INF;
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'Nonfinite spectral evidence fails closed');
$wrong=$data;$wrong['tracks'][0]['points'][0]['rms_dbfs']='-11';
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'String coercion cannot smuggle numeric evidence');
$wrong=$data;$wrong['mode']='overview';$wrong['step_seconds']=.5;$wrong['end_seconds']=500.;
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$wrong)),'Overview duration bounded to 8 minutes');
$first=sf_stem_alignment_save('song-1',$data);
yes86l($first['format']==='stonefellow.stem-alignment.v1'&&
   $first['provenance']==='browser_measured_raw_clip_pre_fx_not_verified','Aligned private measurements saved with unverified provenance');
yes86l(sf_stem_project_read('song-1')['revision']===2,'Evidence sidecar does not change mix revision or master approval');
$summary=sf_stem_alignment_summary('song-1',sf_stem_project_read('song-1'));
yes86l($summary['status']==='recent'&&count($summary['top_findings'])===4,'Four simultaneous strong bass-band events found at exact project times');
yes86l(count($summary['top_passages'])===1&&$summary['top_passages'][0]['sample_count']===4&&
 $summary['top_passages'][0]['start_seconds']===1.0&&
 $summary['top_passages'][0]['end_seconds']===3.0,
 'Consecutive strong aligned FFT events merge into a specific 1–3s listening passage');
yes86l($summary['top_findings'][0]['band']==='bass'&&
    in_array($summary['top_findings'][0]['time'],[1.,1.5,2.,2.5],true),'Events contain precise passage time and band');
$brief=sf_stem_alignment_agent_brief($summary,$p);
$j=json_encode($brief);
yes86l($brief['passages'][0]['stem_numbers']===[1,2]&&
 $brief['passages'][0]['samples']===4,
 'Admin Agent Brain receives redacted passage windows rather than only isolated events');
yes86l($brief['events'][0]['stem_roles']===['drums','bass']&&
 !str_contains($j,'{KICK}')&&!str_contains($j,'{BASS}')&&!str_contains($j,'private-')&&
 !str_contains($j,'kick.wav')&&!str_contains($j,'Bass.wav'),
 'Admin LLM context maps private GUIDs to numbered roles without leaking media');
$mix=sf_stem_production_inspect('song-1');
yes86l(count(array_filter($mix['issues'],fn($x)=>$x['id']==='time_aligned_competition'))===1,
 'Production assistant incorporates measured timestamped events');
$agent=sf_stem_production_brief($mix);
yes86l($agent['time_alignment']['status']==='recent'&&!str_contains(json_encode($agent),'{KICK}'),
 '86J Admin Agent Chat receives only bounded time evidence');
$quiet=$data;
$quiet['tracks'][1]['points']=array_map(static function($q){
    $q['rms_dbfs']=-65.;$q['bands_dbfs']['bass']=-70.;return $q;
},$points);
sf_stem_alignment_save('song-1',$quiet);
$quietSummary=sf_stem_alignment_summary('song-1',$p);
yes86l(count($quietSummary['scans'])===2&&
 count($quietSummary['scans'][1]['findings'])===0,'Near-silent source produces no false time-aligned masking');
$one=$data;$one['tracks'][1]['points']=array_map(static function($q){$q['time']+=.5;return $q;},array_slice($points,0,3));
sf_stem_alignment_save('song-1',$one);
$s=sf_stem_alignment_summary('song-1',$p);
yes86l(count($s['scans'])===3&&count($s['scans'][2]['findings'])===3,
 'Only identical grid times are compared, never adjacent asynchronous observations');
$original=file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp');
yes86l($original===$source,'Original REAPER project bytes unchanged by any scan');
$p['tracks'][0]['volume']=.5;
$p=sf_stem_project_write('song-1',$p,2);
yes86l(sf_stem_alignment_summary('song-1',$p)['status']==='stale_after_project_edit',
 'Edited mix makes every prior timed measurement stale');
yes86l(bad86l(fn()=>sf_stem_alignment_save('song-1',$data)),
 'Old scan is rejected after a new project revision');
$php=file_get_contents(dirname(__DIR__).'/admin/api/stem-alignment.php');
$js=file_get_contents(dirname(__DIR__).'/admin/assets/stem-alignment.js');
$studio=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
yes86l(str_contains($php,"sf_admin_require_auth(\$method!=='GET')")&&str_contains($php,"Cache-Control: private, no-store"),
 'Time-aligned API is Admin-only and disables public caching');
yes86l(!str_contains($php,'sf_track_media_publish_master')&&
 !str_contains($php,'sf_agent_action_execute'),'Time-aligned evidence cannot publish or execute agent edits');
yes86l(str_contains($js,"credentials:'same-origin'")&&str_contains($js,'MAX_BYTES=31457280')&&
 str_contains($js,'controller.abort()'),'Private browser fetching is credentialed, size-bounded and cancelable');
yes86l(str_contains($studio,'SFStemAlignment.bind(root,this)')&&
 str_contains($studio,'SFStemAlignment.render(p)'),'Only the existing Stem Studio hosts timeline intelligence');
echo "86L PHP: $checks assertions green\n";
