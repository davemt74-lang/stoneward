<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$fixture=sys_get_temp_dir().'/stonefellow-image-review-'.bin2hex(random_bytes(8));
mkdir($fixture.'/config',0700,true);
mkdir($fixture.'/data',0700,true);
mkdir($fixture.'/assets/js',0700,true);
symlink($root.'/api',$fixture.'/api');
file_put_contents($fixture.'/config/database.php','<?php return '.var_export(['driver'=>'sqlite','path'=>$fixture.'/test.sqlite'],true).';');
copy($root.'/config/store.default.php',$fixture.'/config/store.default.php');
file_put_contents($fixture.'/data/catalog.json','[]');
file_put_contents($fixture.'/data/releases.json','[]');
register_shutdown_function(static function()use($fixture):void {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry){
        if($entry->isDir()&&!$entry->isLink())rmdir($entry->getPathname());else unlink($entry->getPathname());
    }
    rmdir($fixture);
});
define('SF_ROOT',$fixture);
require $root.'/api/bootstrap.php';
require_once $root.'/api/migrations.php';
require_once $root.'/api/agent-canvas-image-review.php';
require_once $root.'/api/agent-scene-core.php';
require_once $root.'/api/agent-canvas-image-references.php';

$checks=0;
function verify(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo "PASS $message\n";
}
function rejects(callable $run,string $message,string $type=InvalidArgumentException::class):void {
    try{$run();}catch(Throwable $error){
        if(!$error instanceof $type)throw $error;
        verify(true,$message);return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}
function review_count():int {return (int)sf_db()->query('SELECT COUNT(*) FROM agent_canvas_image_reviews')->fetchColumn();}
function scene_count():int {return (int)sf_db()->query('SELECT COUNT(*) FROM agent_scene_history')->fetchColumn();}
function claim_review(string $requestId,int $owner=2):int {
    $epoch=sf_agent_scene_history_epoch($owner);
    verify(sf_agent_scene_request_claim($owner,$requestId,hash('sha256',$requestId),$epoch),'claim '.$requestId);
    return $epoch;
}
function review_candidates(string $requestId,int $owner=2,int $count=3):array {
    $images=[];
    foreach(array_slice(sf_agent_canvas_image_batch_ids($requestId),0,$count) as $offset=>$id){
        $asset=sf_agent_canvas_image_store($owner,$id,'Variation '.($offset+1),['bytes'=>$GLOBALS['png']]);
        $images[]=['id'=>$asset['id'],'title'=>$asset['title'],'index'=>$offset+1];
    }
    return $images;
}
function stage_review(string $requestId,?int $parent=null,int $owner=2):array {
    $epoch=claim_review($requestId,$owner);
    $images=review_candidates($requestId,$owner);
    $scene=sf_agent_canvas_image_apply($GLOBALS['base'],$requestId,$GLOBALS['sourceId']);
    sf_db()->beginTransaction();
    try{
        $result=sf_agent_canvas_image_review_stage($owner,$requestId,$scene,$parent,$epoch,$images,3,97);
        sf_db()->commit();return $result;
    }catch(Throwable $error){sf_db()->rollBack();throw $error;}
}

$db=sf_db();
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT UNIQUE,display_name TEXT,password_hash TEXT,role TEXT,status TEXT,created_at TEXT,updated_at TEXT,last_login_at TEXT)');
$q=$db->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,NULL)');
for($id=1;$id<=3;$id++)$q->execute([$id,"fixture$id@example.invalid","Fixture $id",password_hash('fixture-only-password',PASSWORD_DEFAULT),$id===1?'admin':'user','active',gmdate('c'),gmdate('c')]);
sf_migration_run_all(1,'');
sf_agent_canvas_image_ensure_schema();
sf_agent_canvas_image_review_ensure_schema();
sf_agent_canvas_reference_ensure_schema();
sf_privacy_ensure_schema();
$chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
$png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $chunk('IDAT',gzcompress(str_repeat("\0".random_bytes(128*3),128))).$chunk('IEND','');
$sourceId=str_repeat('a',32);$otherSlot=str_repeat('b',32);$replacement=str_repeat('c',32);
foreach([$sourceId,$otherSlot,$replacement] as $id)sf_agent_canvas_image_store(2,$id,'Existing '.$id,['bytes'=>$png]);
$base=['canvas_kind'=>'custom','track_id'=>'','title'=>'Image composition','catalog_refs'=>[],'lyric_section'=>null,
    'html'=>'<section class="composition"><div class="sf-image-'.$sourceId.'"></div><div class="sf-image-'.$otherSlot.'"></div></section>',
    'css'=>'.composition { background: var(--sf-image-'.$sourceId.', none); } .sf-image-'.$sourceId.' { color: #eee; } .secondary { background: var(--sf-image-'.$otherSlot.', none); }'];
