<?php
declare(strict_types=1);
/**
 * 86D: version-gated *verified* native-state inspection.
 *
 * An RPP stores JSFX slider values as plain tokens but VST payloads as
 * opaque, plugin-dependent base64. This module NEVER guesses binary knob
 * offsets. Only loser/masterLimiter 2006 slider definitions are proven,
 * using the original script's numbered slider declarations.
 */
function sf_stem_decode_loser_masterlimiter(string $module,array $lines):array {
    if(strtolower($module)!=='loser/masterlimiter')
        return ['status'=>'unverified-jsfx-module','confidence'=>'none'];
    $tokens=preg_split('/\\s+/',trim((string)($lines[0]??'')))?:[];
    $schema=[
      'threshold_db'=>[-20.,-.1],
      'lookahead_ns'=>[0.,1000.],
      'attack_ns'=>[0.,1000.],
      'hold_ms'=>[0.,10.],
      'release_ms'=>[0.,1000.],
      'ceiling_db'=>[-6.,0.],
    ];
    if(count($tokens)<7)
        return ['status'=>'unsupported-jsfx-slider-count','confidence'=>'none'];
    $values=[];$i=0;
    foreach($schema as $key=>[$low,$high]){
        $token=$tokens[$i++];
        if(!preg_match('/^[+-]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)$/D',$token))
            return ['status'=>'invalid-jsfx-slider-token','confidence'=>'none'];
        $value=(float)$token;
        if(!is_finite($value)||$value<$low||$value>$high)
            return ['status'=>'jsfx-slider-out-of-range','confidence'=>'none'];
        $values[$key]=$value;
    }
    // Slider 7 is the dynamic REDUCTION meter; never treat as a saved knob.
    $meter=$tokens[6];
    if(!preg_match('/^[+-]?(?:\\d+(?:\\.\\d*)?|\\.\\d+)$/D',$meter)
        ||!is_finite((float)$meter))
        return ['status'=>'invalid-jsfx-meter','confidence'=>'none'];

    return [
      'status'=>'verified-source-slider-schema',
      'confidence'=>'exact-native-slider-values',
      'schema_id'=>'reaper.jsfx.loser-masterLimiter.2006.v1',
      'schema_reference'=>'https://raw.githubusercontent.com/Samelot/Reaper/master/Effects/LOSER/masterLimiter',
      'native_parameters'=>$values,
      'parameter_units'=>[
        'threshold_db'=>'dB','lookahead_ns'=>'ns','attack_ns'=>'ns',
        'hold_ms'=>'ms','release_ms'=>'ms','ceiling_db'=>'dB'
      ],
      // Browser DSP is not the REAPER JSFX algorithm. It has no lookahead
      // or hold knobs; never silently misrepresent those as supported.
      'browser_mapped_parameters'=>[
        'threshold'=>$values['threshold_db'],
        'ceiling'=>$values['ceiling_db'],
        'attack'=>$values['attack_ns']/1000000.,
        'release'=>$values['release_ms']/1000.,
      ],
      'browser_unmapped_parameters'=>[
        'lookahead_ns'=>$values['lookahead_ns'],
        'hold_ms'=>$values['hold_ms'],
      ]
    ];
}
/**
 * Strictly inspect the VST container header. A valid FourCC/version confirms
 * plugin identity only; it is NOT evidence of decoded effect parameters.
 */
