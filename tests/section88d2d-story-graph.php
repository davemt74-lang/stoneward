<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-scene-core.php';
require dirname(__DIR__).'/api/story-transcript-core.php';
require dirname(__DIR__).'/api/story-graph-core.php';
$n=0;function ok(bool $b,string $what):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$what);$n++;echo "PASS ".$what.PHP_EOL;}
function rejects(callable $action,string $what):void{
 try{$action();throw new LogicException('accepted');}
 catch(InvalidArgumentException|RuntimeException $e){ok(true,$what);}
}
$submission=sf_capture_submit(2,['title'=>'Video','submission_type'=>'video','description'=>'Concert','rights_attested'=>true],4);
$other=sf_capture_submit(3,['title'=>'Other','submission_type'=>'video','description'=>'Stage','rights_attested'=>true],4);
$scene=sf_scene_create(2,$submission,['start_ms'=>0,'end_ms'=>1200,'description'=>'Stage entrance']);
$transcript=sf_transcript_create(2,$submission,['segments'=>[['start_ms'=>0,'end_ms'=>900,'text'=>'Welcome']],'language'=>'en']);
$entity=sf_story_graph_create_entity(1,['entity_type'=>'character','label'=>'Narrator','description'=>'Fictional story role','origin'=>'machine_draft']);
ok($entity>0,'Editor creates private proposed character');
ok(sf_story_graph_read_entity(1,$entity)['review_status']==='draft','Machine concept is not verified');
ok(sf_story_graph_read_entity(2,$entity)===null,'Contributor cannot browse story graph');
rejects(fn()=>sf_story_graph_create_entity(2,['entity_type'=>'character','label'=>'Leak']),'Contributor cannot create privileged entity');
$sceneLink=sf_story_graph_add_link(1,$entity,$submission,'scene',$scene,'appears_in');
ok($sceneLink>0,'Scene establishes provenance link');
$dialogueLink=sf_story_graph_add_link(1,$entity,$submission,'transcript',$transcript,'mentioned_in');
ok($dialogueLink>$sceneLink,'Transcript also establishes provenance');
ok(count(sf_story_graph_read_entity(1,$entity)['links'])===2,'Editor can inspect linked evidence');
rejects(fn()=>sf_story_graph_add_link(1,$entity,$other,'scene',$scene,'appears_in'),'Cross-submission evidence spoof rejected');
rejects(fn()=>sf_story_graph_add_link(1,$entity,$other,'transcript',$transcript,'mentioned_in'),'Cross-submission transcript spoof rejected');
rejects(fn()=>sf_story_graph_add_link(1,$entity,$submission,'submission',$other,'associated_with'),'Submission source mismatch rejected');
rejects(fn()=>sf_story_graph_add_link(2,$entity,$submission,'scene',$scene,'appears_in'),'Contributor cannot alter graph');
rejects(fn()=>sf_story_graph_add_link(1,$entity,$submission,'scene',$scene,'appears_in'),'Duplicate link rejected');
rejects(fn()=>sf_story_graph_review_entity(2,$entity,'verified'),'Contributor cannot verify character');
sf_story_graph_review_entity(1,$entity,'verified');
ok(sf_story_graph_read_entity(1,$entity)['review_status']==='verified','Editor verifies story concept');
rejects(fn()=>sf_story_graph_review_entity(1,$entity,'verified'),'Second verification rejected');
rejects(fn()=>sf_story_graph_create_entity(1,['entity_type'=>'person_identity','label'=>'Unverified identity']),'Real identity is not a graph entity type');
$loc=sf_story_graph_create_entity(1,['entity_type'=>'location','label'=>'Music hall']);
ok($loc>$entity,'Location concepts supported');
$event=sf_story_graph_create_entity(1,['entity_type'=>'event','label'=>'Show night']);
ok($event>$loc,'Event concepts supported');
echo "88D2D: $n checks passed".PHP_EOL;
