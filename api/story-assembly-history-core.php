<?php
declare(strict_types=1);
/** 88D3G4G — immutable private metadata revision snapshots; no media or URLs. */
function sf_assembly_history_schema():void{
 static $ready=false;if($ready)return;
 sf_assembly_schema();$db=sf_db();
 if((sf_db_config()['driver']??'')==='sqlite'){
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_revisions(
   assembly_id INTEGER NOT NULL,revision INTEGER NOT NULL,items_json TEXT NOT NULL,
   digest TEXT NOT NULL,editor_id INTEGER NOT NULL,created_at TEXT NOT NULL,
   PRIMARY KEY(assembly_id,revision),
   FOREIGN KEY(assembly_id) REFERENCES story_assemblies(id) ON DELETE CASCADE,
   FOREIGN KEY(editor_id) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_assembly_revisions(
   assembly_id BIGINT UNSIGNED NOT NULL,revision INT NOT NULL,items_json MEDIUMTEXT NOT NULL,
   digest CHAR(64) NOT NULL,editor_id BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,
   PRIMARY KEY(assembly_id,revision),
   CONSTRAINT fk_story_history_assembly FOREIGN KEY(assembly_id)
    REFERENCES story_assemblies(id) ON DELETE CASCADE,
   CONSTRAINT fk_story_history_editor FOREIGN KEY(editor_id)
    REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
/** Must be called after schema initialization and within the caller's assembly transaction. */
function sf_assembly_history_archive(int $editor,int $assembly,int $revision,array $items):void{
 if($editor<1||$assembly<1||$revision<1||count($items)>50)
  throw new InvalidArgumentException('Invalid snapshot arguments.');
 $rows=[];
 foreach(array_values($items) as $item){
  if(!is_array($item)||!in_array((string)($item['kind']??''),['scene','transcript','track'],true))
   throw new InvalidArgumentException('Unknown archived editorial item.');
  $rows[]=['kind'=>(string)$item['kind'],'source_id'=>(string)$item['source_id'],
   'in_ms'=>(int)$item['in_ms'],'out_ms'=>(int)$item['out_ms'],
   'duration_ms'=>(int)$item['duration_ms'],'notes'=>(string)($item['notes']??'')];
 }
 $json=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 $digest=hash('sha256',$json);$db=sf_db();
 $q=$db->prepare('SELECT digest FROM story_assembly_revisions WHERE assembly_id=? AND revision=?');
 $q->execute([$assembly,$revision]);$old=$q->fetchColumn();
 if($old!==false){
  if(!hash_equals((string)$old,$digest))
   throw new RuntimeException('Archived revision conflicts with immutable history.');
  return;
 }
 $q=$db->prepare('INSERT INTO story_assembly_revisions
  (assembly_id,revision,items_json,digest,editor_id,created_at) VALUES(?,?,?,?,?,?)');
 $q->execute([$assembly,$revision,$json,$digest,$editor,gmdate('c')]);
}
function sf_assembly_history_read(int $editor,int $assembly,int $revision):array{
 sf_assembly_editor($editor);sf_assembly_history_schema();
 if($assembly<1||$revision<1)throw new InvalidArgumentException('Invalid revision.');
 $q=sf_db()->prepare('SELECT items_json,digest,editor_id,created_at FROM story_assembly_revisions
  WHERE assembly_id=? AND revision=?');
 $q->execute([$assembly,$revision]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new RuntimeException('Historical revision was not archived.');
 if(!hash_equals((string)$row['digest'],hash('sha256',(string)$row['items_json'])))
  throw new RuntimeException('Historical revision integrity failed.');
 $items=json_decode((string)$row['items_json'],true,32,JSON_THROW_ON_ERROR);
 if(!is_array($items)||!array_is_list($items)||count($items)>50)
  throw new RuntimeException('Historical items are invalid.');
 return ['revision'=>$revision,'items'=>$items,'editor_id'=>(int)$row['editor_id'],
  'created_at'=>$row['created_at'],'digest'=>$row['digest']];
}
function sf_assembly_history_list(int $editor,int $assembly):array{
 sf_assembly_editor($editor);sf_assembly_history_schema();
 $q=sf_db()->prepare('SELECT revision,editor_id,created_at,items_json FROM story_assembly_revisions
  WHERE assembly_id=? ORDER BY revision DESC LIMIT 80');
 $q->execute([$assembly]);$list=[];
 foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
  $items=json_decode((string)$row['items_json'],true);
  if(!is_array($items))throw new RuntimeException('History entries are malformed.');
  $list[]=['revision'=>(int)$row['revision'],'editor_id'=>(int)$row['editor_id'],
   'created_at'=>$row['created_at'],'item_count'=>count($items),
   'duration_ms'=>array_sum(array_map(static fn($x)=>(int)($x['duration_ms']??0),$items))];
 }
 return $list;
}
/** Ordinal and source-aware comparison, never edits either original revision. */
function sf_assembly_history_compare(int $editor,int $assembly,int $before,int $after):array{
 if($before===$after)throw new InvalidArgumentException('Compare two distinct revisions.');
 $a=sf_assembly_history_read($editor,$assembly,$before);
 $b=sf_assembly_history_read($editor,$assembly,$after);
 $old=$a['items'];$new=$b['items'];$rows=[];
 $id=static fn($v):string=>(string)$v['kind'].'|'.(string)$v['source_id'];
 $oldKeys=array_map($id,$old);$newKeys=array_map($id,$new);
 foreach($old as $i=>$clip) {
  if(!in_array($oldKeys[$i],$newKeys,true))
   $rows[]=['type'=>'removed','ordinal'=>$i+1,'kind'=>$clip['kind'],'source_id'=>$clip['source_id']];
 }
 foreach($new as $i=>$clip){
  $matches=array_keys($oldKeys,$newKeys[$i],true);
  $oldIndex=$matches[0]??null;
  if($oldIndex===null){
   $rows[]=['type'=>'added','ordinal'=>$i+1,'kind'=>$clip['kind'],'source_id'=>$clip['source_id']];
   continue;
  }
  // Stable deterministic first occurrence; ordinal is part of provenance for duplicates.
  $oldClip=$old[$oldIndex];
  if($oldIndex!==$i)$rows[]=['type'=>'reordered','ordinal'=>$i+1,'from_ordinal'=>$oldIndex+1,
    'kind'=>$clip['kind'],'source_id'=>$clip['source_id']];
  $changed=[];
  foreach(['in_ms','out_ms','duration_ms','notes'] as $field)
   if($oldClip[$field]!==$clip[$field])$changed[]=$field;
  if($changed)$rows[]=['type'=>'modified','ordinal'=>$i+1,'kind'=>$clip['kind'],
   'source_id'=>$clip['source_id'],'fields'=>$changed];
 }
 return ['from_revision'=>$before,'to_revision'=>$after,'from_count'=>count($old),
  'to_count'=>count($new),'from_duration_ms'=>array_sum(array_column($old,'duration_ms')),
  'to_duration_ms'=>array_sum(array_column($new,'duration_ms')),
  'changes'=>array_slice($rows,0,120),'private_only'=>true,'restored'=>false,
  'media_exported'=>false,'publication_allowed'=>false];
}
