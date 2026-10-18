<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video'),(5,'audio'),(6,'image')");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($v,int $max=300):string{return substr(trim((string)$v),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
$failAudit=false;
function sf_team_audit(int $id,string $evt,string $kind,string $entity,string $detail):void{
 global $failAudit;if($failAudit)throw new RuntimeException('Activity audit failed.');
}
function sf_catalog():array{
 return [['id'=>'stone-song-1','title'=>'Original Stonefellow song','duration'=>80,
  'metadata'=>['copyright_text'=>'Unverified sync license']]];
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-transcript-core.php';
require dirname(__DIR__).'/api/story-scene-core.php';
require dirname(__DIR__).'/api/story-assembly-core.php';
$n=0;
function assert88d(bool $pass,string $message):void{
 global $n;if(!$pass)throw new RuntimeException('FAIL '.$message);$n++;echo 'PASS '.$message.PHP_EOL;
}
function reject88d(callable $action,string $message):void{
 try{$action();throw new LogicException('Expected rejection');}
 catch(RuntimeException|InvalidArgumentException $e){assert88d(true,$message);}
}
$sceneSub=sf_capture_submit(2,['title'=>'Backstage wide','submission_type'=>'video',
 'description'=>'Stage and venue','rights_attested'=>true,'people_release_status'=>'unconfirmed'],4);
sf_capture_review(1,$sceneSub,'approved_for_edit');
$scene=sf_scene_create(1,$sceneSub,['origin'=>'human','start_ms'=>1000,'end_ms'=>4200,
 'description'=>'Verified wide backstage shot','tags'=>['venue']]);
reject88d(fn()=>sf_assembly_validate_item(['kind'=>'scene','source_id'=>(string)$scene,
 'in_ms'=>1000,'out_ms'=>4200,'duration_ms'=>3200]),'Unverified scene cannot enter sequence');
sf_scene_review(1,$scene,'verified');
$dialogueSub=sf_capture_submit(2,['title'=>'Band conversation','submission_type'=>'audio',
 'description'=>'Story dialogue','rights_attested'=>true,'people_release_status'=>'requires_review'],5);
sf_capture_review(1,$dialogueSub,'approved_for_edit');
$trans=sf_transcript_create(1,$dialogueSub,['origin'=>'human','language'=>'en',
 'segments'=>[['start_ms'=>100,'end_ms'=>3600,'text'=>'We are beginning the show.','speaker_label'=>'Band']]]);
sf_transcript_review(1,$trans,'verified');
$stillSub=sf_capture_submit(2,['title'=>'Venue detail','submission_type'=>'photo',
 'description'=>'Stage object','rights_attested'=>true,'people_release_status'=>'not_applicable'],6);
sf_capture_review(1,$stillSub,'approved_for_edit');
$still=sf_scene_create(1,$stillSub,['origin'=>'human','start_ms'=>0,'end_ms'=>0,
 'description'=>'Verified venue detail photograph','tags'=>[]]);
sf_scene_review(1,$still,'verified');
reject88d(fn()=>sf_assembly_create(2,'Unauthorized story'),'Non-editor may not create story');
$failAudit=true;
reject88d(fn()=>sf_assembly_create(1,'Unlogged assembly'),'Failed creation audit blocks insertion');
$failAudit=false;
assert88d((int)$db->query('SELECT COUNT(*) FROM story_assemblies')->fetchColumn()===0,
 'Failed creation audit leaves no untracked assembly');
$id=sf_assembly_create(1,'Opening night film','A private music and venue story');
assert88d($id>0,'Private story assembly created');
reject88d(fn()=>sf_assembly_list(2),'Contributor cannot list private assemblies');
reject88d(fn()=>sf_assembly_read(2,$id),'Contributor cannot read private story');
$candidates=sf_assembly_candidates(1);
assert88d(count($candidates)===4,'Verified scenes, verified transcript, and canonical song are discoverable');
assert88d(count(array_filter($candidates,fn($r)=>$r['kind']==='track'))===1,
 'Only existing catalog tracks are proposed as music');
assert88d(count(array_filter($candidates,fn($r)=>$r['kind']==='scene'))===2,
 'Both independently verified visual sources available');
$empty=sf_assembly_read(1,$id);
assert88d($empty['revision']==1&&!$empty['director']['ready_to_render']
  &&count($empty['director']['gaps'])===3,'Empty assembly has three explicit coverage gaps; rendering blocked');
$sceneItem=['kind'=>'scene','source_id'=>(string)$scene,'in_ms'=>1000,
 'out_ms'=>4200,'duration_ms'=>3200,'notes'=>'Opening establishing shot'];
$transItem=['kind'=>'transcript','source_id'=>(string)$trans,'in_ms'=>100,
 'out_ms'=>3600,'duration_ms'=>3500,'notes'=>'First narrative beat'];
$musicItem=['kind'=>'track','source_id'=>'stone-song-1','in_ms'=>0,
 'out_ms'=>20000,'duration_ms'=>20000,'notes'=>'Music transition; sync rights pending'];
$stillItem=['kind'=>'scene','source_id'=>(string)$still,'in_ms'=>0,
 'out_ms'=>0,'duration_ms'=>3500,'notes'=>'Closing detail'];
reject88d(fn()=>sf_assembly_replace(1,$id,1,[['kind'=>'track','source_id'=>'made-up',
 'in_ms'=>0,'out_ms'=>2000,'duration_ms'=>2000]]),'Invented music ID cannot be referenced');
reject88d(fn()=>sf_assembly_replace(1,$id,1,[array_merge($sceneItem,
 ['out_ms'=>9000,'duration_ms'=>8000])]),'Verified clip boundaries enforce original source interval');
reject88d(fn()=>sf_assembly_replace(1,$id,1,[array_merge($stillItem,
 ['out_ms'=>1000])]),'Still image cannot invent source timecodes');
reject88d(fn()=>sf_assembly_replace(1,$id,1,[array_merge($musicItem,
 ['out_ms'=>90000,'duration_ms'=>90000])]),'Song clip cannot exceed canonical track duration');
$record=sf_assembly_replace(1,$id,1,[$sceneItem,$transItem,$musicItem,$stillItem]);
assert88d($record['revision']===2&&$record['item_count']===4
  &&$record['duration_ms']===30200,'Four-source sequence committed at revision 2');
$read=sf_assembly_read(1,$id);
assert88d(count($read['items'])===4&&$read['items'][0]['source_id']===(string)$scene,
 'Timeline persists ordered, verified provenance IDs');
assert88d(count($read['director']['gaps'])===0&&!$read['director']['ready_to_render'],
 'Director finds basic coverage without asserting render/publication eligibility');
assert88d($read['director']['publication_rights_pending']===3,
 'Uncleared capture-based segments remain separately flagged');
assert88d(count($read['director']['notes'])>=2,
 'Director distinguishes pending publication and separate music synchronization rights');
reject88d(fn()=>sf_assembly_replace(1,$id,1,[$musicItem]),
 'Stale revision cannot overwrite another editor’s timeline');
assert88d(count(sf_assembly_read(1,$id)['items'])===4,
 'Conflict leaves original timeline intact');
$failAudit=true;
reject88d(fn()=>sf_assembly_replace(1,$id,2,[$musicItem]),
 'Audit failure rolls back edited sequence');
$failAudit=false;
assert88d(sf_assembly_read(1,$id)['revision']==2
  &&count(sf_assembly_read(1,$id)['items'])===4,'Failed audit rolls back revision and items');


$db->prepare("UPDATE story_scene_annotations SET review_status='rejected' WHERE id=?")->execute([$still]);
reject88d(fn()=>sf_assembly_replace(1,$id,2,[$stillItem]),
 'Revoked editorial verification blocks a new revision');
assert88d(sf_assembly_read(1,$id)['revision']==2,
 'Invalid source revision leaves current project intact');
assert88d(sf_assembly_read(1,$id)['director']['invalid_source_items']===1,
 'Director rescans source verification and removes revoked evidence from coverage');
sf_capture_rights_review(1,$sceneSub,'publication','cleared',
 'Independent source release documentation checked for publication permissions.');
assert88d(sf_assembly_read(1,$id)['director']['publication_rights_pending']===1,
 'Publication readiness recalculates clearance without counting invalid evidence');
$modified=sf_assembly_replace(1,$id,2,[$musicItem,$transItem]);
assert88d($modified['revision']===3&&$modified['item_count']===2,
 'Editor can reorder and remove verified clips with next revision');
assert88d(sf_assembly_read(1,$id)['items'][0]['kind']==='track',
 'Explicit editor ordering is persisted');
$api=(string)file_get_contents(dirname(__DIR__).'/admin/api/story-assembly.php');
assert88d(str_contains($api,"sf_admin_require_auth(".chr(36)."method!=='GET')")
 &&!str_contains($api,'sf_inference_run_once('),
 'Assembly API requires Admin write authorization and does not invoke models');
echo "88D3D: $n functional assertions passed".PHP_EOL;