$parent=sf_agent_scene_history_save(2,$base);
sf_token_adjust(2,100,'admin_grant','review-fixture');
$requestId=str_repeat('d',32);
$epoch=claim_review($requestId);
$images=review_candidates($requestId);
$draft=sf_agent_canvas_image_apply($base,$requestId,$sourceId);
rejects(fn()=>sf_agent_canvas_image_review_stage(2,$requestId,$draft,$parent['id'],$epoch,$images,3,97),
    'staging requires the token transaction',LogicException::class);
$hold=sf_ai_reserve_tokens(['id'=>2,'role'=>'user'],10,'review-fixture');
$result=[];
$remaining=sf_ai_finish_reservation(['id'=>2,'role'=>'user'],$hold,3,[],'review-fixture',true,
    static function(int $balance)use(&$result,$requestId,$draft,$parent,$epoch,$images):void {
        $result=sf_agent_canvas_image_review_stage(2,$requestId,$draft,$parent['id'],$epoch,$images,3,$balance);
    });
verify($remaining===97&&$result['state']==='review'&&$result['generated_images']===$images&&
    $result['active_tokens_used']===3&&$result['active_tokens_remaining']===97,'a durable review settles the build charge once');
verify(scene_count()===1&&sf_agent_scene_history_get(2,$parent['id'])===$parent,'staging leaves the previous canvas active and does not publish a draft');
verify(sf_agent_scene_request_result(2,$requestId)===$result,'request recovery returns review choices and the settled token receipt');
verify(sf_agent_canvas_image_review_result(3,$requestId)===['state'=>'failed']&&sf_agent_scene_request_result(1,$requestId)===null,
    'review choices remain private to the owning account');
$db->prepare('UPDATE agent_scene_requests SET created_at=0 WHERE user_id=2 AND request_id=?')->execute([$requestId]);
verify(sf_agent_scene_request_result(2,$requestId)===$result,'saved image choices remain available beyond the generation deadline');
rejects(fn()=>sf_agent_canvas_image_review_choose(3,$requestId,$images[1]['id']),'a different listener cannot select a variation');
rejects(fn()=>sf_agent_canvas_image_review_choose(1,$requestId,$images[1]['id']),'a site administrator cannot select a private variation');
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$requestId,$replacement),'selection requires a candidate from this request');
$db->exec("CREATE TRIGGER reject_selection BEFORE UPDATE OF state ON agent_scene_requests WHEN NEW.state='ready'
    BEGIN SELECT RAISE(ABORT,'fixture selection failure'); END");
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$requestId,$images[1]['id']),
    'a failed ready receipt rolls back the selected canvas revision',PDOException::class);
verify(scene_count()===1&&sf_agent_scene_request_result(2,$requestId)===$result,
    'an incomplete selection remains available for a safe retry');
$db->exec('DROP TRIGGER reject_selection');
$chosen=sf_agent_canvas_image_review_choose(2,$requestId,$images[1]['id']);
$expected=sf_agent_canvas_image_apply($draft,$images[1]['id'],$requestId);
verify($chosen['state']==='ready'&&$chosen['scene']['previous_id']===$parent['id']&&
    $chosen['scene']['html']===$expected['html']&&$chosen['scene']['css']===$expected['css'],
    'choosing one variation changes only its HTML and CSS binding in a new revision');
verify(in_array($otherSlot,sf_agent_canvas_image_bindings($chosen['scene']),true)&&sf_agent_scene_history_get(2,$parent['id'])===$parent,
    'other image bindings and earlier history remain intact');
verify($chosen['active_tokens_used']===3&&$chosen['active_tokens_remaining']===97&&sf_token_balance(2)===97,
    'selection preserves the original settlement without another debit');
