<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86k-'.bin2hex(random_bytes(8));
mkdir($root,0700,true);define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Private release']];}
require dirname(__DIR__).'/api/stem-production-advisor.php';
$checks=0;
function ok86k(bool $yes,string $label):void{
    global $checks;if(!$yes)throw new RuntimeException('86K FAILED '.$label);
    $checks++;echo "PASS ".$label."\n";
}
function fail86k(callable $fn):bool{
    try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}
}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {DRUMS}\n    NAME \"drum-source\"\n  >\n  <TRACK {BASS}\n    NAME \"bass-source\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'real.rpp',1);
$p['tracks'][0]['source_file']='drum.wav';$p['tracks'][0]['asset_uuid']='private-drum-uuid';
$p['tracks'][0]['role']='drums';$p['tracks'][0]['clips']=[['position'=>0.,'length'=>8.,'offset'=>0.,'file'=>'drum.wav']];
$p['tracks'][1]['source_file']='bass.wav';$p['tracks'][1]['asset_uuid']='private-bass-uuid';
$p['tracks'][1]['role']='bass';$p['tracks'][1]['clips']=[['position'=>2.,'length'=>6.,'offset'=>0.,'file'=>'bass.wav']];
$p=sf_stem_project_write('song-1',$p,1);
$bands=['sub_bass'=>7.,'bass'=>65.,'low_mid'=>15.,'presence'=>9.,'high'=>4.];
$base=['sample_rate'=>48000,'sample_peak_dbfs'=>-.5,'rms_dbfs'=>-13.,
    'crest_db'=>12.5,'stereo_imbalance_db'=>.4,'active_window_ratio'=>1.,
    'observed_seconds'=>.3413,'windows'=>8,'bands_pct'=>$bands];
$input=['revision'=>2,'tracks'=>[
    ['guid'=>'{DRUMS}','source'=>'browser_decoded_private_source','metrics'=>$base],
    ['guid'=>'{BASS}','source'=>'browser_decoded_private_source','metrics'=>$base]
]];
ok86k(sf_stem_intelligence_summary('song-1',$p)['status']==='not_analyzed',
    'Unmeasured private stem data is explicitly absent');
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',array_replace($input,['revision'=>1]))),
    'Stale revision rejects incoming source evidence');
$wrong=$input;$wrong['tracks'][0]['guid']='{BAD}';
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Unassigned or fabricated track GUID rejected');
$wrong=$input;$wrong['tracks'][1]['guid']='{DRUMS}';
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Duplicate stem GUID rejected');
$wrong=$input;$wrong['tracks'][0]['metrics']['bands_pct']['bass']=INF;
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Nonfinite FFT energy ratio rejected');
$wrong=$input;$wrong['tracks'][0]['metrics']['bands_pct']['bass']=5.;
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Inconsistent spectral band sums rejected');
$wrong=$input;$wrong['tracks'][0]['source']='server_certified_full_native_reaper';
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Unsupported or misleading native/audio certification provenance rejected');
$wrong=$input;$wrong['tracks'][0]['metrics']['sample_peak_dbfs']='-0.5';
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$wrong)),
    'Client numeric strings cannot bypass strict metric typing');
$report=sf_stem_intelligence_save('song-1',$input);
ok86k($report['measurement_origin']==='browser_decoded_private_source_not_server_verified',
    'Report carries permanent browser-measured caveat');
ok86k(sf_stem_project_read('song-1')['revision']===2,
    'Evidence-only write does not bump project revision or stale 86G mastering');
$s=sf_stem_intelligence_summary('song-1',sf_stem_project_read('song-1'));
ok86k($s['status']==='recent'&&$s['analyzed_stems']===2,
    'Per-stem private measurements recover as current project evidence');
ok86k(count($s['masking_candidates'])===1&&
    $s['masking_candidates'][0]['band']==='bass'&&
    $s['masking_candidates'][0]['temporal_overlap_ratio']>=.9,
    'Potential band overlap requires real overlapping REAPER clips and actual shared spectral energy');
ok86k(str_contains($s['masking_candidates'][0]['interpretation'],'not proven'),
    'Masking remains a hypothesis, not an ungrounded claim of audibility');
$brief=sf_stem_intelligence_agent_brief($s);
$json=json_encode($brief,JSON_THROW_ON_ERROR);
ok86k(!str_contains($json,'{DRUMS}')&&!str_contains($json,'{BASS}')&&
    !str_contains($json,'private-drum-uuid')&&!str_contains($json,'drum-source')&&
    !str_contains($json,'source_file')&&!str_contains($json,'audio_url'),
    'Admin LLM gets only numbered/allowlisted role measurements, never GUID, media UUID or paths');
ok86k($brief['masking_candidates'][0]['stem_numbers']===[1,2],
    'LLM masking explanation is numbered but targets remain private Admin-only');
$review=sf_stem_production_inspect('song-1');
ok86k(($review['stem_audio']['status']??'')==='recent'&&
    count(array_filter($review['issues'],fn($x)=>$x['id']==='stem_spectral_overlap'))===1,
    '86J Agent production panel integrates grounded 86K FFT risk');
$agent=sf_stem_production_brief($review);
ok86k($agent['stem_audio']['analyzed_stems']===2&&
    !str_contains(json_encode($agent),'{DRUMS}'),
    'Configured Admin Brain provider receives bounded spectral metadata only');
$before=file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp');
ok86k($before===$source,'Original native REAPER source remains byte-exact');
$p=sf_stem_project_read('song-1');
$p['tracks'][1]['volume']=.4;
$p=sf_stem_project_write('song-1',$p,2);
ok86k(sf_stem_intelligence_summary('song-1',$p)['status']==='stale_after_project_edit',
    'Changing mix routing/fader revision invalidates stored source evidence');
ok86k(fail86k(fn()=>sf_stem_intelligence_save('song-1',$input)),
    'Stale browser scan cannot override a newer saved mix revision');
ok86k(sf_stem_intelligence_overlap(
 ['clips'=>[['position'=>0.,'length'=>2.]]],
 ['clips'=>[['position'=>4.,'length'=>2.]]])===0.,
 'No timeline overlap means no fabricated masking suggestion');
ok86k(sf_stem_intelligence_overlap(
 ['clips'=>[['position'=>0.,'length'=>4.]]],
 ['clips'=>[['position'=>1.,'length'=>2.]]])===1.,
 'Contained clip produces accurate normalized overlap evidence');
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/stem-intelligence.php');
$browser=file_get_contents(dirname(__DIR__).'/admin/assets/stem-intelligence.js');
$studio=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
ok86k(str_contains($endpoint,"sf_admin_require_auth(\$method!=='GET')")&&
 str_contains($endpoint,"header('Cache-Control: private, no-store")&&
 str_contains($endpoint,'sf_stem_intelligence_save'),
 'Only authenticated Admin API may persist private sidecar measurements');
ok86k(str_contains($browser,"credentials:'same-origin'")&&
 str_contains($browser,'MAX_FILE_BYTES=31457280')&&
 str_contains($browser,'window.SFStemIntelligence')&&
 str_contains($studio,'SFStemIntelligence.bind(root,this)'),
 'Browser source scans obey credentials, bounded memory and existing canvas integration');
ok86k(!str_contains($endpoint,'sf_track_media_publish_master')&&
 !str_contains($endpoint,'sf_agent_action_execute'),
 'Spectral evidence endpoint cannot publish masters, modify ownership or execute Agent drafts');
echo "86K PHP: $checks assertions green\n";
