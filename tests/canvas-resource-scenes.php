<?php
declare(strict_types=1);
require __DIR__.'/canvas-projects.php';
$resourceChecks=0;
function resource_check(bool $ok,string $message): void {global $resourceChecks;if(!$ok)throw new RuntimeException($message);$resourceChecks++;echo 'RESOURCE PASS '.$message.PHP_EOL;}
function resource_reject(callable $run,string $class,string $message): void {try{$run();}catch(Throwable $error){if($error instanceof $class){resource_check(true,$message);return;}throw $error;}throw new RuntimeException('Expected rejection: '.$message);}
file_put_contents($fixture.'/data/shows.json',json_encode([['id'=>'public-show','title'=>'Public show','date'=>'2040-01-01','venue'=>'Public Hall']]));
sf_commerce_save_product(['id'=>'shirt','title'=>'Published shirt','status'=>'active','base_price_cents'=>1000,
    'variants'=>[['title'=>'Blue','price_cents'=>1000],['title'=>'Gold','price_cents'=>1200]]],0);
sf_commerce_save_product(['id'=>'mug','title'=>'Published mug','status'=>'active','base_price_cents'=>500],0);
sf_commerce_save_product(['id'=>'secret-product','title'=>'Hidden supplier product','status'=>'draft'],0);
$ticket=sf_ticket_offer_save(['show_id'=>'public-show','title'=>'Published admission','offer_type'=>'ticket','status'=>'published','price_cents'=>2400,'fulfillment_type'=>'external','external_url'=>'https://example.invalid/checkout'],0);$ticketId=(string)$ticket['id'];
$source=sf_agent_scene_history_save(1,['canvas_kind'=>'custom','track_id'=>'','title'=>'Owned source','html'=>'<section></section>','css'=>'','design_brief'=>'An actual earlier composition']);
$foreign=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Foreign private source','html'=>'<section></section>','css'=>'']);
$GLOBALS['fixtureCanvasHtml']='<section class="sf-dataset-published_merch"><div class="sf-data-row"><span class="sf-field-title"></span></div></section><section class="sf-dataset-published_ticket_offers"><div class="sf-data-row"><span class="sf-field-title"></span></div></section>';
$raw=['canvas_kind'=>'custom','brief'=>'Arrange an artist storefront {canvas:'.$source['id'].'} {merch:shirt} {ticket:'.$ticketId.'}'];
$proposal=sf_canvas_project_propose($user,['project'=>$raw]);$project=$proposal['project'];
resource_check(!str_contains($project['brief'],'{')&&$project['source_scene_ids']===[$source['id']]&&$project['artist_inputs']===['merch_ids'=>['shirt'],'ticket_ids'=>[$ticketId]],'Resource tokens become independent approval inputs and leave natural instructions intact');
$build=$raw+['project_managed'=>true,'proposal_id'=>$proposal['id'],'request_id'=>str_repeat('1',32)];$beforeCalls=$calls;
resource_reject(fn()=>sf_agent_scene_create($user,$build),SfCanvasProjectApprovalRequired::class,'Selected commerce still waits for the exact project approval');
resource_check($calls===$beforeCalls,'Unapproved resources do not trigger a coding model call');
sf_canvas_project_approve(1,$proposal['id']);
resource_reject(fn()=>sf_agent_scene_create($user,array_replace($build,['brief'=>'Arrange an artist storefront {canvas:'.$source['id'].'} {merch:mug} {ticket:'.$ticketId.'}'])),SfCanvasProjectApprovalRequired::class,'Changing one resource invalidates an existing approval snapshot');
$created=sf_agent_scene_create($user,$build);$scene=$created['scene'];
resource_check($scene['artist_inputs']===$project['artist_inputs']&&sf_agent_scene_history_get(1,$scene['id'])['artist_inputs']===$project['artist_inputs'],'New code canvases persist selected merch and ticket identities through normal approved generation');
resource_check(!str_contains($prompt['design_brief'],'{')&&$prompt['source_canvases']['sources'][0]['id']===$source['id']&&count($prompt['selected_artist_resources']['published_merch'])===2&&count($prompt['selected_artist_resources']['published_ticket_offers'])===1,'The coding model receives selected public inventory and owner source code without pasted picker syntax');
resource_check($scene['catalog_refs']===[]&&$scene['catalog_blocks']===[]&&str_contains($GLOBALS['lastCanvasSystem'],'never a prescribed layout or host widget'),'Selected resources do not inject a host commerce widget or force a visual presentation');
$data=sf_agent_canvas_data_for_scene($user,$scene['html'],'',null,$scene['artist_inputs']);
resource_check(count($data['published_merch']['rows'])===2&&array_unique(array_column($data['published_merch']['rows'],'product_id'))===['shirt']&&count($data['published_ticket_offers']['rows'])===1,'Reopened dataset resolution uses the exact saved selection and every selected variant');
$edit=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>'','previous_id'=>$scene['id'],'brief'=>'Change the atmosphere','request_id'=>str_repeat('2',32)]);
resource_check($edit['scene']['artist_inputs']===$scene['artist_inputs'],'An ordinary edit inherits both independent resource selections');
$partial=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>'','previous_id'=>$scene['id'],'brief'=>'Use a new material','artist_inputs'=>['merch_ids'=>['mug']],'request_id'=>str_repeat('3',32)]);
resource_check($partial['scene']['artist_inputs']===['merch_ids'=>['mug'],'ticket_ids'=>[$ticketId]],'A partial merchandise update keeps the separately selected ticket input');
$cleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>'','previous_id'=>$scene['id'],'brief'=>'Simplify the surface {merch-clear}','request_id'=>str_repeat('4',32)]);
resource_check($cleared['scene']['artist_inputs']===['merch_ids'=>[],'ticket_ids'=>[$ticketId]]&&$prompt['selected_artist_resources']['published_merch']===[],'Explicitly clearing merchandise retains tickets and never falls back to the full merch inventory');
$clearData=sf_agent_canvas_data_for_scene($user,$cleared['scene']['html'],'',null,$cleared['scene']['artist_inputs']);
resource_check($clearData['published_merch']['rows']===[]&&count($clearData['published_ticket_offers']['rows'])===1,'An empty resource kind remains empty in delivered live data');
$allCleared=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>'','previous_id'=>$scene['id'],'brief'=>'Only a quiet setting','artist_inputs'=>null,'request_id'=>str_repeat('5',32)]);
resource_check(!isset($allCleared['scene']['artist_inputs']),'Explicit null resets both inherited resource scopes without changing older canvas shapes');
$long=str_repeat('x',1790).' '.str_repeat('{merch:shirt} ',80);
$large=sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>$long,'request_id'=>str_repeat('6',32)]);
resource_check(strlen($long)>1800&&strlen($large['scene']['design_brief'])===1790&&$large['scene']['artist_inputs']===['merch_ids'=>['shirt']],'Resource tokens do not consume the 1800-byte prose budget');
$beforeCalls=$calls;
resource_reject(fn()=>sf_agent_scene_create($user,['canvas_kind'=>'custom','brief'=>'Arrange merchandise {merch:secret-product}','request_id'=>str_repeat('7',32)]),InvalidArgumentException::class,'New private merchandise fails before any provider call');
resource_reject(fn()=>sf_canvas_project_propose($user,['project'=>['brief'=>'Integrate {canvas:'.$foreign['id'].'}']]),InvalidArgumentException::class,'Canvas tokens cannot reference another listener private history');
resource_check($calls===$beforeCalls,'Invalid new resource identities make no paid inference');
$db->exec("UPDATE store_products SET status='hidden' WHERE id='shirt'");$beforeCalls=$calls;
$replayed=sf_agent_scene_create($user,$build);
resource_check($calls===$beforeCalls&&$replayed['scene']['id']===$scene['id'],'A settled approved request still replays safely after public resources are withdrawn');
$withdrawn=sf_agent_canvas_data_for_scene($user,$scene['html'],'',null,$scene['artist_inputs']);
resource_check($withdrawn['published_merch']['rows']===[]&&count($withdrawn['published_ticket_offers']['rows'])===1,'Fresh live data removes withdrawn merchandise while retaining public ticket nodes');
$inherited=sf_agent_scene_create($user,['canvas_kind'=>'custom','track_id'=>'','previous_id'=>$scene['id'],'brief'=>'Adjust the scene lighting','request_id'=>str_repeat('8',32)]);
resource_check($inherited['scene']['artist_inputs']===$scene['artist_inputs']&&$prompt['selected_artist_resources']['published_merch']===[],'Editing an inherited scope preserves its IDs while the model sees no newly private inventory');
require_once $root.'/api/canvas-feedback-core.php';
sf_canvas_feedback_set(1,['scene_id'=>$scene['id'],'preference'=>'like','reason'=>'A deliberate style preference']);
sf_canvas_feedback_set(2,['scene_id'=>$foreign['id'],'preference'=>'dislike','reason'=>'Another listener preference']);
// Older approvals retain their original structured scope; new token semantics
// cannot rewrite an already approved project's identity while it is read.
$legacyProject=sf_canvas_project_normalize(['brief'=>'An older literal {canvas:'.$source['id'].'} {merch:mug}','source_scene_ids'=>[]],false);
$db->prepare('INSERT INTO canvas_projects(user_id,title,concept,project_json,fingerprint,source,status,approval_mode,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([1,'Older proposal','Original scope',json_encode($legacyProject),sf_canvas_project_fingerprint($legacyProject),'user','approved','manual',gmdate('c'),gmdate('c')]);
$legacyId=(int)$db->lastInsertId();$restoredLegacy=sf_canvas_project_get(1,$legacyId);
resource_check($restoredLegacy['project']['brief']===$legacyProject['brief']&&$restoredLegacy['project']['source_scene_ids']===[]&&!isset($restoredLegacy['project']['artist_inputs']),'Reading a pre-feature proposal never reinterprets its literal text or changes its approved resource scope');
$newScope=sf_canvas_project_normalize(['brief'=>$legacyProject['brief']]);
resource_check(sf_canvas_project_reuse(1,$legacyId,$newScope)===null,'An older literal becoming a new resource selection requires a fresh approval');
$export=sf_privacy_export(1,['id'=>1,'email'=>'project1@example.invalid','display_name'=>'Listener','role'=>'user','status'=>'active']);
resource_check(count($export['canvas_feedback'])===1&&$export['canvas_feedback'][0]['scene_id']===$scene['id']&&!str_contains(json_encode($export['canvas_feedback']),'Another listener'),'Privacy export includes only explicit feedback owned by the current account');
$reset=sf_privacy_clear_agent_history(1);
resource_check($reset['canvas_feedback']===1&&sf_canvas_feedback_export(1)===[]&&count(sf_canvas_feedback_export(2))===1,'Clearing history atomically clears its feedback and leaves another listener preferences intact');
echo 'Canvas resource scene integration: '.$resourceChecks.' checks passed'.PHP_EOL;
