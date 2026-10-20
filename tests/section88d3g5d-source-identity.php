<?php
declare(strict_types=1);
require __DIR__.'/section88d3d-story-assembly.php';
$verified=0;
function identityPass(bool $v,string $label):void{
 global $verified;
 if(!$v)throw new RuntimeException('SOURCE IDENTITY FAIL: '.$label);
 $verified++;echo 'SOURCE IDENTITY PASS '.$label.PHP_EOL;
}
function identityDeny(callable $fn,string $label):void{
 $denied=false;try{$fn();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
 identityPass($denied,$label);
}
$before=sf_assembly_candidates(1);
identityPass(in_array((string)$scene,array_column(
 array_filter($before,static fn(array $c):bool=>$c['kind']==='scene'),'source_id'),true),
 'Approved verified video scene is available before any identity corruption.');
identityPass(sf_assembly_validate_item($sceneItem)['source_id']===(string)$scene,
 'Original scene source is validated against a real approved capture.');
$db->exec("INSERT INTO media_assets(id,category) VALUES(7,'video')");
$db->prepare('UPDATE story_scene_annotations SET asset_id=? WHERE id=?')->execute([7,$scene]);
$after=sf_assembly_candidates(1);
identityPass(!in_array((string)$scene,array_column(
 array_filter($after,static fn(array $c):bool=>$c['kind']==='scene'),'source_id'),true),
 'A different valid video asset cannot masquerade as the approved capture source.');
identityDeny(fn()=>sf_assembly_validate_item($sceneItem),
 'Existing editorial revisions reject stale scene-to-capture media bindings.');
identityDeny(fn()=>sf_assembly_replace(1,$id,3,[$sceneItem]),
 'Atomic assembly write cannot reintroduce an altered media source.');
$db->prepare('UPDATE story_scene_annotations SET asset_id=? WHERE id=?')->execute([4,$scene]);
identityPass(sf_assembly_validate_item($sceneItem)['source_id']===(string)$scene,
 'Restoring original source identity restores authorized verified scene validity.');
echo "G5D SOURCE IDENTITY: $verified checks passed".PHP_EOL;
