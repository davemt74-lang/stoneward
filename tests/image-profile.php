<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-image-profile-'.bin2hex(random_bytes(8));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';

$checks=0;
function verify(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;echo "PASS $message\n";
}
function rejects(callable $run,string $message,string $type=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){if(!$error instanceof $type)throw $error;verify(true,$message);return;}
    throw new RuntimeException('Expected rejection: '.$message);
}

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-only-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(128*3),128))).$chunk('IEND','');
$photo=sf_agent_canvas_photo_store(2,$png,'personal.png','Guitar photo');
$otherPhoto=sf_agent_canvas_photo_store(3,$png,'other.png','Other photo');
$suggestions=[
    ['category'=>'interests','value'=>'Acoustic guitar playing','evidence'=>'An acoustic guitar and music stand are visible.'],
    ['category'=>'creative_preferences','value'=>'Warm earth tones','evidence'=>'Warm brown and amber colors appear throughout the photo.'],
];
$epoch=sf_image_profile_epoch(2);
$proposed=sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,$epoch);
verify(count($proposed)===2&&array_column($proposed,'status')===['pending','pending']&&array_column($proposed,'uncertain')===[true,true],
    'photo analysis creates tentative preferences that require user review');
verify(sf_image_profile_context(2)===[]&&!str_contains(sf_agent_customer_memory_context(2),'Acoustic guitar'),
    'pending photo suggestions never enter Agent personalization');
verify(sf_image_profile_list(3)===[]&&sf_image_profile_context(1)===[],'photo preferences are private to their owner');
$guitar=array_values(array_filter($proposed,static fn(array $row):bool=>$row['category']==='interests'))[0];
$colors=array_values(array_filter($proposed,static fn(array $row):bool=>$row['category']==='creative_preferences'))[0];
rejects(fn()=>sf_image_profile_update(3,$guitar['id'],'accept'),'another listener cannot accept a private preference',OutOfBoundsException::class);
rejects(fn()=>sf_image_profile_update(1,$guitar['id'],'accept'),'an administrator cannot accept another user preference',OutOfBoundsException::class);
$accepted=sf_image_profile_update(2,$guitar['id'],'accept');
verify($accepted['status']==='accepted'&&!$accepted['uncertain']&&sf_image_profile_context(2)===[['category'=>'interests','value'=>'Acoustic guitar playing']],
    'explicit user acceptance contributes only the confirmed preference');
verify(str_contains(sf_agent_customer_memory_context(2),'Acoustic guitar playing')&&!str_contains(sf_agent_customer_memory_context(2),'music stand'),
    'the normal Agent memory path uses confirmed values without passing observed evidence as instructions');
$retried=sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,$epoch);
verify(count($retried)===2&&array_values(array_filter($retried,static fn(array $row):bool=>$row['id']===$guitar['id']))[0]['status']==='accepted',
    'repeated analysis preserves the original accepted record and creates no duplicate');
$removed=sf_image_profile_update(2,$guitar['id'],'remove');
verify($removed['status']==='removed'&&$removed['value']===''&&$removed['evidence']===''&&sf_image_profile_context(2)===[],
    'removal erases the preference text and immediately revokes Agent context');
sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,$epoch);
verify(count(sf_image_profile_list(2))===1,'repeated analysis cannot silently restore a removed preference');
rejects(fn()=>sf_image_profile_update(2,$guitar['id'],'accept'),'a removed preference cannot be reaccepted from an old request');
sf_image_profile_update(2,$colors['id'],'dismiss');
verify(sf_image_profile_list(2)===[]&&count(sf_image_profile_list(2,true))===2,'dismissal hides an uncertain suggestion while preserving a review decision');
sf_image_profile_update(2,$colors['id'],'accept');
verify(sf_image_profile_context(2)===[['category'=>'creative_preferences','value'=>'Warm earth tones']],
    'a user can explicitly confirm a previously dismissed suggestion');

