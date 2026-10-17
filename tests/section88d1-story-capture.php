<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'image')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return $id===2?['band_member','photographer']:[];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{}
require dirname(__DIR__).'/api/story-capture-core.php';
$n=0;
function chk(bool $b,string $why):void{global $n;if(!$b)throw new RuntimeException('Capture FAIL: '.$why);$n++;echo "PASS ".$why.PHP_EOL;}
chk(sf_capture_roles(3)===['fan'],'Signed in audience is Fan without team authority');
chk(in_array('band_member',sf_capture_roles(2),true)&&in_array('photographer',sf_capture_roles(2),true),'Contributor can have multiple professional roles');
chk(isset(sf_capture_role_types()['customer'])&&isset(sf_capture_role_types()['fan']),'Customer and fan audience role types are defined');
$req=sf_capture_create_request(1,['title'=>'Sunset establishing shot','brief'=>'Horizontal landscape',
 'shot_type'=>'video','target_role'=>'fan','publish'=>true]);
chk(count(sf_capture_requests(3))===1,'Published fan shot request is discoverable');
$ai=sf_capture_create_request(1,['title'=>'Suggested conversation','brief'=>'Short dialogue prompt',
 'shot_type'=>'dialogue','target_role'=>'fan','suggestion_origin'=>'ai_suggestion','publish'=>true]);
chk(count(sf_capture_requests(3))===1,'AI suggestions remain drafts until human approval');
sf_capture_publish_request(1,$ai,true);
chk(count(sf_capture_requests(3))===2,'Admin may review and publish suggested dialogue');
$crew=sf_capture_create_request(1,['title'=>'On-stage moment','brief'=>'Backstage concert clip',
 'shot_type'=>'video','target_role'=>'band_member','publish'=>true]);
chk(count(sf_capture_requests(3))===2&&count(sf_capture_requests(2))===3,'Role-scoped requests do not leak to fans');
try{sf_capture_submit(3,['title'=>'Unattested','submission_type'=>'story_idea','description'=>'Possible story']);throw new RuntimeException('accepted without rights');}catch(InvalidArgumentException $e){chk(true,'Rights attestation required');}
$id=sf_capture_submit(3,['title'=>'A little moment','submission_type'=>'story_idea',
 'description'=>'Birds outside the venue','rights_attested'=>true,'people_release_status'=>'unconfirmed']);
chk($id>0,'Unrequested general/background assets are supported');
$related=sf_capture_submit(2,['request_id'=>$crew,'title'=>'A line','submission_type'=>'dialogue',
 'dialogue_text'=>'Here is an idea','rights_attested'=>true,'people_release_status'=>'requires_review']);
chk($related>$id,'Authorized role can answer directed request');
try{sf_capture_submit(3,['request_id'=>$crew,'title'=>'Private shot','submission_type'=>'video',
 'description'=>'Try','rights_attested'=>true]);throw new RuntimeException('saw private request');}
catch(InvalidArgumentException $e){chk(true,'Fan cannot submit to a crew-only shot request');}
chk(sf_capture_my_submissions(3)[0]['id']===$id,'Fan sees own uploads and review state');
chk(count(sf_capture_my_submissions(2))===1,'Other fan contributions stay private');
chk(count(sf_capture_review_queue(1))===2,'Admin sees private review queue');
sf_capture_review(1,$id,'approved_for_edit');
chk(sf_capture_my_submissions(3)[0]['review_status']==='approved_for_edit','Review status visible to contributor');
try{sf_capture_review(1,$id,'approved_for_edit');throw new RuntimeException('double review');}
catch(InvalidArgumentException $e){chk(true,'Reviewed submission cannot be changed silently');}
chk(sf_capture_kind('screenshot')&&sf_capture_kind('ambient'),'Screenshots and ambient sound first-class');
chk(!sf_capture_kind('stripe_payment'),'Untrusted capture types rejected');
echo "CAPTURE: $n checks green".PHP_EOL;
