<?php
declare(strict_types=1);
/** Non-destructive browser plugin rack over byte-exact saved REAPER source. */
function sf_stem_plugin_registry():array {
    return ['eq'=>'EQ (5-band planning)',
        'compressor'=>'Compressor','delay'=>'Delay','reverb'=>'Reverb',
        'limiter'=>'Master Limiter'];
}
function sf_stem_plugin_chain_reference(array &$project,string $scope,string $guid):array {
    if($scope==='master'){
        if(!isset($project['master_fx'])||!is_array($project['master_fx']))
            $project['master_fx']=[];
        return ['kind'=>'master','guid'=>'__master__'];
    }
    if($scope!=='track')throw new InvalidArgumentException('Unsupported FX bus.');
    foreach($project['tracks'] as $i=>$track)
        if(hash_equals((string)($track['guid']??''),$guid))
            return ['kind'=>'track','index'=>$i,'guid'=>$guid];
    throw new InvalidArgumentException('Track not found.');
}
function sf_stem_plugin_settings(string $type,array $existing,array $incoming):array {
    $bounds=sf_stem_fx_bounds($type);
    if(!$bounds)throw new InvalidArgumentException('This native REAPER plugin has no verified browser mapping.');
    if(array_diff(array_keys($incoming),array_keys($bounds)))
        throw new InvalidArgumentException('Unknown plugin parameter.');
    $values=sf_stem_fx_defaults($type);
    foreach($values as $key=>$value){
        if(isset($existing[$key])&&is_numeric($existing[$key]))
            $values[$key]=sf_stem_numeric($existing[$key],$bounds[$key][0],$bounds[$key][1],$key);
        if(array_key_exists($key,$incoming))
            $values[$key]=sf_stem_numeric($incoming[$key],$bounds[$key][0],$bounds[$key][1],$key);
    }
    return $values;
}
function sf_stem_plugin_apply(array &$project,array $payload):void {
    $action=(string)($payload['action']??'');
    $scope=(string)($payload['scope']??'');
    $guid=(string)($payload['guid']??'');
    $target=sf_stem_plugin_chain_reference($project,$scope,$guid);
    if($scope==='master')$chain=&$project['master_fx'];
    else $chain=&$project['tracks'][$target['index']]['fx'];
    $history=(array)($project['plugin_history']??[]);
    $undo=array_values((array)($history['undo']??[]));
    $redo=array_values((array)($history['redo']??[]));
    if(in_array($action,['plugin_undo','plugin_redo'],true)){
        $source=$action==='plugin_undo'?$undo:$redo;
        if(!$source)throw new InvalidArgumentException('No plugin change to undo.');
        $entry=end($source);
        if(!is_array($entry)||($entry['scope']??'')!==$scope||($entry['guid']??'')!==$target['guid'])
            throw new InvalidArgumentException('Switch to the bus of the most recent plugin change.');
        array_pop($source);
        $snapshot=$action==='plugin_undo'?$entry['before']:$entry['after'];
        if(!is_array($snapshot))throw new RuntimeException('Invalid plugin history.');
        $chain=$snapshot;
        if($action==='plugin_undo'){$undo=$source;$redo[]=$entry;}
        else{$redo=$source;$undo[]=$entry;}
        $project['plugin_history']=['undo'=>array_slice($undo,-20),'redo'=>array_slice($redo,-20)];
        return;
    }
    if(!is_array($chain))$chain=[];
    $before=$chain;
    $index=$payload['fx_index']??null;
    if($action==='plugin_add'){
        $type=(string)($payload['type']??'');
        if(!isset(sf_stem_plugin_registry()[$type]))
            throw new InvalidArgumentException('Unknown Stonefellow plugin.');
        if(count($chain)>=32)throw new InvalidArgumentException('32 plugins per bus maximum.');
        $chain[]=[
            'name'=>'Stonefellow '.sf_stem_plugin_registry()[$type],
            'type'=>$type,'format'=>'BROWSER','module'=>'stonefellow/'.$type,
            'vendor'=>'Stonefellow','browser_instance_id'=>bin2hex(random_bytes(8)),
            'preset_name'=>'','fx_guid'=>'','source_enabled'=>null,
            'enabled'=>true,'mapping'=>'approximate-browser-emulation',
            'parameter_provenance'=>'browser-user-values',
            'settings'=>sf_stem_fx_defaults($type),
        ];
    }else{
        if(!is_int($index)||$index<0||$index>=count($chain))
            throw new InvalidArgumentException('Select an existing plugin.');
        $type=(string)($chain[$index]['type']??'unsupported');
        if($action==='plugin_settings'){
            $chain[$index]['settings']=sf_stem_plugin_settings(
                $type,(array)($chain[$index]['settings']??[]),
                (array)($payload['settings']??[]));
            $chain[$index]['enabled']=!empty($payload['enabled']);
            $chain[$index]['browser_settings_origin']='user-override';
        }elseif($action==='plugin_toggle'){
            $chain[$index]['enabled']=!empty($payload['enabled']);
        }elseif($action==='plugin_move'){
            $direction=$payload['direction']??null;
            if(!is_int($direction)||!in_array($direction,[-1,1],true))
                throw new InvalidArgumentException('Invalid plugin move.');
            $to=$index+$direction;
            if($to<0||$to>=count($chain))throw new InvalidArgumentException('Plugin already at boundary.');
            [$removed]=array_splice($chain,$index,1);
            array_splice($chain,$to,0,[$removed]);
        }elseif($action==='plugin_remove'){
            if(($chain[$index]['format']??'')!=='BROWSER')
                throw new InvalidArgumentException('Imported native plugins must be retained; bypass instead.');
            array_splice($chain,$index,1);
        }else throw new InvalidArgumentException('Unsupported plugin action.');
    }
    if($before!==$chain){
        $undo[]=['scope'=>$scope,'guid'=>$target['guid'],'before'=>$before,'after'=>$chain];
        $redo=[];
    }
    $project['plugin_history']=['undo'=>array_slice($undo,-20),'redo'=>array_slice($redo,-20)];
}
