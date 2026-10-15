<?php
declare(strict_types=1);
/**
 * 86C: lossless REAPER FX identification and provenance, not lossless native DSP.
 * Original .rpp text including opaque VST chunks remains stored unmodified.
 * This parser records the exact FX chain order, GUID, preset, bypass and hashes.
 */
function sf_stem_rpp_plugin_identity(string $kind,string $header):array {
    if($kind==='JS'){
        preg_match('/^([^\\s"]{1,200})/',$header,$m);
        $module=$m[1]??'unknown';
        return ['name'=>substr($module,0,180),'module'=>$module,'vendor'=>'REAPER JSFX'];
    }
    if(preg_match('/^"([^"]{1,200})"\\s+([^\\s"]+)/',$header,$m)){
        $name=$m[1];$module=$m[2];
    }else{
        $name=trim(explode(' ',$header)[0]??'Unknown');
        $module='';
    }
    preg_match('/\\(([^()]{1,80})\\)/',$name,$vendor);
    return ['name'=>substr($name,0,180),'module'=>substr($module,0,200),
        'vendor'=>substr($vendor[1]??'',0,80)];
}
function sf_stem_rpp_plugin_metadata(string $text):array {
    $lines=preg_split('/\\r\\n|\\r|\\n/',$text);
    if(!is_array($lines)||count($lines)>160000||strlen($text)>8388608)
        throw new InvalidArgumentException('Invalid REAPER plugin source.');
    $master=[];$tracks=[];$trackGuid='';$scope='';
    $pendingBypass=0;$current=null;$state=[];$depth=0;$pluginIndent=0;
    $commit=static function()use(&$current,&$master,&$tracks,&$scope,&$trackGuid):void{
        if($current===null)return;
        if($scope==='master'){$current['slot']=count($master);$master[]=$current;}
        elseif($scope==='track'&&$trackGuid!==''){
            $tracks[$trackGuid]??=[];
            $current['slot']=count($tracks[$trackGuid]);
            $tracks[$trackGuid][]=$current;
        }
        $current=null;
    };
    foreach($lines as $line){
        if(preg_match('/^  <TRACK\\s+(\\{[^}]{1,80}\\})/',$line,$m)){
            $commit();$trackGuid=$m[1];$scope='';
        }
        if(preg_match('/^  <MASTERFXLIST(?:\\s|$)/',$line)){
            $commit();$scope='master';$pendingBypass=0;continue;
        }
        if(preg_match('/^    <FXCHAIN(?:\\s|$)/',$line)){
            $commit();$scope='track';$pendingBypass=0;continue;
        }
        if($scope==='')continue;
        if($current!==null&&$depth>0){
            if(preg_match('/^(\\s*)>\\s*$/',$line,$m)&&strlen($m[1])===$pluginIndent){
                $depth=0;
                $current['native_state_sha256']=hash('sha256',implode("\n",$state));
                $current['native_state_lines']=count($state);
                $current['native_state_bytes']=strlen(implode("\n",$state));
                if($current['format']==='JS'&&isset($state[0])){
                    $tokens=preg_split('/\\s+/',trim($state[0]))?:[];
                    $current['js_slider_tokens']=array_slice($tokens,0,128);
                    $current['parameter_provenance']='exact-js-slider-tokens';
                }
                $state=[];
                continue;
            }
            if(count($state)<4000)$state[]=trim($line);
            continue;
        }
        if(preg_match('/^(\\s*)>\\s*$/',$line,$m)){
            $indent=strlen($m[1]);
            if(($scope==='master'&&$indent===2)||($scope==='track'&&$indent===4)){
                $commit();$scope='';continue;
            }
        }
        if(preg_match('/^\\s*BYPASS\\s+([01])(?:\\s|$)/',$line,$m)){
            $commit();$pendingBypass=(int)$m[1];continue;
        }
        if(preg_match('/^(\\s*)<(VST|JS|CLAP|AU|DX)\\s+(.+)$/',$line,$m)){
            $commit();
            $pluginIndent=strlen($m[1]);$depth=1;$state=[];
            $format=$m[2];$identity=sf_stem_rpp_plugin_identity($format,$m[3]);
            $fx=sf_stem_rpp_fx($identity['name']);
            $current=array_merge($fx,[
                'name'=>$identity['name'],
                'format'=>$format,'module'=>$identity['module'],'vendor'=>$identity['vendor'],
                'preset_name'=>'','fx_guid'=>'','slot'=>0,
                'source_enabled'=>$pendingBypass===0,
                'enabled'=>$pendingBypass===0,
                'source_bypass'=>$pendingBypass,
                'native_state_sha256'=>'',
                'native_state_bytes'=>0,'native_state_lines'=>0,
                'parameter_provenance'=>'native-opaque-state-preserved',
                'browser_settings_origin'=>'default-not-decoded',
                'chain'=>$scope,
            ]);
            continue;
        }
        if($current===null)continue;
        if(preg_match('/^\\s*PRESETNAME\\s+"([^"]{0,255})"/',$line,$m))
            $current['preset_name']=$m[1];
        if(preg_match('/^\\s*FXID\\s+(\\{[^}]{1,80}\\})/',$line,$m))
            $current['fx_guid']=$m[1];
    }
    $commit();
    return ['master_fx'=>$master,'track_fx_by_guid'=>$tracks,
        'plugin_count'=>count($master)+array_sum(array_map('count',$tracks))];
}
function sf_stem_rpp_enrich_plugins(array $project,string $source):array {
    $native=sf_stem_rpp_plugin_metadata($source);
    $project['master_fx']=$native['master_fx'];
    foreach($project['tracks'] as &$track){
        $guid=(string)($track['guid']??'');
        $track['fx']=$native['track_fx_by_guid'][$guid]??[];
    }unset($track);
    $project['plugin_import']=[
        'version'=>'stonefellow.reaper-fx.v1',
        'plugin_count'=>$native['plugin_count'],
        'native_source_sha256'=>hash('sha256',$source),
        'status'=>'native-state-intact-browser-dsp-approximate',
    ];
    return $project;
}
