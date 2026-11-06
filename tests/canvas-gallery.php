<?php
declare(strict_types=1);
require __DIR__.'/canvas-projects.php';
require_once $root.'/api/canvas-gallery-core.php';
$galleryChecks=0;$providerCalls=$calls;$balanceBeforeGallery=sf_token_balance(1);
function gallery_check(bool $ok,string $label):void {global $galleryChecks;if(!$ok)throw new RuntimeException($label);$galleryChecks++;echo 'GALLERY PASS '.$label.PHP_EOL;}
function gallery_reject(callable $run,string $class,string $label):void {try{$run();}catch(Throwable $error){if($error instanceof $class){gallery_check(true,$label);return;}throw $error;}throw new RuntimeException('Expected rejection: '.$label);}
function gallery_request(int $scene,string $requestId,string $title='Public paper landscape'):array {return ['scene_id'=>$scene,'request_id'=>$requestId,'title'=>$title,'description'=>'An interactive public landscape.','creator_label'=>'Paper maker','publication_consent'=>true];}
sf_db()->exec("UPDATE users SET role='admin' WHERE id=2");$admin=['id'=>2,'role'=>'admin'];
$source=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'PRIVATE_SOURCE_TITLE',
    'html'=>'<section class="scene sf-dataset-my_purchases"><div class="sf-data-row"><span class="sf-field-item_label"></span></div></section>',
    'css'=>'.scene{color:#abc}','js'=>'window.galleryFixture=true;','design_brief'=>'PRIVATE_ORIGINAL_PROMPT',
    'music_inputs'=>['mode'=>'all'],'artist_inputs'=>['merch_ids'=>[]]]);
gallery_check(sf_canvas_gallery_list()['items']===[]&&!sf_canvas_gallery_exists(),'Public reads are empty and create no optional gallery storage before the first submission');
$request=gallery_request($source['id'],str_repeat('a1',16));
gallery_reject(fn()=>sf_canvas_gallery_submit($user,array_replace($request,['publication_consent'=>false])),InvalidArgumentException::class,'A submission requires explicit code and asset publication consent');
gallery_reject(fn()=>sf_canvas_gallery_submit($admin,$request),InvalidArgumentException::class,'A different account cannot submit another listener saved scene');
$pending=sf_canvas_gallery_submit($user,$request);
gallery_check($pending['status']==='pending'&&sf_canvas_gallery_get($pending['id'])===null&&sf_canvas_gallery_list()['total']===0,'New immutable submissions stay private until administrator approval');
gallery_reject(fn()=>sf_canvas_gallery_open($pending['id'],$user),SfCanvasGalleryNotFound::class,'Public Open requires approval even for the submitting owner');
gallery_check(sf_canvas_gallery_submit($user,$request)===$pending&&count(sf_canvas_gallery_list(1)['items'])===1,'Submitting the same saved request is idempotent without duplicate entries');
gallery_reject(fn()=>sf_canvas_gallery_submit($user,array_replace($request,['title'=>'Different snapshot'])),SfCanvasGalleryConflict::class,'A submission request ID cannot silently change its exact snapshot');
gallery_check(sf_canvas_gallery_get($pending['id'],2)===null&&sf_canvas_gallery_list(2)['items']===[],'Owner submission status and feedback remain isolated by account');
gallery_reject(fn()=>sf_canvas_gallery_withdraw($admin,$pending['id']),SfCanvasGalleryNotFound::class,'Another owner cannot withdraw a private submission');
$later=sf_agent_scene_history_save(1,array_replace($source,['html'=>'<section class="later"></section>','title'=>'PRIVATE_LATER_EDIT']),$source['id']);
$exact=sf_canvas_gallery_open($pending['id'],$admin,'',true);
gallery_check($exact['scene']['html']===$source['html']&&$exact['scene']['title']===$request['title'],'Later private revisions never modify the administrator reviewed immutable snapshot');
gallery_check(!isset($exact['scene']['datasets']['my_purchases']),'An unreviewed administrator preview receives public artist data without administrator personal data');
$serialized=json_encode($exact['scene'],JSON_THROW_ON_ERROR);
gallery_check(!str_contains($serialized,'PRIVATE_ORIGINAL_PROMPT')&&!str_contains($serialized,'PRIVATE_SOURCE_TITLE')&&
    !isset($exact['scene']['id'],$exact['scene']['previous_id'],$exact['scene']['design_brief'],$exact['scene']['workflow']),
    'Gallery code delivery omits publisher prompts private scene identifiers and workflow data');
