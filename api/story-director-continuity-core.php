<?php
declare(strict_types=1);
/**
 * G5E4. Read-only, deterministic continuity review over a bounded, already
 * validated Composer source manifest. Verified story links are *fictional*
 * editorial concepts, not human identities. Music pacing estimates are based
 * on clip placement durations, not actual audio beat/tempo or video playback.
 */
function sf_director_continuity_report(array $ordered,array $evidence):array{
 if(count($ordered)>38)throw new InvalidArgumentException('Too many editorial clips.');
 $timeline=[];$entities=[];$locations=[];$total=0;
 $counts=['scene'=>0,'transcript'=>0,'track'=>0];$durations=$counts;
 $lastScene=null;$musicBridgeCount=0;$issues=[];$gaps=[];$suggestions=[];
 foreach(array_values($ordered) as $index=>$source){
  if(!is_array($source))throw new InvalidArgumentException('Invalid timeline source.');
  $kind=(string)($source['kind']??'');
  $id=(string)($source['source_id']??'');
  $duration=(int)($source['duration_ms']??0);
  if(!isset($counts[$kind])||$id===''||$duration<0||$duration>86400000)
   throw new InvalidArgumentException('Invalid source kind or duration.');
  $key=$kind.':'.$id;$info=(array)($evidence[$key]??[]);
  $counts[$kind]++;$durations[$kind]+=$duration;
  $links=[];
  foreach(array_slice((array)($info['story_connections']??[]),0,5) as $link){
   if(!is_array($link)||($link['origin']??'')!=='editor_verified_story_graph')continue;
   $entityId=(int)($link['entity_id']??0);
   $type=(string)($link['entity_type']??'');
   if($entityId<1||!in_array($type,['character','location','event','theme'],true))continue;
   $label=substr(trim((string)($link['label']??'')),0,100);
   $k=$type.':'.$entityId;
   $entities[$k]??=['entity_id'=>$entityId,'type'=>$type,'label'=>$label,'positions'=>[]];
   $entities[$k]['positions'][]=$index+1;
   $links[]=['entity_id'=>$entityId,'type'=>$type,'label'=>$label];
  }
  if($kind==='scene'){
   $here=array_map(static fn(array $x):string=>$x['type'].':'.$x['entity_id'],
    array_values(array_filter($links,static fn(array $x):bool=>$x['type']==='location')));
   if($lastScene!==null && $here && $lastScene['locations'] &&
    !array_intersect($here,$lastScene['locations'])){
    $issues[]=['code'=>'location_handoff','position'=>$index+1,
     'source_key'=>$key,'severity'=>'review',
     'message'=>'Two verified story-location concepts differ across scene shots; check the establishing transition.',
     'evidence'=>'verified_editor_story_graph'];
   }
   $lastScene=['positions'=>$index+1,'locations'=>$here];
  }
  $start=$total;$total+=$duration;
  $timeline[]=['position'=>$index+1,'source_key'=>$key,'kind'=>$kind,
   'start_ms'=>$start,'end_ms'=>$total,'duration_ms'=>$duration,
   'verified_story_links'=>$links,'reviewed_visual_frames'=>count((array)($info['visual_observations']??[]))];
 }
 $arcs=[];
 foreach($entities as $item){
  if($item['type']!=='character')continue;
  $positions=array_values(array_unique($item['positions']));sort($positions,SORT_NUMERIC);
  $longGaps=[];
  for($i=1;$i<count($positions);$i++){
   $distance=$positions[$i]-$positions[$i-1];
   if($distance>=3)$longGaps[]=['from_clip'=>$positions[$i-1],
      'to_clip'=>$positions[$i],'unseen_clips'=>$distance-1];
  }
  if($longGaps)$issues[]=['code'=>'character_return_gap',
   'position'=>$positions[count($positions)-1],
   'source_key'=>'','severity'=>'review',
   'message'=>'A verified fictional character-story concept returns after an unlinked interval; review whether a bridge is needed.',
   'evidence'=>'verified_editor_story_graph'];
  $arcs[]=['entity_id'=>$item['entity_id'],'label'=>$item['label'],
    'first_clip'=>$positions[0],'last_clip'=>$positions[count($positions)-1],
    'appearance_count'=>count($positions),'return_gaps'=>$longGaps,
    'arc_status'=>count($positions)===1?'single_verified_appearance':
      ($longGaps?'reappears_after_gap':'repeated_verified_story_link')];
  if(count($arcs)>=12)break;
 }
 if($counts['scene']<2)$gaps[]=['code'=>'establishing_visual_coverage',
  'message'=>'Fewer than two verified visual placements; review opening and closing coverage.',
  'target_role'=>'videographer'];
 if($counts['transcript']===0)$gaps[]=['code'=>'verified_dialogue_coverage',
  'message'=>'No independently verified dialogue is placed; consider whether narration is needed.',
  'target_role'=>'band_member'];
 if($counts['track']===0)$gaps[]=['code'=>'original_music_cue',
  'message'=>'No music cue is placed; consider eligible original Stonefellow music.',
  'target_role'=>'music_editor'];
 foreach($timeline as $i=>$item){
  if($item['kind']!=='transcript')continue;
  $before=$timeline[$i-1]['kind']??'';
  $after=$timeline[$i+1]['kind']??'';
  if($before!=='scene'&&$after!=='scene'){
   $gaps[]=['code'=>'dialogue_visual_bridge','clip'=>$item['position'],
    'message'=>'Verified dialogue is not adjacent to a scene; review a visual bridge or cutaway.',
    'target_role'=>'video_editor'];
  }
  if($before==='track'||$after==='track')$musicBridgeCount++;
 }
 if($counts['track']&&$counts['transcript']&&!$musicBridgeCount)
  $issues[]=['code'=>'music_dialogue_handoff','position'=>0,'source_key'=>'',
   'severity'=>'review','message'=>'Music and verified dialogue exist but are not adjacent; review soundtrack handoffs or a ducking bridge.',
   'evidence'=>'verified_editor_timeline'];
 if($counts['scene']&&$durations['track']>max(10000,$durations['scene']*3))
  $issues[]=['code'=>'music_visual_balance','position'=>0,'source_key'=>'',
   'severity'=>'review','message'=>'Approximate music cue duration is long relative to visual placements; review pacing against actual playback.',
   'evidence'=>'duration_metadata_only'];
 $total=max(1,$total);
 $music=['cue_count'=>$counts['track'],'cue_duration_ms'=>$durations['track'],
  'dialogue_duration_ms'=>$durations['transcript'],
  'visual_duration_ms'=>$durations['scene'],
  'estimated_music_fraction'=>round($durations['track']/$total,3),
  'dialogue_adjacent_to_music'=>$musicBridgeCount,
  'tempo_or_audio_beats_analyzed'=>false,
  'method'=>'linear sum of source-placement durations; excludes crossfades, concurrent mixing and audio analysis'];
 $suggestions[]=['code'=>'review_story_continuity',
  'message'=>'Inspect the verified story links and ordered sources before confirming any AI sequence.',
  'requires_human'=>true];
 if($issues)$suggestions[]=['code'=>'review_story_handoffs',
  'message'=>'Review the flagged location, character and music handoffs in the private editor.',
  'requires_human'=>true];
 if($gaps)$suggestions[]=['code'=>'request_missing_coverage',
  'message'=>'Prepare a private contributor shot or dialogue request for uncovered story beats.',
  'requires_human'=>true];
 return ['source_count'=>count($timeline),
  'timeline'=>array_slice($timeline,0,38),
  'character_arcs'=>$arcs,'issue_count'=>count($issues),
  'issues'=>array_slice($issues,0,15),
  'coverage_gaps'=>array_slice($gaps,0,15),
  'music_pacing'=>$music,
  'editorial_suggestions'=>$suggestions,
  'private_only'=>true,'read_only'=>true,
  'visual_identity_inferred'=>false,'video_playback_analyzed'=>false,
  'publication_approved'=>false,'render_started'=>false];
}
function sf_director_continuity_for_order(array $order,array $context):array{
 $available=array_merge((array)($context['items']??[]),(array)($context['candidate_pool']??[]));
 $selected=[];
 foreach($order as $index){
  if(!is_int($index)||!array_key_exists($index,$available))
   throw new InvalidArgumentException('Continuity plan referenced an unavailable source.');
  $selected[]=$available[$index];
 }
 return sf_director_continuity_report($selected,(array)($context['source_understanding']??[]));
}
function sf_director_continuity_evidence_digest(array $context):string{
 // Deterministic digest prevents an approved proposal from silently inheriting
 // changed visual/story links or music authorization after the editor review.
 return hash('sha256',json_encode([
  'items'=>(array)($context['items']??[]),
  'pool'=>(array)($context['candidate_pool']??[]),
  'understanding'=>(array)($context['source_understanding']??[])
 ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
