<?php
declare(strict_types=1);
$repo=dirname(__DIR__);$root=sys_get_temp_dir().'/stonefellow-private-photo-'.bin2hex(random_bytes(6));
mkdir($root.'/config',0700,true);mkdir($root.'/data',0700,true);mkdir($root.'/assets/js',0700,true);symlink($repo.'/api',$root.'/api');
file_put_contents($root.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$root.'/fixture.sqlite'],true).';');
copy($repo.'/config/store.default.php',$root.'/config/store.default.php');
file_put_contents($root.'/data/catalog.json','[]');file_put_contents($root.'/data/releases.json','[]');
define('SF_ROOT',$root);
require $repo.'/api/bootstrap.php';require_once $repo.'/api/migrations.php';
require_once $repo.'/api/agent-canvas-image-analysis-core.php';
$checks=0;
function verify(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo "PASS $message\n";}
function rejects(callable $run,string $message):void {try{$run();}catch(InvalidArgumentException|RuntimeException $error){verify(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
register_shutdown_function(static function()use($root):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }rmdir($root);
});
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).$chunk('tEXt',"GPS\0SECRET_LOCATION").
    $chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(384),128))).$chunk('IEND','');
$photo=sf_agent_canvas_photo_store(2,$png,'my-photo.png','My photo');
$source=sf_agent_canvas_photo_source(2,$photo['id']);
verify($photo['source_kind']==='uploaded_photo'&&$photo['is_own_photo']&&$source['source_kind']==='actual_uploaded'&&
    $source['sha256']===hash_file('sha256',$source['path'])&&$source['original_sha256']===hash('sha256',$png),
    'Private uploads record original and stored hashes with verified actual-upload provenance');
verify(!str_contains(file_get_contents($source['path']),'SECRET_LOCATION')&&sf_agent_canvas_photo_source(3,$photo['id'])===null&&
    sf_agent_canvas_image_file(1,$photo['id'])===null,'Photo metadata is removed and neither another listener nor Admin can read its private file');
verify(sf_agent_canvas_photo_store(2,$png,'retry.png','Retried upload')['id']===$photo['id'],
    'Retrying the same photo reuses the submitting account saved identity');
$other=sf_agent_canvas_photo_store(3,$png,'my-photo.png','Other photo');
verify($other['id']!==$photo['id'],'Identical uploads from different accounts retain independent private identities');
rejects(fn()=>sf_agent_canvas_photo_store(2,str_repeat('x',300),'fake.jpg'),'A MIME label cannot turn text into a photo');
rejects(fn()=>sf_agent_canvas_photo_store(2,$png.str_repeat('x',SF_AGENT_CANVAS_PHOTO_MAX_BYTES),'big.png'),'Uploads over 10 MB are rejected before storage');
$small="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',127,128,8,2,0,0,0)).$chunk('IDAT',str_repeat('x',300)).$chunk('IEND','');
rejects(fn()=>sf_agent_canvas_photo_store(2,$small,'small.png'),'Photo dimensions must meet the established 128-pixel minimum');

// Real portrait JPEG, with an orientation-only TIFF plus private trailing EXIF data.
$jpeg=base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAUDBAQEAwUEBAQFBQUGBwwIBwcHBw8LCwkMEQ8SEhEPERETFhwXExQaFRERGCEYGh0dHx8fExciJCIeJBweHx7/2wBDAQUFBQcGBw4ICA4eFBEUHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh7/wAARCADAAIADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDy2iiivBPrgooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAP/9k=',true);
$orientation="Exif\0\0II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
$privateExif=$orientation.'SECRET_CAMERA_AND_GPS';$icc="ICC_PROFILE\0\x01\x01".'fixture-color-profile';
$jpeg=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($privateExif)+2).$privateExif."\xff\xe2".pack('n',strlen($icc)+2).$icc.substr($jpeg,2);
$portrait=sf_agent_canvas_photo_store(2,$jpeg,'portrait.jpg');$portraitSource=sf_agent_canvas_photo_source(2,$portrait['id']);
$cleanJpeg=file_get_contents($portraitSource['path']);$exif=exif_read_data($portraitSource['path']);
verify(($exif['Orientation']??0)===6&&!str_contains($cleanJpeg,'SECRET_CAMERA_AND_GPS')&&str_contains($cleanJpeg,$icc),
    'Portrait JPEG keeps its visual orientation and ICC profile while camera and GPS metadata are stripped');