gallery_reject(fn()=>sf_canvas_gallery_moderate($admin,$pending['id'],'approve',str_repeat('0',64)),SfCanvasGalleryConflict::class,'Administrator decisions must match the exact reviewed snapshot hash');
gallery_reject(fn()=>sf_canvas_gallery_moderate($user,$pending['id'],'approve',$pending['snapshot_hash']),InvalidArgumentException::class,'Core moderation also rejects non-administrators');
$approved=sf_canvas_gallery_moderate($admin,$pending['id'],'approve',$pending['snapshot_hash']);
gallery_check($approved['status']==='approved'&&sf_canvas_gallery_get($pending['id'])['creator_label']==='Paper maker','Approving the exact snapshot publishes its public creator attribution');
$opened=sf_canvas_gallery_open($pending['id']);$publicItem=$opened['item'];
gallery_check(!isset($publicItem['source_scene_id'],$publicItem['user_id'],$publicItem['feedback'],$publicItem['snapshot_hash'])&&
    $opened['scene']['gallery_id']===$pending['id']&&!isset($opened['scene']['id']),
    'Anonymous Open exposes only public identifiers and approved data with no save or inference');
gallery_check(!isset($opened['scene']['datasets']['my_purchases']),'Anonymous viewers do not receive publisher personal purchase datasets');
$disabledModeration=sf_canvas_gallery_submit($user,gallery_request($source['id'],str_repeat('d1',16),'Disabled creator moderation'));
sf_canvas_gallery_moderate($admin,$disabledModeration['id'],'approve',$disabledModeration['snapshot_hash']);
sf_db()->exec("UPDATE users SET status='disabled' WHERE id=1");
gallery_check(sf_canvas_gallery_get($pending['id'])===null&&sf_canvas_gallery_list()['total']===0,'Disabling a creator consistently removes public code and cards even when no images were selected');
gallery_reject(fn()=>sf_canvas_gallery_open($pending['id']),SfCanvasGalleryNotFound::class,'Public Open also rejects an inactive creator entry');
gallery_check(sf_canvas_gallery_moderate($admin,$disabledModeration['id'],'unpublish',$disabledModeration['snapshot_hash'],'Do not restore this entry.')['status']==='returned',
    'Administrator unpublishing remains available while a creator account is disabled');
sf_db()->exec("UPDATE users SET status='active' WHERE id=1");
gallery_check(sf_canvas_gallery_get($disabledModeration['id'])===null,'An administrator unpublished entry stays private if the creator is later reactivated');
@mkdir(SF_ROOT.'/storage/orders',0700,true);
foreach([1,2] as $buyer)file_put_contents(SF_ROOT.'/storage/orders/SF-GALLERY-'.$buyer.'.json',json_encode([
    'id'=>'SF-GALLERY-'.$buyer,'user_id'=>$buyer,'created_at'=>gmdate('c'),'status'=>'paid',
    'quote'=>['total_cents'=>1200,'currency'=>'USD','items'=>[['label'=>'PRIVATE_BUYER_'.$buyer,'type'=>'merch','price_cents'=>1200,'quantity'=>1]]],
    'payment'=>['status'=>'paid']],JSON_THROW_ON_ERROR));
