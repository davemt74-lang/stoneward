<?php
declare(strict_types=1);
$repo=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-batch-privacy-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js','storage/media/library/image'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($repo.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($repo.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');file_put_contents($fixture.'/data/releases.json','[]');
define('SF_ROOT',$fixture);require $repo.'/api/bootstrap.php';require_once $repo.'/api/migrations.php';
require_once $repo.'/api/image-analysis-batch-core.php';
$checks=0;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo "PASS $message\n";}
function rejects(callable $run,string $message):void {try{$run();}catch(InvalidArgumentException|RuntimeException $error){verify(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }rmdir($fixture);
});
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
verify(sf_image_analysis_batch_export(2)===[], 'Accounts without batch jobs export an empty queue without initializing queue storage');
$db->beginTransaction();$empty=sf_image_analysis_batch_clear(2);$db->commit();
verify(array_sum($empty)===0,'Unused batch queue cleanup can join an existing privacy transaction');

$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$makePng=static fn():string=>"\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(384),128))).$chunk('IEND','');
$png=$makePng();$photo=sf_agent_canvas_photo_store(2,$png,'private-photo.png','Private guitar photograph');
$second=sf_agent_canvas_photo_store(2,$makePng(),'second-photo.png','Second private photograph');
$foreign=sf_agent_canvas_photo_store(3,$png,'foreign-photo.png','Other listener private photograph');
$fan=['id'=>2,'role'=>'user'];$other=['id'=>3,'role'=>'user'];
$fields=['description'=>'A guitar beside a poster.','visible_text'=>"LIVE\n8 PM",'objects'=>['guitar','poster'],
    'scene'=>'A stage.','style'=>'Photograph.','colors'=>['amber'],'tags'=>['guitar'],'uncertainties'=>[]];
$suggestions=[['category'=>'interests','value'=>'Guitar playing','evidence'=>'A guitar and music stand are visible.']];
$provider=['provider'=>'openai','model'=>'gpt-4o-mini','api_key'=>'fixture-no-network'];$calls=0;
$transport=static function(string $url,array $headers,array $body)use(&$calls,$fields,$suggestions):array {
    $calls++;
    verify(!str_contains($body['instructions'],'Also return profile_suggestions'),
        'The private batch adapter never opts an uploaded photo into profile inference');
    return ['output_text'=>json_encode(['analysis'=>$fields,'profile_suggestions'=>$suggestions]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
};

function jobData(array $response):array {return $response['job']??$response;}
$job=jobData(sf_image_analysis_batch_create($fan,'private',str_repeat('1',32),'selected',[$photo['id'],$second['id']]));
$otherJob=jobData(sf_image_analysis_batch_create($other,'private',str_repeat('2',32),'selected',[$foreign['id']]));
rejects(fn()=>sf_image_analysis_batch_get($other,'private',$job['id']), 'Another listener cannot read a private batch or its selected photos');
rejects(fn()=>sf_image_analysis_batch_step($other,'private',$job['id'],$transport,$provider), 'Another listener cannot dispatch an owner private batch');
verify($calls===0,'Failed batch ownership checks make no provider request');

// An actor can own a managed-media queue created before demotion; closure must clear both scopes.
sf_media_ensure_schema();$relative='storage/media/library/image/shared-artist.png';file_put_contents(SF_ROOT.'/'.$relative,$png);
$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,caption,alt_text,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['BATCHPRIVACY','image','image/png','png','artist.png',$relative,hash('sha256',$png),strlen($png),'Shared artist image','','',gmdate('c'),gmdate('c')]);
$assetId=(int)$db->lastInsertId();$admin=['id'=>1,'role'=>'admin'];
$managedTransport=static fn()=>['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
$managed=sf_media_image_analysis_analyze(1,$assetId,str_repeat('3',32),$managedTransport,$provider);
sf_media_image_analysis_save(1,$assetId,$managed['id'],$managed['version'],$fields);
$db->exec("UPDATE users SET role='admin' WHERE id=2");
$managedJob=jobData(sf_image_analysis_batch_create(['id'=>2,'role'=>'admin'],'media',str_repeat('4',32),'selected',[$assetId]));
$db->exec("UPDATE users SET role='user' WHERE id=2");

$pending=sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,sf_image_profile_epoch(2));
sf_image_profile_update(2,$pending[0]['id'],'accept');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
$serialized=json_encode($export['image_analysis_batches']);
verify(str_contains($serialized,$job['id'])&&str_contains($serialized,$managedJob['id'])&&!str_contains($serialized,$otherJob['id'])&&
    !str_contains($serialized,$foreign['id']), 'Account export includes the actor private and managed queues without another listener job or image');
verify(!str_contains($serialized,'storage_key')&&!str_contains($serialized,'lease_token')&&!str_contains($serialized,'reservation_key')&&
    !str_contains($serialized,'Guitar playing')&&!str_contains($serialized,SF_ROOT),
    'Batch exports contain safe job projections without worker secrets, paths or profile facts');

$reset=sf_privacy_reset_personalization(2);
verify($reset['photo_preferences']===1&&sf_image_profile_context(2)===[]&&sf_image_profile_list(2,true)===[],
    'Personalization reset clears photo preference decisions before a queued batch resumes');
verify(jobData(sf_image_analysis_batch_get($fan,'private',$job['id']))['id']===$job['id'],
    'Personalization reset preserves independent image analysis work');
sf_token_adjust(2,200000,'admin_grant','batch-privacy-test');
$stepped=jobData(sf_image_analysis_batch_step($fan,'private',$job['id'],$transport,$provider));
verify($calls===1&&sf_image_profile_list(2,true)===[]&&sf_image_profile_context(2)===[],
    'Resuming analysis after personalization reset creates a metadata draft without restoring any photo preference');

$fresh=sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,sf_image_profile_epoch(2));
sf_image_profile_update(2,$fresh[0]['id'],'accept');
$cleared=sf_privacy_clear_agent_history(2);
verify($cleared['photo_preferences']===1&&sf_image_profile_context(2)===[]&&sf_image_profile_list(2,true)===[],
    'Agent history clearing revokes photo preferences before the remaining batch item');