verify(sf_agent_canvas_image_review_choose(2,$requestId,$images[1]['id'])===$chosen&&scene_count()===2,
    'replaying the same selection returns the same saved revision');
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$requestId,$images[2]['id']),'a completed review cannot apply a second candidate');

$newId=str_repeat('e',32);
stage_review($newId);
$newChosen=sf_agent_canvas_image_review_choose(2,$newId,sf_agent_canvas_image_batch_ids($newId)[2]);
verify($newChosen['scene']['previous_id']===null&&$newChosen['state']==='ready','a brand-new canvas supports variation review without a previous canvas');
$cancelId=str_repeat('f',32);stage_review($cancelId,$parent['id']);
$before=scene_count();$balance=sf_token_balance(2);$beforeReviews=review_count();
sf_agent_scene_request_cancel(2,$cancelId);
verify(sf_agent_scene_request_get(2,$cancelId)===['state'=>'canceled']&&review_count()===$beforeReviews-1&&scene_count()===$before,
    'canceling a review removes the unpublished draft and preserves saved history');
verify(count(sf_agent_canvas_image_saved_batch(2,$cancelId))===3&&sf_token_balance(2)===$balance,
    'canceling preserves generated images and their original token settlement');
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$cancelId,$cancelId),'canceled review cannot publish');

$rollbackId=str_repeat('1',32);$rollbackEpoch=claim_review($rollbackId);$rollbackImages=review_candidates($rollbackId);
$rollbackDraft=sf_agent_canvas_image_apply($base,$rollbackId,$sourceId);
$db->exec("CREATE TRIGGER reject_review BEFORE INSERT ON agent_canvas_image_reviews BEGIN SELECT RAISE(ABORT,'fixture review failure'); END");
$rollbackHold=sf_ai_reserve_tokens(['id'=>2,'role'=>'user'],10,'review-rollback');
rejects(fn()=>sf_ai_finish_reservation(['id'=>2,'role'=>'user'],$rollbackHold,4,[],'review-rollback',true,
    fn(int $balance)=>sf_agent_canvas_image_review_stage(2,$rollbackId,$rollbackDraft,null,$rollbackEpoch,$rollbackImages,4,$balance)),
    'review storage failure rolls back token settlement',PDOException::class);
$db->exec('DROP TRIGGER reject_review');
verify(sf_agent_scene_request_get(2,$rollbackId)===['state'=>'pending']&&sf_token_balance(2)===87,
    'failed review settlement retains its pending claim and original refundable hold');
sf_ai_finish_reservation(['id'=>2,'role'=>'user'],$rollbackHold,0,[],'review-rollback',false);
verify(sf_token_balance(2)===97,'releasing the unsuccessful review restores its held tokens');

$swapId=str_repeat('2',32);
$swap=['request_id'=>$swapId,'previous_id'=>$chosen['scene']['id'],'image_id'=>$images[1]['id'],'replacement_id'=>$replacement];
$before=scene_count();$ledger=(int)$db->query('SELECT COUNT(*) FROM token_ledger')->fetchColumn();
$swapped=sf_agent_canvas_image_swap(2,$swap);
$expected=sf_agent_canvas_image_apply($chosen['scene'],$replacement,$images[1]['id']);
verify($swapped['scene']['html']===$expected['html']&&$swapped['scene']['css']===$expected['css']&&
    $swapped['scene']['previous_id']===$chosen['scene']['id']&&scene_count()===$before+1,'swapping a saved image changes one binding in a new revision');
verify($swapped['active_tokens_used']===0&&$swapped['active_tokens_remaining']===97&&
    (int)$db->query('SELECT COUNT(*) FROM token_ledger')->fetchColumn()===$ledger,'saved-image swaps make no provider request or token entry');
verify(sf_agent_canvas_image_swap(2,$swap)===$swapped&&scene_count()===$before+1,'retrying an image swap recovers the same revision');
rejects(fn()=>sf_agent_canvas_image_swap(3,array_replace($swap,['request_id'=>str_repeat('3',32)])),
    'a different listener cannot swap an image in another listener canvas');
rejects(fn()=>sf_agent_canvas_image_swap(2,array_replace($swap,['request_id'=>str_repeat('4',32),'image_id'=>$sourceId])),
    'swapping requires a source binding in the specified current canvas');
