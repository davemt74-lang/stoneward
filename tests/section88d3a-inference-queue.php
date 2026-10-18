<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$bytes='real private media bytes';
$sha=hash('sha256',$bytes);
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT,sha256 TEXT,size_bytes INTEGER)');
$q=$db->prepare('INSERT INTO media_assets VALUES(?,?,?,?)');
$q->execute([4,'video',$sha,strlen($bytes)]);
$db->exec('CREATE TABLE inference_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER,action TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
function sf_team_audit(int $id,string $event,string $kind,string $entity,string $desc):void{
  sf_db()->prepare('INSERT INTO inference_audit(actor_id,action) VALUES(?,?)')->execute([$id,$event]);
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-inference-queue-core.php';
$n=0;
function good(bool $b,string $name):void{
 global $n;if(!$b)throw new RuntimeException('FAIL '.$name);
 echo 'PASS '.$name.PHP_EOL;$n++;
}
function deny(callable $f,string $name):void{
 try{$f();throw new LogicException('Call unexpectedly succeeded');}
 catch(RuntimeException|InvalidArgumentException $e){good(true,$name);}
}
$obs=['sha256'=>$sha,'byte_size'=>strlen($bytes),'mime_type'=>'video/mp4','image_width'=>null,'image_height'=>null];
$draft=['title'=>'Camera footage','description'=>'Stage lighting','submission_type'=>'video',
 'rights_attested'=>true,'people_release_status'=>'not_applicable'];
$submission=sf_capture_submit(2,$draft,4);
deny(fn()=>sf_inference_enqueue(2,$submission,'transcript'),'Contributor cannot enqueue');
deny(fn()=>sf_inference_enqueue(1,$submission,'transcript'),'Unapproved capture blocked');
sf_evidence_record($submission,4,$obs);
sf_capture_review(1,$submission,'approved_for_edit');
deny(fn()=>sf_inference_enqueue(1,$submission,'transcript'),'Rights self-attestation alone blocked');
sf_capture_rights_review(1,$submission,'external_ai','cleared',
 'Signed appearance and media processing consent was verified by the editor.');
$first=sf_inference_enqueue(1,$submission,'transcript');
good($first['status']==='queued'&&!$first['existing'],'Verified and cleared capture enters queue');
$second=sf_inference_enqueue(1,$submission,'transcript');
good($second['job_id']===$first['job_id']&&$second['existing'],'Duplicate enqueue idempotent');
good((int)$db->query('SELECT COUNT(*) FROM story_inference_jobs')->fetchColumn()===1,'Only one durable job');
$jobs=sf_inference_jobs(1);
good(count($jobs)===1&&!array_key_exists('source_sha256',$jobs[0])
  &&!array_key_exists('lease_token_sha256',$jobs[0]),'Admin job list excludes private evidence and lease secrets');
deny(fn()=>sf_inference_jobs(2),'Fan cannot list inference queue');
$claimed=sf_inference_claim(1);
good($claimed!==null&&$claimed['job_id']===$first['job_id'],'Worker claims one bounded lease');
good(sf_inference_claim(1)===null,'No concurrent duplicate claim');
good(!sf_inference_lease_valid($claimed['job_id'],str_repeat('0',48)),'Incorrect lease rejected');
good(sf_inference_lease_valid($claimed['job_id'],$claimed['lease_token']),'Real lease and rights validated');
sf_capture_rights_review(1,$submission,'external_ai','restricted',
 'Consent was revoked; all further external AI processing must immediately stop.');
good(!sf_inference_lease_valid($claimed['job_id'],$claimed['lease_token']),'Revoked rights invalidate existing lease');
good(sf_inference_cancel(1,$claimed['job_id']),'Editor cancellation invalidates lease');
good(!sf_inference_lease_valid($claimed['job_id'],$claimed['lease_token']),'Cancelled lease cannot be used');
deny(fn()=>sf_inference_retry(2,$claimed['job_id']),'Contributor cannot retry inference');
deny(fn()=>sf_inference_retry(1,$claimed['job_id']),'Cancelled work cannot restart while rights revoked');
sf_capture_rights_review(1,$submission,'external_ai','cleared',
 'Updated private authorization evidence was reviewed and documented for processing.');
good(sf_inference_retry(1,$claimed['job_id']),'Explicit reviewer retry allowed with renewed rights');
$claimed=sf_inference_claim(1);
good($claimed!==null,'Retried task claimable after editor decision');
$db->prepare("UPDATE story_inference_jobs SET lease_expires_at=? WHERE id=?")
  ->execute([time()-1,$claimed['job_id']]);
good(!sf_inference_lease_valid($claimed['job_id'],$claimed['lease_token']),'Expired token invalid');
good(sf_inference_recover(1)===1,'Expired claim becomes interrupted');
good(sf_inference_claim(1)===null,'Interrupted external operation is never replayed automatically');
good(sf_inference_retry(1,$claimed['job_id']),'Human explicitly retries interrupted operation');
$claimed=sf_inference_claim(1);
good($claimed!==null,'Third and final attempt may be claimed');
$db->prepare("UPDATE story_inference_jobs SET lease_expires_at=? WHERE id=?")
  ->execute([time()-1,$claimed['job_id']]);
good(sf_inference_recover(1)===1,'Final expired attempt marked interrupted');
good(sf_inference_retry(1,$claimed['job_id'])===false,'Three-attempt cap prevents further retries');
good(sf_inference_claim(1)===null,'No hidden retry after budget exhaustion');
$scene=sf_inference_enqueue(1,$submission,'scene');
good($scene['existing']===false,'Independent visual analysis task can enqueue');
good($scene['status']==='queued'&&$scene['job_id']!==$first['job_id'],'Separate task types have separate durable jobs');
$q=$db->prepare('UPDATE media_assets SET sha256=? WHERE id=?');
$q->execute([str_repeat('a',64),4]);
good(sf_inference_claim(1)===null,'Altered source hashes fail closed when leasing');
$q=$db->prepare('SELECT status,error_code FROM story_inference_jobs WHERE id=?');
$q->execute([$scene['job_id']]);$state=$q->fetch();
good($state['status']==='cancelled'&&$state['error_code']==='authorization_changed','Source mutation cancels queued task');
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/story-inference.php');
good(str_contains($endpoint,'sf_admin_require_auth($method!==\'GET\')')
 &&!str_contains($endpoint,'sf_inference_claim('),'Admin API requires write CSRF and does not expose worker leasing');
echo "88D3A: $n assertions passed".PHP_EOL;
