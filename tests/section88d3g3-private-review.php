<?php
declare(strict_types=1);
$private=sys_get_temp_dir().'/sf-g3-private-'.bin2hex(random_bytes(5));
mkdir($private,0700,true);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO users(id) VALUES(1),(2)');
$db->exec('CREATE TABLE story_assemblies(id INTEGER PRIMARY KEY)');
$db->exec('INSERT INTO story_assemblies(id) VALUES(7)');
$db->exec("CREATE TABLE story_render_jobs(id INTEGER PRIMARY KEY,assembly_id INTEGER NOT NULL,
 revision INTEGER NOT NULL,status TEXT NOT NULL,output_name TEXT NOT NULL,
 output_sha256 TEXT NOT NULL,output_bytes INTEGER NOT NULL,created_at TEXT NOT NULL,
 updated_at TEXT NOT NULL)");
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_assembly_editor(int $id):void{if($id!==1)throw new RuntimeException('Editor permission denied.');}
function sf_render_jobs_schema():void{}
function sf_team_ensure_schema():void{}
$failAudit=false;$rightsOkay=true;
function sf_team_audit(int $editor,string $action,string $kind,string $entity,string $detail):void{
 global $failAudit;if($failAudit)throw new RuntimeException('Activity audit failed.');
}
function sf_capture_clean($value,int $n=300):string{return substr(trim((string)$value),0,$n);}
function sf_render_private_root():string{global $private;return $private;}
function sf_render_plan(int $editor,int $assembly,int $revision):array{
 global $rightsOkay;sf_assembly_editor($editor);
 if(!$rightsOkay||$assembly!==7||$revision!==3)
  throw new RuntimeException('Source rights, digest or revision revoked.');
 return ['total_ms'=>3000];
}
require dirname(__DIR__).'/api/story-render-review-core.php';
$n=0;
function yesG3(bool $yes,string $label):void{global $n;if(!$yes)throw new RuntimeException('FAIL '.$label);echo "PASS $label\n";$n++;}
function noG3(callable $f,string $label):void{
 try{$f();throw new LogicException('Expected denial');}
 catch(RuntimeException|InvalidArgumentException $e){yesG3(true,$label);}
}
$name='assembly-7-rev-3-job-11-0123456789abcdef.mp4';$data=str_repeat('private video bytes',100);
file_put_contents($private.'/'.$name,$data);
$sha=hash_file('sha256',$private.'/'.$name);$size=strlen($data);
$db->prepare("INSERT INTO story_render_jobs VALUES(11,7,3,'private_review',?,?,?,?,?)")
 ->execute([$name,$sha,$size,gmdate('c'),gmdate('c')]);
noG3(fn()=>sf_render_review_file(2,11),'Contributor denied private MP4 access');
$stream=sf_render_review_file(1,11);
yesG3($stream['size']===$size&&$stream['path']===$private.'/'.$name,'Owner access requires matching SHA256 and valid output path');
yesG3(sf_render_review_range('bytes=0-99',$size)===[0,99,true],'Exact byte range supports browser playback');
yesG3(sf_render_review_range('bytes=100-',$size)===[100,$size-1,true],'Open-ended range bounded to output size');
yesG3(sf_render_review_range(null,$size)===[0,$size-1,false],'Small video permits safe complete initial response');
noG3(fn()=>sf_render_review_range('bytes=0-10,20-30',$size),'Multipart range requests rejected');
noG3(fn()=>sf_render_review_range('bytes=-100',$size),'Suffix range cannot bypass bounded access policy');
noG3(fn()=>sf_render_review_range('bytes=999999999-',$size),'Out-of-bounds range denied');
noG3(fn()=>sf_render_review_decide(2,11,'approved_private',
 'Reviewed source video and identity with permission attached.'),'Noneditor cannot approve video');
noG3(fn()=>sf_render_review_decide(1,11,'approved_private','okay'),'Editorial basis must be substantive');
$failAudit=true;
noG3(fn()=>sf_render_review_decide(1,11,'approved_private',
 'I inspected the private video for rights and editorial content.'),'Approval rolls back when audit fails');
$failAudit=false;
yesG3((int)$db->query('SELECT COUNT(*) FROM story_render_reviews')->fetchColumn()===0
 &&sf_render_review_job(1,11)['status']==='private_review',
 'Audit failure cannot leave an undocumented approved output');
$rightsOkay=false;
noG3(fn()=>sf_render_review_file(1,11),'Rights revocation immediately blocks playback');
noG3(fn()=>sf_render_review_decide(1,11,'approved_private',
 'I reviewed rights documentation and the final private video.'),'Revoked rights block private approval');
$rightsOkay=true;
file_put_contents($private.'/'.$name,$data.'modified');
noG3(fn()=>sf_render_review_file(1,11),'Tampered private output SHA256 blocks playback');
file_put_contents($private.'/'.$name,$data);
yesG3(sf_render_review_decide(1,11,'approved_private',
 'I independently checked final private film against the approved source rights.'),
 'Editor explicitly approves verified video as private only');
yesG3(sf_render_review_job(1,11)['status']==='approved_private',
 'Approval never makes a public video or downloadable route');
yesG3(!sf_render_review_decide(1,11,'approved_private',
 'I independently checked final private film against the approved source rights.'),
 'Duplicate approval cannot overwrite review');
yesG3(sf_render_review_admin(1,7)[0]['decision']==='approved_private'
 &&!isset(sf_render_review_admin(1,7)[0]['output_name']),
 'Admin review list omits private storage path and fingerprint');
$db->prepare("INSERT INTO story_render_jobs VALUES(12,7,3,'private_review',?,?,?,?,?)")
 ->execute(['assembly-7-rev-3-job-12-0123456789abcdef.mp4',$sha,$size,gmdate('c'),gmdate('c')]);
$rightsOkay=false;
yesG3(sf_render_review_decide(1,12,'rejected',
 'Rights changed and this private film must not be used or distributed.'),
 'Editor can reject even when capture permission has been revoked');
yesG3(sf_render_review_job(1,12)['status']==='rejected',
 'Rejected output remains private and inaccessible to playback');
noG3(fn()=>sf_render_review_file(1,12),'Rejected video cannot stream');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-render-review.php');
yesG3(str_contains($api,"sf_admin_require_auth(".chr(36)."method!=='GET')")
 &&str_contains($api,'sf_render_review_file($editor,$jobId)')
 &&str_contains($api,'sf_render_review_range(')
 &&str_contains($api,'Cache-Control: private, no-store')
 &&str_contains($api,'publication_allowed'),
 'Admin stream requires auth, bounded range, no caching and no public rights');
unlink($private.'/'.$name);rmdir($private);
echo "88D3G3: $n functional assertions passed\n";
