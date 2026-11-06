<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-gallery-privacy-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }rmdir($fixture);
});
define('SF_ROOT',$fixture);require $root.'/api/bootstrap.php';require_once $root.'/api/migrations.php';
$checks=0;
function verify(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function rejects(callable $run,string $label):void {try{$run();}catch(InvalidArgumentException|RuntimeException){verify(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function galleryTables(PDO $db):array {return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'canvas_gallery%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);}
function fixturePixels():string {
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $data='';for($y=0;$y<128;$y++){$data.="\0";for($x=0;$x<128;$x++)$data.=chr($x).chr($y).chr(($x+$y)%256);}
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',128,128,8,2,0,0,0)).$chunk('IDAT',gzcompress($data)).$chunk('IEND','');
}
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
verify($export['canvas_gallery']===[]&&sf_canvas_gallery_export(2)===[]&&galleryTables($db)===[],
    'Unused gallery export does not initialize optional publication storage');
$empty=sf_privacy_clear_agent_history(2);
verify($empty['canvas_gallery_entries']===0&&galleryTables($db)===[],
    'Unused gallery clearing joins the privacy transaction without creating gallery tables');

$admin=['id'=>1,'role'=>'admin'];$fan=['id'=>2,'role'=>'user'];$other=['id'=>3,'role'=>'user'];
$pixels=fixturePixels();
$makeScene=static function(int $uid,string $label)use($pixels):array {
    $image=bin2hex(random_bytes(16));sf_agent_canvas_image_store($uid,$image,'Private artwork',['bytes'=>$pixels]);
    return sf_agent_scene_history_save($uid,['canvas_kind'=>'custom','track_id'=>'',
        'title'=>$label,'html'=>'<section class="art sf-image-'.$image.'"></section>',
        'css'=>'.art{color:#fff;background-image:var(--sf-image-'.$image.',none)}',
        'design_brief'=>'Create a Stonefellow composition using only public artist resources.']);
};
$capture=static fn(int $uid,array $scene,string $publicId):array=>sf_canvas_gallery_assets_snapshot($uid,$scene,$publicId);
$submit=static function(array $user,array $scene,string $requestId,string $label)use($capture):array {
    return sf_canvas_gallery_submit($user,['scene_id'=>$scene['id'],'request_id'=>$requestId,'title'=>$label,
        'description'=>'Public artist composition.','creator_label'=>$label.' Creator','publication_consent'=>true],$capture);
};
$ownScene=$makeScene(2,'Owner two artwork');$otherScene=$makeScene(3,'Other owner artwork');
$ownPending=$submit($fan,$ownScene,str_repeat('a',32),'Owner pending');
$ownApproved=$submit($fan,$ownScene,str_repeat('b',32),'Owner published');
$otherApproved=$submit($other,$otherScene,str_repeat('c',32),'Other publication');
sf_canvas_gallery_moderate($admin,$ownApproved['id'],'approve',$ownApproved['snapshot_hash']);
sf_canvas_gallery_moderate($admin,$otherApproved['id'],'approve',$otherApproved['snapshot_hash']);
$ownDirectory=sf_canvas_gallery_assets_directory($ownApproved['id']);$pendingDirectory=sf_canvas_gallery_assets_directory($ownPending['id']);
$otherDirectory=sf_canvas_gallery_assets_directory($otherApproved['id']);
$ownAsset=sf_canvas_gallery_open($ownApproved['id'])['scene']['image_assets'][0]['id'];
$otherAsset=sf_canvas_gallery_open($otherApproved['id'])['scene']['image_assets'][0]['id'];
$ownPath=$ownDirectory.'/'.$ownAsset.'.bin';$otherPath=$otherDirectory.'/'.$otherAsset.'.bin';
verify(file_get_contents($ownPath)===$pixels&&file_get_contents($otherPath)===$pixels,
    'Privacy fixtures contain real frozen image bytes owned by different creators');
$ownRemix=sf_canvas_gallery_remix($fan,$otherApproved['id'],str_repeat('d',32));
$otherRemix=sf_canvas_gallery_remix($other,$ownApproved['id'],str_repeat('e',32));
$otherCopy=sf_agent_canvas_image_file(3,$otherRemix['scene']['image_assets'][0]['id'])['path'];
$export=sf_privacy_export(2,sf_user_by_email('fixture2@example.invalid'));
$ids=array_column($export['canvas_gallery'],'id');sort($ids);$expected=[$ownPending['id'],$ownApproved['id']];sort($expected);
verify($ids===$expected&&array_column($export['canvas_gallery'],'status')!==[],
    'Gallery privacy export contains only the caller pending and published submissions');
$serialized=json_encode($export['canvas_gallery'],JSON_THROW_ON_ERROR);$raw=sf_canvas_gallery_row($ownApproved['id']);
verify(!str_contains($serialized,'Other publication')&&!str_contains($serialized,$raw['request_fingerprint'])&&
    !str_contains($serialized,'assets_json')&&!str_contains($serialized,'request_fingerprint')&&!str_contains($serialized,SF_ROOT),
    'Gallery export omits another creator records and internal asset paths or request fingerprints');
verify(sf_canvas_gallery_get($ownApproved['id'])!==null&&sf_canvas_gallery_get($otherApproved['id'])!==null,
    'Approved public fixtures are visible before a privacy action');
$db->exec("CREATE TEMP TRIGGER fixture_reject_gallery_clear BEFORE INSERT ON user_privacy_events
    WHEN NEW.user_id=2 AND NEW.event_type='agent_history_cleared' BEGIN SELECT RAISE(ABORT,'fixture rejection'); END");
$failed=false;try{sf_privacy_clear_agent_history(2);}catch(PDOException){$failed=true;}
$after=sf_canvas_gallery_export(2);
verify($failed&&$after===$export['canvas_gallery']&&sf_canvas_gallery_get($ownApproved['id'])!==null&&
    sf_agent_scene_history_get(2,$ownScene['id'])!==null&&file_get_contents($ownPath)===$pixels,
    'An audit failure restores publications and sources while preserving their exact frozen image bytes');
$q=$db->prepare('SELECT COUNT(*) FROM canvas_gallery_remixes WHERE user_id=?');$q->execute([2]);
verify((int)$q->fetchColumn()===1,
    'A rolled-back history reset also preserves the owner remix receipt');
$db->exec('DROP TRIGGER fixture_reject_gallery_clear');
$db->beginTransaction();sf_privacy_clear_agent_history(2);
$nestedKeptBytes=file_get_contents($ownPath)===$pixels&&sf_canvas_gallery_export(2)===[];
$db->rollBack();
verify($nestedKeptBytes&&sf_canvas_gallery_get($ownApproved['id'])!==null&&file_get_contents($ownPath)===$pixels,
    'A nested history reset leaves physical bytes untouched so its outer transaction can roll back');
$epoch=sf_agent_scene_history_epoch(2);$reset=sf_privacy_clear_agent_history(2);
verify($reset['canvas_gallery_entries']===2&&$reset['canvas_gallery_remixes']===1&&sf_canvas_gallery_export(2)===[]&&
    sf_canvas_gallery_get($ownApproved['id'])===null&&sf_canvas_gallery_asset_entry($ownApproved['id'])===null,
    'History clearing purges owner submissions and remix receipts and immediately revokes public asset authorization');
verify(!file_exists($ownDirectory)&&!file_exists($pendingDirectory)&&file_get_contents($otherPath)===$pixels,
    'A committed history reset removes owner gallery files after commit and preserves another creator exact bytes');
verify(sf_agent_scene_history_epoch(2)>$epoch&&sf_canvas_gallery_get($otherApproved['id'])!==null&&
    sf_agent_scene_history_get(3,$otherRemix['scene']['id'])['gallery_attribution']['source']['id']===$ownApproved['id']&&file_get_contents($otherCopy)===$pixels,
    'Another creator publication and already imported private copy survive source-owner history clearing');
$q=$db->prepare('SELECT COUNT(*) FROM canvas_gallery_remixes WHERE user_id=?');$q->execute([3]);
verify((int)$q->fetchColumn()===1,'History clearing preserves another listener remix receipt');

$raceScene=$makeScene(2,'Race artwork');$capturedId=null;$captureCalls=0;
$clearDuringCapture=static function(int $uid,array $scene,string $publicId)use(&$capturedId,&$captureCalls):array {
    $capturedId=$publicId;$captureCalls++;$captured=sf_canvas_gallery_assets_snapshot($uid,$scene,$publicId);
    sf_privacy_clear_agent_history($uid);return $captured;
};
$raceRejected=false;
try{sf_canvas_gallery_submit($fan,['scene_id'=>$raceScene['id'],'request_id'=>str_repeat('f',32),
    'title'=>'Race publication','description'=>'Public art.','creator_label'=>'Owner Creator','publication_consent'=>true],$clearDuringCapture);}
catch(SfCanvasGalleryConflict){$raceRejected=true;}
verify($raceRejected,'A submission captured before history clearing cannot publish after the privacy epoch changes');
verify($captureCalls===1&&is_string($capturedId)&&sf_canvas_gallery_row($capturedId)===null&&sf_canvas_gallery_export(2)===[]&&
    sf_canvas_gallery_assets_directory($capturedId)===null&&sf_canvas_gallery_get($otherApproved['id'])!==null,
    'The stale submission cleans captured files and creates no gallery row or restored source data');

$closingScene=$makeScene(2,'Closing artwork');$closing=$submit($fan,$closingScene,str_repeat('1',32),'Closing publication');
sf_canvas_gallery_moderate($admin,$closing['id'],'approve',$closing['snapshot_hash']);
$closingDirectory=sf_canvas_gallery_assets_directory($closing['id']);
$closingAsset=sf_canvas_gallery_open($closing['id'])['scene']['image_assets'][0]['id'];$closingPath=$closingDirectory.'/'.$closingAsset.'.bin';
sf_canvas_gallery_remix($fan,$otherApproved['id'],str_repeat('2',32));
$db->exec("CREATE TEMP TRIGGER fixture_reject_gallery_closure BEFORE INSERT ON user_privacy_events
    WHEN NEW.user_id=2 AND NEW.event_type='account_closed' BEGIN SELECT RAISE(ABORT,'fixture rejection'); END");
$closureFailed=false;try{sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),'fixture-password','DELETE');}catch(PDOException){$closureFailed=true;}
verify($closureFailed&&sf_canvas_gallery_get($closing['id'])!==null&&file_get_contents($closingPath)===$pixels&&
    sf_user_by_email('fixture2@example.invalid')['status']==='active',
    'A failed outer account closure preserves its publication rows and physical image bytes');
$db->exec('DROP TRIGGER fixture_reject_gallery_closure');
$closed=sf_privacy_close_account(2,sf_user_by_email('fixture2@example.invalid'),'fixture-password','DELETE');
verify($closed['closed']===true&&sf_canvas_gallery_export(2)===[]&&sf_canvas_gallery_get($closing['id'])===null&&
    sf_canvas_gallery_asset_entry($closing['id'])===null,
    'Account closure removes owner publications and revokes their public asset metadata');
verify(!file_exists($closingDirectory)&&file_get_contents($otherPath)===$pixels&&file_get_contents($otherCopy)===$pixels,
    'Committed account closure removes frozen gallery files while retaining other creators and imported private copies');
$q=$db->prepare('SELECT COUNT(*) FROM canvas_gallery_remixes WHERE user_id=?');$q->execute([2]);
verify((int)$q->fetchColumn()===0&&sf_canvas_gallery_get($otherApproved['id'])!==null&&sf_canvas_gallery_export(3)!==[],
    'Account closure purges only the closing owner remix receipts and gallery records');
rejects(fn()=>$submit($fan,$closingScene,str_repeat('3',32),'Late closed-account publication'),
    'A late submission cannot recreate gallery data for a closed account');
verify(sf_canvas_gallery_export(2)===[]&&sf_agent_scene_history_get(3,$otherRemix['scene']['id'])!==null,
    'Closed-account rejection preserves another listener existing private remix');
echo "Canvas gallery privacy: $checks checks passed\n";
