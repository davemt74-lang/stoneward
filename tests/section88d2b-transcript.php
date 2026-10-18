<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'audio'),(5,'image')");
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
require dirname(__DIR__).'/api/story-transcript-core.php';
$n=0;
function ok(bool $b,string $why):void{global $n;if(!$b)throw new RuntimeException('FAIL: '.$why);$n++;echo "PASS ".$why.PHP_EOL;}
function denies(callable $action,string $why):void{
 try{$action();throw new LogicException('Unexpected acceptance');}
 catch(InvalidArgumentException|RuntimeException $e){ok(true,$why);}
}
$submission=sf_capture_submit(2,['submission_type'=>'audio','title'=>'Backstage sound','description'=>'Dialogue','rights_attested'=>true],4);
$segments=[['start_ms'=>0,'end_ms'=>1300,'speaker_label'=>'Speaker A','text'=>'Hello world'],['start_ms'=>1400,'end_ms'=>1900,'speaker_label'=>'','text'=>'Later line']];
$tid=sf_transcript_create(2,$submission,['origin'=>'human','language'=>'en','segments'=>$segments]);
ok($tid>0,'Contributor creates timecoded draft');
$row=sf_transcript_read(2,$tid);
ok($row['review_status']==='draft'&&count($row['segments'])===2,'Segments retained but unverified');
ok(sf_transcript_read(3,$tid)===null,'Other contributor denied private transcript');
ok(sf_transcript_read(1,$tid)!==null,'Admin can review private transcript');
denies(fn()=>sf_transcript_create(3,$submission,['segments'=>$segments]),'Unrelated contributor cannot write');
denies(fn()=>sf_transcript_create(2,$submission,['origin'=>'machine_draft','segments'=>$segments]),'Contributor cannot claim machine ingestion');
$machine=sf_transcript_create(1,$submission,['origin'=>'machine_draft','language'=>'und','segments'=>$segments]);
ok(sf_transcript_read(1,$machine)['review_status']==='draft','Machine text stays unverified');
denies(fn()=>sf_transcript_review(2,$tid,'verified'),'Contributor cannot self-verify');
sf_transcript_review(1,$tid,'verified');
ok(sf_transcript_read(2,$tid)['review_status']==='verified','Editor verifies immutable draft');
denies(fn()=>sf_transcript_review(1,$tid,'verified'),'Review cannot be repeated');
denies(fn()=>sf_transcript_create(2,$submission,['segments'=>[['start_ms'=>1000,'end_ms'=>0,'text'=>'Bad']]]),'Reversed timecodes rejected');
denies(fn()=>sf_transcript_create(2,$submission,['segments'=>[['start_ms'=>2000,'end_ms'=>2200,'text'=>'one'],['start_ms'=>100,'end_ms'=>300,'text'=>'two']]]),'Unordered segments rejected');
denies(fn()=>sf_transcript_create(2,$submission,['language'=>'bad_code','segments'=>$segments]),'Invalid language rejected');
denies(fn()=>sf_transcript_create(2,$submission,['segments'=>[]]),'Empty transcript rejected');
denies(fn()=>sf_transcript_create(2,$submission,['segments'=>[['start_ms'=>0,'end_ms'=>100,'text'=>'']]]),'Blank dialogue rejected');
$image=sf_capture_submit(2,['submission_type'=>'photo','title'=>'Still','description'=>'Still','rights_attested'=>true],5);
denies(fn()=>sf_transcript_create(2,$image,['segments'=>$segments]),'Still photo cannot receive speech transcript');
echo "88D2B: $n checks passed".PHP_EOL;
