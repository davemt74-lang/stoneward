<?php
declare(strict_types=1);
/**
 * 86B — bounded, revision-safe clip edits and browser-FX overrides.
 * REAPER source stays untouched. These are NON-DESTRUCTIVE editing overlays.
 */
function sf_stem_numeric(mixed $value,float $min,float $max,string $field):float {
    if(!(is_int($value)||is_float($value)||is_string($value))||!is_numeric($value)||
        !is_finite((float)$value)||(float)$value<$min||(float)$value>$max)
        throw new InvalidArgumentException("Invalid $field.");
    return round((float)$value,4);
}
function sf_stem_fx_defaults(string $type):array {
    return match($type) {
        'eq'=>['frequency'=>900.0,'gain'=>0.0,'q'=>0.9],
        'compressor'=>['threshold'=>-18.0,'ratio'=>2.0,'attack'=>0.01,'release'=>0.25],
        'delay'=>['time'=>0.25,'feedback'=>0.2,'wet'=>0.2],
        'reverb'=>['wet'=>0.2,'seconds'=>1.4],
        default=>[]
    };
}
function sf_stem_fx_bounds(string $type):array {
    return match($type) {
        'eq'=>['frequency'=>[20,20000],'gain'=>[-24,24],'q'=>[0.1,18]],
        'compressor'=>['threshold'=>[-60,0],'ratio'=>[1,20],'attack'=>[0,1],'release'=>[0.01,2]],
        'delay'=>['time'=>[0.01,1.8],'feedback'=>[0,0.75],'wet'=>[0,1]],
        'reverb'=>['wet'=>[0,1],'seconds'=>[0.2,4]],
        default=>[]
    };
}
/** Capture ONLY editable clip and FX state; never roll back asset authorizations. */
function sf_stem_edit_snapshot(array $track):array {
    return ['clips'=>$track['clips']??[], 'fx'=>$track['fx']??[]];
}
function sf_stem_edit_refresh(array &$track):void {
    $max=0.;$pos=null;
    foreach($track['clips']??[] as $clip){
        $max=max($max,(float)$clip['position']+(float)$clip['length']);
        if((string)($clip['file']??'')===(string)($track['source_file']??''))
            $pos=$pos===null?(float)$clip['position']:min($pos,(float)$clip['position']);
    }
    $track['duration_seconds']=round($max,4);
    $track['source_position']=$pos??0.;
}
function sf_stem_editor_apply(array &$project,array $payload):void {
    $action=(string)($payload['action']??'');
    $history=(array)($project['edit_history']??[]);
    $undo=array_values((array)($history['undo']??[]));
    $redo=array_values((array)($history['redo']??[]));
    if($action==='undo_edit'||$action==='redo_edit'){
        $source=$action==='undo_edit'?$undo:$redo;
        if(!$source)throw new InvalidArgumentException('No clip or effect edits to restore.');
        $entry=array_pop($source);
        $guid=(string)($entry['guid']??'');
        $found=false;
        foreach($project['tracks'] as &$t)if((string)$t['guid']===$guid){
            $next=$action==='undo_edit'?$entry['before']:$entry['after'];
            if(!is_array($next)||!isset($next['clips'],$next['fx']))
                throw new RuntimeException('Edit history is damaged.');
            $t['clips']=$next['clips'];$t['fx']=$next['fx'];
            sf_stem_edit_refresh($t);$found=true;break;
        }unset($t);
        if(!$found)throw new RuntimeException('Track in undo history is missing.');
        if($action==='undo_edit'){
            $undo=$source;$redo[]=$entry;
        }else{$redo=$source;$undo[]=$entry;}
        $project['edit_history']=['undo'=>array_slice($undo,-20),'redo'=>array_slice($redo,-20)];
        return;
    }
    $guid=(string)($payload['guid']??'');
    $found=false;
    foreach($project['tracks'] as &$track){
        if(!hash_equals((string)$track['guid'],$guid))continue;
        $found=true;$before=sf_stem_edit_snapshot($track);
        if($action==='edit_clip'){
            $index=$payload['clip_index']??null;
            if(!is_int($index)||$index<0||$index>=count((array)($track['clips']??[])))
                throw new InvalidArgumentException('Select an existing REAPER clip.');
            $clip=&$track['clips'][$index];
            $position=sf_stem_numeric($payload['position']??null,0,86400,'clip position');
            $length=sf_stem_numeric($payload['length']??null,0.02,86400,'clip length');
            $offset=sf_stem_numeric($payload['offset']??null,0,86400,'source offset');
            if($position+$length>86400||$offset+$length>86400)
                throw new InvalidArgumentException('Clip exceeds supported 24-hour timeline.');
            // Source filename and media authorization remain immutable.
            $clip['position']=$position;$clip['length']=$length;$clip['offset']=$offset;
            unset($clip);
            sf_stem_edit_refresh($track);
        }elseif($action==='edit_fx'){
            $index=$payload['fx_index']??null;
            if(!is_int($index)||$index<0||$index>=count((array)($track['fx']??[])))
                throw new InvalidArgumentException('Select an imported effect.');
            $fx=&$track['fx'][$index];
            $type=(string)($fx['type']??'unsupported');
            $bounds=sf_stem_fx_bounds($type);
            if(!$bounds)throw new InvalidArgumentException('This imported REAPER plugin is read-only in the browser.');
            $settings=(array)($payload['settings']??[]);
            if(array_diff(array_keys($settings),array_keys($bounds)))
                throw new InvalidArgumentException('Unrecognized effect parameter.');
            $values=sf_stem_fx_defaults($type);
            foreach($values as $name=>$default){
                if(isset($fx['settings'][$name])&&is_numeric($fx['settings'][$name]))
                    $values[$name]=(float)$fx['settings'][$name];
                if(array_key_exists($name,$settings))
                    $values[$name]=sf_stem_numeric($settings[$name],$bounds[$name][0],$bounds[$name][1],$name);
            }
            $fx['settings']=$values;
            $fx['enabled']=!empty($payload['enabled']);
            unset($fx);
        }else throw new InvalidArgumentException('Unsupported stem edit.');
        $after=sf_stem_edit_snapshot($track);
        if($before!=$after){$undo[]=['guid'=>$guid,'before'=>$before,'after'=>$after];$redo=[];}
        break;
    }unset($track);
    if(!$found)throw new InvalidArgumentException('Stem track not found.');
    $project['edit_history']=['undo'=>array_slice($undo,-20),'redo'=>array_slice($redo,-20)];
}
