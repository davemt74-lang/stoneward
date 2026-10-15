<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/sf86e-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
define('SF_ROOT',$dir);
function sf_catalog():array{return [['id'=>'song-1']];}
require dirname(__DIR__).'/api/stem-studio-core.php';
$n=0;
function ok86e(bool $condition,string $message):void{global $n;if(!$condition)throw new RuntimeException($message);$n++;echo "PASS $message\n";}
function fails86e(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException){return true;}}
$rpp="<REAPER_PROJECT 0.1 \"7.0\" 1\n".
"  <TRACK {FOLDER}\n    NAME \"Drums bus\"\n    I_FOLDERDEPTH 1\n  >\n".
"  <TRACK {KICK}\n    NAME \"Kick\"\n    I_FOLDERDEPTH 0\n  >\n".
"  <TRACK {SNARE}\n    NAME \"Snare\"\n    I_FOLDERDEPTH -1\n  >\n".
"  <TRACK {RETURN}\n    NAME \"Return\"\n    MAINSEND 1 0\n    AUXRECV 1 0 0.5 0 0 0 0 0 0 0 0\n  >\n>\n";
$m=sf_stem_project_import('song-1',$rpp,'synthetic.rpp',1);
ok86e($m['tracks'][1]['folder_parent_guid']==='{FOLDER}'&&
 $m['tracks'][2]['folder_parent_guid']==='{FOLDER}'&&
 $m['tracks'][3]['folder_parent_guid']===null,'REAPER folder descendants and closure mapped');
ok86e(count($m['routing']['sends'])===1&&$m['routing']['sends'][0]['source_guid']==='{KICK}'&&
 $m['routing']['sends'][0]['target_guid']==='{RETURN}','Post-fader REAPER AUXRECV imports source-index correctly');
ok86e(fails86e(fn()=>sf_stem_project_update('song-1',['action'=>'route_add_send',
 'revision'=>1,'source_guid'=>'{FOLDER}','target_guid'=>'{KICK}'])),'Cycle through parent bus rejected');
$m=sf_stem_project_update('song-1',['action'=>'route_add_bus','revision'=>1,'name'=>'Reverb']);
$id=$m['routing']['buses'][0]['id'];
ok86e((bool)preg_match('/^bus-[a-f0-9]{16}$/',$id),'Auxiliary bus receives bounded identity');
$m=sf_stem_project_update('song-1',['action'=>'route_add_send','revision'=>2,
 'source_guid'=>'{SNARE}','target_guid'=>$id,'gain'=>0.25]);
ok86e(count($m['routing']['sends'])===2,'Browser post-fader send is stored');
ok86e(fails86e(fn()=>sf_stem_project_update('song-1',['action'=>'route_add_send','revision'=>3,
 'source_guid'=>$id,'target_guid'=>'{SNARE}'])),'Cycle from bus into ancestor rejected');
ok86e(fails86e(fn()=>sf_stem_project_update('song-1',['action'=>'route_delete_bus',
 'revision'=>3,'id'=>$id])),'Connected buses cannot be deleted');
ok86e(fails86e(fn()=>sf_stem_project_update('song-1',['action'=>'route_update_send',
 'revision'=>3,'id'=>'rpp-0','gain'=>0.8])),'Imported native send is read-only');
$m=sf_stem_project_update('song-1',['action'=>'route_update_bus','revision'=>3,'id'=>$id,
 'volume'=>0.6,'pan'=>-0.2,'mute'=>true]);
ok86e($m['routing']['buses'][0]['mute']===true,'Bus gain pan and mute persist');
ok86e(fails86e(fn()=>sf_stem_project_update('song-1',['action'=>'route_add_bus',
 'revision'=>3,'name'=>'Stale'])),'Stale revision is rejected');
$m=sf_stem_project_update('song-1',['action'=>'plugin_add','revision'=>4,
 'scope'=>'bus','guid'=>$id,'type'=>'eq']);
ok86e(count($m['routing']['buses'][0]['fx'])===1,'Auxiliary bus supports independent FX chain');
$m=sf_stem_project_update('song-1',['action'=>'save_mix','revision'=>5,
 'mix'=>['name'=>'Bus mix','tracks'=>[]]]);
ok86e(count($m['mixes']['Bus mix']['buses'])===1,'Mix snapshot contains bus controls');
ok86e(file_get_contents($dir.'/storage/stem-projects/'.hash('sha256','song-1').'.rpp')===$rpp,
 'Original private RPP remains byte-exact');
echo "86E routing: $n assertions passed\n";
