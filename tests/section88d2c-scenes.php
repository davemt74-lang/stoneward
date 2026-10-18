<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video'),(5,'image'),(6,'audio')");
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
$n=0;
function ok(bool $b,string $why):void{global $n;if(!$b)throw new RuntimeException('FAIL: '.$why);$n++;echo "PASS ".$why.PHP_EOL;}
function rejects(callable $f,string $why):void{
 try{$f();throw new LogicException('Unexpected acceptance');}
 catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);}
}
$v=sf_capture_submit(2,['submission_type'=>'video','title'=>'Concert','description'=>'Backstage','rights_attested'=>true],4);
$input=['start_ms'=>1500,'end_ms'=>2900,'description'=>'Guitar player enters stage','tags'=>['music','backstage'],'location_label'=>'Venue backstage'];
$scene=sf_scene_create(2,$v,$input);
ok($scene>0,'Contributor annotates own captured video');
$row=sf_scene_read(2,$scene);
ok($row['review_status']==='draft'&&$row['start_ms']==1500,'Timecoded scene remains draft');
ok($row['tags']===['music','backstage'],'Structured scene tags retained');
ok(sf_scene_read(3,$scene)===null,'Other contributors cannot access scene');
ok(sf_scene_read(1,$scene)!==null,'Editor may inspect scene');
rejects(fn()=>sf_scene_create(3,$v,$input),'Cross-account scene write denied');
rejects(fn()=>sf_scene_create(2,$v,$input+['origin'=>'machine_draft']),'Contributor cannot insert machine draft');
$ai=sf_scene_create(1,$v,array_merge($input,['origin'=>'machine_draft']));
ok(sf_scene_read(1,$ai)['review_status']==='draft','AI-origin scene cannot auto-verify');
rejects(fn()=>sf_scene_review(2,$scene,'verified'),'No self-verification');
sf_scene_review(1,$scene,'verified');
ok(sf_scene_read(2,$scene)['review_status']==='verified','Editor decision recorded');
rejects(fn()=>sf_scene_review(1,$scene,'verified'),'Cannot repeatedly approve same scene');
rejects(fn()=>sf_scene_create(2,$v,array_merge($input,['end_ms'=>1499])),'Invalid video timecodes rejected');
rejects(fn()=>sf_scene_create(2,$v,array_merge($input,['tags'=>['bad/tag']])),'Invalid tags rejected');
rejects(fn()=>sf_scene_create(2,$v,array_merge($input,['description'=>''])),'Empty description rejected');
$still=sf_capture_submit(2,['submission_type'=>'photo','title'=>'Still','description'=>'Shot','rights_attested'=>true],5);
rejects(fn()=>sf_scene_create(2,$still,$input),'Still image rejects video timecodes');
$stillId=sf_scene_create(2,$still,array_merge($input,['start_ms'=>0,'end_ms'=>0]));
ok($stillId>0,'Still image annotation supports zero timecodes');
$audio=sf_capture_submit(2,['submission_type'=>'audio','title'=>'Audio','description'=>'Sound','rights_attested'=>true],6);
rejects(fn()=>sf_scene_create(2,$audio,array_merge($input,['start_ms'=>0,'end_ms'=>0])),'Audio cannot be visually annotated');
echo "88D2C: $n checks passed".PHP_EOL;
