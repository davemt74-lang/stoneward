<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,status TEXT,role TEXT)');
$db->exec("INSERT INTO users VALUES(1,'Administrator','active','admin'),(2,'Lead Guitar','active','user'),
 (3,'Fan','active','user'),(4,'Other Musician','active','user'),(5,'Inactive','disabled','user')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_team_ensure_schema():void{}
function sf_team_roles(int $id):array{return in_array($id,[2,4,5],true)?['band_member']:[];}
function sf_clean_text(mixed $x,int $n=120):string{return substr(trim((string)$x),0,$n);}
function sf_team_audit(int $user,string $event,string $type,string $id,string $description,array $details=[]):void{global $audit;$audit[]=[$user,$event,$id];}
function sf_gallery_get(int $id,bool $publicOnly=false):?array{return $id===20?['id'=>20,'slug'=>'member-images','title'=>'Member Images','status'=>'published']:null;}
function sf_gallery_public_payload(array $g):array{return $g;}
function sf_media_links(string $type,string $id):array{return $type==='photo_gallery'&&$id==='20'?[['asset_id'=>44,'public_visible'=>1,'role'=>'photo']]:[];}
function sf_media_asset(int $id):?array{return $id===44?['id'=>44,'category'=>'image','alt_text'=>'Guitar player','credit'=>'Photographer']:null;}
function sf_media_managed_url(array $a,string $variant='original'):string{return 'api/media.php?id=M'.str_repeat('A',20);}
require dirname(__DIR__).'/api/band-member-core.php';
$checks=0;function assertBand(bool $ok,string $s):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$s);$checks++;echo "PASS $s\n";}
assertBand(!sf_band_is_member(3),'Fan cannot impersonate band role');
assertBand(sf_band_profile_public(2)===null,'Unreviewed member profile never public');
$own=sf_band_profile_update(2,2,['stage_name'=>'Stage Name','biography_draft'=>'Unpublished Biography','instruments'=>['Guitar','Vocals']]);
assertBand($own['biography_draft']==='Unpublished Biography','Existing account edits own draft');
assertBand(sf_band_profile_public(2)===null,'Draft does not publish itself');
try{sf_band_profile_update(4,2,['stage_name'=>'Imposter']);assertBand(false,'Cross-member write rejected');}
catch(RuntimeException $e){assertBand(true,'Cross-member write rejected');}
try{sf_band_profile_update(2,2,['gallery_id'=>20]);assertBand(false,'Member publishes likeness');}
catch(RuntimeException $e){assertBand(true,'Gallery choice is Admin-only');}
sf_band_profile_update(2,1,['gallery_id'=>20,'likeness_asset_id'=>44]);
try{sf_band_profile_publish(2,1,false);assertBand(false,'Unsigned likeness published');}
catch(InvalidArgumentException $e){assertBand(true,'Explicit likeness consent confirmation required');}
$public=sf_band_profile_publish(2,1,true);
assertBand($public['stage_name']==='Stage Name'&&$public['biography']==='Unpublished Biography','Only Admin approves biography');
assertBand($public['likeness']['asset_id']===44,'Public likeness must belong to currently published photo gallery');
assertBand($public['gallery']['slug']==='member-images','Gallery reused from existing Media Library');
sf_band_profile_update(2,2,['biography_draft'=>'Updated pending notes','stage_name'=>'Private next name','instruments'=>['Drums']]);
$stillPublic=sf_band_profile_public(2);
assertBand($stillPublic['biography']==='Unpublished Biography','Members cannot overwrite public copy without approval');
assertBand($stillPublic['stage_name']==='Stage Name'&&$stillPublic['instruments']===['Guitar','Vocals'],
 'Draft stage names and instruments do not leak into published public profile');
$k=sf_band_knowledge_save(2,2,'Recording setup','Amp configuration');
assertBand(count(sf_band_knowledge(2,true))===0,'Member knowledge drafts remain private');
sf_band_knowledge_approve($k,1);
assertBand(count(sf_band_knowledge(2,true))===1,'Admin-approved knowledge visible in published profile');
try{sf_band_knowledge_approve($k,2);assertBand(false,'Non-admin knowledge approval');}
catch(RuntimeException $e){assertBand(true,'Members cannot approve own knowledge');}
sf_band_profile_update(2,1,['likeness_asset_id'=>0]);
assertBand(sf_band_profile_public(2)['likeness']===null,'Image withdrawal immediately removes published likeness');
assertBand(count($audit)>=4,'Profile, knowledge and review actions have audit events');
$adminApi=file_get_contents(dirname(__DIR__).'/admin/api/band-members.php');
$teamApi=file_get_contents(dirname(__DIR__).'/api/team-member-profile.php');
$publicApi=file_get_contents(dirname(__DIR__).'/api/band-members.php');
assertBand(str_contains($adminApi,'sf_admin_require_auth(')&&str_contains($adminApi,'likeness_consent_confirmed'),'Admin confirmation on likeness publication');
assertBand(str_contains($teamApi,"sf_team_require('team.enter'")&&str_contains($publicApi,'sf_band_profile_public('),'Existing team login and public approval split');
echo "88C3A: $checks regression checks passed\n";
