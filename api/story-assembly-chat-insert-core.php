<?php
declare(strict_types=1);
/**
 * G5D-1: bounded, metadata-only, read-before-confirm clip insertion.
 * UI selects a verified source; the server resolves its original timing.
 * No media URLs, inferred consent, automated publication or rendering.
 */
require_once __DIR__.'/story-assembly-chat-core.php';

function sf_assembly_chat_insert_intent(string $message):array {
 $assemblyId=null;
 if(preg_match('/\bassembly\s*#?\s*([1-9][0-9]{0,8})\b/i',$message,$m))
  $assemblyId=(int)$m[1];
 $slot=null;$placement='choose_position';
 if(preg_match('/\bafter\s+(?:the\s+)?(?:opening|first)\s+(?:scene|clip|shot)\b/i',$message)){
  $slot=1;$placement='after_opening';
 }elseif(preg_match('/\bafter\s+(?:clip|item|scene)\s*#?\s*([1-9][0-9]{0,2})\b/i',$message,$m)){
  $slot=(int)$m[1];$placement='after_clip';
 }elseif(preg_match('/\bbefore\s+(?:clip|item|scene)\s*#?\s*([1-9][0-9]{0,2})\b/i',$message,$m)){
  $slot=(int)$m[1]-1;$placement='before_clip';
 }elseif(preg_match('/\b(?:at\s+(?:the\s+)?(?:beginning|start)|as\s+(?:the\s+)?first\s+clip)\b/i',$message)){
  $slot=0;$placement='start';
 }elseif(preg_match('/\b(?:at\s+(?:the\s+)?end|as\s+(?:the\s+)?last\s+clip|append)\b/i',$message)){
  $placement='end';
 }
 $topic=trim(preg_replace('/^.*?\b(?:add|insert|place|append)\b\s*/i','',$message,1)??$message);
 $topic=preg_replace('/^\s*(?:the\s+)?(?:verified\s+)?/i','',$topic)??$topic;
 $topic=preg_replace('/\s+(?:to|into|in)\s+(?:the\s+)?(?:assembly|timeline)\s*#?\s*[1-9][0-9]{0,8}.*$/i','',$topic)??$topic;
 $topic=preg_replace('/\s+(?:(?:after|before)\s+(?:the\s+)?(?:opening|first|clip|item|scene)|at\s+(?:the\s+)?(?:beginning|start|end)|as\s+(?:the\s+)?(?:first|last)).*$/i','',$topic)??$topic;
 $topic=preg_replace('/\b(?:footage|video|shot|scene|clip)\b/i',' ',$topic)??$topic;
 $topic=sf_capture_clean(preg_replace('/\s+/',' ',trim($topic))??'',100);
 return ['assembly_id'=>$assemblyId,'topic'=>$topic,'slot'=>$slot,'placement'=>$placement];
}
function sf_assembly_chat_insert_preview(int $editorId,?int $assemblyId,string $topic,
 ?int $slot=null,string $placement='choose_position'):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-assembly-core.php';
 $topic=sf_capture_clean($topic,100);
 if($assemblyId===null){
  $projects=array_values(array_filter(sf_assembly_list($editorId),
   static fn(array $p):bool=>$p['status']==='draft'));
  return ['assemblies'=>array_slice(array_map(static fn(array $p):array=>[
    'id'=>(int)$p['id'],'title'=>(string)$p['title'],
    'revision'=>(int)$p['revision'],'item_count'=>(int)$p['item_count']],$projects),0,12),
    'assembly'=>null,'matches'=>[],'topic'=>$topic,'slot'=>$slot,'placement'=>$placement,
    'requires_selection'=>true,'private_only'=>true,'executed_changes'=>false];
 }
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft')
  throw new RuntimeException('This assembly cannot accept new clips.');
 $count=count($assembly['items']);
 if($placement==='end')$slot=$count;
 if($slot!==null&&($slot<0||$slot>$count))
  throw new InvalidArgumentException('The requested clip position is outside the current timeline.');
 $terms=array_values(array_unique(array_filter(preg_split('/\s+/',strtolower($topic))?:[],
  static fn(string $word):bool=>strlen($word)>=3)));
 $available=[];
 foreach(sf_assembly_candidates($editorId) as $c){
  if(($c['kind']??'')!=='scene')continue;
  $source=(string)$c['source_id'];
  if(array_filter($assembly['items'],static fn(array $item):bool=>
    $item['kind']==='scene'&&(string)$item['source_id']===$source))continue;
  $rank=0;$label=strtolower((string)$c['label']);
  foreach($terms as $word)if(str_contains($label,$word))$rank++;
  if($terms&&$rank===0)continue;
  // Only actually verified sources can be offered, and they are rechecked
  // in the write transaction before a proposed insertion can be applied.
  try{sf_assembly_validate_item($c);}catch(Throwable $e){continue;}
  $available[]=['source_id'=>$source,'label'=>(string)$c['label'],
    'submission_id'=>(int)$c['submission_id'],
    'category'=>(string)$c['category'],'in_ms'=>(int)$c['in_ms'],
    'out_ms'=>(int)$c['out_ms'],'duration_ms'=>(int)$c['duration_ms'],
    'publication_clearance'=>(string)$c['publication_clearance'],'term_matches'=>$rank];
 }
 usort($available,static fn(array $a,array $b):int=>
  ($b['term_matches']<=>$a['term_matches'])?:((int)$b['source_id']<=>(int)$a['source_id']));
 return ['assembly'=>['id'=>(int)$assembly['id'],'title'=>(string)$assembly['title'],
     'revision'=>(int)$assembly['revision'],'status'=>(string)$assembly['status'],
     'item_count'=>$count],
   'assemblies'=>[],'matches'=>array_slice($available,0,12),
   'topic'=>$topic,'slot'=>$slot,'placement'=>$placement,
   'requires_selection'=>false,'private_only'=>true,'executed_changes'=>false];
}
function sf_assembly_chat_insert(int $editorId,int $assemblyId,int $revision,int $index,
 string $sourceId,string $basis):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document review basis (25+ characters).');
 if(!preg_match('/^[1-9][0-9]{0,17}$/D',$sourceId))
  throw new InvalidArgumentException('Verified source ID required.');
 require_once __DIR__.'/story-assembly-core.php';
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$revision<1||(int)$assembly['revision']!==$revision||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly changed or cannot be edited. Refresh it before review.');
 $items=(array)$assembly['items'];
 if($index<0||$index>count($items)||count($items)>=50)
  throw new InvalidArgumentException('Invalid insertion position or timeline is full.');
 foreach($items as $item){
  if($item['kind']==='scene'&&(string)$item['source_id']===$sourceId)
   throw new RuntimeException('This scene is already present in the timeline.');
 }
 $candidate=null;
 foreach(sf_assembly_candidates($editorId) as $c)
  if($c['kind']==='scene'&&(string)$c['source_id']===$sourceId){$candidate=$c;break;}
 if(!$candidate)throw new RuntimeException('Verified footage is unavailable or has been revoked.');
 $insertion=sf_assembly_validate_item($candidate);
 $insertion['notes']='Added after editor review in Agent Chat';
 $normalized=array_map(static fn(array $i):array=>[
  'kind'=>(string)$i['kind'],'source_id'=>(string)$i['source_id'],
  'in_ms'=>(int)$i['in_ms'],'out_ms'=>(int)$i['out_ms'],
  'duration_ms'=>(int)$i['duration_ms'],'notes'=>(string)$i['notes']],$items);
 array_splice($normalized,$index,0,[$insertion]);
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$normalized,
   static function(int $newRevision)use($editorId,$assemblyId,$index,$sourceId,$basis):void{
    sf_team_audit($editorId,'capture.assembly_chat_insert','story_assembly',(string)$assemblyId,
      'Confirmed insertion of verified scene #'.$sourceId.' at position '.($index+1).
      ' in revision '.$newRevision.' · Basis: '.$basis);
   });
 return $result+['inserted_index'=>$index,'source_id'=>$sourceId,
  'private_only'=>true,'publication_approved'=>false];
}
