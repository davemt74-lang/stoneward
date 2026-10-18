<?php
declare(strict_types=1);
// Real private directory fixture; reuse the complete 88D3D verified-source scenario.
$privateRoot=sys_get_temp_dir().'/sf88d3f-'.bin2hex(random_bytes(4));
if(!mkdir($privateRoot.'/storage/media/library',0700,true))
 throw new RuntimeException('Private fixture media directory unavailable.');
define('SF_ROOT',$privateRoot);
require __DIR__.'/section88d3d-story-assembly.php';
require dirname(__DIR__).'/api/story-composer-core.php';
require dirname(__DIR__).'/api/storyboard-preview-core.php';
$checks=0;
function success88f(bool $ok,string $message):void{
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 $checks++;echo 'PASS '.$message.PHP_EOL;
}
function deny88f(callable $fn,string $message):void{
 try{$fn();throw new LogicException('Expected refusal');}
 catch(InvalidArgumentException|RuntimeException $e){success88f(true,$message);}
}
$db->exec("ALTER TABLE media_assets ADD COLUMN stored_path TEXT NOT NULL DEFAULT ''");
$db->exec("ALTER TABLE media_assets ADD COLUMN size_bytes INTEGER NOT NULL DEFAULT 0");
$bytes='Private binary footage fixture for storyboard';$path=$privateRoot.'/storage/media/library/test-source.bin';
file_put_contents($path,$bytes);
$db->prepare('UPDATE media_assets SET stored_path=?,size_bytes=? WHERE id=?')->execute([
 'storage/media/library/test-source.bin',strlen($bytes),4]);
$db->prepare('UPDATE media_assets SET stored_path=?,size_bytes=? WHERE id=?')->execute([
 'storage/media/library/test-source.bin',strlen($bytes),5]);
$sequence=[$sceneItem,$transItem,$musicItem];
$r=sf_assembly_replace(1,$id,3,$sequence);
success88f($r['revision']===4,'New private assembly revision prepared for storyboard');
$first=sf_storyboard_preview(1,$id);
success88f(count($first['cards'])===3&&$first['total_ms']===26700,
 'Storyboard uses real source placement durations for three cards');
success88f($first['cards'][0]['start_ms']===0&&$first['cards'][0]['end_ms']===3200
 &&$first['cards'][1]['start_ms']===3200&&$first['cards'][2]['start_ms']===6700,
 'Virtual preview offsets are deterministic and contiguous');
success88f(!$first['render_allowed']&&!$first['publication_allowed']
 &&!$first['render_engine_available'],'No rendering or public output endpoint authorized');
$codes=fn($preview)=>array_column($preview['blockers'],'code');
success88f(in_array('publication_rights_not_cleared',$codes($first),true)
 &&in_array('music_sync_not_cleared',$codes($first),true)
 &&in_array('music_master_not_cleared',$codes($first),true),
 'Composer requirements distinguish contributor publication, music sync and master rights');
success88f($first['cards'][0]['asset_present']===true&&$first['cards'][1]['asset_present']===true,
 'Both verified capture source assets are present in private storage');
deny88f(fn()=>sf_storyboard_preview(2,$id),'Contributor cannot inspect private storyboard');
deny88f(fn()=>sf_storyboard_license_review(2,$id,'stone-song-1','sync','cleared',
 'Independently verified publishing authorization letter.',4),
 'Contributor cannot clear track rights');
deny88f(fn()=>sf_storyboard_license_review(1,$id,'nonexistent-track','sync','cleared',
 'Independently verified publishing authorization letter.',4),
 'Invented catalog track cannot receive license clearance');
deny88f(fn()=>sf_storyboard_license_review(1,$id,'stone-song-1','sync','cleared',
 'Unverified sentence not long enough',2),
 'Old revision cannot create a misleading music clearance');
deny88f(fn()=>sf_storyboard_license_review(1,$id,'stone-song-1','mechanical','cleared',
 'Independently verified publishing authorization letter.',4),
 'Unsupported music right type rejected');
deny88f(fn()=>sf_storyboard_license_review(1,$id,'stone-song-1','sync','cleared','yes',4),
 'Short self-attestation is not independent rights evidence');
$failAudit=true;
deny88f(fn()=>sf_storyboard_license_review(1,$id,'stone-song-1','sync','cleared',
 'Reviewed the separately signed audiovisual synchronization agreement.',4),
 'License decision rolls back when audit fails');
