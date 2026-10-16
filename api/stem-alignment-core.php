<?php
declare(strict_types=1);
require_once __DIR__.'/stem-intelligence-core.php';

/**
 * 86L: project-time-aligned, source-audio measurements. Raw private audio
 * NEVER enters this sidecar, any provider, or the customer Agent. Every row
 * is bounded, tied to a linked source, an actual clip and a saved mix hash.
 */
function sf_stem_alignment_path(string $id):string {
    sf_stem_project_track($id);
    return sf_stem_project_dir().'/'.hash('sha256',$id).'.stem-alignment.json';
}
function sf_stem_alignment_clip_at(array $track,float $time):bool {
    foreach((array)($track['clips']??[]) as $clip){
        if(strtolower((string)($clip['file']??''))!==strtolower((string)($track['source_file']??'')))continue;
        $start=(float)($clip['position']??0);$length=(float)($clip['length']??0);
        if($time+0.0001>=$start&&$time<$start+$length-0.0001)return true;
    }
    return false;
}
function sf_stem_alignment_validate(string $song,array $project,array $input):array {
    if(!is_int($input['revision']??null)||$input['revision']!==(int)$project['revision'])
        throw new RuntimeException('Project was edited. Re-analyze the current revision.');
    $mode=$input['mode']??'';
    if(!in_array($mode,['overview','focus'],true))
        throw new InvalidArgumentException('Choose overview or focused time-aligned analysis.');
    $step=sf_stem_intelligence_bounds($input['step_seconds']??null,.25,10,'analysis step');
    if($mode==='focus'&&$step>1.0)throw new InvalidArgumentException('Focused scan must use subsecond/one-second resolution.');
    $start=sf_stem_intelligence_bounds($input['start_seconds']??null,0,86400,'start');
    $end=sf_stem_intelligence_bounds($input['end_seconds']??null,0,86400,'end');
    if($end<=$start||$end-$start>($mode==='focus'?24:480))
        throw new InvalidArgumentException('Invalid analysis time range.');
    $rows=$input['tracks']??null;
    if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>16)
        throw new InvalidArgumentException('Analyze 1–16 linked private source tracks.');
    $allowed=[];
    foreach((array)$project['tracks'] as $t)if(!empty($t['asset_uuid'])&&!empty($t['source_file'])&&isset($t['guid']))
        $allowed[(string)$t['guid']]=$t;
    $seen=[];$clean=[];$totalPoints=0;
    foreach($rows as $row){
        if(!is_array($row))throw new InvalidArgumentException('Invalid track analysis.');
        $guid=(string)($row['guid']??'');
        if(!isset($allowed[$guid])||isset($seen[$guid]))
            throw new InvalidArgumentException('Unknown, duplicated or unlinked source track.');
        $seen[$guid]=true;
        if(($row['origin']??'')!=='browser_raw_private_clip')
            throw new InvalidArgumentException('Claimed source-audio provenance is unsupported.');
        $points=$row['points']??null;
        if(!is_array($points)||!array_is_list($points)||count($points)<1||count($points)>120)
            throw new InvalidArgumentException('Each analyzed track needs 1–120 timed points.');
        $out=[];$last=-1.;
        foreach($points as $point){
            if(!is_array($point))throw new InvalidArgumentException('Invalid timed measurement.');
            $time=sf_stem_intelligence_bounds($point['time']??null,$start,$end,'timeline time');
            if($time<=$last||!sf_stem_alignment_clip_at($allowed[$guid],$time))
                throw new InvalidArgumentException('Measurements must be ordered and inside real linked clips.');
            // Points must live on a shared analysis grid; fabricated different
            // time axes can never be used as direct masking comparisons.
            $n=round(($time-$start)/$step);
            if(abs($time-($start+$n*$step))>0.011)
                throw new InvalidArgumentException('Point is not on the requested shared time grid.');
            $last=$time;
            $rms=sf_stem_intelligence_bounds($point['rms_dbfs']??null,-120,12,'sample RMS');
            $bands=$point['bands_dbfs']??null;
            if(!is_array($bands)||count($bands)!==5)
                throw new InvalidArgumentException('Five absolute frequency-energy bands required.');
            $mapped=[];
            foreach(['sub_bass','bass','low_mid','presence','high'] as $band)
                $mapped[$band]=sf_stem_intelligence_bounds($bands[$band]??null,-120,12,$band);
            $out[]=['time'=>$time,'rms_dbfs'=>$rms,'bands_dbfs'=>$mapped];
        }
        $totalPoints+=count($out);
        $clean[]=['guid'=>$guid,'fingerprint'=>hash('sha256',(string)$allowed[$guid]['asset_uuid']),
            'points'=>$out];
    }
    if($totalPoints>1440)throw new InvalidArgumentException('Too many analysis points.');
    return ['format'=>'stonefellow.stem-alignment.v1','song_id'=>$song,
        'project_revision'=>$project['revision'],'signature'=>sf_stem_86h_signature($project),
        'mode'=>$mode,'start_seconds'=>$start,'end_seconds'=>$end,'step_seconds'=>$step,
        'provenance'=>'browser_measured_raw_clip_pre_fx_not_verified',
        'created_at'=>gmdate('c'),'tracks'=>$clean];
}
function sf_stem_alignment_save(string $song,array $input):array {
    $path=sf_stem_alignment_path($song);
    $lock=@fopen($path.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Private analysis lock unavailable.');
    try{
        $p=sf_stem_project_read($song);
        if(!$p)throw new InvalidArgumentException('Private Stem Studio project not found.');
        $record=sf_stem_alignment_validate($song,$p,$input);
        $again=sf_stem_project_read($song);
        if(!$again||$again['revision']!==$p['revision']||
            !hash_equals(sf_stem_86h_signature($p),sf_stem_86h_signature($again)))
            throw new RuntimeException('Project changed during time-aligned scan.');
        $all=['format'=>'stonefellow.stem-alignment-history.v1','song_id'=>$song,'scans'=>[]];
        if(is_file($path)){
            $previous=json_decode((string)file_get_contents($path),true);
            if(is_array($previous)&&($previous['format']??'')===$all['format']&&
                ($previous['song_id']??'')===$song)
                $all['scans']=array_slice((array)($previous['scans']??[]),-2);
        }
        $all['scans'][]=$record;
        $serialized=json_encode($all,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if(strlen($serialized)>270000)throw new InvalidArgumentException('Private time-aligned report too large.');
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if(file_put_contents($tmp,$serialized,LOCK_EX)===false)throw new RuntimeException('Cannot save source report.');
        @chmod($tmp,0660);
        if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Cannot commit source report.');}
        return $record;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function sf_stem_alignment_read(string $song,array $project):array {
    $path=sf_stem_alignment_path($song);
    if(!is_file($path))return ['status'=>'not_analyzed','scans'=>[]];
    $raw=@file_get_contents($path);
    if(!is_string($raw)||strlen($raw)>270000)throw new RuntimeException('Invalid time-aligned evidence.');
    $history=json_decode($raw,true);
    if(!is_array($history)||($history['format']??'')!=='stonefellow.stem-alignment-history.v1'||
        ($history['song_id']??'')!==$song)
        throw new RuntimeException('Invalid private analysis history.');
    $current=[];
    foreach((array)$project['tracks'] as $track)if(!empty($track['asset_uuid']))
        $current[(string)($track['guid']??'')]=hash('sha256',(string)$track['asset_uuid']);
    $scans=[];
    foreach(array_slice((array)($history['scans']??[]),-3) as $scan){
        if(!is_array($scan))continue;
        $fresh=($scan['project_revision']??null)===$project['revision']&&
            hash_equals((string)($scan['signature']??''),sf_stem_86h_signature($project));
        foreach((array)($scan['tracks']??[]) as $track){
            $g=(string)($track['guid']??'');
            if(!isset($current[$g])||!hash_equals($current[$g],(string)($track['fingerprint']??''))){
                $fresh=false;break;
            }
        }
        $scan['status']=$fresh?'recent':'stale_after_project_edit';
        $scans[]=$scan;
    }
    return ['status'=>in_array('recent',array_column($scans,'status'),true)
        ?'recent':'stale_after_project_edit','scans'=>$scans];
}
/** A masking candidate requires actual simultaneous sampled PCM. */
function sf_stem_alignment_findings(array $scan,int $limit=24):array {
    if(($scan['status']??'')!=='recent')return [];
    $grid=[];
    foreach((array)($scan['tracks']??[]) as $track){
        foreach((array)($track['points']??[]) as $point){
            $key=(string)round((float)$point['time'],3);
            $grid[$key][]=['guid'=>$track['guid'],'p'=>$point];
        }
    }
    $events=[];
    foreach($grid as $time=>$rows){
        if(count($rows)<2)continue;
        foreach($rows as $i=>$a)foreach(array_slice($rows,$i+1) as $b){
            if($a['p']['rms_dbfs']<-45||$b['p']['rms_dbfs']<-45)continue;
            foreach(['sub_bass','bass','low_mid','presence','high'] as $band){
                $x=(float)$a['p']['bands_dbfs'][$band];
                $y=(float)$b['p']['bands_dbfs'][$band];
                if(min($x,$y)<-36||abs($x-$y)>8)continue;
                $confidence=min(1,max(0,(min($x,$y)+36)/30))
                    *min(1,max(0,(8-abs($x-$y))/8));
                $events[]=['time'=>(float)$time,'track_a'=>$a['guid'],'track_b'=>$b['guid'],
                    'band'=>$band,'levels_dbfs'=>[$x,$y],
                    'priority'=>round($confidence,3),
                    'interpretation'=>'Simultaneous raw-source band energy; possible competition, not proof of audible masking.'];
            }
        }
    }
    usort($events,static fn(array $a,array $b):int=>($b['priority']<=>$a['priority'])
        ?:($a['time']<=>$b['time']));
    return array_slice($events,0,$limit);
}
function sf_stem_alignment_summary(string $song,array $project):array {
    $record=sf_stem_alignment_read($song,$project);
    $scans=[];$all=[];
    foreach((array)($record['scans']??[]) as $scan){
        $findings=sf_stem_alignment_findings($scan);
        $scans[]=['status'=>$scan['status'],'mode'=>$scan['mode'],
            'start_seconds'=>$scan['start_seconds'],'end_seconds'=>$scan['end_seconds'],
            'step_seconds'=>$scan['step_seconds'],
            'sampled_stem_count'=>count($scan['tracks']),'created_at'=>$scan['created_at'],
            'findings'=>$findings];
        if($scan['status']==='recent')array_push($all,...$findings);
    }
    usort($all,static fn(array $a,array $b):int=>$b['priority']<=>$a['priority']);
    return ['status'=>$record['status'],'scans'=>$scans,
        'top_findings'=>array_slice($all,0,20),
        'provenance'=>'browser_raw_source_pre_fx_sparse_fft',
        'not_proof_of_masking'=>true,
        'not_native_reaper_dsp'=>true];
}
function sf_stem_alignment_agent_brief(array $summary,array $project):array {
    if(($summary['status']??'')!=='recent')return ['status'=>$summary['status']??'not_analyzed'];
    $index=[];$role=[];$i=0;
    foreach((array)$project['tracks'] as $track){
        $g=(string)($track['guid']??'');
        $index[$g]=++$i;
        $label=strtolower((string)($track['role']??''));
        $role[$g]=match(true){
            str_contains($label,'voc')=>'vocals',str_contains($label,'bass')=>'bass',
            str_contains($label,'drum')=>'drums',str_contains($label,'guitar')=>'guitar',
            str_contains($label,'key')=>'keys',default=>'unclassified'
        };
    }
    $events=[];
    foreach(array_slice($summary['top_findings'],0,12) as $r){
        $a=$r['track_a'];$b=$r['track_b'];
        if(!isset($index[$a],$index[$b]))continue;
        $events[]=['time_seconds'=>$r['time'],'stem_numbers'=>[$index[$a],$index[$b]],
            'stem_roles'=>[$role[$a],$role[$b]],'frequency_band'=>$r['band'],
            'levels_dbfs'=>$r['levels_dbfs'],'priority'=>$r['priority'],
            'claim'=>'potential overlap in simultaneously sampled raw sources, not confirmed masking'];
    }
    return ['status'=>'recent','events'=>$events,
        'scan_modes'=>array_values(array_unique(array_column($summary['scans'],'mode'))),
        'measurement_limit'=>'sparse browser FFT snapshots of decoded linked clips; no native FX, no verified listening, no raw audio'];
}
