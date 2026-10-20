<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video')");
$failAudit=false;$events=[];
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($text,int $max=300):string{return substr(trim((string)$text),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_audit(int $id,string $action,string $kind,string $entity,string $description):void{
 global $failAudit,$events;
 if($failAudit)throw new RuntimeException('Simulated independent audit failure.');
 $events[]=['action'=>$action,'description'=>$description];
}
require dirname(__DIR__).'/api/story-capture-core.php';
$count=0;
function dPass(bool $ok,string $reason):void{
 global $count;if(!$ok)throw new RuntimeException('G5A decision FAIL: '.$reason);
 echo 'G5A PASS '.$reason.PHP_EOL;$count++;
}
function dReject(callable $fn,string $reason):void{
 $denied=false;try{$fn();}catch(InvalidArgumentException|RuntimeException $e){$denied=true;}
 dPass($denied,$reason);
}
$sub=sf_capture_submit(2,['submission_type'=>'video','title'=>'Private take','description'=>'Raw rehearsal','rights_attested'=>true],4);
$read=static function(int $id)use($db):string{
 $q=$db->prepare('SELECT review_status FROM story_capture_submissions WHERE id=?');
 $q->execute([$id]);return (string)$q->fetchColumn();
};
dReject(fn()=>sf_capture_review(2,$sub,'approved_for_edit'),
 'Contributor cannot approve their own submitted capture.');
dReject(fn()=>sf_capture_review(1,$sub,'published'),
 'Editorial review cannot publish or grant a license.');
dReject(fn()=>sf_capture_review(1,$sub,'approved_for_edit','short'),
 'Short evidence statements cannot be recorded.');
$failAudit=true;
dReject(fn()=>sf_capture_review(1,$sub,'approved_for_edit',
 'Documented stage continuity and clean visual framing.'),
 'Failed activity audit aborts editorial decision.');
dPass($read($sub)==='pending_review',
 'Failed audit rolls back the exact capture status.');
$failAudit=false;
sf_capture_review(1,$sub,'approved_for_edit',
 'Human inspected original video and verified framing and suitability.');
dPass($read($sub)==='approved_for_edit',
 'Explicit human review applies exact non-public edit decision.');
dPass(str_contains(end($events)['description'],'Human inspected original'),
 'Written human basis persists in the existing activity ledger.');
dReject(fn()=>sf_capture_review(1,$sub,'rejected',
 'Replayed stale decision must be rejected without mutation.'),
 'Second or stale review cannot rewrite the original decision.');
$sub2=sf_capture_submit(2,['submission_type'=>'video','title'=>'Second take','description'=>'Private rehearsal','rights_attested'=>true],4);
$db->beginTransaction();$failAudit=true;
dReject(fn()=>sf_capture_review(1,$sub2,'rejected',
 'Human review documents privacy and image quality concerns.'),
 'Nested transaction audit failure is rejected.');
dPass($db->inTransaction()&&$read($sub2)==='pending_review',
 'Savepoint rollback preserves surrounding transaction and pending status.');
$failAudit=false;$db->commit();
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-capture.php');
$client=(string)file_get_contents(dirname(__DIR__).'/admin/assets/agent-canvas.js');
dPass(str_contains($api,"'via_agent_chat'") && str_contains($api,'review_basis') &&
 str_contains($api,'confirmed') && str_contains($client,'data-agent-capture-review') &&
 str_contains($client,"send('review footage')"),
 'Chat-first UI and independently authenticated review endpoint share confirmation contract.');
echo "G5A CHAT DECISIONS: $count assertions passed".PHP_EOL;
