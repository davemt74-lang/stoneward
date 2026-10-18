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
require dirname(__DIR__).'/api/story-production-search-core.php';
$n=0;function ok(bool $b,string $why):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$why);$n++;echo "PASS ".$why.PHP_EOL;}
$approved=sf_capture_submit(2,['title'=>'Backstage take','submission_type'=>'video','description'=>'Scene behind the venue','rights_attested'=>true],4);
$private=sf_capture_submit(3,['title'=>'Backstage private','submission_type'=>'video','description'=>'Unreviewed','rights_attested'=>true],4);
sf_capture_review(1,$approved,'approved_for_edit');
$scene=sf_scene_create(2,$approved,['start_ms'=>1200,'end_ms'=>3400,'description'=>'Backstage doorway scene']);
sf_scene_review(1,$scene,'verified');
$transcript=sf_transcript_create(2,$approved,['segments'=>[['start_ms'=>200,'end_ms'=>950,'text'=>'Backstage ready']]]);
sf_transcript_review(1,$transcript,'verified');
$concept=sf_story_graph_create_entity(1,['entity_type'=>'location','label'=>'Backstage location']);
sf_story_graph_review_entity(1,$concept,'verified');
$found=sf_production_search(1,'Backstage',20);
ok(count($found)===4,'Returns four source-backed reviewed evidence types');
ok(count(array_unique(array_column($found,'source_type')))==4,'Capture, transcript, scene and concept all indexed');
ok(!in_array($private,array_column($found,'submission_id')),'Unreviewed submission hidden');
ok(count(sf_production_search(1,'%',20))===0,'LIKE wildcard is interpreted literally');
ok(count(sf_production_search(1,'Backstage',1))===1,'Search limit enforced');
ok(sf_production_search(1,'')===[],'Blank lookup returns no content');
foreach($found as $f)ok(isset($f['review_status'],$f['origin'],$f['people_release_status'])&&!isset($f['contributor_id'],$f['file_path']),'Result includes provenance status without private media paths');
try{sf_production_search(2,'Backstage');throw new LogicException('Fan allowed');}
catch(RuntimeException $e){ok(true,'Non-editor cannot query production graph');}
$brief=sf_production_search_admin_brief(1,'Backstage');
ok(str_contains($brief,'editorial-only')&&str_contains($brief,'SCENE'),'Agent Brain brief explicitly limits claim and names source');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
ok(str_contains($brain,"production_evidence_search")&&str_contains($brain,'sf_production_search_admin_brief'),'Existing Admin Agent Brain routes production retrieval');
echo "88D2E: $n checks passed".PHP_EOL;