sf_agent_canvas_image_store(3,str_repeat('5',32),'Private other listener image',['bytes'=>$png]);
rejects(fn()=>sf_agent_canvas_image_swap(2,array_replace($swap,['request_id'=>str_repeat('6',32),'replacement_id'=>str_repeat('5',32)])),
    'swapping cannot place an image owned by another listener');

mkdir($fixture.'/storage/media/review-fixture',0700,true);
$mediaPath='storage/media/review-fixture/source.png';file_put_contents($fixture.'/'.$mediaPath,$png);
$db->prepare('INSERT INTO media_assets(uuid,category,mime_type,extension,original_name,stored_path,sha256,size_bytes,width,height,title,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['MREVIEWFIXTURE','image','image/png','png','source.png',$mediaPath,hash('sha256',$png),strlen($png),128,128,'Opted-in artist image',gmdate('c'),gmdate('c')]);
$mediaId=(int)$db->lastInsertId();$reference=sf_agent_canvas_reference_set($mediaId,true)['reference_image_id'];
$referenceSwap=['request_id'=>str_repeat('7',32),'previous_id'=>$swapped['scene']['id'],'image_id'=>$replacement,'replacement_id'=>$reference];
$shared=sf_agent_canvas_image_swap(2,$referenceSwap);
verify(in_array($reference,sf_agent_canvas_image_bindings($shared['scene']),true)&&$shared['active_tokens_used']===0,
    'an explicitly shared artist reference can replace the selected saved-image binding');
sf_agent_canvas_reference_set($mediaId,false);
verify(sf_agent_canvas_image_swap(2,$referenceSwap)===$shared,'a completed swap remains recoverable after its reference is revoked');
rejects(fn()=>sf_agent_canvas_image_swap(2,array_replace($referenceSwap,['request_id'=>str_repeat('8',32)])),
    'a revoked artist reference cannot be placed in a new revision');
$reference=sf_agent_canvas_reference_set($mediaId,true)['reference_image_id'];
$tampered=$png;$tampered[100]=chr(ord($tampered[100])^1);file_put_contents($fixture.'/'.$mediaPath,$tampered);
rejects(fn()=>sf_agent_canvas_image_swap(2,array_replace($referenceSwap,['request_id'=>str_repeat('8',31).'a','replacement_id'=>$reference])),
    'a changed artist reference must pass its saved content hash before a swap');
file_put_contents($fixture.'/'.$mediaPath,$png);

$missingId=str_repeat('9',32);stage_review($missingId,$parent['id']);
$missing=sf_agent_canvas_image_file(2,$missingId);unlink($missing['path']);
verify(count(sf_agent_canvas_image_review_result(2,$missingId)['generated_images'])===2,'review recovery hides images whose stored file is unavailable');
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$missingId,$missingId),'an unavailable candidate cannot be selected');
$disabledId=str_repeat('0',32);stage_review($disabledId,$parent['id']);
$db->exec("UPDATE users SET status='disabled' WHERE id=2");
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$disabledId,$disabledId),'a disabled account cannot publish a review');
verify(sf_agent_canvas_image_review_result(2,$disabledId)===['state'=>'failed'],'review recovery checks current account availability');
$db->exec("UPDATE users SET status='active' WHERE id=2");

$beforeImages=count(sf_agent_canvas_images_list(2,100));
$otherParent=sf_agent_scene_history_save(3,$base);
sf_privacy_clear_agent_history(2);
verify(review_count()===0&&sf_agent_scene_history_list(2)===[]&&sf_agent_scene_history_get(3,$otherParent['id'])!==null,
    'history clearing cascades draft reviews and leaves another account untouched');
verify(count(sf_agent_canvas_images_list(2,100))===$beforeImages,'history clearing preserves the separate generated image inventory');
rejects(fn()=>sf_agent_canvas_image_review_choose(2,$disabledId,$disabledId),'cleared review cannot restore a canvas');
verify(sf_agent_canvas_image_review_result(2,$disabledId)===['state'=>'failed']&&sf_agent_scene_request_result(2,$disabledId)===null,
    'erased request and review metadata cannot be recovered');

echo "PASS $checks image review and swap checks\n";