verify(jobData(sf_image_analysis_batch_get($fan,'private',$job['id']))['id']===$job['id']&&sf_agent_canvas_image_get(2,$photo['id'])!==null,
    'Agent history clearing keeps the image queue and private image inventory available');

$closeCalls=0;
$closeDuringInference=static function(string $url,array $headers,array $body)use(&$closeCalls,$fields):array {
    $closeCalls++;
    $closed=sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),'fixture-password','DELETE');
    verify($closed['closed'],'Account closure commits while a private batch item is in flight');
    return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
};
try{sf_image_analysis_batch_step($fan,'private',$job['id'],$closeDuringInference,$provider);}
catch(InvalidArgumentException|RuntimeException $error){/* The now-deleted job can no longer be returned. */}
verify($closeCalls===1&&sf_image_analysis_batch_export(2)===[],
    'Account closure removes both actor queue scopes and the late response cannot restore either job');
foreach(['agent_canvas_images','agent_canvas_image_analyses','agent_canvas_image_analysis_versions','image_profile_suggestions',
    'user_token_balances','ai_token_reservations','token_ledger'] as $table){
    $q=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE user_id=?');$q->execute([2]);
    verify((int)$q->fetchColumn()===0,'The late batch result cannot recreate closed-account '.$table.' rows');
}
rejects(fn()=>sf_image_analysis_batch_step($fan,'private',$job['id'],$transport,$provider),
    'An inactive account cannot dispatch a subsequent batch step');
verify($calls===1,'No provider call starts for the closed account after its queue is removed');
verify(jobData(sf_image_analysis_batch_get($other,'private',$otherJob['id']))['id']===$otherJob['id']&&sf_agent_canvas_image_get(3,$foreign['id'])!==null,
    'Another listener private queue and image survive account closure');
verify(sf_media_asset($assetId)!==null&&sf_media_image_analysis_approved([sf_media_asset($assetId)])===[$assetId=>$fields],
    'Removing the actor managed queue preserves shared artist media and its reviewed analysis');
echo "Batch privacy: $checks checks passed\n";
