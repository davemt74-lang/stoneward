<?php
declare(strict_types=1);
require __DIR__.'/section88d2c-scenes.php';
require_once dirname(__DIR__).'/api/story-director-shot-matches-core.php';
$checks=0;
function bPass(bool $condition,string $message):void{
 global $checks;if(!$condition)throw new RuntimeException('G5B FAIL: '.$message);
 $checks++;echo 'G5B PASS '.$message.PHP_EOL;
}
function bDenied(callable $job,string $message):void{
 $denied=false;try{$job();}catch(RuntimeException|InvalidArgumentException $e){$denied=true;}
 bPass($denied,$message);
}
bDenied(fn()=>sf_director_verified_shot_matches(2,'Guitar stage'),
 'Contributor cannot see any private Director shot evidence.');
bPass(sf_director_verified_shot_matches(1,'guitar')['matches']===[],
 'Human-verified scene is not searchable until capture has its own editorial approval.');
sf_capture_review(1,$v,'approved_for_edit');
$m=sf_director_verified_shot_matches(1,'GUITAR stage');
bPass(count($m['matches'])===1&&$m['matches'][0]['scene_id']===$scene&&
 $m['matches'][0]['match_count']===2,
 'Two literal title/description matches produce a deterministic ranked scene.');
bPass($m['matches'][0]['start_ms']===1500&&$m['matches'][0]['end_ms']===2900,
 'Source clip timecodes are preserved without invented observations.');
bPass($m['matches'][0]['publication_clearance']==='unreviewed' &&
 !$m['matches'][0]['publication_ready'] && !$m['external_model_used'],
 'Matching never grants publication rights or asserts machine vision was run.');
bPass(sf_director_verified_shot_matches(1,'unrelated_term')['matches']===[],
 'Absent terms return no fabricated footage matches.');
bPass(count(sf_director_verified_shot_matches(1,'',1)['matches'])===1,
 'Topic-free shot list can show editor-verified clips, within requested limit.');
$before=(int)$db->query('SELECT COUNT(*) FROM story_scene_annotations')->fetchColumn();
sf_director_verified_shot_matches(1,'guitar');
$after=(int)$db->query('SELECT COUNT(*) FROM story_scene_annotations')->fetchColumn();
bPass($before===$after,'Read-only Director shot matching never changes scene records.');
$agent=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
$client=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
bPass(str_contains($agent,'sf_director_verified_shot_matches(') &&
 str_contains($agent,"'director_scene_matches'") &&
 str_contains($client,'data-agent-create-shot-draft') &&
 str_contains($client,"c.api('story-director.php'") &&
 str_contains($client,'director_scene_matches'),
 'Agent Chat links verified scene evidence with existing editor-only private draft workflow.');
echo "88D3G5B: $checks focused assertions passed".PHP_EOL;