$provider=['provider'=>'openai','model'=>'gpt-4o-mini','api_key'=>'fixture-no-network'];$fan=['id'=>2,'role'=>'user'];
$fields=['description'=>'A guitar beside a printed poster.','visible_text'=>"LIVE\n  8 PM\n[illegible]",'objects'=>['guitar','poster'],
    'scene'=>'A stage.','style'=>'Warm photograph.','colors'=>['amber'],'tags'=>['guitar'],'uncertainties'=>['Last line illegible.']];
$calls=0;$request=str_repeat('a',32);
sf_token_adjust(2,200000,'admin_grant','private-image-test');
$transport=static function(string $url,array $headers,array $body)use(&$calls,$fields,$fan,$photo,$request,$provider):array {
    $calls++;
    verify($url==='https://api.openai.com/v1/responses'&&!str_contains(base64_decode(explode(',',$body['input'][0]['content'][1]['image_url'],2)[1]),'SECRET_LOCATION'),
        'Vision receives only the validated private image with metadata removed');
    $replayed=sf_agent_canvas_image_analyze($fan,$photo['id'],$request,false,null,$provider);
    rejects(fn()=>sf_agent_canvas_image_analyze($fan,$photo['id'],str_repeat('b',32),false,null,$provider),
        'A second request ID is rejected while the same source is processing');
    verify($replayed['analysis_status']==='processing','Same-request replay recovers the processing attempt without a provider repeat');
    return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>88,'output_tokens'=>122]];
};
$draft=sf_agent_canvas_image_analyze($fan,$photo['id'],$request,false,$transport,$provider);
verify($draft['analysis_status']==='draft'&&$draft['revision']===1&&$draft['image_analysis']===$fields&&$draft['active_tokens_used']===210&&
    sf_token_balance(2)===199790,'A private draft and actual-owner token charge settle together with verbatim OCR');
verify(sf_agent_canvas_image_analyze($fan,$photo['id'],$request,false,$transport,$provider)===$draft&&$calls===1,
    'Completed analysis replay incurs no provider call or additional debit');
rejects(fn()=>sf_agent_canvas_image_analyze($fan,$photo['id'],$request,true,$transport,$provider),
    'Replay cannot change the explicit profile-suggestion consent');
rejects(fn()=>sf_agent_canvas_image_analyze($fan,$photo['id'],$request,false,$transport,$provider,str_repeat('0',64)),
    'A queued source fingerprint is checked before replaying an existing analysis');
$beforeMismatch=sf_token_balance(2);
rejects(fn()=>sf_agent_canvas_image_analyze($fan,$photo['id'],str_repeat('7',32),false,$transport,$provider,str_repeat('0',64)),
    'A changed queued source is rejected before a new provider attempt');
verify($calls===1&&sf_token_balance(2)===$beforeMismatch&&sf_agent_canvas_image_analysis_row(2,str_repeat('7',32))===null,
    'A source snapshot mismatch creates no analysis request, provider call or token hold');
rejects(fn()=>sf_agent_canvas_image_analyze(['id'=>3,'role'=>'user'],$photo['id'],str_repeat('c',32),false,$transport,$provider),
    'Another account cannot analyze a private photo');
verify(sf_agent_canvas_image_analysis_approved([['id'=>$photo['id']]],2)===[],
    'Unreviewed descriptions and OCR do not enter the searchable inventory');
$reviewed=array_replace($fields,['description'=>'Reviewed guitar photograph.']);
$saved=sf_agent_canvas_image_analysis_save(2,$photo['id'],$request,1,$reviewed);
verify($saved['analysis_status']==='approved'&&$saved['revision']===2&&
    sf_agent_canvas_image_analysis_approved([['id'=>$photo['id']]],2)===[$photo['id']=>$reviewed],
    'Explicit review exposes only the approved eight-field analysis for the matching private source');
