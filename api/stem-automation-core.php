<?php
declare(strict_types=1);
/**
 * 86F non-destructive automation overlays. Only verified track VOLENV2/PANENV2
 * point formats are imported. No native plugin bytes, mute polarity or
 * unsupported interpolation shapes are guessed.
 */
function sf_stem_automation_bounds(array $project,string $scope,string $guid,string $parameter,?int $fxIndex=null,string $fxParameter=''):array {
    if(!in_array($scope,['track','bus','master'],true))throw new InvalidArgumentException('Unknown automation scope.');
    $fx=[];
    if($scope==='master'){
        if($guid!=='__master__')throw new InvalidArgumentException('Master automation target is invalid.');
        $fx=(array)($project['master_fx']??[]);
    }elseif($scope==='bus'){
        $found=false;
        foreach((array)($project['routing']['buses']??[]) as $bus)if(($bus['id']??'')===$guid){
            $fx=(array)($bus['fx']??[]);$found=true;break;
        }
        if(!$found)throw new InvalidArgumentException('Automation bus no longer exists.');
    }else{
        $found=false;
        foreach((array)($project['tracks']??[]) as $track)if(($track['guid']??'')===$guid){
            $fx=(array)($track['fx']??[]);$found=true;break;
        }
        if(!$found)throw new InvalidArgumentException('Automation track no longer exists.');
    }
    if($parameter==='volume')return [0.,4.];
    if($parameter==='pan')return [-1.,1.];
    if($parameter==='mute')return [0.,1.];
    if($parameter!=='fx'||$fxIndex===null||$fxIndex<0||$fxIndex>=count($fx))
        throw new InvalidArgumentException('Unknown automation parameter.');
    $effect=$fx[$fxIndex];
    // Browser processing is an explicit approximation; never pretend opaque VST
    // state has been natively decoded.
    $type=(string)($effect['type']??'unsupported');
    $bounds=sf_stem_fx_bounds($type);
    if(!isset($bounds[$fxParameter]))throw new InvalidArgumentException('Effect parameter is not browser-mapped.');
    return array_map('floatval',$bounds[$fxParameter]);
}
function sf_stem_automation_validate_lane(array $project,array $raw):array {
    $scope=(string)($raw['scope']??'');
    $guid=(string)($raw['guid']??'');
    $parameter=(string)($raw['parameter']??'');
    $fxIndex=$parameter==='fx'?($raw['fx_index']??null):null;
    if($fxIndex!==null&&!is_int($fxIndex))throw new InvalidArgumentException('FX index must be integer.');
    $fxParameter=$parameter==='fx'?(string)($raw['fx_parameter']??''):'';
    if(strlen($guid)>100||strlen($fxParameter)>32)throw new InvalidArgumentException('Automation identifier exceeds limit.');
    [$lo,$hi]=sf_stem_automation_bounds($project,$scope,$guid,$parameter,$fxIndex,$fxParameter);
    $points=$raw['points']??null;
    if(!is_array($points)||!array_is_list($points)||count($points)<1||count($points)>256)
        throw new InvalidArgumentException('Automation requires 1–256 ordered points.');
    $verified=[];$last=-1;
    foreach($points as $p){
        if(!is_array($p))throw new InvalidArgumentException('Invalid automation point.');
        $time=sf_stem_numeric($p['time']??null,0,86400,'automation time');
        $value=sf_stem_numeric($p['value']??null,$lo,$hi,'automation value');
        $shape=(string)($p['shape']??($parameter==='mute'?'square':'linear'));
        if(!in_array($shape,['linear','square'],true)||($parameter==='mute'&&$shape!=='square'))
            throw new InvalidArgumentException('Unsupported automation interpolation.');
        if($time<=$last)throw new InvalidArgumentException('Automation points must be strictly increasing.');
        if($parameter==='mute'&&!in_array($value,[0.,1.],true))
            throw new InvalidArgumentException('Mute automation must be binary.');
        $verified[]=['time'=>$time,'value'=>$value,'shape'=>$shape];$last=$time;
    }
    $id=(string)($raw['id']??'');
    if($id!==''&&!preg_match('/^(?:lane|rpp)-[A-Za-z0-9-]{1,80}$/D',$id))
        throw new InvalidArgumentException('Invalid automation lane identifier.');
    return ['id'=>$id?:'lane-'.bin2hex(random_bytes(8)),
        'scope'=>$scope,'guid'=>$guid,'parameter'=>$parameter,
        'fx_index'=>$fxIndex,'fx_parameter'=>$fxParameter,
        'enabled'=>($raw['enabled']??true)===true,
        'source'=>'BROWSER','points'=>$verified];
}
function sf_stem_automation_lane_key(array $lane):string {
    return implode('|',[$lane['scope']??'',$lane['guid']??'',$lane['parameter']??'',
       (string)($lane['fx_index']??''),$lane['fx_parameter']??'']);
}
function sf_stem_automation_apply(array &$project,array $payload):void {
    $action=(string)($payload['action']??'');
    $project['automation']??=[];
    if(!is_array($project['automation'])||count($project['automation'])>256)
        throw new InvalidArgumentException('Automation capacity exceeded.');
    if($action==='automation_delete_lane'){
        $id=(string)($payload['id']??'');$found=false;
        foreach($project['automation'] as $i=>$lane)if(($lane['id']??'')===$id){
            if(($lane['source']??'')!=='BROWSER')
                throw new InvalidArgumentException('Native source envelope cannot be removed.');
            array_splice($project['automation'],$i,1);$found=true;break;
        }
        if(!$found)throw new InvalidArgumentException('Automation lane was not found.');
        return;
    }
    if($action!=='automation_set_lane')throw new InvalidArgumentException('Unknown automation action.');
    $data=$payload['lane']??null;
    if(!is_array($data))throw new InvalidArgumentException('Expected automation lane.');
    $lane=sf_stem_automation_validate_lane($project,$data);
    $key=sf_stem_automation_lane_key($lane);
    $index=null;
    foreach($project['automation'] as $i=>$old){
        if(($old['source']??'')==='BROWSER'&&sf_stem_automation_lane_key($old)===$key){
            $index=$i;break;
        }
    }
    if($index!==null){
        $lane['id']=$project['automation'][$index]['id'];
        $project['automation'][$index]=$lane;
    }else{
        if(count($project['automation'])>=256)throw new InvalidArgumentException('Automation lane limit reached.');
        $project['automation'][]=$lane;
    }
}
/**
 * A lane's interpolation shape belongs to its starting point; square steps
 * hold the earlier value. JS evaluation must mirror this exactly.
 */
