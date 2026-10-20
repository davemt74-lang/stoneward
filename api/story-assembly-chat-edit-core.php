<?php
declare(strict_types=1);
/**
 * G5D-2 — editor-confirmed conversational removal and scene replacement.
 * Uses existing verified-source, optimistic revision, history and audit gates.
 * No automatic edits, external media transfer, publishing, or rights grants.
 */
require_once __DIR__.'/story-assembly-chat-insert-core.php';

function sf_assembly_chat_edit_intent(string $message):array{
 $operation=preg_match('/\b(?:replace|swap)\b/i',$message)?'replace':'remove';
 $assemblyId=null;$index=null;
 if(preg_match('/\bassembly\s*#?\s*([1-9][0-9]{0,8})\b/i',$message,$match))
  $assemblyId=(int)$match[1];
 if(preg_match('/\b(?:clip|item)\s*#?\s*([1-9][0-9]{0,2})\b/i',$message,$match))
  $index=(int)$match[1]-1;
 $topic='';
 if($operation==='replace'&&preg_match('/\b(?:with|using)\s+(.+)$/i',$message,$match)){
  $topic=preg_replace('/\b(?:verified|footage|video|shot|scene|clip)\b/i',' ',(string)$match[1])??'';
  $topic=sf_capture_clean(preg_replace('/\s+/',' ',trim($topic))??'',100);
 }
 return ['operation'=>$operation,'assembly_id'=>$assemblyId,'index'=>$index,'topic'=>$topic];
}
function sf_assembly_chat_edit_preview(int $editorId,?int $assemblyId,?int $index,
 string $operation,string $topic=''):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 if(!in_array($operation,['remove','replace'],true))
  throw new InvalidArgumentException('Unsupported timeline edit operation.');
 require_once __DIR__.'/story-assembly-core.php';
 $topic=sf_capture_clean($topic,100);
 if($assemblyId===null){
  $projects=array_values(array_filter(sf_assembly_list($editorId),
    static fn(array $p):bool=>$p['status']==='draft'));
  return ['requires_selection'=>true,'assemblies'=>array_slice(array_map(
    static fn(array $p):array=>['id'=>(int)$p['id'],'title'=>(string)$p['title'],
      'revision'=>(int)$p['revision'],'item_count'=>(int)$p['item_count']],$projects),0,12),
    'assembly'=>null,'operation'=>$operation,'index'=>$index,'topic'=>$topic,
    'items'=>[],'matches'=>[],'executed_changes'=>false,'private_only'=>true];
 }
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly is unavailable for editorial changes.');
 $items=array_values((array)$assembly['items']);
 if($index!==null&&($index<0||$index>=count($items)))
  throw new InvalidArgumentException('Requested clip position does not exist.');
 $summary=array_map(static fn(array $row,int $i):array=>[
   'index'=>$i,'kind'=>(string)$row['kind'],'source_id'=>(string)$row['source_id'],
   'in_ms'=>(int)$row['in_ms'],'out_ms'=>(int)$row['out_ms'],
   'notes'=>sf_capture_clean((string)$row['notes'],240)],$items,array_keys($items));
 $matches=[];
 if($operation==='replace'&&$index!==null&&$items[$index]['kind']==='scene'){
  $candidates=sf_assembly_chat_insert_preview($editorId,$assemblyId,$topic);
  $matches=$candidates['matches'];
  // An existing scene can only be used once in a timeline, including replacements.
  $matches=array_slice(array_values(array_filter($matches,static fn(array $c):bool=>
    (string)$c['source_id']!==(string)$items[$index]['source_id'])),0,12);
 }
 return ['requires_selection'=>false,'assemblies'=>[],
  'assembly'=>['id'=>(int)$assembly['id'],'title'=>(string)$assembly['title'],
    'revision'=>(int)$assembly['revision'],'item_count'=>count($items)],
  'operation'=>$operation,'index'=>$index,'topic'=>$topic,
  'items'=>$summary,'matches'=>$matches,'executed_changes'=>false,'private_only'=>true];
}
function sf_assembly_chat_edit_baseline(int $editorId,int $assemblyId,int $revision,
 int $index,string $expectedSourceId):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 if($assemblyId<1||$revision<1||$index<0||$expectedSourceId==='')
  throw new InvalidArgumentException('Exact assembly revision, clip index and source required.');
 require_once __DIR__.'/story-assembly-core.php';
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||(int)$assembly['revision']!==$revision||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly changed or is unavailable. Refresh before editing.');
 $items=array_values((array)$assembly['items']);
 if(!isset($items[$index])||(string)$items[$index]['source_id']!==$expectedSourceId)
  throw new RuntimeException('The selected timeline source has changed.');
 $normalized=array_map(static fn(array $i):array=>[
  'kind'=>(string)$i['kind'],'source_id'=>(string)$i['source_id'],
  'in_ms'=>(int)$i['in_ms'],'out_ms'=>(int)$i['out_ms'],
  'duration_ms'=>(int)$i['duration_ms'],'notes'=>(string)$i['notes']],$items);
 return ['assembly'=>$assembly,'items'=>$normalized];
}
function sf_assembly_chat_remove(int $editorId,int $assemblyId,int $revision,
 int $index,string $expectedSourceId,string $basis):array{
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document removal basis (25+ characters).');
 $base=sf_assembly_chat_edit_baseline($editorId,$assemblyId,$revision,$index,$expectedSourceId);
 $items=$base['items'];$removed=$items[$index];
 array_splice($items,$index,1);
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$items,
  static function(int $appliedRevision)use($editorId,$assemblyId,$index,$removed,$basis):void{
   sf_team_audit($editorId,'capture.assembly_chat_remove','story_assembly',(string)$assemblyId,
    'Human-confirmed private removal at position '.($index+1).
    ' ('.$removed['kind'].' #'.$removed['source_id'].') on revision '.$appliedRevision.
    ' · Basis: '.$basis);
  });
 return $result+['removed_source_id'=>$removed['source_id'],
   'removed_index'=>$index,'private_only'=>true,'media_asset_deleted'=>false];
}
function sf_assembly_chat_replace(int $editorId,int $assemblyId,int $revision,
 int $index,string $expectedSourceId,string $newSourceId,string $basis):array{
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document replacement basis (25+ characters).');
 if(!preg_match('/^[1-9][0-9]{0,17}$/D',$newSourceId))
  throw new InvalidArgumentException('New verified scene ID required.');
 $base=sf_assembly_chat_edit_baseline($editorId,$assemblyId,$revision,$index,$expectedSourceId);
 $items=$base['items'];
 if($items[$index]['kind']!=='scene')
  throw new InvalidArgumentException('Choose a verified scene to replace another scene.');
 if($expectedSourceId===$newSourceId)
  throw new InvalidArgumentException('Replacement source must differ from existing scene.');
 foreach($items as $pos=>$item)
  if($pos!==$index&&$item['kind']==='scene'&&$item['source_id']===$newSourceId)
   throw new RuntimeException('Replacement scene is already present elsewhere in the timeline.');
 $candidate=null;
 foreach(sf_assembly_candidates($editorId) as $entry)
  if($entry['kind']==='scene'&&(string)$entry['source_id']===$newSourceId){
   $candidate=$entry;break;
  }
 if(!$candidate)throw new RuntimeException('New verified footage is unavailable or revoked.');
 $replacement=sf_assembly_validate_item($candidate);
 $replacement['notes']='Replaced after editor review in Agent Chat';
 $items[$index]=$replacement;
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$items,
   static function(int $appliedRevision)use($editorId,$assemblyId,$index,
    $expectedSourceId,$newSourceId,$basis):void{
    sf_team_audit($editorId,'capture.assembly_chat_replace','story_assembly',(string)$assemblyId,
      'Human-confirmed scene #'.$expectedSourceId.' replaced with scene #'.$newSourceId.
      ' at position '.($index+1).' on revision '.$appliedRevision.' · Basis: '.$basis);
   });
 return $result+['replaced_index'=>$index,'previous_source_id'=>$expectedSourceId,
  'new_source_id'=>$newSourceId,'private_only'=>true,'publication_approved'=>false];
}
