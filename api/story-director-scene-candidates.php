<?php
declare(strict_types=1);
/** Bounded, read-only, human-verified scene clip candidates. No machine footage analysis. */
function sf_director_scene_candidates(int $editorId,int $limit=24):array{
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 require_once __DIR__.'/story-scene-core.php';
 sf_scene_schema();
 $limit=max(1,min(40,$limit));
 $sql="SELECT n.id AS scene_id,n.submission_id,n.start_ms,n.end_ms,n.description,
   n.location_label,n.origin,s.title AS capture_title,s.people_release_status,
   COALESCE(r.status,'unreviewed') AS publication_clearance
   FROM story_scene_annotations n
   JOIN story_capture_submissions s ON s.id=n.submission_id
   LEFT JOIN story_capture_rights_reviews r
     ON r.submission_id=s.id AND r.purpose='publication'
   WHERE n.review_status='verified' AND s.review_status='approved_for_edit'
   ORDER BY n.id DESC LIMIT ".$limit;
 require_once __DIR__.'/story-capture-rights-core.php';
 sf_capture_rights_schema();
 $rows=sf_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
 return array_map(static function(array $r):array{
   return ['scene_id'=>(int)$r['scene_id'],'submission_id'=>(int)$r['submission_id'],
     'start_ms'=>(int)$r['start_ms'],'end_ms'=>(int)$r['end_ms'],
     'description'=>sf_capture_clean((string)$r['description'],240),
     'capture_title'=>sf_capture_clean((string)$r['capture_title'],180),
     'location'=>sf_capture_clean((string)$r['location_label'],180),
     'origin'=>(string)$r['origin'],
     'people_release_status'=>(string)$r['people_release_status'],
     'publication_clearance'=>(string)$r['publication_clearance']];
 },$rows);
}