function sf_stem_automation_value(array $points,float $time):?float {
    if(!$points)return null;
    $prev=$points[0];
    if($time<=(float)$prev['time'])return (float)$prev['value'];
    foreach(array_slice($points,1) as $next){
        if($time<=(float)$next['time']){
            if(($prev['shape']??'')==='square')return (float)$prev['value'];
            $span=(float)$next['time']-(float)$prev['time'];
            return $span<=0?(float)$next['value']:(float)$prev['value']+
                (((float)$next['value']-(float)$prev['value'])*(($time-(float)$prev['time'])/$span));
        }
        $prev=$next;
    }
    return (float)$prev['value'];
}
/** Read only top-level native TRACK envelope blocks; preserve other forms. */
function sf_stem_automation_import(array $project,string $source):array {
    $lanes=[];$report=['unsupported_envelopes'=>0,'unsupported_points'=>0,'imported_lanes'=>0];
    $trackIndex=-1;$env=null;$kind=null;$act=true;
    $finish=static function()use(&$lanes,&$env,&$kind,&$act,&$trackIndex,&$report,$project):void{
        if($env===null)return;
        if($act&&count($env)>0&&$trackIndex>=0&&isset($project['tracks'][$trackIndex])){
            $parameter=['VOLENV2'=>'volume','PANENV2'=>'pan'][$kind]??null;
            if($parameter!==null){
                $candidate=['id'=>'rpp-'.($trackIndex).'-'.$parameter,
                  'scope'=>'track','guid'=>$project['tracks'][$trackIndex]['guid'],
                  'parameter'=>$parameter,'enabled'=>true,'points'=>$env];
                try{$valid=sf_stem_automation_validate_lane($project,$candidate);
                    $valid['source']='REAPER';$lanes[]=$valid;$report['imported_lanes']++;}
                catch(InvalidArgumentException){$report['unsupported_envelopes']++;}
            }
        }
        $env=null;$kind=null;
    };
    foreach(preg_split('/\r\n|\r|\n/',$source)?:[] as $line){
        if(preg_match('/^  <TRACK(?:\s|$)/',$line)){
            $finish();$trackIndex++;continue;
        }
        if($trackIndex<0||!isset($project['tracks'][$trackIndex]))continue;
        if(preg_match('/^    <(VOLENV2|PANENV2|MUTEENV|VOLENV|PANENV|PARMENV)(?:\s|$)/',$line,$m)){
            $finish();$kind=$m[1];$env=[];$act=true;
            if(!in_array($kind,['VOLENV2','PANENV2'],true)){
                $report['unsupported_envelopes']++;$env=null;$kind=null;
            }
            continue;
        }
        if($env===null)continue;
        if(preg_match('/^    >\s*$/',$line)){$finish();continue;}
        if(preg_match('/^      ACT\s+([01])(?:\s|$)/',$line,$m)){
            $act=$m[1]==='1';continue;
        }
        if(preg_match('/^      PT\s+(\S+)\s+(\S+)\s+(\d+)(?:\s|$)/',$line,$m)){
            if(count($env)>=256){$report['unsupported_points']++;continue;}
            $shape=$m[3]==='0'?'linear':($m[3]==='1'?'square':null);
            if($shape===null||!is_numeric($m[1])||!is_numeric($m[2])||
                !is_finite((float)$m[1])||!is_finite((float)$m[2])){
                $report['unsupported_points']++;$env=[];$act=false;continue;
            }
            $env[]=['time'=>(float)$m[1],'value'=>(float)$m[2],'shape'=>$shape];
        }
    }
    $finish();
    $project['automation']=$lanes;
    $project['automation_import']=$report;
    return $project;
}
function sf_stem_automation_reimport(array &$fresh,?array $old):void {
    if(!$old)return;
    foreach((array)($old['automation']??[]) as $lane){
        if(!is_array($lane)||($lane['source']??'')!=='BROWSER')continue;
        try{
            $valid=sf_stem_automation_validate_lane($fresh,$lane);
            if(count((array)($fresh['automation']??[]))>=256)break;
            $valid['id']=$lane['id'];
            $fresh['automation'][]=$valid;
        }catch(InvalidArgumentException){$fresh['automation_import']['orphaned_browser_lanes']=
            (int)($fresh['automation_import']['orphaned_browser_lanes']??0)+1;}
    }
}