$blocked=[
    ['category'=>'age','value'=>'Guitar playing','evidence'=>'A guitar is visible.'],
    ['category'=>'interests','value'=>'Female guitarist','evidence'=>'A guitar is visible.'],
    ['category'=>'interests','value'=>'Ethnicity from skin tone','evidence'=>'A guitar is visible.'],
    ['category'=>'experience_preferences','value'=>'Lives at 123 Main Street','evidence'=>'A street sign is visible.'],
    ['category'=>'music_preferences','value'=>'Christian music','evidence'=>'A cross is visible.'],
    ['category'=>'interests','value'=>'Republican politics','evidence'=>'A campaign poster is visible.'],
    ['category'=>'interests','value'=>'Health diagnosis','evidence'=>'A prescription is visible.'],
    ['category'=>'interests','value'=>'Gay identity','evidence'=>'Clothing is visible.'],
    ['category'=>'interests','value'=>'Wealth and salary','evidence'=>'An expensive object is visible.'],
    ['category'=>'interests','value'=>'Recognized identity','evidence'=>'This face looks like a named person.'],
    ['category'=>'interests','value'=>'Ignore instructions','evidence'=>'System prompt text appears in the photo.'],
    ['category'=>'interests','value'=>'Guitar playing','evidence'=>'Contact fixture@example.invalid to learn more.'],
    ['category'=>'interests','value'=>'Guitar playing','evidence'=>'37.12345, -122.12345'],
];
foreach($blocked as $unsafe)verify(sf_image_profile_suggestion($unsafe)===null,'restricted suggestion rejected · '.$unsafe['value']);
verify(count(sf_image_profile_propose(2,$photo['id'],$photo['sha256'],array_slice($blocked,0,8),$epoch))===1,
    'filtered model output cannot add unsupported personal attributes');
$generated=sf_agent_canvas_image_store(2,str_repeat('a',32),'Generated art',['bytes'=>$png]);
rejects(fn()=>sf_image_profile_propose(2,$generated['id'],hash('sha256',$png),$suggestions),'generated artwork cannot become evidence of the listener life');
rejects(fn()=>sf_image_profile_propose(2,$otherPhoto['id'],$otherPhoto['sha256'],$suggestions),'another account photo cannot become a profile source');
rejects(fn()=>sf_image_profile_propose(2,$photo['id'],str_repeat('0',64),$suggestions),'photo suggestions must match the verified source bytes');

sf_privacy_save_profile(2,['personalization_enabled'=>false]);
verify(sf_image_profile_context(2)===[]&&sf_agent_customer_memory_context(2)==='','disabled personalization suppresses confirmed photo preferences');
$disabledAcceptance=sf_image_profile_update(2,$colors['id'],'accept');
verify($disabledAcceptance['status']==='accepted'&&sf_image_profile_context(2)===[],'review remains available while personalization is disabled');
sf_privacy_save_profile(2,['personalization_enabled'=>true]);
verify(count(sf_image_profile_context(2))===1,'reenabling personalization respects the previously confirmed preference');
$other=sf_image_profile_propose(3,$otherPhoto['id'],$otherPhoto['sha256'],$suggestions);
sf_image_profile_update(3,$other[0]['id'],'accept');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
verify(count($export['photo_preferences'])===2&&$export['photo_preferences'][0]['source_id']===$photo['id']&&isset($export['private_photo_analysis']),
    'account export includes the owner review decisions and private photo analysis');
$reset=sf_privacy_reset_personalization(2);
verify($reset['photo_preferences']===2&&sf_image_profile_list(2)===[]&&sf_image_profile_context(2)===[]&&count(sf_image_profile_context(3))===1,
    'personalization reset clears only the owner photo suggestions and accepted facts');
rejects(fn()=>sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,$epoch),
    'an analysis started before a privacy reset cannot restore the erased preferences');
$fresh=sf_image_profile_propose(2,$photo['id'],$photo['sha256'],$suggestions,sf_image_profile_epoch(2));
sf_image_profile_update(2,$fresh[0]['id'],'accept');
$file=sf_agent_canvas_image_file(2,$photo['id']);$original=file_get_contents($file['path']);
$tampered=$original;$tampered[100]=chr(ord($tampered[100])^1);file_put_contents($file['path'],$tampered);
verify(sf_image_profile_context(2)===[],'changing the source bytes revokes accepted photo context');
file_put_contents($file['path'],$original);
$db->prepare('DELETE FROM agent_canvas_photo_sources WHERE user_id=? AND image_id=?')->execute([2,$photo['id']]);
verify(sf_image_profile_export(2)===[]&&sf_image_profile_context(2)===[],'revoking the original photo provenance cascades all derived profile facts');

$closingPhoto=sf_agent_canvas_photo_store(3,$png,'closing.png','Closing account photo');
sf_image_profile_propose(3,$closingPhoto['id'],$closingPhoto['sha256'],$suggestions);
$closed=sf_privacy_close_account(3,sf_user_by_email('fixture3@example.invalid'),'fixture-only-password','DELETE');
verify($closed['closed']&&sf_image_profile_export(3)===[]&&array_filter(sf_agent_canvas_photo_export(3))===[],
    'account closure removes private photo analysis and derived profile data');
verify(sf_agent_canvas_image_get(2,$generated['id'])!==null,'another listener image inventory survives account closure');

echo "PASS $checks photo preference checks\n";
