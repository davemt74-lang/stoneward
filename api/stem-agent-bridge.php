<?php
declare(strict_types=1);
require_once __DIR__.'/stem-studio-core.php';
require_once __DIR__.'/stem-mastering-core.php';
require_once __DIR__.'/stem-catalog-publishing.php';
/**
 * Read-only Admin Brain bridge. Only a named song, validated against the site
 * catalog, can be inspected. Never supply RPP/VST binary, paths, stem URLs,
 * media UUIDs, private lyric text or customer records to language models.
 */
function sf_stem_agent_review(string $songId):array {
    sf_stem_project_track($songId);
    $project=sf_stem_project_read($songId);
    if($project===null)return ['song_id'=>$songId,'status'=>'not_imported','recommendations'=>[
        'Import a private REAPER project before reviewing mix automation.']];
    $tracks=(array)($project['tracks']??[]);
    $routing=(array)($project['routing']??[]);
    $lanes=(array)($project['automation']??[]);
    $missing=0;$muted=0;$unsupportedFx=0;$unmapped=0;
    foreach($tracks as $track){
        if(empty($track['asset_uuid'])&&!empty($track['source_file']))$missing++;
        if(!empty($track['mute']))$muted++;
        foreach((array)($track['fx']??[]) as $fx)if(($fx['type']??'')==='unsupported')$unsupportedFx++;
    }
    foreach((array)($project['master_fx']??[]) as $fx)if(($fx['type']??'')==='unsupported')$unsupportedFx++;
    $automationByType=[];
    foreach($lanes as $lane){
        $key=(string)($lane['parameter']??'unsupported');
        $automationByType[$key]=($automationByType[$key]??0)+1;
        if(($lane['source']??'')==='REAPER'&&empty($lane['enabled']))$unmapped++;
    }
    $mastering=sf_stem_mastering_agent_summary($project);
    $releaseReview=sf_stem_86h_review_status($project);
    $catalogPublication=sf_stem_86i_status($project);
    $suggestions=sf_stem_mastering_recommendations($mastering);
    if($missing>0)$suggestions[]=$missing.' tracks have missing source media. Link the private WAV/MP3 stems before assessing levels.';
    if((int)($routing['unsupported_receives']??0)>0)$suggestions[]='Some REAPER receives were not mapped; compare playback against the original REAPER session.';
    if($unsupportedFx>0)$suggestions[]=$unsupportedFx.' native effects have no supported browser processor. Do not claim matching native sound.';
    if(!$lanes)$suggestions[]='No supported automation lanes found. Consider volume and pan automation where transitions need shaping.';
    if($muted)$suggestions[]='Review '.$muted.' muted tracks before exporting or comparing the mix.';
    if(!$suggestions)$suggestions[]='Review master headroom, track balance and recalled mix automation by listening to the original session.';
    return [
      'song_id'=>$songId,'status'=>'imported','revision'=>(int)($project['revision']??0),
      'track_count'=>count($tracks),'unlinked_tracks'=>$missing,'muted_tracks'=>$muted,
      'auxiliary_buses'=>count((array)($routing['buses']??[])),
      'sends'=>count((array)($routing['sends']??[])),
      'unsupported_native_receives'=>(int)($routing['unsupported_receives']??0),
      'unmapped_effects'=>$unsupportedFx,'automation_lanes'=>count($lanes),
      'automation_by_parameter'=>$automationByType,
      'import_limitations'=>(array)($project['automation_import']??[]),
      'saved_mix_count'=>count((array)($project['mixes']??[])),
      'current_mix'=>(string)($project['current_mix']??''),
      'mastering'=>$mastering,
      'mastering_review'=>$releaseReview,
      'catalog_publication'=>[
          'state'=>$catalogPublication['state'],
          'profile'=>$catalogPublication['profile'],
          'audio_signature_matches'=>$catalogPublication['audio_signature_matches'],
          'publish_scope'=>'private_master_only'],
      'reference_available'=>isset($project['mastering_reference']['tag']),
      'recommendations'=>array_values(array_slice(array_unique($suggestions),0,12)),
      'mutation_policy'=>'No autonomous edits: exact project revision and admin proposal approval required.'
    ];
}
function sf_stem_agent_normalize_draft(array $raw):array {
    $id=(string)($raw['track_id']??'');
    $project=sf_stem_project_read($id);
    if(!$project)throw new InvalidArgumentException('A saved private song project is required.');
    $revision=$raw['revision']??null;
    if(!is_int($revision)||$revision!==(int)($project['revision']??0))
        throw new InvalidArgumentException('Mix was updated. Reload before preparing a proposal.');
    if(!is_array($raw['lane']??null))throw new InvalidArgumentException('Automation lane is required.');
    $lane=sf_stem_automation_validate_lane($project,$raw['lane']);
    // The generated edit ID is created on execution, not in proposal payload;
    // deduplication requires an identical hash for an identical request.
    unset($lane['id']);
    return ['track_id'=>$id,'revision'=>$revision,'lane'=>$lane];
}
