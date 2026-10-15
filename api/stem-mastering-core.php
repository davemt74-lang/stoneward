<?php
declare(strict_types=1);
require_once __DIR__.'/stem-studio-core.php';
/**
 * Private per-song mix evidence (86G). Browser signal measurements are
 * explicitly labelled client-measured, never independently certified DSP.
 */
function sf_stem_mastering_report_validate(array $input):array {
    $metrics=(array)($input['metrics']??[]);
    $allowed=[
        'duration_seconds'=>[0.1,480.0],
        'sample_rate'=>[8000,96000],
        'sample_peak_dbfs'=>[-120,24],
        'integrated_lufs'=>[-120,24],
        'rms_dbfs'=>[-120,24],
        'crest_db'=>[0,120],
        'stereo_imbalance_db'=>[0,120],
        'clipped_samples'=>[0,96000*480*2],
        'low_band_pct'=>[0,100], 'high_band_pct'=>[0,100],
        'reference_low_band_pct'=>[0,100], 'reference_high_band_pct'=>[0,100],
        'reference_integrated_lufs'=>[-120,24],
    ];
    $safe=[];
    foreach($allowed as $key=>[$lo,$hi]){
        if(!array_key_exists($key,$metrics)){
            if($key==='integrated_lufs'||str_starts_with($key,'reference_')||in_array($key,['low_band_pct','high_band_pct'],true)){$safe[$key]=null;continue;}
            throw new InvalidArgumentException('Missing mix measurement: '.$key);
        }
        $v=$metrics[$key];
        if($v===null&&($key==='integrated_lufs'||str_starts_with($key,'reference_'))) {$safe[$key]=null;continue;}
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<$lo||(float)$v>$hi)
            throw new InvalidArgumentException('Invalid mix measurement: '.$key);
        $safe[$key]=$key==='clipped_samples'?(int)$v:round((float)$v,3);
    }
    if((int)$safe['sample_rate']!==48000)
        throw new InvalidArgumentException('86G LUFS analysis requires the 48 kHz export preset.');
    $meta=(array)($input['details']??[]);
    return [
        'metrics'=>$safe,
        'details'=>[
            'unsupported_fx'=>max(0,min(256,(int)($meta['unsupported_fx']??0))),
            'unsupported_routing'=>max(0,min(256,(int)($meta['unsupported_routing']??0))),
            'unlinked_stems'=>max(0,min(256,(int)($meta['unlinked_stems']??0))),
            'reference_name'=>substr(preg_replace('/[\x00-\x1F\x7F]/','',(string)($meta['reference_name']??''))?:'',0,120),
        ],
        'measurement_provenance'=>'browser_measured_not_server_verified',
        'method'=>'48000 Hz stereo PCM; 400ms K-weighted gated LUFS (EBU R128/BS1770 approximation); sample peak, RMS and crest',
        'mastering_not_certified'=>true
    ];
}
function sf_stem_mastering_save_analysis(string $id,array $request):array {
    $project=sf_stem_project_read($id);
    if(!$project)throw new InvalidArgumentException('Import a private REAPER project first.');
    $rev=$request['revision']??null;
    if(!is_int($rev))throw new InvalidArgumentException('Mix revision required.');
    $analysis=sf_stem_mastering_report_validate((array)($request['analysis']??[]));
    $analysis['revision']=$rev;
    $analysis['created_at']=gmdate('c');
    // Never store a raw audio URL, PII or a full project in evidence JSON.
    $project['mastering_analyses']=array_slice((array)($project['mastering_analyses']??[]),-11);
    $project['mastering_analyses'][]=$analysis;
    return sf_stem_project_write($id,$project,$rev);
}
function sf_stem_mastering_reference_path(string $id,array $project):?string {
    $tag=(string)($project['mastering_reference']['tag']??'');
    if(!preg_match('/^[a-f0-9]{32}\.(wav|mp3)$/D',$tag))return null;
    $base=sf_stem_project_dir().'/references/'.hash('sha256',$id);
    $path=$base.'/'.$tag;
    if(!is_file($path))return null;
    return $path;
}
function sf_stem_mastering_save_reference(string $id,array $file,int $expectedRevision):array {
    $project=sf_stem_project_read($id);
    if(!$project)throw new InvalidArgumentException('Import REAPER stems first.');
    $src=(string)($file['tmp_name']??'');
    $bytes=(int)($file['size']??0);
    if(($file['error']??99)!==UPLOAD_ERR_OK||!is_uploaded_file($src)||$bytes<128||$bytes>78643200)
        throw new InvalidArgumentException('Use a WAV/MP3 reference under 75 MiB. Check PHP upload limits.');
    $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
    if(!in_array($ext,['wav','mp3'],true))throw new InvalidArgumentException('Reference must be WAV or MP3.');
    $head=(string)file_get_contents($src,false,null,0,12);
    $valid=$ext==='wav'?strlen($head)>=12&&substr($head,0,4)==='RIFF'&&substr($head,8,4)==='WAVE':
        (str_starts_with($head,'ID3')||(strlen($head)>=2&&ord($head[0])===255&&(ord($head[1])&224)===224));
    if(!$valid)throw new InvalidArgumentException('Reference does not match WAV/MP3 file signature.');
    $base=sf_stem_project_dir().'/references/'.hash('sha256',$id);
    if(!is_dir($base)&&!mkdir($base,0770,true)&&!is_dir($base))throw new RuntimeException('Cannot create private reference storage.');
    $tag=bin2hex(random_bytes(16)).'.'.$ext;$target=$base.'/'.$tag;
    if(!move_uploaded_file($src,$target))throw new RuntimeException('Could not store private reference.');
    @chmod($target,0660);
    $prior=sf_stem_mastering_reference_path($id,$project);
    $project['mastering_reference']=[
        'tag'=>$tag,'name'=>substr(basename((string)$file['name']),0,150),
        'sha256'=>hash_file('sha256',$target),'bytes'=>$bytes,'updated_at'=>gmdate('c')];
    try{$saved=sf_stem_project_write($id,$project,$expectedRevision);}
    catch(Throwable $error){@unlink($target);throw $error;}
    if($prior!==null&&$prior!==$target)@unlink($prior);
    return $saved;
}
/** Only aggregate, bounded evidence may flow to the Admin Agent. */
function sf_stem_mastering_agent_summary(array $project):array {
    $latest=array_slice((array)($project['mastering_analyses']??[]),-1);
    if(!$latest)return ['status'=>'not_analyzed','measurement_provenance'=>'none'];
    $row=$latest[0];$m=(array)($row['metrics']??[]);$rev=(int)($project['revision']??0);
    $analysisRev=(int)($row['revision']??-1);
    return ['status'=>$analysisRev===$rev-1?'recent':'stale_after_project_edit',
        'measurement_provenance'=>'browser_measured_not_server_verified',
        'analysis_revision'=>$analysisRev,'project_revision'=>$rev,
        'sample_peak_dbfs'=>$m['sample_peak_dbfs']??null,
        'integrated_lufs'=>$m['integrated_lufs']??null,
        'crest_db'=>$m['crest_db']??null,
        'stereo_imbalance_db'=>$m['stereo_imbalance_db']??null,
        'low_band_pct'=>$m['low_band_pct']??null,
        'high_band_pct'=>$m['high_band_pct']??null,
        'reference_low_band_pct'=>$m['reference_low_band_pct']??null,
        'reference_high_band_pct'=>$m['reference_high_band_pct']??null,
        'clipped_samples'=>$m['clipped_samples']??null,
        'not_mastering_certified'=>true];
}
function sf_stem_mastering_recommendations(array $summary):array {
    if(($summary['status']??'')==='not_analyzed')return ['Render and analyze a mix before requesting measurement-based mastering guidance.'];
    $out=[];
    if(($summary['status']??'')!=='recent')$out[]='Measurements predate the current project revision. Re-render before acting.';
    if(($summary['clipped_samples']??0)>0)$out[]='Browser measurement shows clipped output samples; inspect master gain and limiter ceiling.';
    if(($summary['sample_peak_dbfs']??-120)>-1.0)$out[]='Sample peaks approach digital full scale; review headroom and check true peak in a mastering application.';
    if(($summary['stereo_imbalance_db']??0)>3)$out[]='Left/right RMS differs by over 3 dB; review panning and the reference mix.';
    if(($summary['low_band_pct']??null)!==null && ($summary['reference_low_band_pct']??null)!==null &&
        abs((float)$summary['low_band_pct']-(float)$summary['reference_low_band_pct'])>=10)
        $out[]='Approximate low-band balance differs materially from the selected reference; check source material and listen before EQ.';
    if(($summary['high_band_pct']??null)!==null && ($summary['reference_high_band_pct']??null)!==null &&
        abs((float)$summary['high_band_pct']-(float)$summary['reference_high_band_pct'])>=10)
        $out[]='Approximate high-band balance differs from the reference; verify tonal balance by listening, not only this estimate.';
    if(($summary['integrated_lufs']??null)!==null)$out[]='Compare the measured integrated loudness against your intended release format; no universal LUFS target is assumed.';
    if(!$out)$out[]='No obvious clipping or channel-imbalance flags in the browser measurements; listen against the source REAPER mix.';
    $out[]='Measurements are browser-reported and not independently server-verified; any proposed changes require exact Admin approval.';
    return $out;
}