function sf_stem_inspect_reaplugs_state(string $module,array $lines):array {
    $modules=[
        'reaeq.dll'=>['qeer',2],
        'reacomp.dll'=>['mcer',4],
        'reaverb.dll'=>['bver',2],
        'readelay.dll'=>['lder',2],
    ];
    $name=strtolower(basename(str_replace('\\\\','/',$module)));
    if(!isset($modules[$name]))
        return ['status'=>'unsupported-native-vst','confidence'=>'none'];
    $state=array_values(array_filter(array_map('trim',$lines),'strlen'));
    if(count($state)<2||count($state)>3000)
        return ['status'=>'unsupported-vst-container-shape','confidence'=>'none'];
    // RPP VST state is line-wrapped base64; the first line is its
    // ReaPlugs header and the following lines are opaque state blocks.
    $first=$state[0];
    if(!preg_match('/^[A-Za-z0-9+\\/]+={0,2}$/D',$first))
        return ['status'=>'invalid-vst-base64','confidence'=>'none'];
    $binary=base64_decode($first,true);
    if($binary===false||strlen($binary)<12)
        return ['status'=>'invalid-vst-header','confidence'=>'none'];
    $fourcc=substr($binary,0,4);
    $version=(int)unpack('V',substr($binary,8,4))[1];
    if($fourcc!==$modules[$name][0]||$version!==$modules[$name][1]
       ||substr($binary,4,4)!=="\\xee\\x5e\\xed\\xfe")
        return ['status'=>'unknown-vst-container-version','confidence'=>'none',
            'observed_fourcc'=>bin2hex($fourcc),'observed_version'=>$version];
    $total=0;$hashContext=hash_init('sha256');
    foreach($state as $line){
        if(!preg_match('/^[A-Za-z0-9+\\/]+={0,2}$/D',$line))
            return ['status'=>'invalid-vst-base64','confidence'=>'none'];
        $bytes=base64_decode($line,true);
        if($bytes===false)return ['status'=>'invalid-vst-base64','confidence'=>'none'];
        $total+=strlen($bytes);
        if($total>1048576)return ['status'=>'vst-state-too-large','confidence'=>'none'];
        hash_update($hashContext,$bytes);
    }
    return [
      'status'=>'vst-container-verified-parameters-opaque',
      'confidence'=>'container-only',
      'schema_id'=>'reaper.vst-container.reaplugs-observed.v1',
      'container'=>[
        'plugin_fourcc'=>$fourcc,'container_version'=>$version,
        'binary_sha256'=>hash_final($hashContext),
        'binary_bytes'=>$total,
        'lines'=>count($state)
      ],
      'native_parameters'=>[],
      'browser_mapped_parameters'=>[]
    ];
}
function sf_stem_native_decode(string $format,string $module,array $state):array {
    if($format==='JS')return sf_stem_decode_loser_masterlimiter($module,$state);
    if($format==='VST')return sf_stem_inspect_reaplugs_state($module,$state);
    return ['status'=>'unsupported-native-format','confidence'=>'none'];
}
function sf_stem_native_enrich_fx(array &$fx,array $state):void {
    $result=sf_stem_native_decode((string)($fx['format']??''),
        (string)($fx['module']??''),$state);
    $fx['native_decode']=$result;
    if(($result['status']??'')==='verified-source-slider-schema'){
        // Current import can initialize the browser approximation only when
        // an exact source slider schema is proven.
        $fx['native_parameters']=$result['native_parameters'];
        $fx['settings']=$result['browser_mapped_parameters'];
        $fx['browser_settings_origin']='verified-jsfx-slider-translation';
        $fx['parameter_provenance']='exact-jsfx-known-schema-browser-approximate';
    }
}
/**
 * Re-decode an already-imported project without re-uploading source audio,
 * changing FX order, clearing history or overwriting deliberate user edits.
 */
function sf_stem_native_refresh(array &$project,string $raw):array {
    if(($project['source_sha256']??'')!==hash('sha256',$raw))
        throw new RuntimeException('Original REAPER source checksum changed.');
    $fresh=sf_stem_rpp_plugin_metadata($raw);
    $stats=['total'=>0,'exact_jsfx'=>0,'verified_container_only'=>0,'unverified'=>0];
    $apply=static function(array &$chain,array $decoded)use(&$stats):void {
        $byGuid=[];
        foreach($decoded as $p)if(($p['fx_guid']??'')!=='')
            $byGuid[$p['fx_guid']]=$p;
        foreach($chain as &$p){
            if(($p['format']??'')==='BROWSER')continue;
            $stats['total']++;
            $new=$byGuid[$p['fx_guid']??'']??null;
            if(!is_array($new)||($new['module']??'')!==($p['module']??'')
               ||($new['native_state_sha256']??'')!==($p['native_state_sha256']??'')){
                $stats['unverified']++;continue;
            }
            // Only assign state-inspection fields, never media, plugin order,
            // saved user browser controls, native original metadata, or GUID.
            $p['native_decode']=$new['native_decode'];
            if(isset($new['native_parameters']))
                $p['native_parameters']=$new['native_parameters'];
            $status=$new['native_decode']['status']??'';
            if($status==='verified-source-slider-schema'){
                $stats['exact_jsfx']++;
                if(($p['browser_settings_origin']??'')!=='user-override'){
                    $p['settings']=$new['settings'];
                    $p['browser_settings_origin']='verified-jsfx-slider-translation';
                }
                $p['parameter_provenance']='exact-jsfx-known-schema-browser-approximate';
            }elseif($status==='vst-container-verified-parameters-opaque'){
                $stats['verified_container_only']++;
            }else $stats['unverified']++;
        }unset($p);
    };
    $apply($project['master_fx'],$fresh['master_fx']);
    foreach($project['tracks'] as &$track){
        $guid=(string)($track['guid']??'');
        $apply($track['fx'],$fresh['track_fx_by_guid'][$guid]??[]);
    }unset($track);
    $project['native_decode_refresh']=[
      'schema'=>'stonefellow.native-state.v1','counts'=>$stats,'at'=>gmdate('c')
    ];
    return $stats;
}
