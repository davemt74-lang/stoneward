<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fixture=sys_get_temp_dir().'/stonefellow-gallery-assets-'.bin2hex(random_bytes(6));
foreach(['config','data','assets/js','storage/media/library/image'] as $dir)mkdir($fixture.'/'.$dir,0700,true);
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
require_once $root.'/api/canvas-gallery-core.php';require_once $root.'/api/canvas-gallery-assets.php';
$checks=0;
function verify(bool $condition,string $label):void {if(!$condition)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function rejects(callable $run,string $label):void {try{$run();}catch(InvalidArgumentException|RuntimeException){verify(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
function pixels(int $width=128,int $height=128,int $seed=1):string {
    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $data='';for($y=0;$y<$height;$y++){$data.="\0";for($x=0;$x<$width;$x++)$data.=chr(($x+$seed)%256).chr(($y+$seed)%256).chr(($x+$y)%256);}
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).$chunk('IDAT',gzcompress($data)).$chunk('IEND','');
}
function savedScene(int $uid,string $image,string $extra=''):array {
    return sf_agent_scene_history_save($uid,['canvas_kind'=>'custom','track_id'=>'','title'=>'Private original title',
        'html'=>'<section class="art sf-image-'.$image.'"></section>',
        'css'=>'.art{background-image:var(--sf-image-'.$image.',none)}',
        'js'=>'const image=document.querySelector(".sf-image-'.$image.'");const id="'.$image.'";'.$extra,
        'design_brief'=>'Private original design request.']);
}
function submitScene(array $user,array $scene,?string $preview=null):array {
    return sf_canvas_gallery_submit($user,['scene_id'=>$scene['id'],'request_id'=>bin2hex(random_bytes(16)),
        'publication_consent'=>true,'title'=>'Shared artwork','description'=>'Public description','creator_label'=>'Artist alias','preview_asset_id'=>$preview]);
}
function approve(array $item,array $admin):array {return sf_canvas_gallery_moderate($admin,$item['id'],'approve',$item['snapshot_hash']);}
function delivered(string $id,string $asset,?array $viewer=null):?string {
    $file=sf_canvas_gallery_assets_file($id,$asset,$viewer);if($file===null)return null;
    try{return stream_get_contents($file['handle']);}finally{fclose($file['handle']);}
}
$db=sf_db();$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users(id,email,display_name,password_hash,role,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for($id=1;$id<=4;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');sf_canvas_gallery_ensure_schema();sf_canvas_gallery_assets_prepare();
$admin=['id'=>1,'role'=>'admin'];$owner=['id'=>2,'role'=>'user'];$other=['id'=>3,'role'=>'user'];
$privateId=str_repeat('a',32);$foreignId=str_repeat('b',32);$bytes=pixels();$foreignBytes=pixels(128,128,2);
sf_agent_canvas_image_store(2,$privateId,'Private filename and personal label',['bytes'=>$bytes]);
sf_agent_canvas_image_store(3,$foreignId,'Another listener image',['bytes'=>$foreignBytes]);
$scene=savedScene(2,$privateId);$item=submitScene($owner,$scene,$privateId);$publicId=$item['id'];
$pending=sf_canvas_gallery_open($publicId,$admin,'',true);$publicAsset=$pending['scene']['image_assets'][0]['id'];
verify($publicAsset!==$privateId&&sf_canvas_gallery_assets_id($publicAsset)&&
    !str_contains(json_encode($pending['scene']),$privateId)&&!isset($pending['scene']['id'])&&
    !str_contains(json_encode($pending['scene']),'Private original')&&!str_contains(json_encode($pending['scene']),'Private filename'),
    'Submitted image bindings use independent public identities and omit private scene, prompt and image labels');
verify($pending['scene']['js_hash']===base64_encode(hash('sha256',$pending['scene']['js'],true))&&
    str_contains($pending['scene']['html'],'sf-image-'.$publicAsset)&&str_contains($pending['scene']['css'],'--sf-image-'.$publicAsset)&&
    str_contains($pending['scene']['js'],'"'.$publicAsset.'"'),
    'HTML, CSS and JavaScript image references are rewritten together and script integrity is recalculated');
$suffixed='sf-image-'.$privateId.'-alt';$rewritten=sf_canvas_gallery_assets_rewrite([
    'html'=>'<div class="'.$suffixed.'"></div>','css'=>'.'.$suffixed.'{color:#fff}',
    'js'=>'document.querySelector(".'.$suffixed.'");'],[$privateId=>$publicAsset]);
verify(str_contains($rewritten['html'],$suffixed)&&str_contains($rewritten['css'],$suffixed)&&str_contains($rewritten['js'],$suffixed),
    'Image identity remapping preserves unrelated authored class names with a longer suffix');
$row=sf_canvas_gallery_row($publicId);$manifest=json_decode($row['assets_json'],true);
verify(array_keys($manifest[0])===['id','mime','size_bytes','width','height','sha256']&&
    $manifest[0]['sha256']===hash('sha256',$bytes)&&$manifest[0]['size_bytes']===strlen($bytes)&&
    $item['preview_url']==='api/canvas-gallery.php?action=asset&id='.$publicId.'&asset_id='.$publicAsset,
    'The immutable manifest records exact image bytes and preview selection maps only to the frozen public asset');
verify(delivered($publicId,$publicAsset)===null&&delivered($publicId,$publicAsset,$other)===null&&
    delivered($publicId,$publicAsset,['id'=>3,'role'=>'admin'])===null,
    'Pending images reject guests, another listener and a forged client administrator role');
verify(delivered($publicId,$publicAsset,$owner)===$bytes&&delivered($publicId,$publicAsset,$admin)===$bytes,
    'The owner and a current administrator can preview exact pending image bytes');
approve($item,$admin);
verify(delivered($publicId,$publicAsset)===$bytes&&sf_canvas_gallery_list()['items'][0]['id']===$publicId,
    'Approved image snapshots are publicly readable through the permission-gated delivery helper');
verify(sf_canvas_gallery_assets_file('../'.$publicId,$publicAsset)===null&&sf_canvas_gallery_assets_file($publicId,'../'.$publicAsset)===null&&
    sf_canvas_gallery_assets_file($publicId,$foreignId)===null,
    'Traversal identifiers and unbound or foreign image identities cannot select gallery files');
$path=sf_canvas_gallery_assets_directory($publicId).'/'.$publicAsset.'.bin';
file_put_contents($path,$foreignBytes);
verify(delivered($publicId,$publicAsset)===null,'Changed snapshot bytes fail the immutable manifest integrity check');
file_put_contents($path,$bytes);unlink($path);symlink(sf_agent_canvas_image_file(3,$foreignId)['path'],$path);
verify(delivered($publicId,$publicAsset)===null,'A snapshot file replaced by a symlink cannot deliver another account image');
unlink($path);file_put_contents($path,$bytes);
$sourcePath=sf_agent_canvas_image_file(2,$privateId)['path'];unlink($sourcePath);$db->prepare('DELETE FROM agent_canvas_images WHERE user_id=? AND id=?')->execute([2,$privateId]);
verify(delivered($publicId,$publicAsset)===$bytes,'A frozen approved copy does not depend on the original private image file');
$remix=sf_canvas_gallery_remix($other,$publicId,str_repeat('c',32));$privateCopy=$remix['scene']['image_assets'][0]['id'];
verify($privateCopy!==$publicAsset&&sf_agent_canvas_image_get(3,$privateCopy)!==null&&sf_agent_canvas_image_get(2,$privateCopy)===null&&
    file_get_contents(sf_agent_canvas_image_file(3,$privateCopy)['path'])===$bytes,
    'Remixing imports exact pixels into new owner-scoped private storage');
sf_canvas_gallery_withdraw($owner,$publicId);
verify(delivered($publicId,$publicAsset)===null&&delivered($publicId,$publicAsset,$owner)===null&&
    file_get_contents(sf_agent_canvas_image_file(3,$privateCopy)['path'])===$bytes,
    'Withdrawal revokes all gallery delivery while the listener existing private remix retains its own bytes');
rejects(fn()=>sf_canvas_gallery_remix($other,$publicId,str_repeat('d',32)),
    'A withdrawn image snapshot cannot be imported into a new remix');
$foreign=savedScene(2,$foreignId);
rejects(fn()=>submitScene($owner,$foreign),'Submitting a foreign private image binding is refused instead of copied');
$unfinished=savedScene(2,'new-1');
rejects(fn()=>submitScene($owner,$unfinished),'An unfinished generated-image slot cannot enter a published snapshot');
rejects(fn()=>sf_canvas_gallery_assets_snapshot(3,$scene,str_repeat('e',32)),
    'A saved scene owned by another listener cannot authorize a publication capture');
$url=savedScene(3,$foreignId,'const source="https://example.invalid/private.png";');
rejects(fn()=>submitScene($other,$url),'External image URLs are refused with a saved-image binding requirement');
$scriptOnly=sf_agent_scene_history_save(3,['canvas_kind'=>'custom','track_id'=>'','title'=>'Script only','html'=>'<main></main>','css'=>'',
    'js'=>'const image=document.querySelector(".sf-image-'.$foreignId.'");']);
rejects(fn()=>submitScene($other,$scriptOnly),'Script-only private image references cannot escape the declared image bindings');
// Current artist opt-in is tracked privately without placing source identities in public metadata.
$small=pixels(64,64,9);$relative='storage/media/library/image/reference.png';file_put_contents(SF_ROOT.'/'.$relative,$small);
$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,title,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['reference','image','image/png','png','Private-original-name.png',$relative,hash('sha256',$small),strlen($small),'Artist reference',gmdate('c'),gmdate('c')]);
$mediaId=(int)$db->lastInsertId();$ref=sf_agent_canvas_reference_set($mediaId,true)['reference_image_id'];
$refScene=savedScene(2,$ref);
$refScene=sf_agent_scene_history_save(2,array_replace($refScene,[
    'html'=>'<section class="art sf-image-'.$ref.'"><span class="sf-action-app-notifications">Updates</span></section>']),$refScene['id']);
$refItem=submitScene($owner,$refScene,$ref);approve($refItem,$admin);
sf_canvas_app_change(3,'install',$refItem['id'],null);
sf_canvas_app_change(3,'set_notifications',null,$refItem['id'],true,$refItem['id']);
$refOpen=sf_canvas_gallery_open($refItem['id']);$refAsset=$refOpen['scene']['image_assets'][0]['id'];
verify(delivered($refItem['id'],$refAsset)===$small&&!str_contains(json_encode($refOpen['scene']),$ref)&&
    !str_contains(json_encode($refOpen['scene']),'Private-original-name'),
    'Artist reference publication copies exact pixels under independent safe identities');
$refRemix=sf_canvas_gallery_remix($other,$refItem['id'],str_repeat('f',32));$refCopy=$refRemix['scene']['image_assets'][0]['id'];
verify(sf_agent_canvas_image_get(3,$refCopy)['width']===64&&file_get_contents(sf_agent_canvas_image_file(3,$refCopy)['path'])===$small,
    'Valid small artist images retain their exact dimensions and pixels in a private remix');
sf_agent_canvas_reference_set($mediaId,false);
verify(delivered($refItem['id'],$refAsset)===null&&sf_canvas_gallery_get($refItem['id'])===null&&
    file_get_contents(sf_agent_canvas_image_file(3,$refCopy)['path'])===$small,
    'Removing artist public permission immediately revokes gallery copies without deleting a valid prior private remix');
$outage=sf_canvas_app_get(3,null,$refItem['id']);
verify($outage['gallery_id']===null&&$outage['enabled']&&!$outage['supports_alerts']&&
    !sf_canvas_app_change(3,'set_notifications',null,$refItem['id'],false)['enabled'],
    'An app unavailable due to a revoked reference still exposes its saved opt-in so the installer can turn it off');
// Independent publication from the other account survives owner-specific clearing.
$otherItem=submitScene($other,savedScene(3,$foreignId));approve($otherItem,$admin);
$otherAsset=sf_canvas_gallery_open($otherItem['id'])['scene']['image_assets'][0]['id'];
$db->exec("UPDATE users SET status='closed' WHERE id=3");
verify(delivered($otherItem['id'],$otherAsset)===null,'Closing a creator account immediately blocks its formerly public image delivery');
$db->exec("UPDATE users SET status='active' WHERE id=3");
sf_privacy_clear_agent_history(2);
verify(sf_canvas_gallery_asset_entry($refItem['id'])===null&&delivered($refItem['id'],$refAsset)===null&&
    delivered($otherItem['id'],$otherAsset)===$foreignBytes,
    'History clearing removes the creator gallery authority and leaves another creator publication intact');
$otherDir=sf_canvas_gallery_assets_directory($otherItem['id']);
sf_canvas_gallery_assets_remove($otherItem['id']);
verify(!is_dir($otherDir)&&delivered($otherItem['id'],$otherAsset)===null,
    'Snapshot cleanup removes only the selected protected asset directory and closed handles cannot be reissued');
verify(str_contains(file_get_contents($root.'/.htaccess'),'RewriteRule ^storage/ - [F,L,NC]'),
    'Frozen gallery files remain beneath the existing static storage deny rule');
echo 'Canvas gallery assets: '.$checks." checks passed\n";
