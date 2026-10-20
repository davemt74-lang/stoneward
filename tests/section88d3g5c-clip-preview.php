<?php
declare(strict_types=1);
require __DIR__.'/section88d2c-scenes.php';
require_once dirname(__DIR__).'/api/story-director-scene-candidates.php';
$verified=0;
function cPass(bool $value,string $why):void{
 global $verified;if(!$value)throw new RuntimeException('G5C FAIL: '.$why);
 $verified++;echo 'G5C PASS '.$why.PHP_EOL;
}
cPass(sf_director_scene_candidates(1)===[],
 'Editor-only scene cards exclude captures awaiting separate editorial review.');
sf_capture_review(1,$v,'approved_for_edit');
$clips=sf_director_scene_candidates(1);
cPass(count($clips)===1&&$clips[0]['scene_id']===$scene&&$clips[0]['media_category']==='video',
 'Verified video scene binds correct source asset category.');
cPass($clips[0]['start_ms']===1500&&$clips[0]['end_ms']===2900,
 'Exact human-verified clip timecodes retained.');
$db->prepare('UPDATE story_scene_annotations SET asset_id=? WHERE id=?')->execute([5,$scene]);
cPass(sf_director_scene_candidates(1)===[],
 'Mismatched scene asset and capture source are excluded from private preview.');
$db->prepare('UPDATE story_scene_annotations SET asset_id=? WHERE id=?')->execute([4,$scene]);
$clips=sf_director_scene_candidates(1);
cPass(count($clips)===1&&$clips[0]['publication_clearance']==='unreviewed',
 'Source recovery does not infer publication or likeness clearance.');
$blocked=false;
try{sf_director_scene_candidates(2);}catch(RuntimeException|InvalidArgumentException $e){$blocked=true;}
cPass($blocked,'Contributor cannot enumerate reviewed clip metadata.');
$ui=file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
cPass(str_contains($ui,'data-agent-scene-start') &&
 str_contains($ui,'data-agent-scene-end') &&
 str_contains($ui,"video.addEventListener('loadedmetadata'") &&
 str_contains($ui,"video.addEventListener('timeupdate'") &&
 str_contains($ui,'story-footage-preview.php?submission_id='),
 'Scene timecodes control video playback through the editor-only preview endpoint.');
echo "G5C: $verified clip inspection checks passed".PHP_EOL;