$failAudit=false;
success88f((int)$db->query('SELECT COUNT(*) FROM story_track_license_reviews')->fetchColumn()===0,
 'No music clearance persists after activity audit failure');
sf_storyboard_license_review(1,$id,'stone-song-1','sync','cleared',
 'Independently verified artist song-sync license for this specific film.',4);
success88f(in_array('music_master_not_cleared',$codes(sf_storyboard_preview(1,$id)),true),
 'Sync approval does not grant master-use rights');
sf_storyboard_license_review(1,$id,'stone-song-1','master','cleared',
 'Independently verified signed master-recording use agreement.',4);
success88f(!in_array('music_sync_not_cleared',$codes(sf_storyboard_preview(1,$id)),true)
 &&!in_array('music_master_not_cleared',$codes(sf_storyboard_preview(1,$id)),true),
 'Separate documented approvals satisfy both music scopes');
sf_capture_rights_review(1,$dialogueSub,'publication','cleared',
 'Signed contribution rights for this specific private assembly were independently checked.');
$ready=sf_storyboard_preview(1,$id);
success88f($ready['rights_and_local_assets_ready']&&count($ready['blockers'])===0,
 'Readiness clears only with verified sources, both music scopes and file presence');
success88f(!$ready['render_allowed']&&!$ready['publication_allowed']
 &&!$ready['hash_verification_at_render'],'Ready preflight never claims actual render certification');
success88f(!str_contains(json_encode($ready),$privateRoot)
 &&!str_contains(json_encode($ready),'test-source.bin'),'Private file path never leaves backend preview');
sf_storyboard_license_review(1,$id,'stone-song-1','master','restricted',
 'Master owner has explicitly withdrawn permission pending amendments.',4);
success88f(in_array('music_master_not_cleared',$codes(sf_storyboard_preview(1,$id)),true),
 'License revocation invalidates readiness immediately');
sf_storyboard_license_review(1,$id,'stone-song-1','master','cleared',
 'Updated master-use agreement independently verified and countersigned.',4);
unlink($path);
success88f(in_array('source_file_missing',$codes(sf_storyboard_preview(1,$id)),true),
 'Missing private media file immediately blocks preflight');
file_put_contents($path,$bytes.'changed');
success88f(in_array('source_file_missing',$codes(sf_storyboard_preview(1,$id)),true),
 'Byte-size mismatch blocks source playback readiness');
file_put_contents($path,$bytes);
sf_composer_schema();
$proposal=['beats'=>[['transition'=>'dissolve','reason'=>'Transition into the performer'],
  ['transition'=>'audio_bridge','reason'=>'Voice over the next beat'],
  ['transition'=>'fade','reason'=>'End with the song']]];
$db->prepare("INSERT INTO story_composer_proposals
 (assembly_id,revision,status,provider,model,suggestion_json,error_code,created_by,
  created_at,applied_revision) VALUES(?,?,'applied','openai','test',?,'',?,?,?)")
 ->execute([$id,3,json_encode($proposal),1,gmdate('c'),4]);
$trans=sf_storyboard_preview(1,$id);
success88f($trans['cards'][0]['transition']==='dissolve'&&$trans['cards'][2]['transition']==='fade',
 'Only applied composer proposal transitions appear as timed editorial cues');
sf_assembly_replace(1,$id,4,$sequence);
$stale=sf_storyboard_preview(1,$id);
success88f(in_array('music_sync_not_cleared',$codes($stale),true)
 &&in_array('music_master_not_cleared',$codes($stale),true),
 'Any changed assembly revision expires both prior music clearances');
success88f($stale['cards'][0]['transition']==='cut',
 'Old applied AI transitions do not carry into an unrelated revision');
$db->prepare("UPDATE story_scene_annotations SET review_status='rejected' WHERE id=?")->execute([$scene]);
success88f(in_array('source_not_verified',$codes(sf_storyboard_preview(1,$id)),true),
 'Revoked scene editorial verification blocks a previously assembled storyboard');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
success88f(str_contains($api,'review_music_rights')&&str_contains($api,
 "sf_admin_require_auth(".chr(36)."method!=='GET')"),
 'Admin license decisions require authenticated CSRF-protected writes');
unlink($path);rmdir($privateRoot.'/storage/media/library');
rmdir($privateRoot.'/storage/media');rmdir($privateRoot.'/storage');rmdir($privateRoot);
echo "88D3F: $checks functional assertions passed".PHP_EOL;
