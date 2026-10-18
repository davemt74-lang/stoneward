<?php
declare(strict_types=1);
/**
 * 88D2E — Private editorial retrieval of approved production evidence.
 * Retrieval is deterministic: no private footage or contributor dialogue is
 * transmitted to an external model by this component.
 */
function sf_production_search(int $viewerId,string $query,int $limit=20):array{
 if($viewerId<1||!sf_team_can($viewerId,'team.assign_roles'))
  throw new RuntimeException('Production search requires editor authority.');
 $needle=sf_capture_clean($query,100);
 if($needle==='')return [];
 $limit=max(1,min(40,$limit));
 // Escape LIKE operators so a literal title search cannot fan out on '%'.
 $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$needle).'%';
 sf_capture_schema();sf_transcript_schema();sf_scene_schema();sf_story_graph_schema();
 $db=sf_db();$results=[];
 $spec=[
  ['capture','SELECT s.id,s.id AS submission_id,s.title AS excerpt,s.review_status,s.people_release_status,
     0 AS start_ms,0 AS end_ms,\'human\' AS origin
     FROM story_capture_submissions s
     WHERE s.review_status=\'approved_for_edit\'
     AND (s.title LIKE ? ESCAPE \'!\' OR s.description LIKE ? ESCAPE \'!\')
     ORDER BY s.id DESC LIMIT '.$limit],
  ['transcript','SELECT t.id,t.submission_id,g.dialogue_text AS excerpt,t.review_status,
     s.people_release_status,g.start_ms,g.end_ms,t.origin
     FROM story_transcript_segments g
     JOIN story_transcripts t ON t.id=g.transcript_id
     JOIN story_capture_submissions s ON s.id=t.submission_id
     WHERE t.review_status=\'verified\' AND s.review_status=\'approved_for_edit\'
     AND g.dialogue_text LIKE ? ESCAPE \'!\'
     ORDER BY g.id DESC LIMIT '.$limit],
  ['scene','SELECT n.id,n.submission_id,n.description AS excerpt,n.review_status,
     s.people_release_status,n.start_ms,n.end_ms,n.origin
     FROM story_scene_annotations n
     JOIN story_capture_submissions s ON s.id=n.submission_id
     WHERE n.review_status=\'verified\' AND s.review_status=\'approved_for_edit\'
     AND n.description LIKE ? ESCAPE \'!\'
     ORDER BY n.id DESC LIMIT '.$limit],
  ['concept','SELECT g.id,0 AS submission_id,g.label AS excerpt,g.review_status,
     \'not_applicable\' AS people_release_status,0 AS start_ms,0 AS end_ms,g.origin
     FROM story_graph_entities g WHERE g.review_status=\'verified\'
     AND (g.label LIKE ? ESCAPE \'!\' OR g.description LIKE ? ESCAPE \'!\')
     ORDER BY g.id DESC LIMIT '.$limit]
 ];
 foreach($spec as [$kind,$sql]){
  $q=$db->prepare($sql);
  $q->execute(in_array($kind,['capture','concept'],true)?[$like,$like]:[$like]);
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
   $results[]=['source_type'=>$kind,'source_id'=>(int)$row['id'],
    'submission_id'=>(int)$row['submission_id'],
    'excerpt'=>sf_capture_clean((string)$row['excerpt'],220),
    'review_status'=>(string)$row['review_status'],
    'people_release_status'=>(string)$row['people_release_status'],
    'start_ms'=>(int)$row['start_ms'],'end_ms'=>(int)$row['end_ms'],
    'origin'=>(string)$row['origin']];
  }
 }
 // No media URLs, owner identifiers, or unapproved submissions leave the search API.
 return array_slice($results,0,$limit);
}
function sf_production_search_admin_brief(int $editorId,string $query):string{
 $results=sf_production_search($editorId,$query,8);
 if(!$results)return 'No reviewed Artist World evidence matched this search. Try a specific character, scene, lyric, location or event. Nothing was published.';
 $lines=[];
 foreach($results as $row){
  $position=$row['start_ms']>0?' at '.number_format($row['start_ms']/1000,2).'s':'';
  $lines[]=strtoupper($row['source_type']).' #'.$row['source_id'].
   ' (submission #'.$row['submission_id'].$position.', release '.$row['people_release_status'].'): '.
   $row['excerpt'];
 }
 return 'Private Artist World production evidence. Results are editorial-only and not proof of likeness rights. '.implode("\n",$lines);
}
