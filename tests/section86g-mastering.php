<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/sf86g-'.bin2hex(random_bytes(8));mkdir($dir,0700,true);
define('SF_ROOT',$dir);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Private mixing fixture']];}
require dirname(__DIR__).'/api/stem-agent-bridge.php';
$count=0;
function ok86g(bool $yes,string $label):void{global $count;if(!$yes)throw new RuntimeException('FAIL: '.$label);$count++;echo "PASS ".$label.PHP_EOL;}
function reject86g(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {GUITAR}\n    NAME \"Guitar\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'fixture.rpp',1);
ok86g($p['revision']===1,'Fixture stored in private project manifest');
$input=['metrics'=>['duration_seconds'=>6,'sample_rate'=>48000,'sample_peak_dbfs'=>-0.25,
'integrated_lufs'=>-13.5,'rms_dbfs'=>-15,'crest_db'=>14.75,'stereo_imbalance_db'=>3.8,
'clipped_samples'=>4],'details'=>['unsupported_fx'=>0,'unsupported_routing'=>0,
 'unlinked_stems'=>0,'reference_name'=>'My Mix']];
$normalized=sf_stem_mastering_report_validate($input);
ok86g($normalized['measurement_provenance']==='browser_measured_not_server_verified',
 'Browser measurements explicitly not claimed as independently verified');
ok86g(reject86g(fn()=>sf_stem_mastering_report_validate(['metrics'=>
 ['duration_seconds'=>6,'sample_rate'=>48000,'sample_peak_dbfs'=>INF]])),
 'Nonfinite and partial data rejected');
$invalid=$input;$invalid['metrics']['integrated_lufs']=-999;
ok86g(reject86g(fn()=>sf_stem_mastering_report_validate($invalid)),'Implausible LUFS values rejected');
$invalid=$input;$invalid['metrics']['sample_rate']=44100;
ok86g(reject86g(fn()=>sf_stem_mastering_report_validate($invalid)),'48 kHz analysis preset enforced');
$invalid=$input;$invalid['details']['reference_name']="<script>\n".str_repeat('x',200);
ok86g(!str_contains(sf_stem_mastering_report_validate($invalid)['details']['reference_name'],"\n")&&
 strlen(sf_stem_mastering_report_validate($invalid)['details']['reference_name'])<=120,
 'Reference caption is bounded and newline-free');
$p=sf_stem_mastering_save_analysis('song-1',['revision'=>1,'analysis'=>$input]);
ok86g($p['revision']===2&&count($p['mastering_analyses'])===1,
 'Measurement history saves only after exact revision match');
ok86g(reject86g(fn()=>sf_stem_mastering_save_analysis('song-1',
 ['revision'=>1,'analysis'=>$input])),'Stale render cannot overwrite changed mix');
$summary=sf_stem_mastering_agent_summary($p);
ok86g($summary['status']==='recent'&&$summary['clipped_samples']===4,
 'Agent Brain receives recent clipping evidence with source revision');
$adv=sf_stem_mastering_recommendations($summary);
ok86g(count(array_filter($adv,fn($x)=>str_contains($x,'clipped output')))>0,
 'Recommendations grounded in measured clipping');
ok86g(count(array_filter($adv,fn($x)=>str_contains($x,'channel')))>0,
 'Recommendations grounded in stereo imbalance');
ok86g(count(array_filter($adv,fn($x)=>str_contains($x,'browser-reported')))>0,
 'Agent recommendations label measurement provenance');
$review=sf_stem_agent_review('song-1');
ok86g(isset($review['mastering'])&&$review['mastering']['clipped_samples']===4,
 'Existing private Agent Brain review integrated with mastering report');
$serialized=json_encode($review);
ok86g(!str_contains($serialized,'source_file')&&!str_contains($serialized,'asset_uuid')&&
 !str_contains($serialized,'audio_url')&&!str_contains($serialized,'stored_path'),
 'Agent report excludes media paths and private raw project data');
$p=sf_stem_project_update('song-1',['action'=>'edit_track','revision'=>2,
 'guid'=>'{GUITAR}','name'=>'Guitar adjusted','role'=>'String']);
ok86g(sf_stem_mastering_agent_summary($p)['status']==='stale_after_project_edit',
 'Any project change makes the previous measurement stale');
ok86g(file_get_contents($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$source,
 'Original REAPER source is byte-exact after mastering and edits');
$referencePath=sf_stem_mastering_reference_path('song-1',$p);
ok86g($referencePath===null,'Missing reference cannot be streamed or guessed');
$p['mastering_reference']=['tag'=>'../outside.wav','name'=>'traversal'];
ok86g(sf_stem_mastering_reference_path('song-1',$p)===null,
 'Reference filename traversal rejected before filesystem access');
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/stem-mastering.php');
ok86g(str_contains($endpoint,"sf_admin_require_auth(\$method!=='GET')")&&
 str_contains($endpoint,"header('Cache-Control: private, no-store")&&
 str_contains($endpoint,'sf_stem_mastering_reference_path'),
 'Private reference streaming authenticates and disables public caching');
ok86g(str_contains($endpoint,"sf_log_admin_action("),'Mastering evidence changes are logged for Admin audit');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
$assets=file_get_contents(dirname(__DIR__).'/admin/index.php');
ok86g(str_contains($ui,'SFStemMastering.bind(root,this)')&&
 str_contains($ui,'SFStemMastering.ui(p)')&&
 str_contains($assets,'assets/stem-mastering.js'), 'Mastering lab integrated in original Stem Studio, not parallel player');
ok86g(str_contains(file_get_contents(dirname(__DIR__).'/admin/assets/stem-mastering.js'),
 'data-auto-propose'),'Suggested gain passes through existing governed Agent approval workbench');
echo "86G PHP acceptance: $count assertions green\n";
