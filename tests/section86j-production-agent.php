<?php
declare(strict_types=1);
/** Deterministic private production advice + real Agent approval ledger test. */
$root=sys_get_temp_dir().'/sf86j-'.bin2hex(random_bytes(7));
mkdir($root,0700,true);define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Synthetic private track']];}
$GLOBALS['sf86j_db']=new PDO('sqlite::memory:');
$GLOBALS['sf86j_db']->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$GLOBALS['sf86j_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function sf_db():PDO{return $GLOBALS['sf86j_db'];}
function sf_db_config():array{return ['driver'=>'sqlite'];}
sf_db()->exec('PRAGMA foreign_keys=ON');
sf_db()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
sf_db()->exec('INSERT INTO users (id) VALUES (1),(2)');
require dirname(__DIR__).'/api/stem-production-advisor.php';
require dirname(__DIR__).'/api/agent-actions-core.php';
require dirname(__DIR__).'/api/agent-skill-registry.php';
$checks=0;
function ok86j(bool $pass,string $label):void {
    global $checks;
    if(!$pass)throw new RuntimeException('86J FAIL '.$label);
    $checks++;echo "PASS ".$label."\n";
}
function rejects86j(callable $fn):bool {
    try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}
}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  <TRACK {GUITAR}\n    NAME \"Guitar\"\n  >\n  <TRACK {DRUMS}\n    NAME \"Drums\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'private.rpp',1);
$before=sf_stem_production_inspect('song-1');
ok86j($before['status']==='inspected'&&$before['project']['track_count']===2,
 'Production advisor inspects existing private song project');
ok86j(count($before['proposals'])===0,'No blind gain suggestions without fresh measurement evidence');
ok86j(count(array_filter($before['issues'],fn($i)=>$i['id']==='analysis_missing_or_stale'))===1,
 'Missing mastering measurements explicitly surfaced');
ok86j(sf_stem_production_model_explain($before,'How is the mix?')===null,
 'Provider unavailable: safe deterministic fallback without secrets or keys');
$analysis=['metrics'=>[
 'duration_seconds'=>4.,'sample_rate'=>48000,'sample_peak_dbfs'=>-.2,
 'integrated_lufs'=>-13.2,'rms_dbfs'=>-17.5,'crest_db'=>17.3,
 'stereo_imbalance_db'=>3.8,'clipped_samples'=>5,
 'low_band_pct'=>22.,'high_band_pct'=>38.,
 'reference_low_band_pct'=>40.,'reference_high_band_pct'=>48.,
 'reference_integrated_lufs'=>-17.],
 'details'=>['unsupported_fx'=>0,'unsupported_routing'=>0,'unlinked_stems'=>0]];
$p=sf_stem_mastering_save_analysis('song-1',['revision'=>1,'analysis'=>$analysis]);
$advisor=sf_stem_production_inspect('song-1');
ok86j($advisor['mastering']['status']==='recent','Browser mastering evidence is attached to current revision');
ok86j(count(array_filter($advisor['issues'],fn($i)=>$i['id']==='clipping'))===1,
 'Report identifies measured clipping rather than guessing audible distortion');
ok86j(count(array_filter($advisor['issues'],fn($i)=>$i['id']==='stereo_balance'))===1,
 'Report identifies measured stereo imbalance');
ok86j(count(array_filter($advisor['issues'],fn($i)=>$i['id']==='low_reference'))===1,
 'Report grounds tonal comparison in approximate reference band measurements');
ok86j(count($advisor['proposals'])===1,
 'A single conservative gain proposal is available on supported fresh audio');
$one=$advisor['proposals'][0];
ok86j($one['tool_id']==='stem_automation_update'&&$one['owner_approval_required']===true,
 'Advice reuses existing reviewed Agent tool; no new executor');
ok86j($one['draft']['revision']===2&&$one['draft']['lane']['scope']==='master'&&
 $one['draft']['lane']['parameter']==='volume',
 'Exact revision and valid master envelope travel with candidate');
ok86j($one['draft']['lane']['points'][0]['value']<.82&&
 $one['draft']['lane']['points'][0]['value']>=0,
 'Proposed gain is conservative and within real bounds');
$brief=sf_stem_production_brief($advisor);
$encoded=json_encode($brief);
ok86j(!str_contains($encoded,'source_file')&&!str_contains($encoded,'asset_uuid')&&
 !str_contains($encoded,'audio_url')&&!str_contains($encoded,'private.rpp')&&
 !str_contains($encoded,'Guitar')&&!str_contains($encoded,'storage/'),
 'Language model receives only redacted, bounded evidence, not track names or paths');
