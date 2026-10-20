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
