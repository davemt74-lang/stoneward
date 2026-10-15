<?php
declare(strict_types=1);
/**
 * 86E: bounded browser routing model for imported REAPER folder buses and
 * supported post-fader receives. This is not a REAPER routing-state encoder.
 * The original private .rpp remains byte-identical.
 */
function sf_stem_route_number(mixed $value,float $min,float $max):float {
    if(!is_numeric($value)||!is_finite((float)$value)||((float)$value)<$min||((float)$value)>$max)
        throw new InvalidArgumentException('Routing value is out of range.');
    return (float)$value;
}
function sf_stem_route_import(array &$project,string $source):void {
    $stack=[];$rows=[];$index=-1;
    $warnings=[];$incoming=[];
    foreach(preg_split('/\r\n|\r|\n/',$source)?:[] as $line){
        if(preg_match('/^  <TRACK(?:\s|$)/',$line)){
            if($index>=0){
                $delta=$rows[$index]['folder_depth'];
                if($delta===1)$stack[]=$index;
                elseif($delta<0)for($k=0;$k<-$delta;$k++){
                    if(!$stack){$warnings[]='Unbalanced REAPER folder closure';break;}
                    array_pop($stack);
                }
            }
            $index++;
            if(!isset($project['tracks'][$index]))break;
            $rows[$index]=['folder_depth'=>0,'parent'=>$stack?end($stack):null,'main_send'=>true];
            continue;
        }
        if($index<0||!isset($rows[$index]))continue;
        if(preg_match('/^    I_FOLDERDEPTH\s+(-?\d+)(?:\s|$)/',$line,$m)){
            $depth=(int)$m[1];
            if($depth>1||$depth< -32){$warnings[]='Unsupported folder depth value on track '.($index+1);continue;}
            $rows[$index]['folder_depth']=$depth;
        }elseif(preg_match('/^    MAINSEND\s+([01])(?:\s|$)/',$line,$m)){
            $rows[$index]['main_send']=$m[1]==='1';
        }elseif(preg_match('/^    AUXRECV\s+(\d+)\s+(-?\d+)\s+([^\s]+)(?:\s|$)/',$line,$m)){
            $incoming[]=['source_index'=>(int)$m[1],'target_index'=>$index,'mode'=>(int)$m[2],'gain'=>$m[3]];
        }
    }
    foreach($rows as $i=>$row){
        $project['tracks'][$i]['folder_depth']=$row['folder_depth'];
        $project['tracks'][$i]['folder_parent_guid']=$row['parent']===null?null:
            ($project['tracks'][$row['parent']]['guid']??null);
        $project['tracks'][$i]['main_send_enabled']=$row['main_send'];
    }
    $project['routing']=['version'=>'stonefellow.routing.v1','buses'=>[],'sends'=>[],
        'warnings'=>$warnings,'unsupported_receives'=>0];
    foreach($incoming as $r){
        $src=$project['tracks'][$r['source_index']]['guid']??null;
        $dst=$project['tracks'][$r['target_index']]['guid']??null;
        if($src===null||$dst===null||$src===$dst||$r['mode']!==0||
           !is_numeric($r['gain'])||!is_finite((float)$r['gain'])||
           (float)$r['gain']<0||(float)$r['gain']>4){
            $project['routing']['unsupported_receives']++;
            continue;
        }
        $send=['id'=>'rpp-'.count($project['routing']['sends']),
            'source_guid'=>$src,'target_guid'=>$dst,'gain'=>(float)$r['gain'],
            'mode'=>'post-fader','origin'=>'REAPER','enabled'=>true];
        $project['routing']['sends'][]=$send;
        try{sf_stem_route_validate($project);}
        catch(InvalidArgumentException){
            array_pop($project['routing']['sends']);
            $project['routing']['unsupported_receives']++;
            $project['routing']['warnings'][]='Receive with circular routing cannot play in browser';
        }
    }
}
/** Reject dangling references, malformed or circular graphs before saving or playback. */
function sf_stem_route_validate(array $project):void {
    $tracks=(array)($project['tracks']??[]);
    $routing=(array)($project['routing']??[]);
    if(count($tracks)>256||count((array)($routing['buses']??[]))>32||
       count((array)($routing['sends']??[]))>256)
        throw new InvalidArgumentException('Routing capacity exceeded.');
    $nodes=[];$edges=[];
    foreach($tracks as $track){
        $id=(string)($track['guid']??'');
        if($id===''||isset($nodes[$id]))throw new InvalidArgumentException('Duplicate track routing ID.');
        $nodes[$id]=true;$edges[$id]=[];
    }
    foreach((array)($routing['buses']??[]) as $bus){
        $id=(string)($bus['id']??'');
        if(!preg_match('/^bus-[a-f0-9]{16}$/D',$id)||isset($nodes[$id]))
            throw new InvalidArgumentException('Invalid or duplicate auxiliary bus.');
        $nodes[$id]=true;$edges[$id]=[];
        if(strlen((string)($bus['name']??''))>80||
            trim((string)($bus['name']??''))==='')
            throw new InvalidArgumentException('Invalid bus name.');
        sf_stem_route_number($bus['volume']??1,0,4);
        sf_stem_route_number($bus['pan']??0,-1,1);
    }
    foreach($tracks as $track){
        $id=(string)$track['guid'];$parent=(string)($track['folder_parent_guid']??'');
        if($parent!==''&&!isset($nodes[$parent]))
            throw new InvalidArgumentException('Missing parent track.');
        if(!empty($track['main_send_enabled'])&&$parent!=='')$edges[$id][]=$parent;
    }
    $sendIds=[];
    foreach((array)($routing['sends']??[]) as $send){
        $id=(string)($send['id']??'');
        $src=(string)($send['source_guid']??'');
        $dst=(string)($send['target_guid']??'');
        if($id===''||isset($sendIds[$id])||!isset($nodes[$src],$nodes[$dst])||
           $src===$dst||!in_array($send['mode']??'', ['post-fader'],true))
            throw new InvalidArgumentException('Invalid auxiliary send.');
        $sendIds[$id]=true;
        sf_stem_route_number($send['gain']??1,0,4);
        if(($send['enabled']??true))$edges[$src][]=$dst;
    }
    $colors=[];
    $visit=static function(string $id)use(&$visit,&$colors,&$edges):void {
        if(($colors[$id]??0)===1)throw new InvalidArgumentException('Circular audio routing is forbidden.');
        if(($colors[$id]??0)===2)return;
        $colors[$id]=1;
        foreach($edges[$id] as $dest)$visit($dest);
        $colors[$id]=2;
    };
    foreach(array_keys($nodes) as $id)$visit($id);
}
function sf_stem_route_apply(array &$project,array $payload):void {
    $action=(string)($payload['action']??'');
    $routing=&$project['routing'];
    if(!is_array($routing??null))$routing=['version'=>'stonefellow.routing.v1','buses'=>[],'sends'=>[]];
    $routing['buses']??=[];$routing['sends']??=[];
    $id=(string)($payload['id']??'');
    if($action==='route_add_bus'){
        if(count($routing['buses'])>=32)throw new InvalidArgumentException('Auxiliary bus limit reached.');
        $name=trim((string)($payload['name']??'Aux Bus'));
        if($name===''||strlen($name)>80)throw new InvalidArgumentException('Invalid bus name.');
        $routing['buses'][]=['id'=>'bus-'.bin2hex(random_bytes(8)), 'name'=>$name,
            'volume'=>1.0,'pan'=>0.0,'mute'=>false,'solo'=>false,'fx'=>[]];
    }elseif($action==='route_update_bus'||$action==='route_delete_bus'){
        $idx=null;
        foreach($routing['buses'] as $i=>$bus)if(($bus['id']??'')===$id){$idx=$i;break;}
        if($idx===null)throw new InvalidArgumentException('Auxiliary bus not found.');
        if($action==='route_delete_bus'){
            foreach($routing['sends'] as $send)if(($send['target_guid']??'')===$id||
                ($send['source_guid']??'')===$id)
                throw new InvalidArgumentException('Remove connected sends before deleting bus.');
            array_splice($routing['buses'],$idx,1);
        }else{
            $name=trim((string)($payload['name']??$routing['buses'][$idx]['name']));
            if($name===''||strlen($name)>80)throw new InvalidArgumentException('Invalid bus name.');
            $routing['buses'][$idx]['name']=$name;
            foreach(['volume'=>[0,4],'pan'=>[-1,1]] as $key=>$bounds)
                if(array_key_exists($key,$payload))
                    $routing['buses'][$idx][$key]=sf_stem_route_number($payload[$key],$bounds[0],$bounds[1]);
            foreach(['mute','solo'] as $key)
                if(array_key_exists($key,$payload))$routing['buses'][$idx][$key]=($payload[$key]===true);
        }
    }elseif($action==='route_add_send'){
        if(count($routing['sends'])>=256)throw new InvalidArgumentException('Auxiliary send limit reached.');
        $routing['sends'][]=['id'=>'send-'.bin2hex(random_bytes(8)),
            'source_guid'=>(string)($payload['source_guid']??''),
            'target_guid'=>(string)($payload['target_guid']??''),
            'gain'=>sf_stem_route_number($payload['gain']??0.5,0,4),
            'mode'=>'post-fader','origin'=>'BROWSER','enabled'=>true];
    }elseif($action==='route_update_send'||$action==='route_delete_send'){
        $idx=null;
        foreach($routing['sends'] as $i=>$send)if(($send['id']??'')===$id){$idx=$i;break;}
        if($idx===null)throw new InvalidArgumentException('Auxiliary send not found.');
        if(($routing['sends'][$idx]['origin']??'')!=='BROWSER')
            throw new InvalidArgumentException('Imported REAPER sends are read-only.');
        if($action==='route_delete_send')array_splice($routing['sends'],$idx,1);
        else{
            if(array_key_exists('gain',$payload))
                $routing['sends'][$idx]['gain']=sf_stem_route_number($payload['gain'],0,4);
            if(array_key_exists('enabled',$payload))
                $routing['sends'][$idx]['enabled']=$payload['enabled']===true;
        }
    }else throw new InvalidArgumentException('Unsupported routing action.');
    sf_stem_route_validate($project);
}
