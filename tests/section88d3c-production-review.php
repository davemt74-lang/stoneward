<?php
declare(strict_types=1);
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active'),(3,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT,sha256 TEXT,size_bytes INTEGER,duration_seconds REAL)');
$sha=hash('sha256','reviewed-camera-source');
$db->prepare('INSERT INTO media_assets VALUES(?,?,?,?,?)')->execute([4,'audio',$sha,strlen('reviewed-camera-source'),8.5]);
$db->prepare('INSERT INTO media_assets VALUES(?,?,?,?,?)')->execute([5,'image',$sha,strlen('reviewed-camera-source'),0]);
$db->prepare('INSERT INTO media_assets VALUES(?,?,?,?,?)')->execute([6,'video',$sha,strlen('reviewed-camera-source'),12.0]);
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($x,int $max=300):string{return substr(trim((string)$x),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
$failAudit=false;
function sf_team_audit(int $id,string $a,string $kind,string $entity,string $desc):void{
 global $failAudit;if($failAudit)throw new RuntimeException('Audit unavailable.');
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-inference-queue-core.php';
require dirname(__DIR__).'/api/story-inference-worker-core.php';
require dirname(__DIR__).'/api/story-transcript-core.php';
require dirname(__DIR__).'/api/story-scene-core.php';
require dirname(__DIR__).'/api/story-production-review-core.php';
require dirname(__DIR__).'/api/story-inference-video-core.php';
$n=0;
function ok88c(bool $v,string $name):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}
function rejected88c(callable $fn,string $name):void{
 try{$fn();throw new LogicException('Expected rejection');}
 catch(RuntimeException|InvalidArgumentException $e){ok88c(true,$name);}
}
function fixture88c(int $asset,string $kind,string $mime):array{
 global $sha;
 $sid=sf_capture_submit(2,['title'=>'Independent private capture','submission_type'=>$kind,
  'description'=>'Valid source','rights_attested'=>true,'people_release_status'=>'not_applicable'],$asset);
 sf_evidence_record($sid,$asset,['sha256'=>$sha,'byte_size'=>strlen('reviewed-camera-source'),
  'mime_type'=>$mime,'image_width'=>null,'image_height'=>null]);
 sf_capture_review(1,$sid,'approved_for_edit');
 sf_capture_rights_review(1,$sid,'external_ai','cleared','Independently reviewed signed capture release and external processing consent.');
 $kind=($kind==='photo'||$kind==='video')?'scene':'transcript';
 $job=sf_inference_enqueue(1,$sid,$kind);$lease=sf_inference_claim(1);
 if(!$lease||$lease['job_id']!==$job['job_id'])throw new RuntimeException('Bad fixture claim.');
 return [$sid,$job['job_id'],$lease['lease_token']];
}
[$sid,$job,$token]=fixture88c(4,'audio','audio/wav');
ok88c(sf_inference_finish($job,$token,['provider'=>'openai','model'=>'gpt-4o-mini-transcribe'],
 ['text'=>'A band prepares backstage.','start_ms'=>0,'end_ms'=>8500,'timing'=>'whole_clip_estimate']),
 'Mock provider result saved with complete job');
$edited=['language'=>'en','segments'=>[
 ['start_ms'=>0,'end_ms'=>2900,'text'=>'A band prepares','speaker_label'=>''],
 ['start_ms'=>3000,'end_ms'=>8100,'text'=>'backstage.','speaker_label'=>'Narrator']]];
rejected88c(fn()=>sf_production_import(2,$job,$edited),'Contributor may not import reviewed result');
rejected88c(fn()=>sf_production_import(1,$job,$edited),'Pending AI result cannot be imported');
ok88c(sf_inference_review(1,$job,'approved'),'Editor approves private AI result');
rejected88c(fn()=>sf_production_import(1,$job,['segments'=>[
 ['start_ms'=>0,'end_ms'=>18000,'text'=>'Unfounded long speech','speaker_label'=>'']
 ]]),'Reject timestamp extending beyond actual source duration');
$rows=sf_production_review_items(1);
ok88c(count($rows)===1 && $rows[0]['draft']['text']==='A band prepares backstage.',
 'Private Editor review queue includes the machine result');
rejected88c(fn()=>sf_production_review_items(2),'Contributor cannot read production AI drafts');
$import=sf_production_import(1,$job,$edited);
ok88c($import['entity_type']==='transcript'&&!$import['existing'],'Reviewed transcript imported exactly once');
$trans=sf_transcript_read(1,$import['entity_id']);
ok88c($trans['origin']==='machine_draft'&&$trans['review_status']==='draft',
 'Canonical import remains unverified and not published');
ok88c(count($trans['segments'])===2&&(int)$trans['segments'][1]['start_ms']===3000,
 'Human-corrected segment timecodes retained');
$again=sf_production_import(1,$job,$edited);
ok88c($again['existing']===true&&$again['entity_id']===$import['entity_id'],'Idempotent import returns canonical artifact');
ok88c((int)$db->query('SELECT COUNT(*) FROM story_transcripts')->fetchColumn()===1,'Duplicate cannot create extra canonical transcript');
[$imageSid,$imageJob,$imageToken]=fixture88c(5,'photo','image/png');
ok88c(sf_inference_finish($imageJob,$imageToken,['provider'=>'openai','model'=>'gpt-4o-mini'],
 ['description'=>'Stage lighting and microphone','start_ms'=>0,'end_ms'=>0]),'Image scene candidate saved');
ok88c(sf_inference_review(1,$imageJob,'approved'),'Image scene approved for draft import');
rejected88c(fn()=>sf_production_import(1,$imageJob,['start_ms'=>0,'end_ms'=>100,
 'description'=>'A stage','tags'=>[]]),'Still image cannot claim temporal scene duration');
$failAudit=true;
rejected88c(fn()=>sf_production_import(1,$imageJob,['start_ms'=>0,'end_ms'=>0,
 'description'=>'Human-checked stage and microphone','tags'=>['stage']]),
 'Audit failure rolls back canonical scene and import mapping');
$failAudit=false;
ok88c((int)$db->query('SELECT COUNT(*) FROM story_scene_annotations')->fetchColumn()===0,
 'Audit error leaves no orphaned private scene');
$scene=sf_production_import(1,$imageJob,['start_ms'=>0,'end_ms'=>0,
 'description'=>'Human-checked stage and microphone','tags'=>['stage']]);
ok88c($scene['entity_type']==='scene'&&$scene['entity_id']>0,'Canonical still-scene draft imported');
$sceneRow=sf_scene_read(1,$scene['entity_id']);
ok88c($sceneRow['origin']==='machine_draft'&&$sceneRow['review_status']==='draft',
 'Canonical scene requires independent verification');
[$videoSid,$videoJob,$videoToken]=fixture88c(6,'video','video/mp4');
ok88c(sf_inference_finish($videoJob,$videoToken,['provider'=>'anthropic','model'=>'claude-sonnet-4-5'],
 ['description'=>'A person performing on stage','start_ms'=>1000,'end_ms'=>1500,
 'sampled_frame_ms'=>1250,'timing'=>'single_sampled_frame']),'Timestamped video-frame draft saved');
ok88c(sf_inference_review(1,$videoJob,'approved'),'Video-frame result approved');
$videoChanges=['start_ms'=>1000,'end_ms'=>1500,'description'=>'Reviewed single frame of performance',
 'tags'=>['stage']];
sf_capture_rights_review(1,$videoSid,'external_ai','restricted',
 'External AI permission was withdrawn by rights holder after processing.');
rejected88c(fn()=>sf_production_import(1,$videoJob,$videoChanges),'Rights revoked before import block canonical addition');
sf_capture_rights_review(1,$videoSid,'external_ai','cleared',
 'Newly signed and independently reviewed permission explicitly allows the import.');
$video=sf_production_import(1,$videoJob,$videoChanges);
ok88c($video['entity_type']==='scene','Human approved video sample imports private scene draft');
$videoScene=sf_scene_read(1,$video['entity_id']);
ok88c((int)$videoScene['start_ms']===1000&&(int)$videoScene['end_ms']===1500,
 'Video-frame sample interval is preserved without invented whole-scene timing');
$w=sf_inference_frame_window(16000);
ok88c($w['sampled_frame_ms']===3000&&$w['start_ms']===2750&&$w['end_ms']===3250,
 'FFmpeg sample window is bounded and timecoded');
rejected88c(fn()=>sf_inference_frame_window(0),'Unknown video duration cannot be fabricated');
$api=file_get_contents(dirname(__DIR__).'/admin/api/story-inference.php');
ok88c(str_contains($api,'sf_production_import(')&&str_contains($api,'sf_admin_require_auth($method!==\'GET\')'),
 'Existing Admin CSRF gate protects canonical import');
echo "88D3C: $n functional assertions passed\n";
