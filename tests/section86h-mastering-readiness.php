<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86h-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1']];}
require dirname(__DIR__).'/api/stem-release-readiness.php';
$checks=0;
function ok86h(bool $pass,string $label):void {global $checks;if(!$pass)throw new RuntimeException('86H '.$label);$checks++;echo "PASS $label\n";}
function fails86h(callable $x):bool {try{$x();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {GUITAR}\n    NAME \"Guitar\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'synthetic.rpp',1);
ok86h(count(sf_stem_86h_presets())===4,'Four explicit release review destinations');
ok86h(sf_stem_86h_report($p,'streaming')['status']==='blocked','No analysis never grants readiness');
ok86h(fails86h(fn()=>sf_stem_86h_report($p,'untrusted')),'Unknown review profile is forbidden');
$metrics=['duration_seconds'=>5.0,'sample_rate'=>48000,'sample_peak_dbfs'=>-4.0,
 'integrated_lufs'=>-17.0,'rms_dbfs'=>-20.0,'crest_db'=>16.0,
 'stereo_imbalance_db'=>0.5,'clipped_samples'=>0];
$p=sf_stem_mastering_save_analysis('song-1',['revision'=>1,'analysis'=>['metrics'=>$metrics,
 'details'=>['unsupported_fx'=>0,'unsupported_routing'=>0,'unlinked_stems'=>0]]]);
$stream=sf_stem_86h_report($p,'streaming');
ok86h($stream['status']==='ready_for_admin_review','Safe browser-measured WAV is eligible for manual review');
ok86h($stream['can_auto_publish']===false&&$stream['needs_explicit_asset_upload'],
 'Review cannot publish or create a private master automatically');
$vinyl=sf_stem_86h_report($p,'vinyl');
ok86h(count(array_filter($vinyl['warnings'],fn($x)=>str_contains($x,'engineer')))>0,
 'Vinyl pre-master requires independent cutting engineer sign-off');
ok86h(fails86h(fn()=>sf_stem_86h_save_review('song-1',[
 'profile'=>'streaming','revision'=>2,'confirmation'=>'APPROVE'],1)),
 'Exact typed REVIEWED confirmation is mandatory');
ok86h(fails86h(fn()=>sf_stem_86h_save_review('song-1',[
 'profile'=>'streaming','revision'=>1,'confirmation'=>'REVIEWED'],1)),
 'Stale manifest revision cannot approve mix');
$p=sf_stem_86h_save_review('song-1',['profile'=>'streaming','revision'=>2,
 'confirmation'=>'REVIEWED'],1);
ok86h(($p['mastering_readiness']['state']??'')==='reviewed_pending_media_upload'
  &&($p['mastering_readiness']['reviewed_by']??0)===1,
 'Owner manual review persists privately without publishing');
ok86h(sf_stem_86h_review_status($p)['status']==='reviewed_pending_media_upload',
 'Readiness signature survives readiness-only revision');
ok86h(sf_stem_86h_review_status($p)['can_publish']===false,
 'Reviewed candidate is not an existing catalog asset');
$p=sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>3,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'volume',
    'points'=>[['time'=>0,'value'=>0.7,'shape'=>'linear']]]]);
ok86h(sf_stem_86h_review_status($p)['status']==='stale_after_mix_edit',
 'Any changed audio graph invalidates previous approval');
ok86h(fails86h(fn()=>sf_stem_86h_save_review('song-1',[
 'profile'=>'download','revision'=>4,'confirmation'=>'REVIEWED'],1)),
 'Cannot reuse prior analysis after mix edits');
ok86h(file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$source,
 'Native REAPER project stays byte exact');
ok86h(!isset($p['private_master_audio'])&&!isset($p['audio']),
 'Readiness cannot silently replace purchased original or public preview');
$svg=file_get_contents(dirname(__DIR__).'/admin/assets/stem-mastering.js');
ok86h(str_contains($svg,'data-h-readiness')&&str_contains($svg,"action:'save_readiness'")&&
 str_contains($svg,'data-h-profile'),
 'Review controls integrated into the existing Stem Studio canvas');
$api=file_get_contents(dirname(__DIR__).'/admin/api/stem-mastering.php');
ok86h(str_contains($api,'sf_admin_require_auth')&&str_contains($api,'sf_stem_86h_save_review'),
 'Server requires admin authorization and exact review');
echo "86H: $checks acceptance assertions passed\n";
