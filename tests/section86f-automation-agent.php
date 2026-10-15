<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf86f-'.bin2hex(random_bytes(7));mkdir($root,0700,true);
define('SF_ROOT',$root);
function sf_catalog():array{return [['id'=>'song-1','title'=>'Private synthetic song']];}
require dirname(__DIR__).'/api/stem-agent-bridge.php';
require dirname(__DIR__).'/api/agent-skill-registry.php';
$n=0;
function ok86f(bool $value,string $label):void {global $n;if(!$value)throw new RuntimeException('86F: '.$label);$n++;echo "PASS $label\n";}
function rejected86f(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException){return true;}}
$source="<REAPER_PROJECT 0.1 \"7.0\" 1\n  TEMPO 120 4 4\n".
"  <TRACK {GUITAR}\n    NAME \"Guitar\"\n    <VOLENV2\n      ACT 1 -1\n      PT 0 1 0\n      PT 8 0.5 0\n    >\n".
"    <PANENV2\n      ACT 1 -1\n      PT 0 -0.8 1\n      PT 5 0.8 1\n    >\n".
"    <MUTEENV\n      ACT 1 -1\n      PT 0 0 1\n    >\n  >\n".
"  <TRACK {DRUMS}\n    NAME \"Drums\"\n  >\n>\n";
$p=sf_stem_project_import('song-1',$source,'fixture.rpp',1);
ok86f(($p['automation_import']['imported_lanes']??0)===2,'Verified volume and pan native envelope imported');
ok86f(($p['automation_import']['unsupported_envelopes']??0)>=1,'Native mute envelope polarity remains unverified');
ok86f(count($p['automation'])===2&&$p['automation'][0]['source']==='REAPER','Native envelopes preserved in separate source scope');
ok86f(abs(sf_stem_automation_value($p['automation'][0]['points'],4)-.75)<0.00001,'Linear interpolation is deterministic');
ok86f(abs(sf_stem_automation_value($p['automation'][1]['points'],3)+.8)<0.00001,'Square interpolation holds preceding value');
ok86f(rejected86f(fn()=>sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>1,
  'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'volume','points'=>[
  ['time'=>4,'value'=>1],['time'=>2,'value'=>.5]]]])),'Nonmonotonic keyframes fail closed');
ok86f(rejected86f(fn()=>sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>1,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'fx','fx_index'=>0,
 'fx_parameter'=>'gain','points'=>[['time'=>0,'value'=>12]]]])),'Unknown FX slot cannot be automated');
ok86f(rejected86f(fn()=>sf_stem_project_update('song-1',['action'=>'automation_delete_lane','revision'=>1,
 'id'=>$p['automation'][0]['id']])),'Native envelopes cannot be deleted');
$p=sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>1,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'volume',
 'points'=>[['time'=>0,'value'=>.6],['time'=>8,'value'=>1.2]]]]);
ok86f(count($p['automation'])===3,'Browser envelope overlays native without touching it');
$p=sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>2,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'volume',
 'points'=>[['time'=>0,'value'=>.9]]]]);
ok86f(count($p['automation'])===3,'Updating same parameter replaces browser overlay, not native');
$p=sf_stem_project_update('song-1',['action'=>'plugin_add','revision'=>3,'scope'=>'track','guid'=>'{GUITAR}','type'=>'eq']);
ok86f(count($p['tracks'][0]['fx'])===1,'Browser FX rack remains available');
$p=sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>4,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'fx','fx_index'=>0,
 'fx_parameter'=>'gain','points'=>[['time'=>0,'value'=>-2],['time'=>7,'value'=>4]]]]);
ok86f(count($p['automation'])===4,'Mapped browser EQ gain automates without VST binary decode');
ok86f(rejected86f(fn()=>sf_stem_project_update('song-1',['action'=>'automation_set_lane','revision'=>5,
 'lane'=>['scope'=>'track','guid'=>'{GUITAR}','parameter'=>'fx','fx_index'=>0,
 'fx_parameter'=>'secret','points'=>[['time'=>0,'value'=>5]]]])),'Unknown plugin knob rejected');
$p=sf_stem_project_update('song-1',['action'=>'save_mix','revision'=>5,
 'mix'=>['name'=>'Approved preview','tracks'=>['{GUITAR}'=>['volume'=>.6,'pan'=>.3,'mute'=>false,'solo'=>false]]]]);
ok86f(count($p['mixes']['Approved preview']['automation'])===2,'Snapshot includes browser overlay and FX automation');
$p=sf_stem_project_update('song-1',['action'=>'automation_delete_lane','revision'=>6,
 'id'=>$p['automation'][2]['id']]);
ok86f(count($p['automation'])===3,'Delete affects only browser lane');
$p=sf_stem_project_update('song-1',['action'=>'recall_mix','revision'=>7,'name'=>'Approved preview']);
ok86f(count($p['automation'])===4&&$p['current_mix']==='Approved preview','Server-side recall restores revisioned mix automation');
ok86f(abs($p['tracks'][0]['volume']-.6)<.001&&abs($p['tracks'][0]['pan']-.3)<.001,'Recall restores saved track controls');
ok86f(rejected86f(fn()=>sf_stem_project_update('song-1',['action'=>'recall_mix','revision'=>7,'name'=>'Approved preview'])),'Stale mix recall revision rejected');
$raw=file_get_contents($root.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp');
ok86f($raw===$source,'REAPER source remains byte-for-byte unchanged');
$review=sf_stem_agent_review('song-1');
ok86f($review['automation_lanes']===4&&$review['track_count']===2,'Admin Agent Brain reviews real private project metadata');
$serialized=json_encode($review);
ok86f(!str_contains($serialized,'source_file')&&!str_contains($serialized,'asset_uuid')
  &&!str_contains($serialized,'audio_url')&&!str_contains($serialized,$root),
  'Agent Brain receives only a safe redacted mix summary');
$draft=['track_id'=>'song-1','revision'=>8,'lane'=>['scope'=>'track','guid'=>'{DRUMS}',
 'parameter'=>'mute','points'=>[['time'=>0,'value'=>0],['time'=>5,'value'=>1,'shape'=>'square']]]];
$one=sf_stem_agent_normalize_draft($draft);$two=sf_stem_agent_normalize_draft($draft);
ok86f($one===$two,'Agent approved proposal normalizes deterministically for idempotency');
ok86f(rejected86f(fn()=>sf_stem_agent_normalize_draft(['track_id'=>'song-1',
 'revision'=>7,'lane'=>$draft['lane']])), 'Agent proposal rejects stale exact revision');
ok86f(sf_agent_tool_allowed('stem_mix_inspect','admin')&&!sf_agent_tool_allowed('stem_mix_inspect','fan'),
 'Private mix inspection is admin-only');
ok86f(sf_agent_tool_executable('stem_automation_update','admin',true)
 &&!sf_agent_tool_executable('stem_automation_update','admin',false)
 &&!sf_agent_tool_executable('stem_automation_update','fan',true),
 'Agent automation mutation is exact-approval admin-only');
ok86f(sf_agent_skill_for_intent('admin','stem_mix_review')!==null &&
 sf_agent_skill_for_intent('customer','stem_mix_review')===null,
 'Agent mix-review skill remains isolated from customer Brain');
$p=sf_stem_project_import('song-1',$source,'fixture.rpp',1);
ok86f(count($p['automation'])===4,'Reimport preserves independent browser overrides');
echo "86F PHP: $n checks green\n";
