<?php
declare(strict_types=1);
/**
 * 88D2F — Editor-only, deterministic coverage suggestions for Artist World.
 * Recommendations do not imply that machine vision ran, contributors consented,
 * or footage is approved for publication.
 */
function sf_director_schema():void{
 static $ready=false;if($ready)return;
 sf_capture_schema();$db=sf_db();$sqlite=(sf_db_config()['driver']??'')==='sqlite';
 if($sqlite){
  $db->exec("CREATE TABLE IF NOT EXISTS story_director_drafts(
   recommendation_code TEXT PRIMARY KEY,request_id INTEGER NULL,created_by INTEGER NOT NULL,
   created_at TEXT NOT NULL,FOREIGN KEY(request_id) REFERENCES story_capture_requests(id) ON DELETE SET NULL,
   FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)");
 }else{
  $db->exec("CREATE TABLE IF NOT EXISTS story_director_drafts(
   recommendation_code VARCHAR(60) PRIMARY KEY,request_id BIGINT UNSIGNED NULL,
   created_by BIGINT UNSIGNED NOT NULL,created_at VARCHAR(40) NOT NULL,
   CONSTRAINT fk_director_request FOREIGN KEY(request_id) REFERENCES story_capture_requests(id) ON DELETE SET NULL,
   CONSTRAINT fk_director_author FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE RESTRICT)
   ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
 }
 $ready=true;
}
function sf_director_catalog():array{
 return [
  'establishing_video'=>['kind'=>'video','target_role'=>'videographer','min'=>2,
   'title'=>'Capture establishing footage','brief'=>'Wide establishing shot of the venue or surroundings, with a clean beginning and ending. Avoid identifiable bystanders without consent.'],
  'atmosphere_audio'=>['kind'=>'ambient','target_role'=>'sound_crew','min'=>1,
   'title'=>'Capture ambient sound','brief'=>'Record a clean ambient sound bed of the environment without private conversations or music that is not cleared for use.'],
  'authentic_dialogue'=>['kind'=>'dialogue','target_role'=>'band_member','min'=>1,
   'title'=>'Capture optional dialogue','brief'=>'Capture a voluntary short dialogue or personal account with an explicit release review for any recorded speakers.'],
  'photo_cutaways'=>['kind'=>'photo','target_role'=>'all','min'=>2,
   'title'=>'Contribute environmental cutaway photographs','brief'=>'Photograph contextual details, stage objects or the venue environment; avoid identifiable people without consent.']
 ];
}
function sf_director_coverage(int $editorId):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 sf_director_schema();
 if(!function_exists('sf_capture_rights_schema'))require_once __DIR__.'/story-capture-rights-core.php';
 sf_capture_rights_schema();$db=sf_db();
 $rows=$db->query("SELECT submission_type,COUNT(*) AS total FROM story_capture_submissions
  WHERE review_status='approved_for_edit' GROUP BY submission_type")->fetchAll(PDO::FETCH_ASSOC);
 $counts=[];foreach($rows as $r)$counts[(string)$r['submission_type']]=(int)$r['total'];
 $pending=(int)$db->query("SELECT COUNT(*) FROM story_capture_submissions WHERE review_status='pending_review'")->fetchColumn();
 // All contributor statements, including 'not_applicable', require a
 // separate human rights decision before external AI or publication.
 $clearance=(int)$db->query("SELECT COUNT(*) FROM story_capture_submissions s
  LEFT JOIN story_capture_rights_reviews r ON r.submission_id=s.id AND r.purpose='external_ai'
  WHERE s.review_status='approved_for_edit' AND COALESCE(r.status,'unreviewed')<>'cleared'")->fetchColumn();
 $aiReady=(int)$db->query("SELECT COUNT(*) FROM story_capture_submissions s
  JOIN story_capture_rights_reviews r ON r.submission_id=s.id AND r.purpose='external_ai'
  WHERE s.review_status='approved_for_edit' AND r.status='cleared'")->fetchColumn();
 $gaps=[];
 foreach(sf_director_catalog() as $code=>$spec){
  $count=$counts[$spec['kind']]??0;
  if($count>=$spec['min'])continue;
  $q=$db->prepare('SELECT request_id FROM story_director_drafts WHERE recommendation_code=?');
  $q->execute([$code]);$existing=$q->fetchColumn();
  $gaps[]=['code'=>$code,'shot_type'=>$spec['kind'],'target_role'=>$spec['target_role'],
   'title'=>$spec['title'],'brief'=>$spec['brief'],'approved_assets'=>$count,
   'recommended_minimum'=>$spec['min'],'draft_request_id'=>$existing===false?null:($existing===null?null:(int)$existing),
   'reason'=>'Insufficient reviewed '.$spec['kind'].' coverage for a basic editorial assembly'];
 }
 return ['gaps'=>$gaps,'approved_counts'=>$counts,'pending_review'=>$pending,
  'needs_likeness_or_release_review'=>$clearance,'external_ai_rights_cleared'=>$aiReady,
  'basis'=>'editorially approved submissions; independent AI rights clearance is separate',
  'executed_changes'=>false,'auto_publish'=>false];
}
function sf_director_create_draft(int $editorId,string $code):int{
 if(!sf_team_can($editorId,'team.assign_roles'))throw new RuntimeException('Production editor required.');
 if(!isset(sf_director_catalog()[$code]))
  throw new InvalidArgumentException('Unknown coverage recommendation.');
 $coverage=sf_director_coverage($editorId);
 $match=null;
 foreach($coverage['gaps'] as $gap)if($gap['code']===$code){$match=$gap;break;}
 if(!$match)throw new InvalidArgumentException('This coverage gap is already fulfilled.');
 $db=sf_db();
 $q=$db->prepare('SELECT request_id FROM story_director_drafts WHERE recommendation_code=?');
 $q->execute([$code]);$existing=$q->fetchColumn();
 if($existing!==false)throw new RuntimeException('This recommendation already has a draft or reserved request.');
 // Unique code reservation prevents duplicate AI-origin shot requests under concurrent calls.
 $reserve=$db->prepare('INSERT INTO story_director_drafts(recommendation_code,request_id,created_by,created_at) VALUES(?,?,?,?)');
 $reserve->execute([$code,null,$editorId,gmdate('c')]);
 try{
  $spec=sf_director_catalog()[$code];
  $id=sf_capture_create_request($editorId,['title'=>$spec['title'],'brief'=>$spec['brief'],
   'shot_type'=>$spec['kind'],'target_role'=>$spec['target_role'],
   'world_context'=>'Artist World coverage: '.$code,'creative_suggestion'=>$match['reason'],
   'suggestion_origin'=>'ai_suggestion','publish'=>false]);
  $db->prepare('UPDATE story_director_drafts SET request_id=? WHERE recommendation_code=?')
    ->execute([$id,$code]);
  sf_team_audit($editorId,'capture.director_draft','shot_request',(string)$id,'AI Director coverage draft; human approval required');
  return $id;
 }catch(Throwable $e){
  // Only an empty reservation may be removed. Existing drafts must never be recreated.
  $db->prepare('DELETE FROM story_director_drafts WHERE recommendation_code=? AND request_id IS NULL')->execute([$code]);
  throw $e;
 }
}

function sf_director_admin_brief(int $editorId):string{
 $coverage=sf_director_coverage($editorId);
 $lines=[];
 foreach($coverage['gaps'] as $gap){
  $lines[]=$gap['code'].': '.$gap['approved_assets'].'/'.$gap['recommended_minimum'].
   ' reviewed assets; '.($gap['draft_request_id']?'draft #'.$gap['draft_request_id']:'draft not yet created');
 }
 return 'Artist World Director coverage review (read-only). '.
  $coverage['pending_review'].' contribution(s) pending editorial review; '.
  $coverage['needs_likeness_or_release_review'].' accepted contribution(s) still require likeness/release assessment. '.
  ($lines?implode("\n",$lines):'Minimum starting shot coverage is present.').
  ' Suggested requests require an explicit editor action and remain unpublished drafts.';
}
