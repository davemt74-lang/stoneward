<?php
declare(strict_types=1);
require_once __DIR__.'/stem-release-readiness.php';

/**
 * 86K: private per-stem measurements. Sidecar storage avoids changing a mix
 * revision (and accidentally making a valid 86G mixdown stale).
 * These are browser-reported samples from raw source files, before effects.
 */
function sf_stem_intelligence_path(string $songId):string {
    sf_stem_project_track($songId);
    return sf_stem_project_dir().'/'.hash('sha256',$songId).'.stem-intelligence.json';
}
function sf_stem_intelligence_bounds(mixed $value,float $min,float $max,string $name):float {
    if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException($name.' must be numeric.');
    $v=(float)$value;
    if(!is_finite($v)||$v<$min||$v>$max)throw new InvalidArgumentException($name.' is out of range.');
    return round($v,4);
}
function sf_stem_intelligence_validate(string $songId,array $project,array $input):array {
    if(!is_int($input['revision']??null)||$input['revision']!==(int)$project['revision'])
        throw new RuntimeException('Project changed; rescan the current mix.');
    $rows=$input['tracks']??null;
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>24)
        throw new InvalidArgumentException('Submit 1–24 analyzed private stems.');
    $lookup=[];
    foreach((array)($project['tracks']??[]) as $track){
        $guid=(string)($track['guid']??'');
        if($guid!==''&&(!empty($track['asset_uuid']))&&(!empty($track['source_file'])))
            $lookup[$guid]=$track;
    }
    $parsed=[];$seen=[];
    $bands=['sub_bass','bass','low_mid','presence','high'];
    foreach($rows as $r){
        if(!is_array($r))throw new InvalidArgumentException('Invalid per-stem report.');
        $guid=(string)($r['guid']??'');
        if(!isset($lookup[$guid])||isset($seen[$guid]))
            throw new InvalidArgumentException('Stem is missing, duplicated or not linked in this private project.');
        $seen[$guid]=true;
        if(($r['source']??'')!=='browser_decoded_private_source')
            throw new InvalidArgumentException('Unsupported stem measurement origin.');
        $metrics=(array)($r['metrics']??[]);
        $safe=[];
        foreach([
            'sample_peak_dbfs'=>[-120.,6.],
            'rms_dbfs'=>[-120.,6.],
            'crest_db'=>[0.,126.],
            'stereo_imbalance_db'=>[0.,120.],
            'active_window_ratio'=>[0.,1.],
            'observed_seconds'=>[0.01,60.]
        ] as $key=>$bounds)
            $safe[$key]=sf_stem_intelligence_bounds($metrics[$key]??null,$bounds[0],$bounds[1],$key);
        if(!is_int($metrics['windows']??null)||$metrics['windows']<1||$metrics['windows']>16)
            throw new InvalidArgumentException('Invalid sample-window count.');
        $safe['windows']=$metrics['windows'];
        if(!is_int($metrics['sample_rate']??null)||$metrics['sample_rate']<8000||$metrics['sample_rate']>192000)
            throw new InvalidArgumentException('Invalid source sample rate.');
        $safe['sample_rate']=$metrics['sample_rate'];
        $share=(array)($metrics['bands_pct']??[]);
        if(count($share)!==5)throw new InvalidArgumentException('Five measured frequency bands are required.');
        $safe['bands_pct']=[];
        foreach($bands as $key)
            $safe['bands_pct'][$key]=sf_stem_intelligence_bounds($share[$key]??null,0.,100.,$key);
        $sum=array_sum($safe['bands_pct']);
        if(($sum>0&&abs($sum-100.)>2.0)||($sum===0.&&$safe['rms_dbfs']>-90.))
            throw new InvalidArgumentException('Spectral band-energy percentages are inconsistent.');
        $parsed[]=[
            'guid'=>$guid,'source_fingerprint'=>hash('sha256',(string)$lookup[$guid]['asset_uuid']),
            'metrics'=>$safe
        ];
    }
    return [
        'format'=>'stonefellow.stem-intelligence.v1','song_id'=>$songId,
        'project_revision'=>(int)$project['revision'],
        'audio_signature'=>sf_stem_86h_signature($project),
        'measurement_origin'=>'browser_decoded_private_source_not_server_verified',
        'fx_applied'=>false,'created_at'=>gmdate('c'),
        'tracks'=>$parsed
    ];
}
function sf_stem_intelligence_save(string $songId,array $input):array {
    $path=sf_stem_intelligence_path($songId);$lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock private stem evidence.');
    try{
        $project=sf_stem_project_read($songId);
        if(!$project)throw new InvalidArgumentException('Import a song mix before analyzing stems.');
        $row=sf_stem_intelligence_validate($songId,$project,$input);
        $raw=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($raw)>32768)throw new InvalidArgumentException('Stem measurements exceed the private record limit.');
        $again=sf_stem_project_read($songId);
        if(!$again||$again['revision']!==$project['revision']||
            !hash_equals(sf_stem_86h_signature($project),sf_stem_86h_signature($again)))
            throw new RuntimeException('Project changed during stem analysis; rescan.');
        $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($temp,$raw,LOCK_EX)===false)throw new RuntimeException('Cannot save private stem evidence.');
        @chmod($temp,0660);
        if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Cannot publish stem evidence.');}
        return $row;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_stem_intelligence_read(string $songId,array $project):array {
    $path=sf_stem_intelligence_path($songId);
    if(!is_file($path))return ['status'=>'not_analyzed','tracks'=>[]];
    $raw=@file_get_contents($path);
    if(!is_string($raw)||strlen($raw)>32768)throw new RuntimeException('Private stem evidence is invalid.');
    $row=json_decode($raw,true);
    if(!is_array($row)||($row['format']??'')!=='stonefellow.stem-intelligence.v1'||
       ($row['song_id']??'')!==$songId)throw new RuntimeException('Private stem evidence is invalid.');
    $fresh=(int)($row['project_revision']??-1)===(int)$project['revision']&&
        hash_equals((string)($row['audio_signature']??''),sf_stem_86h_signature($project));
    if($fresh){
        $current=[];
        foreach((array)$project['tracks'] as $t)if(!empty($t['asset_uuid']))
            $current[(string)($t['guid']??'')]=hash('sha256',(string)$t['asset_uuid']);
        foreach((array)($row['tracks']??[]) as $t)if(!isset($current[$t['guid']])||
            !hash_equals($current[$t['guid']],(string)$t['source_fingerprint'])){
            $fresh=false;break;
        }
    }
    $row['status']=$fresh?'recent':'stale_after_project_edit';
    return $row;
}
/** Clip positions are reference-only. No overlap means no masking claim. */
function sf_stem_intelligence_overlap(array $a,array $b):float {
    $best=0.;
    foreach((array)($a['clips']??[]) as $left){
        $la=(float)($left['position']??0);$le=$la+max(0.,(float)($left['length']??0));
        foreach((array)($b['clips']??[]) as $right){
            $ra=(float)($right['position']??0);$re=$ra+max(0.,(float)($right['length']??0));
            $inter=max(0.,min($le,$re)-max($la,$ra));
            $smaller=min($le-$la,$re-$ra);
            if($smaller>0)$best=max($best,$inter/$smaller);
        }
    }
    return round($best,3);
}
function sf_stem_intelligence_summary(string $songId,array $project):array {
    $evidence=sf_stem_intelligence_read($songId,$project);
    $state=(string)$evidence['status'];
    if($state!=='recent')return [
        'status'=>$state,'analyzed_stems'=>0,'stem_rows'=>[],'masking_candidates'=>[],
        'warnings'=>[$state==='not_analyzed'
            ?'Measure the decoded private stems in 86K before requesting stem-level production guidance.'
            :'Stem evidence predates the project revision or media assignments; rescan before using it.']
    ];
    $byGuid=[];$source=[];
    foreach((array)$project['tracks'] as $t)$source[(string)($t['guid']??'')]=$t;
    foreach((array)$evidence['tracks'] as $row)$byGuid[$row['guid']]=$row;
    $rows=[];$warnings=[];$pairs=[];
    foreach($byGuid as $guid=>$r){
        $m=$r['metrics'];$t=$source[$guid];
        $role=strtolower((string)($t['role']??''));
        $role=match(true){
            str_contains($role,'voc')=>'vocals',str_contains($role,'bass')=>'bass',
            str_contains($role,'drum')=>'drums',str_contains($role,'guitar')=>'guitar',
            str_contains($role,'key')=>'keys',default=>'unclassified'
        };
        $rows[]=['guid'=>$guid,'role'=>$role,'metrics'=>$m];
        if($m['sample_peak_dbfs']>-1.)$warnings[]='Stem '.$guid.' has sample peaks above -1 dBFS (raw source, not the final mix).';
        if($m['active_window_ratio']<.05)$warnings[]='Stem '.$guid.' is near silent in the sampled windows; verify clip offsets.';
        if($m['stereo_imbalance_db']>6)$warnings[]='Stem '.$guid.' has left/right RMS imbalance above 6 dB in the sampled audio.';
    }
    $keys=array_keys($byGuid);
    foreach($keys as $i=>$left){
        foreach(array_slice($keys,$i+1) as $right){
            $overlap=sf_stem_intelligence_overlap($source[$left],$source[$right]);
            if($overlap<.25)continue;
            $ma=$byGuid[$left]['metrics'];$mb=$byGuid[$right]['metrics'];
            if($ma['active_window_ratio']<.15||$mb['active_window_ratio']<.15)continue;
            foreach(['sub_bass','bass','low_mid','presence','high'] as $band){
                $la=$ma['bands_pct'][$band];$rb=$mb['bands_pct'][$band];
                if($la>=35&&$rb>=35){
                    $pairs[]=['left'=>$left,'right'=>$right,'band'=>$band,
                        'temporal_overlap_ratio'=>$overlap,
                        'source_band_energy_pct'=>[$la,$rb],
                        'interpretation'=>'Potential frequency-band competition; not proven audible masking. Compare solo/mix in REAPER.'];
                }
            }
        }
    }
    usort($pairs,static fn(array $a,array $b):int=>
        ($b['temporal_overlap_ratio']<=>$a['temporal_overlap_ratio']));
    return [
        'status'=>'recent','analyzed_stems'=>count($rows),'stem_rows'=>$rows,
        'masking_candidates'=>array_slice($pairs,0,12),
        'warnings'=>array_slice($warnings,0,24),
        'measurement_provenance'=>'browser_decoded_private_source_not_server_verified',
        'fx_applied'=>false,'not_listening_certified'=>true,
        'project_revision'=>$evidence['project_revision']
    ];
}
function sf_stem_intelligence_agent_brief(array $summary):array {
    if($summary['status']!=='recent')return [
        'status'=>$summary['status'],'warnings'=>$summary['warnings']??[]];
    $numbered=[];$map=[];
    foreach((array)$summary['stem_rows'] as $i=>$row){
        $index=$i+1;$map[$row['guid']]=$index;
        $m=$row['metrics'];
        $numbered[]=['stem_number'=>$index,'role'=>$row['role'],
          'peak_dbfs'=>$m['sample_peak_dbfs'],'rms_dbfs'=>$m['rms_dbfs'],
          'crest_db'=>$m['crest_db'],'active_window_ratio'=>$m['active_window_ratio'],
          'bands_pct'=>$m['bands_pct']];
    }
    return [
        'status'=>'recent','analyzed_stems'=>count($numbered),
        'stems'=>$numbered,
        'masking_candidates'=>array_map(static fn(array $p):array=>[
            'stem_numbers'=>[$map[$p['left']],$map[$p['right']]],
            'band'=>$p['band'],'temporal_overlap_ratio'=>$p['temporal_overlap_ratio'],
            'interpretation'=>'Possible shared spectral energy; not proven masking'
        ],$summary['masking_candidates']),
        'provenance'=>'browser_measured_raw_source_no_fx_not_verified',
        'limitations'=>'No actual native REAPER DSP, instrument recognition, AI hearing or streaming target guarantee'
    ];
}
