<?php
declare(strict_types=1);
/**
 * G5D: governed chat reorder. Only swaps two adjacent existing timeline items,
 * never invents source IDs, changes clip trim, transfers media or publishes.
 */
function sf_assembly_chat_move(int $editorId,int $assemblyId,int $revision,int $index,
 string $direction,string $basis):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 if(!in_array($direction,['up','down'],true))
  throw new InvalidArgumentException('Invalid timeline direction.');
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document review basis (25+ characters).');
 require_once __DIR__.'/story-assembly-core.php';
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$revision<1||(int)$assembly['revision']!==$revision||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly changed or cannot be edited. Refresh it before review.');
 $items=(array)$assembly['items'];
 $target=$index+($direction==='up'?-1:1);
 if($index<0||$index>=count($items)||$target<0||$target>=count($items))
  throw new InvalidArgumentException('No adjacent clip in that direction.');
 // Normalize PDO numeric fields so the existing independent source validator
 // receives exactly its required types on SQLite and MySQL.
 $items=array_map(static fn(array $i):array=>[
  'kind'=>(string)$i['kind'],'source_id'=>(string)$i['source_id'],
  'in_ms'=>(int)$i['in_ms'],'out_ms'=>(int)$i['out_ms'],
  'duration_ms'=>(int)$i['duration_ms'],'notes'=>(string)$i['notes']],$items);
 [$items[$index],$items[$target]]=[$items[$target],$items[$index]];
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$items,
   static function(int $appliedRevision)use($editorId,$assemblyId,$index,$target,$basis):void{
     sf_team_audit($editorId,'capture.assembly_chat_move','story_assembly',(string)$assemblyId,
       'Confirmed chat timeline move '.($index+1).' to '.($target+1).
       ' on revision '.$appliedRevision.' · Basis: '.$basis);
   });
 return $result+['moved_from'=>$index,'moved_to'=>$target,'private_only'=>true];
}

/**
 * G5D: adjust one already verified audiovisual/dialogue source interval.
 * This remains a private editorial timeline change, never a render/export.
 */
function sf_assembly_chat_trim(int $editorId,int $assemblyId,int $revision,int $index,
 int $inMs,int $outMs,string $basis):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 $basis=sf_capture_clean($basis,400);
 if(strlen($basis)<25)throw new InvalidArgumentException('Document review basis (25+ characters).');
 if($inMs<0||$outMs<=$inMs||$outMs>86400000||$outMs-$inMs<500||
    $outMs-$inMs>600000)
  throw new InvalidArgumentException('Clip must span 0.5–600 seconds inside a source.');
 require_once __DIR__.'/story-assembly-core.php';
 $assembly=sf_assembly_read($editorId,$assemblyId);
 if(!$assembly||$revision<1||(int)$assembly['revision']!==$revision||$assembly['status']!=='draft')
  throw new RuntimeException('Assembly changed or cannot be edited. Refresh it before review.');
 $items=(array)$assembly['items'];
 if($index<0||$index>=count($items))throw new InvalidArgumentException('Invalid clip position.');
 $items=array_map(static fn(array $i):array=>[
  'kind'=>(string)$i['kind'],'source_id'=>(string)$i['source_id'],
  'in_ms'=>(int)$i['in_ms'],'out_ms'=>(int)$i['out_ms'],
  'duration_ms'=>(int)$i['duration_ms'],'notes'=>(string)$i['notes']],$items);
 if($items[$index]['kind']==='scene'){
  // Stills have no source interval. Their timeline display duration is separate.
  $q=sf_db()->prepare('SELECT a.category FROM story_scene_annotations n
    JOIN media_assets a ON a.id=n.asset_id WHERE n.id=?');
  $q->execute([(int)$items[$index]['source_id']]);
  if($q->fetchColumn()!=='video')throw new InvalidArgumentException('Still images have no trimmable source range.');
 }
 if($items[$index]['in_ms']===$inMs&&$items[$index]['out_ms']===$outMs)
  throw new InvalidArgumentException('No changes to the selected clip.');
 $items[$index]['in_ms']=$inMs;$items[$index]['out_ms']=$outMs;
 $items[$index]['duration_ms']=$outMs-$inMs;
 // sf_assembly_replace repeats source validity and exact expected revision
 // inside a transaction; audit failure rolls back every timeline mutation.
 $result=sf_assembly_replace($editorId,$assemblyId,$revision,$items,
   static function(int $newRevision)use($editorId,$assemblyId,$index,$inMs,$outMs,$basis):void{
    sf_team_audit($editorId,'capture.assembly_chat_trim','story_assembly',(string)$assemblyId,
      'Confirmed chat trim clip '.($index+1).' to '.$inMs.'–'.$outMs.
      'ms on revision '.$newRevision.' · Basis: '.$basis);
   });
 return $result+['trimmed_index'=>$index,'in_ms'=>$inMs,'out_ms'=>$outMs,'private_only'=>true];
}
