<?php
declare(strict_types=1);
require_once __DIR__.'/stem-agent-bridge.php';

/** 86J: bounded Admin-only evidence for the original Stem Studio/Agent Brain.
 * Never send raw RPP, audio, VST binary, private paths or fan information.
 * Advice is deterministic; an LLM may EXPLAIN the evidence, never authorize it.
 */
function sf_stem_production_inspect(string $songId):array {
    $project=sf_stem_project_read($songId);
    if(!$project)return ['song_id'=>$songId,'status'=>'not_imported','issues'=>[],
        'proposals'=>[],'needs_owner_review'=>true];
    $review=sf_stem_agent_review($songId);
    $tracks=(array)($project['tracks']??[]);
    $buses=(array)($project['routing']['buses']??[]);
    $sends=(array)($project['routing']['sends']??[]);
    $lanes=(array)($project['automation']??[]);
    $fxTypes=static function(array $chain):array {
        return array_map(static fn(array $fx):array=>[
            'type'=>substr((string)($fx['type']??'unsupported'),0,40),
            'enabled'=>($fx['enabled']??true)!==false,
            'browser_mapped'=>($fx['type']??'unsupported')!=='unsupported'
        ],array_slice($chain,0,24));
    };
    $trackFacts=[];
    foreach(array_slice($tracks,0,64) as $t) {
        $trackFacts[]=[
            'guid'=>substr((string)($t['guid']??''),0,100),
            'name'=>substr((string)($t['name']??'Track'),0,80),
            'role'=>substr((string)($t['role']??''),0,50),
            'has_private_audio'=>!empty($t['asset_uuid']),
            'mute'=>!empty($t['mute']),'solo'=>!empty($t['solo']),
            'volume'=>round((float)($t['volume']??1),3),
            'pan'=>round((float)($t['pan']??0),3),
            'fx'=>$fxTypes((array)($t['fx']??[])),
            'automation_lanes'=>count(array_filter($lanes,static fn(array $l):bool=>
                ($l['scope']??'')==='track'&&($l['guid']??'')===($t['guid']??'')))
        ];
    }
    $busFacts=[];
    foreach(array_slice($buses,0,32) as $b){
        $busFacts[]=[
            'id'=>substr((string)($b['id']??''),0,100),
            'name'=>substr((string)($b['name']??'Bus'),0,80),
            'volume'=>round((float)($b['volume']??1),3),
            'pan'=>round((float)($b['pan']??0),3),
            'mute'=>!empty($b['mute']),
            'fx'=>$fxTypes((array)($b['fx']??[]))
        ];
    }
    $analysis=(array)($review['mastering']??[]);
    $issues=[];$proposals=[];
    $add=static function(string $id,string $priority,string $evidence,string $advice,bool $editable=false)
        use(&$issues):void{
        $issues[]=['id'=>$id,'priority'=>$priority,'evidence'=>$evidence,
            'recommendation'=>$advice,'automatable'=>$editable];
    };
    $missing=(int)($review['unlinked_tracks']??0);
    if($missing>0)$add('missing_stems','blocking',
        $missing.' referenced source track(s) do not have linked private audio',
        'Link source media and render again. Do not judge an incomplete mix.');
    $native=(int)($review['unmapped_effects']??0);
    $routing=(int)($review['unsupported_native_receives']??0);
    if($native>0||$routing>0)$add('native_compatibility','blocking',
        $native.' unmapped native FX; '.$routing.' unsupported REAPER receives',
        'Compare in REAPER. Native VST DSP is not reconstructed by the browser.');
    $state=(string)($analysis['status']??'not_analyzed');
    if($state!=='recent')$add('analysis_missing_or_stale','high',
        'Mastering evidence status: '.$state,'Render and save new 86G browser measurements before proposing changes.');
    if(($analysis['clipped_samples']??0)>0)$add('clipping','high',
        (int)$analysis['clipped_samples'].' browser-reported clipped sample(s)',
        'Review headroom and any limiter; measure again after changes.');
    $peak=$analysis['sample_peak_dbfs']??null;
    if(is_numeric($peak)&&(float)$peak>-1.0)$add('sample_peak','high',
        'Browser sample peak '.round((float)$peak,2).' dBFS; not true peak',
        'Consider conservative gain reduction and re-render. No fixed loudness target.');
    $imbalance=$analysis['stereo_imbalance_db']??null;
    if(is_numeric($imbalance)&&(float)$imbalance>3)$add('stereo_balance','medium',
        'Left/right RMS difference '.round((float)$imbalance,2).' dB',
        'Listen for intentional placement before modifying any individual pan.');
    foreach(['low','high'] as $band){
        $a=$analysis[$band.'_band_pct']??null;
        $ref=$analysis['reference_'.$band.'_band_pct']??null;
        if(is_numeric($a)&&is_numeric($ref)&&abs((float)$a-(float)$ref)>=10)
            $add($band.'_reference','medium',
                ucfirst($band).'-band energy difference '.round((float)$a-(float)$ref,1).' percentage points',
                'A/B at matched perceived loudness; do not guess EQ from broad energy estimates.');
    }
    $muted=count(array_filter($tracks,static fn(array $t):bool=>!empty($t['mute'])));
    if($muted)$add('muted_tracks','low',$muted.' muted track(s)',
        'Check that these were intentionally excluded.');
    $masterAutomated=count(array_filter($lanes,static fn(array $l):bool=>
        ($l['scope']??'')==='master'&&($l['parameter']??'')==='volume'&&($l['enabled']??true)!==false));
    if($masterAutomated && is_numeric($peak) && (float)$peak>-1.0)$add('existing_master_envelope','medium',
        'Master volume already has an active envelope',
        'Review the existing envelope before adding competing automation.');
    // Only suggest a precise control change when evidence and supported DSP
    // permit it. This is a proposal, never an applied effect or master.
    if($state==='recent'&&$missing===0&&$native===0&&$routing===0&&
        $masterAutomated===0&&is_numeric($peak)&&(float)$peak>-1.0){
        $reduction=max(-18.0,min(0.,-1.5-(float)$peak));
        $gain=round(.82*pow(10.,$reduction/20.),4);
        $lane=['scope'=>'master','guid'=>'__master__','parameter'=>'volume',
            'enabled'=>true,'points'=>[['time'=>0.,'value'=>$gain,'shape'=>'square']]];
        $verified=sf_stem_agent_normalize_draft([
            'track_id'=>$songId,'revision'=>(int)$project['revision'],'lane'=>$lane]);
        $proposals[]=[
            'id'=>'master_headroom','title'=>'Prepare conservative master headroom',
            'rationale'=>'Measured sample peak '.round((float)$peak,2).' dBFS. Reduce estimated master gain by '.
                round(-$reduction,2).' dB; re-render and compare after approval.',
            'tool_id'=>'stem_automation_update','draft'=>$verified,
            'owner_approval_required'=>true,'execution_state'=>'not_prepared'];
    }
    // No speculative EQ, panning, vocal mastering or automatic catalog publishing.
    $masterFx=$fxTypes((array)($project['master_fx']??[]));
    return [
        'song_id'=>$songId,'revision'=>(int)$project['revision'],'status'=>'inspected',
        'project'=>[
            'track_count'=>count($tracks),'tracks'=>$trackFacts,
            'bus_count'=>count($buses),'buses'=>$busFacts,
            'send_count'=>count($sends),'automation_count'=>count($lanes),
            'master_fx'=>$masterFx,'saved_mix_count'=>count((array)($project['mixes']??[])),
            'current_mix'=>substr((string)($project['current_mix']??''),0,100)
        ],
        'mastering'=>$analysis,
        'readiness'=>$review['mastering_review']??[],
        'catalog_publication'=>$review['catalog_publication']??[],
        'issues'=>array_slice($issues,0,24),'proposals'=>$proposals,
        'provenance'=>'private_project_manifest_and_browser_reported_measurements',
        'limitations'=>[
            'Browser loudness/peak evidence is not independently verified or native REAPER audio.',
            'Track-level diagnoses are metadata-based; per-stem spectral measurements are not available.',
            'No changes, publication or rights transfers occur without explicit owner actions.'
        ],
        'needs_owner_review'=>true
    ];
}
/** Agent chat gets bounded summaries, no complete private project or raw audio. */
function sf_stem_production_brief(array $inspection):array {
    return [
        'song_id'=>$inspection['song_id'],
        'revision'=>$inspection['revision']??null,
        'status'=>$inspection['status'],
        'track_count'=>$inspection['project']['track_count']??0,
        'bus_count'=>$inspection['project']['bus_count']??0,
        'send_count'=>$inspection['project']['send_count']??0,
        'fx_types'=>array_values(array_unique(array_map(static fn(array $fx):string=>$fx['type'],
            (array)($inspection['project']['master_fx']??[])))),
        'mastering'=>$inspection['mastering']??[],
        'readiness_status'=>$inspection['readiness']['status']??'not_reviewed',
        'catalog_state'=>$inspection['catalog_publication']['state']??'not_staged',
        'issues'=>array_map(static fn(array $i):array=>[
            'priority'=>$i['priority'],'evidence'=>$i['evidence'],'recommendation'=>$i['recommendation']
        ],(array)($inspection['issues']??[])),
        'proposals'=>array_map(static fn(array $p):array=>[
            'id'=>$p['id'],'rationale'=>$p['rationale'],'tool_id'=>$p['tool_id'],
            'owner_approval_required'=>true
        ],(array)($inspection['proposals']??[])),
        'limitations'=>$inspection['limitations']??[]
    ];
}