$ownerOpened=sf_canvas_gallery_open($pending['id'],$user);$otherOpened=sf_canvas_gallery_open($pending['id'],$admin);
gallery_check($ownerOpened['scene']['datasets']['my_purchases']['rows']===[]&&$otherOpened['scene']['datasets']['my_purchases']['rows']===[], 'Personal purchase rows require independent viewer consent before delivery');
foreach([$user,$admin] as $viewer){$access=sf_canvas_access_summary($viewer,['gallery_id'=>$pending['id']]);sf_canvas_access_change($viewer,['gallery_id'=>$pending['id']],'grant',$access['fingerprint'],['canvas.data.my_purchases']);}
$ownerOpened=sf_canvas_gallery_open($pending['id'],$user);$otherOpened=sf_canvas_gallery_open($pending['id'],$admin);
gallery_check(isset($ownerOpened['scene']['datasets']['my_purchases'])&&isset($otherOpened['scene']['datasets']['my_purchases'])&&
    !isset($ownerOpened['item']['source_scene_id']),
    'Signed-in runtime datasets resolve for each current viewer without adding owner-private fields to the public card');
gallery_check($ownerOpened['scene']['datasets']['my_purchases']['rows'][0]['item_label']==='PRIVATE_BUYER_1'&&
    $otherOpened['scene']['datasets']['my_purchases']['rows'][0]['item_label']==='PRIVATE_BUYER_2'&&
    !str_contains(sf_canvas_gallery_row($pending['id'])['snapshot_json'],'PRIVATE_BUYER_'),
    'Runtime purchase rows belong only to the current viewer and are never frozen in the published snapshot');
$copy=sf_canvas_gallery_remix($user,$pending['id'],str_repeat('a2',16));$copiedScene=$copy['scene'];
gallery_check(sf_canvas_access_summary($user,['scene_id'=>$copiedScene['id']])['requires_consent'],'Private remixes get independent consent even when the same viewer allowed the public source');
gallery_check($copiedScene['id']!==$source['id']&&!isset($copiedScene['design_brief'],$copiedScene['workflow_required'],$copiedScene['verification_parent_id'])&&
    $copiedScene['gallery_attribution']['source']['id']===$pending['id']&&$copiedScene['html']===$source['html'],
    'Remix saves an independent private reviewed copy with public ancestry and no publisher private metadata');
gallery_check(sf_canvas_gallery_remix($user,$pending['id'],str_repeat('a2',16))['scene']['id']===$copiedScene['id'],
    'Remix recovery returns the same owned scene without a duplicate private copy');
$copySubmission=sf_canvas_gallery_submit($user,gallery_request($copiedScene['id'],str_repeat('a3',16),'Remixed landscape'));
gallery_check($copySubmission['attribution']['source']['id']===$pending['id'],'Publishing a private remix preserves its original public creator ancestry');
$returned=sf_canvas_gallery_moderate($admin,$copySubmission['id'],'return',$copySubmission['snapshot_hash'],'Please clarify the public description.');
gallery_check($returned['status']==='returned'&&sf_canvas_gallery_get($returned['id'],1)['feedback']==='Please clarify the public description.',
    'Administrator return feedback remains visible to the submitting owner');
gallery_reject(fn()=>sf_canvas_gallery_moderate($admin,$returned['id'],'approve',$returned['snapshot_hash']),SfCanvasGalleryConflict::class,
    'Returned snapshots cannot bypass a new submission and fresh review');
$unpublished=sf_canvas_gallery_moderate($admin,$pending['id'],'unpublish',$pending['snapshot_hash'],'A revision is needed.');
gallery_check($unpublished['status']==='returned'&&sf_canvas_gallery_get($pending['id'])===null,'Administrator unpublishing immediately removes public catalog and Open access');
gallery_reject(fn()=>sf_canvas_gallery_open($pending['id']),SfCanvasGalleryNotFound::class,'An unpublished canvas cannot be opened by a public visitor');
gallery_check(sf_agent_scene_history_get(1,$copiedScene['id'])['html']===$source['html'],'Removing a public source leaves already-owned private remix copies intact');
gallery_check(sf_canvas_gallery_remix($user,$pending['id'],str_repeat('a2',16))['scene']['id']===$copiedScene['id'],'A completed private remix remains recoverable after source unpublishing');
$decline=sf_canvas_gallery_submit($user,gallery_request($source['id'],str_repeat('a4',16),'Declined fixture'));
gallery_check(sf_canvas_gallery_moderate($admin,$decline['id'],'decline',$decline['snapshot_hash'],'Not ready for publication.')['status']==='declined',
    'Declining a pending snapshot never publishes its code');
