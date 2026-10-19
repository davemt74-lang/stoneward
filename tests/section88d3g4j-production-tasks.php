<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active'),(4,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video'),(5,'image')");
$roles=[2=>['videographer'],3=>['producer'],4=>['band_member']];$auditFail=false;$events=[];
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{global $roles;return $roles[$id]??[];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{
 global $auditFail,$events;if($auditFail)throw new RuntimeException('Simulated activity audit failure.');
 $events[]=['actor'=>$id,'event'=>$a,'entity'=>$entity];
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-director-core.php';
function sf_composer_schema():void{}
require dirname(__DIR__).'/api/story-production-tasks-core.php';
$n=0;
function g4j(bool $ok,string $label):void{
 global $n;if(!$ok)throw new RuntimeException('G4J FAIL: '.$label);
 $n++;echo 'PASS G4J: '.$label.PHP_EOL;
}
function noG4j(callable $job,string $label):void{
 try{$job();throw new LogicException('Unsafe task operation succeeded: '.$label);}
 catch(RuntimeException|InvalidArgumentException $e){g4j(true,$label);}
}
$coverage=sf_director_coverage(1);
g4j(count($coverage['gaps'])===4,'AI Director derives four unfilled coverage categories from actual review records.');
noG4j(fn()=>sf_production_task_propose_gap(2,'establishing_video'),'Non-editor cannot create crew tasks.');
noG4j(fn()=>sf_production_task_propose_gap(1,'not_a_gap'),'No invented Director task source.');
$first=sf_production_task_propose_gap(1,'establishing_video');$id=$first['id'];
g4j($id>0&&!$first['existing'],'Editor action creates first private verified gap task.');
$repeat=sf_production_task_propose_gap(1,'establishing_video');
g4j($repeat['existing']&&$repeat['id']===$id,'Repeat action reuses exact gap task.');
$req=(int)sf_production_task_read(1,$id)['source_id'];
$visible=sf_capture_requests(2);
g4j(!in_array($req,array_map('intval',array_column($visible,'id')),true),
 'Director task creation does not open unpublished AI shot requests.');
noG4j(fn()=>sf_production_task_assign(1,$id,3),'Cannot assign videographer task to producer-only member.');
noG4j(fn()=>sf_production_task_assign(2,$id,2),'Contributor cannot assign themselves.');
$auditFail=true;
noG4j(fn()=>sf_production_task_assign(1,$id,2),'Failed assignment audit blocks task assignment.');
$auditFail=false;
g4j(sf_production_task_read(1,$id)['status']==='proposed',
 'Audit rollback leaves task without a phantom assignee.');
sf_production_task_assign(1,$id,2);
g4j(count(sf_production_task_list(2))===1&&count(sf_production_task_list(3))===0,
 'Task inbox is scoped to assigned contributor only.');
noG4j(fn()=>sf_production_task_read(3,$id),'Unassigned contributor cannot inspect private assignment.');
noG4j(fn()=>sf_capture_submit(2,['request_id'=>$req,'title'=>'Unreviewed AI request',
 'submission_type'=>'video','description'=>'Test','rights_attested'=>true],4),
 'Draft shot request cannot receive production evidence until editor opens it.');
sf_capture_publish_request(1,$req,true);
$evidence=sf_capture_submit(2,['request_id'=>$req,'title'=>'Venue establishing footage',
 'submission_type'=>'video','description'=>'Wide continuous scene','rights_attested'=>true],4);
noG4j(fn()=>sf_production_task_submit(3,$id,$evidence,'Producer attempted чуж evidence.'),
 'Wrong user cannot complete videographer task.');
noG4j(fn()=>sf_production_task_submit(2,$id,999,'Submitted fake media for approval.'),
 'Unverified or invented capture cannot be evidence.');
$auditFail=true;
noG4j(fn()=>sf_production_task_submit(2,$id,$evidence,'Wide opening venue establishing footage recorded.'),
 'Failed activity audit aborts evidence submission.');
$auditFail=false;
g4j(sf_production_task_read(1,$id)['status']==='assigned',
 'Audited evidence submission is transactional.');
sf_production_task_submit(2,$id,$evidence,'Wide opening venue establishing footage recorded.');
g4j(sf_production_task_read(1,$id)['status']==='submitted',
 'Contributor evidence is proposed, not marked complete.');
noG4j(fn()=>sf_production_task_review(1,$id,'approved',str_repeat('Verified venue footage. ',2)),
 'Task cannot be approved before capture receives independent editorial approval.');
noG4j(fn()=>sf_production_task_review(2,$id,'approved',str_repeat('Self review attempt. ',2)),
 'Contributor cannot self-approve completion.');
sf_capture_review(1,$evidence,'approved_for_edit');
$auditFail=true;
noG4j(fn()=>sf_production_task_review(1,$id,'approved',
 'Reviewed actual footage and its original evidence capture record.'),
 'Failed approval audit rolls back the approved completion.');
$auditFail=false;
g4j(sf_production_task_read(1,$id)['status']==='submitted',
 'Failed audit leaves submitted task awaiting review.');
sf_production_task_review(1,$id,'approved',
 'Reviewed the captured scene, contributor ownership and edit-only approval.');
g4j(sf_production_task_read(1,$id)['status']==='approved',
 'Human approval is recorded after independent source verification.');
noG4j(fn()=>sf_production_task_review(1,$id,'approved',str_repeat('Repeat. ',7)),
 'Completed task cannot be approved twice.');
$roles[2]=[];g4j(count(sf_production_task_list(2))===0,
 'Revoked team role immediately loses task inbox access.');
noG4j(fn()=>sf_production_task_read(2,$id),'Revoked contributor cannot see old private task.');
$roles[2]=['videographer'];
$second=sf_production_task_propose_gap(1,'atmosphere_audio');
g4j($second['id']!==$id,'Other verified coverage gap creates separate task.');
$db->exec('CREATE TABLE story_composer_proposals(id INTEGER PRIMARY KEY,assembly_id INTEGER,revision INTEGER,status TEXT)');
$db->exec("INSERT INTO story_composer_proposals VALUES(39,5,2,'pending_review')");
$editorial=sf_production_task_propose_editorial(1,39);
g4j($editorial['id']>0,'Editor can propose work against real pending AI storyboard review.');
noG4j(fn()=>sf_production_task_propose_editorial(1,99),'Unknown AI proposal cannot create work.');
sf_production_task_assign(1,$editorial['id'],3);
sf_production_task_submit(3,$editorial['id'],39,'Reviewed source arrangement against band narrative.');
noG4j(fn()=>sf_production_task_review(1,$editorial['id'],'approved',
 'Reviewer checked the documentary evidence and pending AI proposal.'),
 'Production task cannot substitute for original Agent Composer human decision.');
$db->exec("UPDATE story_composer_proposals SET status='rejected' WHERE id=39");
sf_production_task_review(1,$editorial['id'],'approved',
 'The authorized editor rejected the creative AI proposal after review.');
g4j(sf_production_task_read(1,$editorial['id'])['status']==='approved',
 'Crew review completion does not silently approve rejected AI content.');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-production-tasks.php');
$public=file_get_contents(dirname(__DIR__).'/api/story-production-tasks.php');
$adminUi=file_get_contents(dirname(__DIR__).'/admin/assets/director-capture.js');
$userUi=file_get_contents(dirname(__DIR__).'/assets/js/app.js');
g4j(str_contains($api,'sf_admin_require_auth(')&&str_contains($public,'sf_require_user(')
 &&str_contains($adminUi,'data-task-review')&&str_contains($userUi,'data-assigned-submit'),
 'Admin and contributor task interfaces reuse existing authenticated ownership boundaries.');
echo "88D3G4J: $n production task assertions passed".PHP_EOL;
