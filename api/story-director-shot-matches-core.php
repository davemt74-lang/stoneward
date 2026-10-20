<?php
declare(strict_types=1);
/**
 * Deterministic, editor-scoped shot matching from human-verified scene evidence.
 * Never asserts visual recognition, inferred identity, consent or model analysis.
 */
function sf_director_verified_shot_matches(int $editorId,string $topic='',int $limit=8):array{
 require_once __DIR__.'/story-director-scene-candidates.php';
 if($editorId<1||!sf_team_can($editorId,'team.assign_roles'))
  throw new RuntimeException('Production editor required.');
 $topic=sf_capture_clean($topic,100);
 $limit=max(1,min(12,$limit));
 $terms=array_values(array_unique(array_filter(preg_split('/\\s+/',strtolower($topic))?:[],
  static fn(string $word):bool=>strlen($word)>=3 && !in_array($word,['the','for','and','with','shot','shots','scene','scenes'],true))));
 $candidates=sf_director_scene_candidates($editorId,40);
 $ranked=[];
 foreach($candidates as $candidate){
  $text=strtolower($candidate['description'].' '.$candidate['capture_title'].' '.$candidate['location']);
  $score=0;$matched=[];
  foreach($terms as $term){
   if(str_contains($text,$term)){$score++;$matched[]=$term;}
  }
  if($terms&&$score===0)continue;
  // Stable ranking: more matches first, newest verified annotations next.
  $candidate['match_count']=$score;$candidate['matched_terms']=$matched;
  $candidate['editorial_only']=true;$candidate['publication_ready']=false;
  $ranked[]=$candidate;
 }
 usort($ranked,static fn(array $a,array $b):int=>
   ($b['match_count']<=>$a['match_count'])?:($b['scene_id']<=>$a['scene_id']));
 return ['query'=>$topic,'candidate_count'=>count($candidates),
  'matches'=>array_slice($ranked,0,$limit),
  'method'=>'literal terms in verified scene titles, descriptions and locations',
  'external_model_used'=>false,'auto_assigned'=>false,'auto_published'=>false];
}
