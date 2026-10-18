<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT)');
$db->exec("INSERT INTO media_assets VALUES(4,'video')");
$db->exec('CREATE TABLE rights_audit(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER,action TEXT)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($t,int $max=300):string{return substr(trim((string)$t),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
$breakAudit=false;
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{
 global $breakAudit;
 if($breakAudit)throw new RuntimeException('Audit unavailable');
 sf_db()->prepare('INSERT INTO rights_audit(actor_id,action) VALUES(?,?)')->execute([$id,$a]);
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-director-core.php';
$n=0;function ok88h4(bool $b,string $label):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$label);$n++;echo "PASS $label\n";}
function denied88h4(callable $work,string $label):void{
 try{$work();throw new LogicException('Expected rejection');}
 catch(InvalidArgumentException|RuntimeException $e){ok88h4(true,$label);}
}
$draft=['title'=>'Studio scene','submission_type'=>'video','description'=>'Background scene',
 'rights_attested'=>true,'people_release_status'=>'not_applicable'];
$submission=sf_capture_submit(2,$draft,4);
denied88h4(fn()=>sf_capture_rights_review(1,$submission,'external_ai','cleared',
 'Review notes: verified rights to use this scene externally.'),'Unapproved submission cannot receive external clearance');
sf_capture_review(1,$submission,'approved_for_edit');
ok88h4(sf_capture_rights_status($submission,'external_ai')==='unreviewed','Self-reported not applicable does not grant rights');
ok88h4(!sf_capture_rights_may_use($submission,'publication'),'No implicit publication rights');
ok88h4(sf_director_coverage(1)['needs_likeness_or_release_review']===1,'Director counts unreviewed even when contributor says not applicable');
denied88h4(fn()=>sf_capture_rights_review(2,$submission,'external_ai','cleared',
 'Review notes: verified signed source permissions were checked.'),'Contributor cannot independently approve');
denied88h4(fn()=>sf_capture_rights_review(1,$submission,'external_ai','cleared','ok'),'Rights clearance requires substantive basis');
denied88h4(fn()=>sf_capture_rights_review(1,$submission,'unknown','cleared',
 'Review notes: enough information is available for approved use.'),'Unknown rights scope denied');
sf_capture_rights_review(1,$submission,'external_ai','cleared',
 'Verified release documents and recording permissions for provider processing.');
ok88h4(sf_capture_rights_may_use($submission,'external_ai'),'Purpose-scoped human clearance allows external AI');
ok88h4(!sf_capture_rights_may_use($submission,'publication'),'External AI clearance does not grant publication');
ok88h4(sf_director_coverage(1)['external_ai_rights_cleared']===1,'Director distinguishes rights-cleared from reviewed footage');
$queue=sf_capture_review_queue(1);
ok88h4($queue[0]['external_ai_clearance']==='cleared'&&$queue[0]['publication_clearance']==='unreviewed','Admin queue shows independent scoped decisions');
sf_capture_rights_review(1,$submission,'external_ai','restricted',
 'A new legal review identified missing permissions for any external processing.');
ok88h4(!sf_capture_rights_may_use($submission,'external_ai'),'Clearance revocation immediately blocks future provider use');
$breakAudit=true;
denied88h4(fn()=>sf_capture_rights_review(1,$submission,'external_ai','cleared',
 'Another editor says this source can be sent to external providers.'),'Audit failure rejects rights change');
$breakAudit=false;
ok88h4(sf_capture_rights_status($submission,'external_ai')==='restricted','Rights decision rolled back with failed audit');
sf_capture_rights_review(1,$submission,'publication','cleared',
 'Independently reviewed licenses and likeness releases for public distribution.');
ok88h4(sf_capture_rights_may_use($submission,'publication'),'Publication clearance is separate from AI clearance');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-capture.php');
ok88h4(str_contains($api,"review_rights")&&str_contains($api,'sf_admin_require_auth($method!==\'GET\')'),'Admin rights write action requires Admin write auth');
echo "88D2H4: $n checks passed\n";