$withdrawn=sf_canvas_gallery_withdraw($user,$copySubmission['id']);gallery_check($withdrawn['status']==='withdrawn',
    'Owners can explicitly withdraw their submitted snapshots');
$unready=sf_agent_scene_history_save(1,array_replace($source,['workflow_required'=>true]));
gallery_reject(fn()=>sf_canvas_gallery_submit($user,gallery_request($unready['id'],str_repeat('a5',16))),InvalidArgumentException::class,
    'A saved untested model candidate cannot be submitted to the gallery');
sf_canvas_build_workflow_ensure_schema();$now=gmdate('c');
sf_db()->prepare("INSERT INTO canvas_build_jobs(user_id,request_id,original_scene_id,candidate_scene_id,history_epoch,state,message,created_at,updated_at) VALUES(?,?,?,?,?,'ready','',?,?)")
    ->execute([1,str_repeat('a6',16),$unready['id'],$unready['id'],sf_agent_scene_history_epoch(1),$now,$now]);
gallery_check(sf_canvas_gallery_submit($user,gallery_request($unready['id'],str_repeat('a7',16)))['status']==='pending',
    'An exact current verified model candidate can enter normal administrator review');
$derived=sf_agent_scene_history_save(1,array_replace($source,['workflow_required'=>true,'verification_parent_id'=>$unready['id']]),$unready['id']);
gallery_check(sf_canvas_gallery_submit($user,gallery_request($derived['id'],str_repeat('a8',16)))['status']==='pending',
    'A host-verified same-code derivative can be submitted without a new model call');
$page1=sf_canvas_gallery_list(1,false,['page'=>1,'limit'=>2]);$page2=sf_canvas_gallery_list(1,false,['page'=>2,'limit'=>2]);
gallery_check($page1['total']>=5&&count($page1['items'])===2&&count($page2['items'])===2&&$page1['has_more']&&
    array_intersect(array_column($page1['items'],'id'),array_column($page2['items'],'id'))===[],
    'Pagination exposes the complete owner catalog rather than silently truncating unseen submissions');
gallery_check(sf_canvas_gallery_list(1,false,['q'=>'Remixed'])['total']===1,'Gallery search uses literal text against submitted public metadata');
for($index=0;$index<103;$index++){
    $card=sf_canvas_gallery_submit($user,gallery_request($source['id'],str_pad(dechex(4096+$index),32,'0',STR_PAD_LEFT),'Chunk paging fixture'));
    sf_canvas_gallery_moderate($admin,$card['id'],'approve',$card['snapshot_hash']);
}
$chunkFirst=sf_canvas_gallery_list(null,false,['q'=>'Chunk paging fixture','limit'=>60]);
$chunkSecond=sf_canvas_gallery_list(null,false,['q'=>'Chunk paging fixture','limit'=>60,'page'=>2]);
gallery_check($chunkFirst['total']===103&&count($chunkFirst['items'])===60&&$chunkFirst['has_more']&&count($chunkSecond['items'])===43&&!$chunkSecond['has_more']&&
    array_intersect(array_column($chunkFirst['items'],'id'),array_column($chunkSecond['items'],'id'))===[],
    'Public metadata paging crosses its bounded scan batch without losing any approved entries or exposing snapshot code');

// A reply assembly error after commit must not delete already committed image copies.
$pngChunk=static fn(string $kind,string $bytes):string=>pack('N',strlen($bytes)).$kind.$bytes.pack('N',crc32($kind.$bytes));
$png="\x89PNG\r\n\x1a\n".$pngChunk('IHDR',pack('NNC5',128,128,8,2,0,0,0)).
    $pngChunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\xe3\x58\x82",128),128))).$pngChunk('IEND','');
$imageId=str_repeat('c1',16);sf_agent_canvas_image_store(1,$imageId,'Private fixture label',['bytes'=>$png]);
$imageScene=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Image fixture',
    'html'=>'<figure class="sf-image-'.$imageId.'"></figure>','css'=>'figure{background:var(--sf-image-'.$imageId.')}']);
