<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'image'),(5,'video')");
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
require dirname(__DIR__).'/api/story-media-evidence-core.php';
$n=0;
function ok(bool $b,string $what):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$what);$n++;echo "PASS ".$what.PHP_EOL;}
$id=sf_capture_submit(2,['title'=>'Photo','submission_type'=>'photo','description'=>'Backstage','rights_attested'=>true],4);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8q7REAAAAASUVORK5CYII=');
$tmp=tempnam(sys_get_temp_dir(),'sf-evi-');
file_put_contents($tmp,$png);
try{
 $obs=sf_evidence_observe_upload(['tmp_name'=>$tmp]);
 ok($obs['byte_size']===strlen($png),'Byte count observed from file');
 ok($obs['sha256']===hash('sha256',$png),'Digest observed from actual bytes');
 ok(str_starts_with($obs['mime_type'],'image/'),'Image detected from bytes');
 sf_evidence_record($id,4,$obs);
 $owner=sf_evidence_for_submission(2,$id);
 ok((int)$owner['asset_id']===4,'Private evidence bound to captured asset');
 ok(sf_evidence_for_submission(3,$id)===null,'Other contributors cannot browse evidence');
 ok(sf_evidence_for_submission(1,$id)!==null,'Editorial admin can inspect evidence');
 $status=sf_capture_my_submissions(2)[0]['review_status'];
 ok($status==='pending_review','Indexing does not approve capture');
 try{sf_evidence_record($id,4,$obs);throw new RuntimeException('duplicate accepted');}
 catch(RuntimeException $e){ok($e->getMessage()==='Evidence is immutable once recorded.','Evidence is immutable');}
 $other=sf_capture_submit(2,['title'=>'Another','submission_type'=>'photo','description'=>'Shot','rights_attested'=>true],4);
 try{sf_evidence_record($other,5,$obs);throw new RuntimeException('mismatch accepted');}
 catch(InvalidArgumentException $e){ok(true,'Submission asset substitution rejected');}
 $bad=$obs;$bad['sha256']='unverified';
 try{sf_evidence_record($other,4,$bad);throw new RuntimeException('bad hash accepted');}
 catch(InvalidArgumentException $e){ok(true,'Malformed digest rejected');}
 $bad=$obs;$bad['mime_type']='video/mp4';
 try{sf_evidence_record($other,4,$bad);throw new RuntimeException('bad category accepted');}
 catch(InvalidArgumentException $e){ok(true,'MIME category substitution rejected');}
 $bad=$obs;$bad['byte_size']=-1;
 try{sf_evidence_record($other,4,$bad);throw new RuntimeException('bad size accepted');}
 catch(InvalidArgumentException $e){ok(true,'Invalid byte counts rejected');}
 echo "88D2A: $n checks passed".PHP_EOL;
}finally{unlink($tmp);}
