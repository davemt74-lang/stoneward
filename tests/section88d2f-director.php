<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video'),(5,'audio'),(6,'image')");
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
require dirname(__DIR__).'/api/story-director-core.php';
$n=0;function ok(bool $b,string $why):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$why);$n++;echo 'PASS '.$why.PHP_EOL;}
function rejects(callable $action,string $why):void{
 try{$action();throw new LogicException('Unexpected acceptance');}
 catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);}
}
$baseline=sf_director_coverage(1);
ok(count($baseline['gaps'])===4,'Four basic coverage categories begin unfilled');
ok($baseline['executed_changes']===false&&$baseline['auto_publish']===false,'Recommendation preview is read-only');
rejects(fn()=>sf_director_coverage(2),'Fan cannot inspect private coverage');
$upload=sf_capture_submit(2,['submission_type'=>'video','title'=>'Concert video','description'=>'Scene','rights_attested'=>true],4);
$still=sf_director_coverage(1);
ok($still['pending_review']===1,'Unreviewed footage is visible as pending, not approved coverage');
ok($still['gaps'][0]['approved_assets']===0,'Pending footage never counts as ready');
sf_capture_review(1,$upload,'approved_for_edit');
$covered=sf_director_coverage(1);
ok($covered['approved_counts']['video']===1,'Reviewed footage contributes to coverage');
ok($covered['needs_likeness_or_release_review']===1,'Uncleared likeness flags remain visible');
$id=sf_director_create_draft(1,'establishing_video');
ok($id>0,'Editor creates concrete AI Director recommendation');
$q=$db->prepare('SELECT review_status,suggestion_origin,target_role FROM story_capture_requests WHERE id=?');
$q->execute([$id]);$row=$q->fetch();
ok($row['review_status']==='draft'&&$row['suggestion_origin']==='ai_suggestion','AI request remains private draft');
ok($row['target_role']==='videographer','Director targets requested role');
ok(count(sf_capture_requests(2))===0,'Fan cannot discover draft recommendations');
ok(sf_director_coverage(1)['gaps'][0]['draft_request_id']===$id,'Repeated previews reuse same draft reference');
rejects(fn()=>sf_director_create_draft(1,'establishing_video'),'Duplicate Director suggestions are rejected');
rejects(fn()=>sf_director_create_draft(2,'atmosphere_audio'),'Contributor cannot create Director drafts');
rejects(fn()=>sf_director_create_draft(1,'invalid_kind'),'Unrecognized recommendation codes rejected');
$second=sf_capture_submit(2,['submission_type'=>'video','title'=>'Second video','description'=>'Scene','rights_attested'=>true,'people_release_status'=>'not_applicable'],4);
sf_capture_review(1,$second,'approved_for_edit');
$again=sf_director_coverage(1);
ok(!in_array('establishing_video',array_column($again['gaps'],'code'),true),'Fulfilled coverage gap no longer suggested');
ok(str_contains(sf_director_admin_brief(1),'unpublished drafts'),'Agent Brain brief preserves review boundary');
$brain=(string)file_get_contents(dirname(__DIR__).'/api/agent-admin-brain.php');
ok(str_contains($brain,'production_director_coverage')&&str_contains($brain,'sf_director_admin_brief'),'Admin Agent Brain can present read-only Director coverage');
echo "88D2F: $n checks passed".PHP_EOL;
