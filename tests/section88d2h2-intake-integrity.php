<?php
declare(strict_types=1);
// 88D2H2 integration: submission, audit and evidence must commit/rollback as one.
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT,created_by INTEGER)');
$db->exec("INSERT INTO media_assets VALUES(4,'image',2),(5,'video',2),(6,'image',2),(7,'image',3)");
$db->exec('CREATE TABLE media_links(asset_id INTEGER NOT NULL)');
$db->exec('CREATE TABLE intake_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{sf_db()->prepare('INSERT INTO intake_audit(user_id) VALUES(?)')->execute([$id]);}
function sf_media_delete_asset(int $id):bool{global $db;$q=$db->prepare('DELETE FROM media_assets WHERE id=?');$q->execute([$id]);return $q->rowCount()===1;}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-capture-intake-core.php';
$n=0;
function ok88h2(bool $b,string $label):void{global $n;if(!$b)throw new RuntimeException("FAIL $label");$n++;echo "PASS $label\n";}
$draft=['title'=>'Backstage still','submission_type'=>'photo','description'=>'Creative reference','rights_attested'=>true,'people_release_status'=>'unconfirmed'];
$obs=['sha256'=>str_repeat('a',64),'mime_type'=>'image/png','byte_size'=>73,'image_width'=>1,'image_height'=>1];
$id=sf_capture_store_with_evidence(2,$draft,4,$obs);
ok88h2($id>0,'Capture accepted with real evidence');
ok88h2((int)$db->query('SELECT COUNT(*) FROM story_media_evidence')->fetchColumn()===1,'Evidence inserted');
ok88h2((int)$db->query('SELECT COUNT(*) FROM intake_audit')->fetchColumn()===1,'Audit committed with capture');
$bad=$obs;$bad['mime_type']='video/mp4';
try{sf_capture_store_with_evidence(2,$draft,4,$bad);ok88h2(false,'Bad evidence must fail');}catch(InvalidArgumentException $e){ok88h2(true,'Invalid technical evidence rejects capture');}
ok88h2((int)$db->query('SELECT COUNT(*) FROM story_capture_submissions')->fetchColumn()===1,'Failed evidence rolls back submission');
ok88h2((int)$db->query('SELECT COUNT(*) FROM intake_audit')->fetchColumn()===1,'Failed evidence rolls back team audit');
$invalid=$draft;$invalid['people_release_status']='confirmed_without_review';
try{sf_capture_preflight_upload(2,$invalid);ok88h2(false,'Self certified release must fail');}catch(InvalidArgumentException $e){ok88h2(true,'Release preflight rejects unsupported claims');}
$invalid=$draft;$invalid['captured_at']='tomorrow-ish-no-valid-date';
try{sf_capture_preflight_upload(2,$invalid);ok88h2(false,'Invalid timestamp must fail');}catch(InvalidArgumentException $e){ok88h2(true,'Timestamp preflight rejects invalid captures');}
ok88h2(!sf_capture_cleanup_created_asset(2,4,false),'Deduplicated media never deleted');
ok88h2(!sf_capture_cleanup_created_asset(2,4,true),'Media referenced by accepted capture not deleted');
ok88h2(!sf_capture_cleanup_created_asset(2,7,true),'Another contributor media never deleted');
$db->exec('INSERT INTO media_links(asset_id) VALUES(6)');
ok88h2(!sf_capture_cleanup_created_asset(2,6,true),'Regular Media Library links protect new assets');
$db->exec('DELETE FROM media_links WHERE asset_id=6');
ok88h2(sf_capture_cleanup_created_asset(2,6,true),'New unreferenced contributor asset discarded');
ok88h2(sf_media_asset(6)===null&&sf_media_asset(4)!==null,'Discard cannot touch shared media');
echo "88D2H2: $n checks passed\n";