$postCommitRequest=gallery_request($imageScene['id'],str_repeat('c2',16),'Committed image fixture');$capturedGalleryId=null;
$capture=static function(int $uid,array $scene,string $id)use(&$capturedGalleryId):array {$capturedGalleryId=$id;return sf_canvas_gallery_assets_snapshot($uid,$scene,$id);};
sf_db()->exec("CREATE TEMP TRIGGER gallery_fixture_bad_reply AFTER INSERT ON canvas_gallery_entries BEGIN UPDATE canvas_gallery_entries SET attribution_json='invalid-json' WHERE id=NEW.id; END");
gallery_reject(fn()=>sf_canvas_gallery_submit($user,$postCommitRequest,$capture),JsonException::class,
    'A deterministic post-commit submission reply failure reaches the recovery path');
sf_db()->exec('DROP TRIGGER gallery_fixture_bad_reply');$committedEntry=sf_canvas_gallery_asset_entry($capturedGalleryId);
$committedAsset=$committedEntry['assets'][0]['id'];$pixels=sf_canvas_gallery_assets_file($capturedGalleryId,$committedAsset,$user);
gallery_check($pixels!==null&&stream_get_contents($pixels['handle'])===$png,
    'Committed publication metadata and exact frozen pixels survive a later reply assembly failure');if($pixels)fclose($pixels['handle']);
sf_db()->prepare('UPDATE canvas_gallery_entries SET attribution_json=NULL WHERE id=?')->execute([$capturedGalleryId]);
$recovered=sf_canvas_gallery_submit($user,$postCommitRequest);
gallery_check($recovered['id']===$capturedGalleryId,'Submission recovery returns the committed record without recreating its snapshot');
sf_canvas_gallery_moderate($admin,$recovered['id'],'approve',$recovered['snapshot_hash']);
$privateFiles=[];$failedRemixRequest=str_repeat('c3',16);
$copyThenReplyFailure=static function(int $uid,array $entry)use(&$privateFiles):array {
    $copied=sf_canvas_gallery_assets_remix($uid,$entry);$privateFiles=$copied['created_files'];
    sf_db()->exec("CREATE TEMP TRIGGER gallery_fixture_bad_history_reply AFTER INSERT ON agent_scene_history BEGIN UPDATE agent_scene_history SET gallery_attribution='invalid-json' WHERE id=NEW.id; END");
    return $copied;
};
gallery_reject(fn()=>sf_canvas_gallery_remix($user,$recovered['id'],$failedRemixRequest,$copyThenReplyFailure),JsonException::class,
    'A deterministic post-commit private remix reply failure reaches the recovery path');
sf_db()->exec('DROP TRIGGER gallery_fixture_bad_history_reply');
gallery_check(count($privateFiles)===1&&is_file($privateFiles[0])&&file_get_contents($privateFiles[0])===$png,
    'Committed independently owned remix pixels survive a later private DTO assembly failure');
$q=sf_db()->prepare('SELECT scene_id FROM canvas_gallery_remixes WHERE user_id=1 AND request_id=?');$q->execute([$failedRemixRequest]);$committedSceneId=(int)$q->fetchColumn();$q->closeCursor();
sf_db()->prepare('UPDATE agent_scene_history SET gallery_attribution=? WHERE user_id=1 AND id=?')->execute([
    sf_canvas_build_workflow_json(sf_canvas_gallery_attribution(sf_canvas_gallery_row($recovered['id']))),$committedSceneId]);
gallery_check(sf_canvas_gallery_remix($user,$recovered['id'],$failedRemixRequest)['scene']['id']===$committedSceneId,
    'Remix recovery reuses the committed owned copy after a transient reply failure');
gallery_check($calls===$providerCalls&&sf_token_balance(1)===$balanceBeforeGallery,'Submit review Open Remix and paging never call a model or deduct Active Tokens');
echo 'Canvas Gallery: '.$galleryChecks.' checks passed'.PHP_EOL;
