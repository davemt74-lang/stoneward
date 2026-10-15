<?php
declare(strict_types=1);
require_once __DIR__.'/stem-mastering-core.php';

/**
 * 86H mastering destinations are REVIEW PROFILES, not automatic native REAPER
 * DSP or platform guarantees. All current browser outputs are stereo 48k/16.
 */
function sf_stem_86h_presets():array {
    return [
        'streaming'=>['label'=>'Streaming candidate','sample_peak_ceiling_dbfs'=>-1.0,
            'sample_rate'=>48000,'channels'=>2,'bits'=>16,'needs_engineer_signoff'=>false,
            'notes'=>'No universal LUFS target; sample peak does not measure true peak.'],
        'download'=>['label'=>'Owned download candidate','sample_peak_ceiling_dbfs'=>-0.5,
            'sample_rate'=>48000,'channels'=>2,'bits'=>16,'needs_engineer_signoff'=>false,
            'notes'=>'Preserve a separate private original from the public preview.'],
        'vinyl'=>['label'=>'Vinyl pre-master REVIEW','sample_peak_ceiling_dbfs'=>-3.0,
            'sample_rate'=>48000,'channels'=>2,'bits'=>16,'needs_engineer_signoff'=>true,
            'notes'=>'Not a cutting master: engineer must review phase, sibilance, low end, side lengths and cutting requirements.'],
        'archive'=>['label'=>'Archive comparison copy','sample_peak_ceiling_dbfs'=>-0.1,
            'sample_rate'=>48000,'channels'=>2,'bits'=>16,'needs_engineer_signoff'=>false,
            'notes'=>'Browser PCM copy is not a lossless copy of the original REAPER project; retain the native files.']
    ];
}
function sf_stem_86h_signature(array $p):string {
    $t=[];
    foreach((array)($p['tracks']??[]) as $x) {
        $t[]=[
            'guid'=>$x['guid']??null,'source_file'=>$x['source_file']??null,
            'asset_uuid'=>$x['asset_uuid']??null,'clips'=>$x['clips']??[],
            'volume'=>$x['volume']??null,'pan'=>$x['pan']??null,
            'mute'=>$x['mute']??null,'solo'=>$x['solo']??null,
            'fx'=>$x['fx']??[],'folder_parent_guid'=>$x['folder_parent_guid']??null,
            'main_send_enabled'=>$x['main_send_enabled']??null
        ];
    }
    $source=['source_sha256'=>$p['source_sha256']??null,'tracks'=>$t,
        'routing'=>$p['routing']??[],'master_fx'=>$p['master_fx']??[],
        'automation'=>$p['automation']??[],'current_mix'=>$p['current_mix']??null];
    return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function sf_stem_86h_report(array $project,string $preset):array {
    $presets=sf_stem_86h_presets();
    if(!isset($presets[$preset]))throw new InvalidArgumentException('Unknown mastering review preset.');
    $profile=$presets[$preset];$blocks=[];$warnings=[];
    $latest=array_slice((array)($project['mastering_analyses']??[]),-1);
    $analysis=$latest[0]??null;
    // Save analysis increments revision by exactly one. A later readiness-only
    // write can be checked against its stored audio signature.
    if(!is_array($analysis)||((int)($analysis['revision']??-1)+1)!==(int)($project['revision']??0))
        $blocks[]='Render and save a new analysis of the current mix revision.';
    $m=(array)($analysis['metrics']??[]);
    if(($analysis['measurement_provenance']??'')!=='browser_measured_not_server_verified')
        $blocks[]='The analysis provenance is missing or unsupported.';
    if((int)($m['sample_rate']??0)!==48000)
        $blocks[]='The analyzed output must be a 48 kHz stereo WAV.';
    if(!is_numeric($m['sample_peak_dbfs']??null)
        ||(float)$m['sample_peak_dbfs']>(float)$profile['sample_peak_ceiling_dbfs'])
        $blocks[]='Sample peak exceeds this review profile’s headroom guideline.';
    if((int)($m['clipped_samples']??-1)!==0)
        $blocks[]='Clipped samples must be resolved and remeasured.';
    foreach((array)($project['tracks']??[]) as $track)
        if(!empty($track['source_file'])&&empty($track['asset_uuid']))
            $blocks[]='Some source stems are not linked; the render may be incomplete.';
    foreach(array_merge([(array)($project['master_fx']??[])],
        array_map(static fn(array $t):array=>(array)($t['fx']??[]),(array)($project['tracks']??[])),
        array_map(static fn(array $b):array=>(array)($b['fx']??[]),(array)($project['routing']['buses']??[]))) as $fxChain)
        foreach($fxChain as $fx)
            if(($fx['enabled']??true)!==false&&($fx['type']??'')==='unsupported')
                $blocks[]='Unsupported enabled native effects prevent browser-fidelity clearance.';
    if((int)($project['routing']['unsupported_receives']??0)>0)
        $blocks[]='Unmapped native REAPER routing prevents browser-fidelity clearance.';
    if((int)($analysis['details']['unsupported_fx']??0)>0
        ||(int)($analysis['details']['unsupported_routing']??0)>0)
        $blocks[]='The measured client identified unsupported audio processing.';
    if(!in_array(($analysis['method']??''), [
        '48000 Hz stereo PCM; 400ms K-weighted gated LUFS (EBU R128/BS1770 approximation); sample peak, RMS and crest'
    ],true))$warnings[]='Measurement method is not independently certified.';
    $warnings[]='Browser-reported levels are not server verified; sample peak is not true peak.';
    if($profile['needs_engineer_signoff'])
        $warnings[]='A mastering/cutting engineer’s separate sign-off remains mandatory.';
    if(empty($project['metadata']['isrc'])&&$preset!=='archive')
        $warnings[]='Check catalog metadata and ISRC before publication; this report does not assign rights.';
    return [
       'profile'=>$preset,'label'=>$profile['label'],
       'status'=>$blocks?'blocked':'ready_for_admin_review',
       'blockers'=>array_values(array_unique($blocks)),
       'warnings'=>array_values(array_unique($warnings)),
       'profile_spec'=>$profile,'audio_signature'=>sf_stem_86h_signature($project),
       'analysis_revision'=>(int)($analysis['revision']??-1),
       'project_revision'=>(int)($project['revision']??0),
       'can_auto_publish'=>false,'needs_explicit_asset_upload'=>true
    ];
}
/**
 * A review record is not a master asset and never updates catalog or licenses.
 * The media copy/upload happens only through existing protected Media Library.
 */
function sf_stem_86h_save_review(string $id,array $input,int $adminId):array {
    $p=sf_stem_project_read($id);
    if(!$p)throw new InvalidArgumentException('Import a private song project first.');
    $revision=$input['revision']??null;
    if(!is_int($revision)||$revision!==(int)$p['revision'])
        throw new RuntimeException('Project revision changed. Reload before reviewing.');
    if(($input['confirmation']??'')!=='REVIEWED')
        throw new InvalidArgumentException('Type REVIEWED to record your exact mastering review.');
    $profile=(string)($input['profile']??'');
    $report=sf_stem_86h_report($p,$profile);
    if($report['status']!=='ready_for_admin_review')
        throw new InvalidArgumentException('Resolve readiness blockers before approving this candidate.');
    $p['mastering_readiness']=[
        'profile'=>$profile,'state'=>'reviewed_pending_media_upload',
        'source_audio_signature'=>$report['audio_signature'],
        'analysis_revision'=>$report['analysis_revision'],
        'reviewed_by'=>$adminId,'reviewed_at'=>gmdate('c'),
        'warnings'=>$report['warnings'],'mastering_not_certified'=>true,
        'public_published'=>false
    ];
    return sf_stem_project_write($id,$p,$revision);
}
function sf_stem_86h_review_status(array $p):array {
    $record=(array)($p['mastering_readiness']??[]);
    if(!$record)return ['status'=>'not_reviewed','can_publish'=>false];
    $same=hash_equals((string)($record['source_audio_signature']??''),sf_stem_86h_signature($p));
    return ['status'=>$same?'reviewed_pending_media_upload':'stale_after_mix_edit',
        'profile'=>(string)($record['profile']??''),
        'reviewed_at'=>$record['reviewed_at']??null,
        'mastering_not_certified'=>true,'can_publish'=>false,
        'media_asset_required'=>true
    ];
}
