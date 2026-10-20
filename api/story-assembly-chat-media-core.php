<?php
declare(strict_types=1);
/**
 * G5D-3: private, editor-confirmed dialogue and original Stonefellow music
 * placement with source validation and standing artist music permission.
 */
require_once __DIR__.'/story-assembly-chat-insert-core.php';
require_once __DIR__.'/story-stonefellow-music-auto-core.php';

function sf_assembly_chat_media_intent(string $message):array{
 $intent=sf_assembly_chat_insert_intent($message);
 $kind=preg_match('/\b(?:dialogue|dialog|transcript|spoken|voiceover|narration)\b/i',$message)?
   'transcript':'track';
 $topic=preg_replace('/^.*?\b(?:add|insert|place|append)\b\s*/i','',$message,1)??$message;
 $topic=preg_replace('/\s+(?:to|into|in)\s+(?:the\s+)?(?:assembly|timeline)\s*#?\s*[1-9][0-9]{0,8}.*$/i','',$topic)??$topic;
 $topic=preg_replace('/\s+(?:after|before)\s+(?:the\s+)?(?:clip|item|scene|opening|first)\b.*$/i','',$topic)??$topic;
 $topic=preg_replace('/\s+(?:at\s+(?:the\s+)?(?:beginning|start|end)|as\s+(?:the\s+)?(?:first|last)).*$/i','',$topic)??$topic;
 $topic=preg_replace('/\b(?:stonefellow|original|track|song|music|dialogue|dialog|transcript|spoken|voiceover|narration|verified|clip|cue)\b/i',' ',$topic)??$topic;
 $topic=sf_capture_clean(preg_replace('/\s+/',' ',trim($topic))??'',100);
 return $intent+['kind'=>$kind,'media_topic'=>$topic];
}
function sf_assembly_chat_media_preview(int $editorId,?int $assemblyId,string $kind,
 string $topic='',?int $slot=null,string $placement='choose_position'):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 if(!in_array($kind,['track','transcript'],true))
  throw new InvalidArgumentException('Unsupported production source type.');
 require_once __DIR__.'/story-assembly-core.php';
 if($assemblyId===null){
  $selection=sf_assembly_chat_insert_preview($editorId,null,$topic,$slot,$placement);
  return $selection+['kind'=>$kind,'matches'=>[]];
 }
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft')
  throw new RuntimeException('Only existing draft assemblies can be edited.');
 $count=count($assembly['items']);$warning='';
 if($placement==='end')$slot=$count;
 if($placement==='after_opening'){
  $slot=null;
  foreach($assembly['items'] as $i=>$item)
   if($item['kind']==='scene'){$slot=$i+1;break;}
  if($slot===null)$warning='No opening scene found. Choose the exact placement.';
 }
 if($slot!==null&&($slot<0||$slot>$count))
  throw new InvalidArgumentException('Requested position exceeds current timeline.');
 $words=array_values(array_unique(array_filter(
   preg_split('/\s+/',strtolower(sf_capture_clean($topic,100)))?:[],
   static fn(string $w):bool=>strlen($w)>=3)));
 $matches=[];
 foreach(sf_assembly_candidates($editorId) as $c){
  if($c['kind']!==$kind)continue;
  $source=(string)$c['source_id'];
  if(array_filter($assembly['items'],static fn(array $i):bool=>
    $i['kind']===$kind&&(string)$i['source_id']===$source))continue;
  // Track origins are governed by artist standing authorization. Never
  // silently substitute covers, samples, outside licensors, or restricted works.
  $automatic=null;
  if($kind==='track'){
   $automatic=sf_stonefellow_music_auto_status($source);
   if(empty($automatic['eligible']))continue;
  }
  $label=strtolower((string)$c['label']);$score=0;
  foreach($words as $word)if(str_contains($label,$word))$score++;
  if($words&&$score===0)continue;
  try{
   $candidate=$kind==='track'?[
    'kind'=>'track','source_id'=>$source,'in_ms'=>0,
    'out_ms'=>min((int)$c['duration_ms'],600000),
    'duration_ms'=>min((int)$c['duration_ms'],600000),'notes'=>''
   ]:[
    'kind'=>'transcript','source_id'=>$source,
    'in_ms'=>(int)$c['in_ms'],'out_ms'=>(int)$c['out_ms'],
    'duration_ms'=>(int)$c['duration_ms'],'notes'=>''
   ];
   sf_assembly_validate_item($candidate);
  }catch(Throwable $e){continue;}
  $matches[]=['kind'=>$kind,'source_id'=>$source,'label'=>(string)$c['label'],
   'in_ms'=>$candidate['in_ms'],'out_ms'=>$candidate['out_ms'],
   'duration_ms'=>$candidate['duration_ms'],'score'=>$score,
   'publication_clearance'=>(string)($c['publication_clearance']??'unreviewed'),
   'music_approval'=>$kind==='track'?'automatic_stonefellow_original':'not_applicable'];
 }
 usort($matches,static fn(array $a,array $b):int=>
  ($b['score']<=>$a['score'])?:strcmp($a['source_id'],$b['source_id']));
 return ['requires_selection'=>false,'assemblies'=>[],'kind'=>$kind,
   'assembly'=>['id'=>(int)$assembly['id'],'title'=>(string)$assembly['title'],
     'revision'=>(int)$assembly['revision'],'item_count'=>$count],
   'matches'=>array_slice($matches,0,12),'topic'=>$topic,
   'slot'=>$slot,'placement'=>$placement,'position_warning'=>$warning,
   'private_only'=>true,'executed_changes'=>false];
}
function sf_assembly_chat_media_insert(int $editorId,int $assemblyId,int $revision,int $index,
 string $kind,string $sourceId,string $basis):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 if(!in_array($kind,['track','transcript'],true))
  throw new InvalidArgumentException('Unsupported media insertion type.');
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document placement reason (25+ characters).');
 require_once __DIR__.'/story-assembly-core.php';
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$revision<1||(int)$assembly['revision']!==$revision||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly changed. Refresh before changing the timeline.');
 $items=(array)$assembly['items'];
 if($index<0||$index>count($items)||count($items)>=50)
  throw new InvalidArgumentException('Invalid insertion index or assembly is full.');
 foreach($items as $item)
  if((string)$item['kind']===$kind&&(string)$item['source_id']===$sourceId)
   throw new RuntimeException('This source is already included in the timeline.');
 if($kind==='track'&&empty(sf_stonefellow_music_auto_status($sourceId)['eligible']))
  throw new RuntimeException('Only owner-authorized original Stonefellow music is automatically eligible.');
 $selected=null;
 foreach(sf_assembly_candidates($editorId) as $c){
  if($c['kind']===$kind&&(string)$c['source_id']===$sourceId){
   $selected=$c;break;
  }
 }
 if(!$selected)throw new RuntimeException('Verified dialogue or catalog track is unavailable.');
 $candidate=$kind==='track'?[
  'kind'=>$kind,'source_id'=>$sourceId,'in_ms'=>0,
  'out_ms'=>min(600000,(int)$selected['duration_ms']),
  'duration_ms'=>min(600000,(int)$selected['duration_ms']),
  'notes'=>'Original Stonefellow music auto-authorized for Artist World production'
 ]:[
  'kind'=>$kind,'source_id'=>$sourceId,'in_ms'=>(int)$selected['in_ms'],
  'out_ms'=>(int)$selected['out_ms'],'duration_ms'=>(int)$selected['duration_ms'],
  'notes'=>'Verified editorial dialogue placed through Agent Chat'
 ];
 $verified=sf_assembly_validate_item($candidate);
 $normalized=array_map(static fn(array $i):array=>[
  'kind'=>(string)$i['kind'],'source_id'=>(string)$i['source_id'],
  'in_ms'=>(int)$i['in_ms'],'out_ms'=>(int)$i['out_ms'],
  'duration_ms'=>(int)$i['duration_ms'],'notes'=>(string)$i['notes']],$items);
 array_splice($normalized,$index,0,[$verified]);
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$normalized,
  static function(int $newRevision)use($editorId,$assemblyId,$kind,$sourceId,$index,$basis):void{
   if($kind==='track'&&empty(sf_stonefellow_music_auto_status($sourceId)['eligible']))
    throw new RuntimeException('Artist standing authorization was revoked before the edit.');
   sf_team_audit($editorId,'capture.assembly_chat_media_insert','story_assembly',
     (string)$assemblyId,'Confirmed '.($kind==='track'?'automatically authorized original music':'verified dialogue').
     ' #'.$sourceId.' at position '.($index+1).' on revision '.$newRevision.' · Basis: '.$basis);
  });
 return $result+['kind'=>$kind,'source_id'=>$sourceId,'inserted_index'=>$index,
  'music_approval'=>$kind==='track'?'automatic_original_stonefellow':'not_applicable',
  'private_only'=>true,'publication_approved'=>false];
}