ok86j(sf_agent_skill_for_intent('admin','stem_production_review')!==null&&
 sf_agent_skill_for_intent('customer','stem_production_review')===null,
 'Production advice discoverable only in Admin Agent Brain');
$proposal=sf_agent_action_prepare(1,'stem86j_approval_exact_0123456789','stem_automation_update',$one['draft']);
ok86j($proposal['status']==='proposed','Preparing a proposal never writes mix automation');
ok86j(count(sf_stem_project_read('song-1')['automation']??[])===0,
 'No automation was applied during read-only analysis or proposal preparation');
ok86j(rejects86j(fn()=>sf_agent_action_execute(1,$proposal['id'])),
 'Execution before explicit approval fails closed');
ok86j(rejects86j(fn()=>sf_agent_action_approve(1,$proposal['id'],'invalid','APPROVE')),
 'Wrong exact proposal hash cannot be approved');
$approved=sf_agent_action_approve(1,$proposal['id'],$proposal['payload_hash'],'APPROVE');
ok86j($approved['status']==='approved','Owner exact-hash approval recorded separately');
$completed=sf_agent_action_execute(1,$proposal['id']);
ok86j($completed['status']==='completed'&&
 $completed['result']['status']==='automation_saved',
 'Approved proposal executes via existing revisioned Stem Studio action');
ok86j(count(sf_stem_project_read('song-1')['automation'])===1,
 'Exactly one browser automation lane applied');
ok86j(sf_agent_action_execute(1,$proposal['id'])['id']===$proposal['id'],
 'Repeated execution deduplicated without duplicate changes');
ok86j(sf_agent_action_get(2,$proposal['id'])===null,
 'Another admin has no access to the first admin proposal');
$after=sf_stem_production_inspect('song-1');
ok86j(count($after['proposals'])===0,'Stale evidence cannot produce another gain proposal after edit');
$p=sf_stem_mastering_save_analysis('song-1',['revision'=>3,'analysis'=>$analysis]);
$already=sf_stem_production_inspect('song-1');
ok86j(count($already['proposals'])===0&&
 count(array_filter($already['issues'],fn($i)=>$i['id']==='existing_master_envelope'))===1,
 'Existing master envelope prevents competing automatic gain proposals');
ok86j(rejects86j(fn()=>sf_agent_action_prepare(1,'stem86j_old_revision_0123456789',
 'stem_automation_update',$one['draft'])),
 'Earlier proposal is rejected if the mix revision advanced');
$now=sf_stem_project_read('song-1');
$now['tracks'][0]['fx'][]=['type'=>'unsupported','enabled'=>true];
$now['tracks'][1]['source_file']='private-drum.wav';
$now['tracks'][1]['asset_uuid']='';
$now=sf_stem_project_write('song-1',$now,(int)$now['revision']);
$unsafe=sf_stem_production_inspect('song-1');
ok86j(count($unsafe['proposals'])===0,'No proposed DSP when source stems or native effects are unsupported');
ok86j(count(array_filter($unsafe['issues'],fn($i)=>$i['id']==='native_compatibility'))===1 &&
 count(array_filter($unsafe['issues'],fn($i)=>$i['id']==='missing_stems'))===1,
 'Missing stems and unsupported native DSP visibly block production confidence');
ok86j(file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$source,
 'Original REAPER bytes unchanged after Agent proposal execution');
$api=file_get_contents(dirname(__DIR__).'/admin/api/stem-advisor.php');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/stem-production-advisor.js');
$studio=file_get_contents(dirname(__DIR__).'/admin/assets/stem-studio.js');
$admin=file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
ok86j(str_contains($api,'sf_admin_require_auth(false)')&&
 str_contains($api,"'GET'")&&str_contains($api,"'read_only'=>true"),
 'Private advisor endpoint is authenticated, GET-only and explicitly read-only');
ok86j(str_contains($ui,'data-j-inspect')&&str_contains($ui,'data-auto-propose')&&
 str_contains($studio,'SFStemProductionAdvisor.bind(root,this)')&&
 str_contains($studio,'SFStemProductionAdvisor.render(p)'),
 'Production diagnostics live inside existing Stem Studio and approved workbench');
ok86j(str_contains($admin,'sf_stem_production_model_explain($production,$message)')&&
 str_contains($admin,"$" . "action['production_brief']"),
 'Existing Admin chat exposes model-driven explanation and bounded Brain evidence');
echo "86J: $checks integrated software acceptance assertions green\n";
