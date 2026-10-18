<?php
declare(strict_types=1);
$root=sys_get_temp_dir().'/sf88d3b-'.bin2hex(random_bytes(5));
if(!mkdir($root.'/storage/media/library',0770,true))throw new RuntimeException('Fixture storage unavailable.');
define('SF_ROOT',$root);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,role TEXT,status TEXT)');
$db->exec("INSERT INTO users VALUES(1,'admin','active'),(2,'user','active')");
$db->exec('CREATE TABLE media_assets(id INTEGER PRIMARY KEY,category TEXT,sha256 TEXT,size_bytes INTEGER,stored_path TEXT,duration_seconds REAL)');
function sf_db():PDO{global $db;return $db;}
function sf_db_config():array{return ['driver'=>'sqlite'];}
function sf_clean_text($x,int $max=300):string{return substr(trim((string)$x),0,$max);}
function sf_media_ensure_schema():void{}
function sf_media_asset(int $id):?array{global $db;$q=$db->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function sf_media_protected_master_asset(int $id):bool{return false;}
function sf_team_roles(int $id):array{return [];}
function sf_team_can(int $id,string $permission):bool{return $id===1;}
function sf_team_ensure_schema():void{}
function sf_team_audit(int $id,string $evt,string $type,string $entity,string $desc):void{}
$meta=['artist_world.inference_enabled'=>'0'];
function sf_ai_meta_get(string $key,string $default=''):string{global $meta;return (string)($meta[$key]??$default);}
function sf_ai_meta_set(string $key,string $value):void{global $meta;$meta[$key]=$value;}
$providerMode='openai';
function sf_ai_llm_candidates(string $task='chat'):array{global $providerMode;
 return [['provider'=>$providerMode,'api_key'=>'TEST-ONLY-DO-NOT-LOG','model'=>'claude-sonnet-4-5','endpoint_url'=>'']];
}
require dirname(__DIR__).'/api/story-capture-core.php';
require dirname(__DIR__).'/api/story-media-evidence-core.php';
require dirname(__DIR__).'/api/story-capture-rights-core.php';
require dirname(__DIR__).'/api/story-inference-queue-core.php';
require dirname(__DIR__).'/api/story-inference-worker-core.php';
$n=0;function check88b(bool $good,string $name):void{global $n;if(!$good)throw new RuntimeException('FAIL '.$name);$n++;echo "PASS $name\n";}
function prepare88b(int $id,string $category,string $mime,string $bytes,string $kind):int{
 global $db,$root;
 $rel='storage/media/library/source-'.$id.($category==='image'?'.png':'.wav');
 file_put_contents($root.'/'.$rel,$bytes);
 $sha=hash('sha256',$bytes);
 $q=$db->prepare('INSERT INTO media_assets VALUES(?,?,?,?,?,?)');
 $q->execute([$id,$category,$sha,strlen($bytes),$rel,2.1]);
 $sub=sf_capture_submit(2,['title'=>'Private source '.$id,'submission_type'=>$kind,
   'description'=>'Controlled test asset','rights_attested'=>true,'people_release_status'=>'not_applicable'],$id);
 sf_evidence_record($sub,$id,['sha256'=>$sha,'byte_size'=>strlen($bytes),'mime_type'=>$mime,
   'image_width'=>null,'image_height'=>null]);
 sf_capture_review(1,$sub,'approved_for_edit');
 sf_capture_rights_review(1,$sub,'external_ai','cleared',
  'Editor verified the private rights evidence and authorized this provider test.');
 return $sub;
}
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8q7REAAAAASUVORK5CYII=');
$sub=prepare88b(8,'image','image/png',$png,'photo');
$job=sf_inference_enqueue(1,$sub,'scene');
check88b($job['status']==='queued','Rights-cleared image enters durable queue');
try{sf_inference_run_once(1,fn()=>[]);throw new RuntimeException('Disabled execution succeeded');}
catch(RuntimeException $e){check88b(str_contains($e->getMessage(),'enabled'),'Provider execution requires explicit Admin opt-in');}
check88b(sf_inference_jobs(1)[0]['status']==='queued','Disabled execution does not consume a lease');
sf_inference_execution_configure(1,true);
$called=0;
$mock=function(string $url,array $headers,array $body,bool $multi=false)use(&$called):array{
 $called++;
 if($url!=='https://api.openai.com/v1/responses'||$multi||!str_contains(json_encode($body),'input_image'))
  throw new RuntimeException('Provider request shape wrong.');
 return ['output'=>[['content'=>[['type'=>'output_text','text'=>'A guitar beside a studio microphone.']]]]];
};
$done=sf_inference_run_once(1,$mock);
check88b($done['status']==='pending_review'&&$called===1,'Real OpenAI visual request constructed through test transport; status='.($done['status']??'missing').'; calls='.$called.'; stage='.($done['failure_stage']??'none').'; error='.($done['error']??'none'));
$result=sf_inference_result_admin(1,$job['job_id']);
check88b($result['draft']['description']==='A guitar beside a studio microphone.','Provider response stored as private machine draft');
check88b($result['status']==='pending_review','No implicit editorial verification');
check88b(!array_key_exists('api_key',$result),'Review response never returns provider key');
check88b(sf_inference_review(1,$job['job_id'],'approved'),'Editor can explicitly approve draft');
check88b(!sf_inference_review(1,$job['job_id'],'approved'),'Double approval is rejected');
$wav='RIFF'.pack('V',38).'WAVEfmt '.pack('V',16).pack('v',1).pack('v',1).pack('V',16000).pack('V',32000).pack('v',2).pack('v',16).'data'.pack('V',2)."\x00\x00";
$audio=prepare88b(9,'audio','audio/x-wav',$wav,'audio');
$audioJob=sf_inference_enqueue(1,$audio,'transcript');
$called=0;
$mockAudio=function(string $url,array $headers,array $body,bool $multi)use(&$called):array{
 $called++;if($url!=='https://api.openai.com/v1/audio/transcriptions'||!$multi
  ||!($body['file'] instanceof CURLFile)||$body['model']!=='gpt-4o-mini-transcribe')
  throw new RuntimeException('Audio multipart provider request malformed.');
 return ['text'=>'Testing one two three.'];
};
$done=sf_inference_run_once(1,$mockAudio);
check88b($done['status']==='pending_review'&&$called===1,'Real multipart speech transcription request built');
$a=sf_inference_result_admin(1,$audioJob['job_id']);
check88b($a['draft']['text']==='Testing one two three.'&&$a['draft']['timing']==='whole_clip_estimate',
 'Transcription stored privately without invented timestamps');
$another=prepare88b(10,'image','image/png',$png,'photo');
$revoked=sf_inference_enqueue(1,$another,'scene');
$mockRevoke=function(string $url,array $headers,array $body,bool $multi)use($another):array{
 $job=sf_inference_result_admin(1,$another['job_id']);
 $q=sf_db()->prepare('SELECT submission_id FROM story_inference_jobs WHERE id=?');
 $q->execute([$another['job_id']]);$sid=(int)$q->fetchColumn();
 sf_capture_rights_review(1,$sid,'external_ai','restricted',
  'All permissions for external provider analysis are now explicitly revoked.');
 return ['output_text'=>'Must not be saved'];
};
$stopped=sf_inference_run_once(1,$mockRevoke);
check88b($stopped['status']==='interrupted','Revocation during provider request interrupts result handling');
check88b(sf_inference_result_admin(1,$revoked['job_id'])===null,'Revoked result not persisted');
check88b(sf_inference_jobs(1)[0]['status']==='interrupted','Uncertain operation requires human recovery');
$providerMode='anthropic';
$still=prepare88b(11,'image','image/png',$png,'photo');$anthropicJob=sf_inference_enqueue(1,$still,'scene');
$anthropicMock=function(string $url,array $headers,array $body,bool $multi=false):array{
 if($url!=='https://api.anthropic.com/v1/messages'||!isset($body['messages'][0]['content'][0]['source']['data']))
  throw new RuntimeException('Anthropic media request malformed.');
 return ['content'=>[['type'=>'text','text'=>'A bright stage.']]];
};
$done=sf_inference_run_once(1,$anthropicMock);
check88b($done['status']==='pending_review','Configured Claude vision route works');
check88b(sf_inference_result_admin(1,$anthropicJob['job_id'])['draft']['description']==='A bright stage.',
 'Claude image result saved privately');
$endpoint=file_get_contents(dirname(__DIR__).'/admin/api/story-inference.php');
check88b(str_contains($endpoint,'configure_execution')&&str_contains($endpoint,'review_result')
 &&!str_contains($endpoint,'sf_inference_run_once('),'Admin may opt in and review but cannot trigger remote inference directly');
sf_inference_execution_configure(1,false);
check88b(!sf_inference_enabled(),'Admin switch disables future provider calls');
echo "88D3B: $n checks passed\n";
foreach(glob($root.'/storage/media/library/*') as $f)unlink($f);
rmdir($root.'/storage/media/library');rmdir($root.'/storage/media');rmdir($root.'/storage');rmdir($root);
