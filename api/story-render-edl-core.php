<?php
declare(strict_types=1);
/**
 * 88D3G4C — deterministic edit decision list (EDL) shared by the private
 * storyboard and supervised FFmpeg music path. Metadata only: no media paths.
 */
function sf_render_edl_build(array $plan,?array $transition=null,?array $mix=null):array{
 $items=array_values((array)($plan['items']??[]));
 $count=count($items);
 if($count<1||$count>12)throw new InvalidArgumentException('EDL requires 1–12 saved clips.');
 $sourceTotal=0;
 foreach($items as $item){
  if(!is_array($item)||!in_array((string)($item['kind']??''),['scene','transcript','track'],true))
   throw new InvalidArgumentException('Unknown editorial clip type.');
  $ms=$item['duration_ms']??null;
  if(!is_int($ms)&&!(is_string($ms)&&ctype_digit($ms)))
   throw new InvalidArgumentException('Clip duration must be a whole number of milliseconds.');
  if((int)$ms<500||(int)$ms>20000)
   throw new InvalidArgumentException('Clip duration outside the supervised render limits.');
  $sourceTotal+=(int)$ms;
 }
 if($sourceTotal<1000||$sourceTotal>60000||$sourceTotal!==(int)($plan['total_ms']??-1))
  throw new InvalidArgumentException('EDL does not match the saved source timeline.');
 $overlap=0;$style='cut';
 if($transition!==null){
  $style=(string)($transition['style']??'');
  $overlap=$transition['overlap_ms']??null;
  if(!in_array($style,['fade','wipeleft'],true)||!in_array($overlap,[250,500],true)
    ||$count<2||$count>6||$sourceTotal>30000)
   throw new InvalidArgumentException('Unapproved crossfade timeline settings.');
  foreach($items as $item)
   if((int)$item['duration_ms']<$overlap*2+500)
    throw new InvalidArgumentException('Crossfaded clips require safe handles.');
 }
 $clips=[];$cuts=[];$duck=[];$cursor=0;
 foreach($items as $i=>$item){
  $start=$cursor-($i===0?0:$overlap);
  $end=$start+(int)$item['duration_ms'];
  if($i>0)$cuts[]=[
    'after_ordinal'=>$i,'before_ordinal'=>$i+1,'style'=>$style,
    'start_ms'=>$start,'end_ms'=>$start+$overlap,'duration_ms'=>$overlap
  ];
  $clips[]=[
   'ordinal'=>$i+1,'kind'=>(string)$item['kind'],
   'output_in_ms'=>$start,'output_out_ms'=>$end,
   'source_in_ms'=>(int)($item['start_ms']??$item['in_ms']??0),
   'duration_ms'=>(int)$item['duration_ms'],
   'overlap_in_ms'=>$i===0?0:$overlap,
   'overlap_out_ms'=>$i===$count-1?0:$overlap
  ];
  if($item['kind']==='transcript'){
   // Adjacent dialogue cues may overlap; merge the windows instead of applying
   // an accidental second multiplier to the licensed bed.
   $last=count($duck)-1;
   if($last>=0&&$start<=$duck[$last]['end_ms'])
    $duck[$last]['end_ms']=max($duck[$last]['end_ms'],$end);
   else $duck[]=['start_ms'=>$start,'end_ms'=>$end];
  }
  $cursor=$end;
 }
 $music=['enabled'=>false];
 if($mix!==null){
  $track=(string)($mix['track_id']??'');
  $found=false;
  foreach($items as $item)
   if($item['kind']==='track'&&(string)($item['source_id']??'')===$track)$found=true;
  if(!$found||!in_array($mix['gain_pct']??null,[10,15,20,25,30],true)
    ||!in_array($mix['duck_pct']??null,[25,35,50,65],true)
    ||!in_array($mix['fade_ms']??null,[250,500,1000],true))
   throw new InvalidArgumentException('EDL music preset does not match approved source and levels.');
  $music=['enabled'=>true,'gain_pct'=>$mix['gain_pct'],
    'duck_pct'=>$mix['duck_pct'],'fade_ms'=>$mix['fade_ms']];
 }
 return [
  'revision'=>(int)($plan['revision']??0),
  'source_total_ms'=>$sourceTotal,'output_total_ms'=>$cursor,
  'overlap_ms'=>$overlap,'clips'=>$clips,'cuts'=>$cuts,
  'dialogue_duck_windows'=>$duck,'music'=>$music,
  'metadata_only'=>true,'render_authorized'=>false,'publication_enabled'=>false
 ];
}
/** The saved timeline may be previewed even when media-rights certification is blocked.
 * A preview never grants the right to queue/render/publish an output.
 */
function sf_render_edl_preview(int $editor,int $assembly,int $revision):array{
 sf_assembly_editor($editor);
 $saved=sf_assembly_read($editor,$assembly);
 if(!$saved||(int)$saved['revision']!==$revision||$saved['status']!=='draft')
  throw new RuntimeException('Current saved private editorial revision required.');
 if(count($saved['items'])===0)return [];
 $plan=['revision'=>$revision,'items'=>$saved['items'],
  'total_ms'=>array_sum(array_map(static fn($v)=>(int)$v['duration_ms'],$saved['items']))];
 try{
  return sf_render_edl_build($plan,
   sf_render_transition_settings($editor,$assembly,$revision),
   sf_render_mix_settings($editor,$assembly,$revision));
 }catch(InvalidArgumentException $e){return [];}

}