verify((int)$db->query('SELECT COUNT(*) FROM agent_canvas_image_analysis_versions')->fetchColumn()===2,
    'Model draft and reviewed edits remain versioned independently');
rejects(fn()=>sf_agent_canvas_image_analysis_save(2,$photo['id'],$request,1,$fields),'Stale review revisions cannot overwrite newer edits');
rejects(fn()=>sf_agent_canvas_image_analysis_save(3,$photo['id'],$request,2,$fields),'Review saves enforce image ownership');

$suggestions=[['category'=>'interests','value'=>'Guitar playing','evidence'=>'A guitar and music stand are visible.']];
$plain=static fn()=>['output_text'=>json_encode(['analysis'=>$fields,'profile_suggestions'=>$suggestions]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
$profile=sf_agent_canvas_image_analyze($fan,$photo['id'],str_repeat('d',32),true,$plain,$provider);
verify(count($profile['profile_suggestions'])===1&&$profile['profile_suggestions'][0]['status']==='pending'&&sf_image_profile_context(2)===[],
    'An uploaded photo and explicit opt-in create only tentative profile suggestions');
sf_image_profile_update(2,$profile['profile_suggestions'][0]['id'],'dismiss');
verify(sf_agent_canvas_image_analysis_get(2,$photo['id'])['profile_suggestions'][0]['status']==='dismissed',
    'Analysis reload reads current profile review decisions rather than reviving cached pending facts');
$generatedId=str_repeat('e',32);sf_agent_canvas_image_store(2,$generatedId,'Generated art',['bytes'=>file_get_contents($source['path'])]);
$generated=sf_agent_canvas_image_analyze($fan,$generatedId,str_repeat('f',32),true,$plain,$provider);
verify($generated['image']['source_kind']==='generated'&&!$generated['image']['is_own_photo']&&$generated['profile_suggestions']===[],
    'Generated artwork can be inventoried but cannot create facts about the user');
verify(!str_contains((string)$db->query("SELECT suggestions_json FROM agent_canvas_image_analyses WHERE analysis_id='".str_repeat('d',32)."'")->fetchColumn(),'Guitar playing'),
    'Analysis storage retains only suggestion IDs so removed preference text has no cached copy');
$epochId=str_repeat('5',32);
$db->exec("CREATE TRIGGER privacy_reset_after_analysis_claim AFTER INSERT ON agent_canvas_image_analyses WHEN NEW.analysis_id='$epochId'
    BEGIN INSERT INTO user_privacy_events(user_id,event_type,metadata_json,created_at) VALUES(2,'personalization_reset','{}','fixture'); END");
$afterReset=sf_agent_canvas_image_analyze($fan,$photo['id'],$epochId,true,$plain,$provider);
$db->exec('DROP TRIGGER privacy_reset_after_analysis_claim');
verify($afterReset['analysis_status']==='draft'&&$afterReset['profile_suggestions']===[]&&sf_image_profile_list(2)===[],
    'A privacy reset immediately after the analysis claim prevents later profile suggestions from repopulating');

$before=sf_token_balance(2);$failedId=str_repeat('1',32);$failCalls=0;
$fail=static function()use(&$failCalls):array {$failCalls++;throw new RuntimeException('fixture provider disconnect');};
$failed=sf_agent_canvas_image_analyze($fan,$portrait['id'],$failedId,false,$fail,$provider);
verify($failed['analysis_status']==='unknown'&&sf_token_balance(2)===$before,
    'Provider failure releases the held tokens and records an uncertain terminal attempt');
sf_agent_canvas_image_analyze($fan,$portrait['id'],$failedId,false,$fail,$provider);
verify($failCalls===1,'Retrying an uncertain request never resends its paid provider operation');
$reset=sf_agent_canvas_image_analysis_reset($fan,$portrait['id'],$failedId);
verify($reset['analysis_status']==='canceled','Explicit reset retires an uncertain analysis without inference');
verify(sf_agent_canvas_image_analysis_reset($fan,$portrait['id'],$failedId)===$reset&&sf_token_balance(2)===$before,
    'Repeated reset returns the same canceled revision without a second token refund');

$adminPhoto=sf_agent_canvas_photo_store(1,$png,'admin-private-photo.png');$adminCalls=0;
$adminFailure=static function()use(&$adminCalls):array {$adminCalls++;throw new RuntimeException('fixture ambiguous provider outcome');};
$adminRequest=str_repeat('6',32);$admin=['id'=>1,'role'=>'admin'];
$adminUnknown=sf_agent_canvas_image_analyze($admin,$adminPhoto['id'],$adminRequest,false,$adminFailure,$provider,$adminPhoto['sha256']);
verify($adminUnknown['analysis_status']==='unknown'&&$adminUnknown['active_tokens_used']===0,
    'An Admin provider failure is uncertain even though unlimited Admin rights create no escrow key');
sf_agent_canvas_image_analyze($admin,$adminPhoto['id'],$adminRequest,false,$adminFailure,$provider,$adminPhoto['sha256']);
verify($adminCalls===1,'Admin uncertain request replay cannot turn its missing escrow key into a paid retry');

$lateId=str_repeat('2',32);$late=static function()use($fan,$portrait,$lateId,$fields):array {
    sf_agent_canvas_image_analysis_reset($fan,$portrait['id'],$lateId);
    return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
};
$lateResult=sf_agent_canvas_image_analyze($fan,$portrait['id'],$lateId,false,$late,$provider);
verify($lateResult['analysis_status']==='canceled'&&$lateResult['image_analysis']===null&&sf_token_balance(2)===$before,
    'A reset during inference releases its hold and blocks the late provider result from restoring the draft');

$tamperId=str_repeat('3',32);$original=file_get_contents($portraitSource['path']);
$tamper=static function()use($portraitSource,$original,$fields):array {
    file_put_contents($portraitSource['path'],substr($original,0,-1)."\0");
    return ['output_text'=>json_encode(['analysis'=>$fields]),'usage'=>['input_tokens'=>80,'output_tokens'=>120]];
};
$stale=sf_agent_canvas_image_analyze($fan,$portrait['id'],$tamperId,false,$tamper,$provider);
verify($stale['analysis_status']==='stale'&&$stale['image_analysis']===null&&sf_token_balance(2)===$before,
    'A source changed during inference cannot settle, expose analysis, or retain its token hold');
file_put_contents($portraitSource['path'],$original);

$storeId=str_repeat('4',32);
$db->exec("CREATE TRIGGER reject_private_analysis_version BEFORE INSERT ON agent_canvas_image_analysis_versions BEGIN SELECT RAISE(ABORT,'fixture storage failure'); END");
$storeFailed=sf_agent_canvas_image_analyze($fan,$portrait['id'],$storeId,false,$plain,$provider);
$db->exec('DROP TRIGGER reject_private_analysis_version');
verify($storeFailed['analysis_status']==='unknown'&&$storeFailed['image_analysis']===null&&sf_token_balance(2)===$before&&
    (int)$db->query("SELECT COUNT(*) FROM ai_token_reservations WHERE status='held'")->fetchColumn()===0,
    'Storage failure rolls back the draft and token debit, releases escrow, and preserves the no-repeat outcome');

$export=sf_agent_canvas_photo_export(2);
verify(count($export['agent_canvas_photo_sources'])===2&&!str_contains(json_encode($export),$root.'/storage'),
    'Privacy export includes only owner metadata and versioned analysis without filesystem paths');
$db->beginTransaction();$cleared=sf_agent_canvas_photo_clear(2);$db->commit();
verify(array_sum($cleared)>0&&sf_agent_canvas_photo_source(2,$photo['id'])===null&&sf_agent_canvas_photo_source(3,$other['id'])!==null&&
    sf_agent_canvas_image_analysis_approved([['id'=>$photo['id']]],2)===[],
    'Privacy clearing joins its caller transaction, revokes photo provenance and removes only the owner analysis');
echo "Private photo and analysis: $checks checks passed\n";
