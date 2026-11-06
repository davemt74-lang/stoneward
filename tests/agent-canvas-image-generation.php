<?php
declare(strict_types=1);

$root=sys_get_temp_dir().'/stonefellow-canvas-image-generation-'.bin2hex(random_bytes(6));
mkdir($root.'/config',0700,true);
define('SF_ROOT',$root);
function sf_db_config(): array {return ['driver'=>'sqlite'];}
function sf_db(): PDO {
    static $db=null;
    return $db??=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function sf_clean_text(mixed $value,int $max=120): string {return substr(trim((string)$value),0,$max);}
function verify(bool $value,string $message): void {
    if(!$value)throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
function rejects(callable $action,string $reason): void {
    try{$action();}
    catch(SfAgentCanvasImageUnavailable $error){verify($error->reason===$reason,'Image request returned '.$reason);return;}
    throw new RuntimeException('Expected '.$reason);
}

require_once __DIR__.'/../api/ai.php';
require_once __DIR__.'/../api/entitlements.php';
require_once __DIR__.'/../api/agent-canvas-image-generation.php';
require_once __DIR__.'/../api/privacy-core.php';
require_once __DIR__.'/../api/agent-canvas-image-edit.php';

try{
    $db=sf_db();
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,status TEXT NOT NULL,role TEXT NOT NULL)");
    $db->exec("INSERT INTO users VALUES (1,'active','admin'),(2,'active','user'),(3,'disabled','user'),(4,'active','admin')");
    $db->exec('CREATE TABLE app_meta (meta_key TEXT PRIMARY KEY,meta_value TEXT,updated_at TEXT)');
    $db->exec('CREATE TABLE image_generation_canvas_attempts (attempt_key TEXT PRIMARY KEY,started_at TEXT NOT NULL)');
    $db->exec("CREATE TABLE image_generation_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,request_key TEXT NOT NULL UNIQUE,created_by INTEGER NOT NULL,
        prompt TEXT NOT NULL,model TEXT NOT NULL,size TEXT NOT NULL,quality TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',prompt_approved_by INTEGER NULL,asset_approved_by INTEGER NULL,
        media_asset_id INTEGER NULL,usage_json TEXT NOT NULL DEFAULT '{}',provider_request_id TEXT NOT NULL DEFAULT '',
        error_code TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL,started_at TEXT NULL,completed_at TEXT NULL)");
    $legacyId=str_repeat('0',32);
    $legacyKey=hash_hmac('sha256','canvas-image:2:'.$legacyId,sf_ai_secret_key());
    $db->prepare('INSERT INTO image_generation_canvas_attempts VALUES(?,?)')->execute([$legacyKey,gmdate('c')]);
    $db->prepare("INSERT INTO image_generation_jobs(request_key,created_by,prompt,model,size,quality,status,usage_json,created_at,updated_at,started_at)
        VALUES ('admin-fixture',1,'fixture','gpt-image-2.5-flare','1024x1024','medium','ready','{}',?,?,?)")
        ->execute(array_fill(0,3,gmdate('c')));
    $admin=['id'=>1,'role'=>'admin'];$fan=['id'=>2,'role'=>'user'];
    verify(!sf_agent_canvas_image_available($admin),'Administrator image generation is disabled by default');
    sf_image_gen_save_settings(['enabled'=>true,'model'=>'gpt-image-2.5-flare','daily_cap'=>4]);
    verify(!sf_agent_canvas_image_available($admin),'Enabling Studio still requires the OpenAI key');
    sf_ai_ensure_schema();
    $db->prepare("INSERT INTO ai_provider_settings (provider,enabled,secret_payload,updated_at) VALUES ('openai',1,?,?)")
        ->execute([sf_ai_encrypt('fixture-key'),gmdate('c')]);
    verify(sf_agent_canvas_image_available($admin)&&!sf_agent_canvas_image_available($fan),
        'A configured administrator can generate while listener generation defaults off');
    sf_image_gen_save_settings(['enabled'=>true,'canvas_listeners_enabled'=>true,'model'=>'gpt-image-2.5-flare','daily_cap'=>4]);
    verify(sf_agent_canvas_image_available($fan),'Listener image generation requires explicit administrator opt-in');

    $chunk=static fn(string $type,string $data):string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $pixels='';
    for($row=0;$row<128;$row++)$pixels.="\x00".str_repeat(chr($row)."\x00\xff",128);
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',128,128,8,2,0,0,0)).$chunk('IDAT',gzcompress($pixels)).$chunk('IEND','');
    $plan=['prompt'=>'Cinematic forest at first light','title'=>'First light','size'=>'1536x1024','background'=>'opaque'];
    $id=str_repeat('a',32);$calls=0;
    $provider=static function(array $payload)use($png,&$calls,$plan):array {
        $calls++;
        verify($payload['model']==='gpt-image-2.5-flare'&&$payload['quality']==='medium'&&$payload['n']===1&&
            $payload['size']===$plan['size']&&$payload['background']===$plan['background']&&
            $payload['prompt']===$plan['prompt']&&$payload['output_format']==='webp'&&$payload['moderation']==='auto'&&
            preg_match('/^[a-f0-9]{64}$/D',$payload['safety_identifier'])===1,
            'Provider receives one image prompt, selected model and pseudonymous safety identifier');
        return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>32,'output_tokens'=>512]];
    };
    try{sf_agent_canvas_image_generate($admin,$id,$plan,static fn()=>true,$provider);throw new LogicException('Expected cancellation.');}
    catch(SfAgentSceneBuildCanceled){verify($calls===0,'Canceling before generation avoids an image request');}
    try{sf_agent_canvas_image_generate($fan,$id,$plan,null,$provider);throw new LogicException('Expected token preflight to fail.');}
    catch(RuntimeException $error){verify($error->getMessage()==='ACTIVE_TOKENS_REQUIRED'&&$calls===0,'No provider request starts without an Active Token hold');}
    sf_token_adjust(2,15000,'admin_grant','canvas-image-test');
    $result=sf_agent_canvas_image_generate($fan,$id,$plan,null,$provider);
    verify($result['image']['id']===$id&&$result['image']['title']===$plan['title']&&$result['active_tokens_used']===544&&
        $result['active_tokens_remaining']===14456&&sf_agent_canvas_image_get(2,$id)===$result['image']&&sf_agent_canvas_image_get(1,$id)===null,
        'A validated result settles one provider-usage debit with a private owner image');
    rejects(static fn()=>sf_agent_canvas_image_generate($fan,$id,$plan,null,$provider),'image_already_started');
    verify($calls===1&&sf_token_balance(2)===14456,'Replaying a completed image build makes no provider request or debit');

    $invalid=str_repeat('b',32);
    rejects(static fn()=>sf_agent_canvas_image_generate($fan,$invalid,$plan,null,static fn()=>['data'=>[['b64_json'=>'invalid']]]),
        'image_generation_failed');
    rejects(static fn()=>sf_agent_canvas_image_generate($fan,$invalid,$plan,null,$provider),'image_already_started');
    verify($calls===1&&sf_token_balance(2)===14456&&sf_agent_canvas_image_get(2,$invalid)===null,
        'Malformed image bytes release the hold and preserve the one-attempt guarantee');

    $storageFailure=str_repeat('c',32);
    $db->exec("CREATE TRIGGER reject_fixture_asset BEFORE INSERT ON agent_canvas_images BEGIN SELECT RAISE(ABORT,'fixture store failure'); END");
    rejects(static fn()=>sf_agent_canvas_image_generate($fan,$storageFailure,$plan,null,$provider),'image_storage_failed');
    $db->exec('DROP TRIGGER reject_fixture_asset');
    $files=glob(sf_agent_canvas_image_dir(2).'/*.bin')?:[];
    verify(count($files)===1&&sf_token_balance(2)===14456&&sf_agent_canvas_image_get(2,$storageFailure)===null,
        'Storage failure rolls back usage, releases the hold and removes uncommitted image bytes');

    rejects(static fn()=>sf_agent_canvas_image_claim(2,$legacyId,true),'image_already_started');
    verify(sf_image_gen_user_quota(2)===['daily_cap'=>4,'default_daily_cap'=>4,'daily_cap_override'=>null,'today_attempts'=>3,'today_remaining'=>1]&&
        sf_image_gen_user_quota(1)===['daily_cap'=>null,'default_daily_cap'=>null,'daily_cap_override'=>null,'today_attempts'=>0,'today_remaining'=>null]&&
        sf_image_gen_job(1)['started_by']===null,
        'Schema upgrade retains unassigned legacy attempts and their replay protection without charging either account');
    sf_image_gen_save_user_cap(2,3);
    rejects(static fn()=>sf_agent_canvas_image_generate($fan,str_repeat('d',32),$plan,null,$provider),'image_daily_limit');
    verify($calls===2&&sf_token_balance(2)===14456&&sf_image_gen_user_quota(2)['today_attempts']===3,
        'A full account quota prevents the provider request and releases the Active Token hold');
    sf_image_gen_save_user_cap(2,null);

    sf_agent_canvas_image_claim(1,$id,true);
    $studio=sf_image_gen_draft(4,['request_key'=>'b6fceea3-2c6a-491d-aa2e-fabc63271015','prompt'=>'Image draft for the Stonefellow studio']);
    sf_image_gen_approve_prompt((int)$studio['id'],4);
    $studio=sf_image_gen_reserve_job((int)$studio['id'],1);
    verify((int)$studio['started_by']===1&&sf_image_gen_user_quota(1)['today_attempts']===2&&
        sf_image_gen_user_quota(4)['today_attempts']===0&&sf_image_gen_user_quota(2)['today_attempts']===3,
        'An unlimited admin records Studio and Canvas attempts against the initiating account, independent of other accounts');

    sf_image_gen_save_settings(['enabled'=>true,'canvas_listeners_enabled'=>true,'model'=>'gpt-image-2.5-flare','daily_cap'=>4,'admin_daily_cap'=>2]);
    rejects(static fn()=>sf_agent_canvas_image_claim(1,str_repeat('d',32),true),'image_daily_limit');
    sf_image_gen_save_user_cap(1,3);
    sf_agent_canvas_image_claim(1,str_repeat('d',32),true);
    verify(sf_image_gen_user_quota(1)['today_remaining']===0&&sf_image_gen_user_quota(4)['daily_cap']===2&&
        sf_image_gen_user_quota(2)['daily_cap']===4,'An admin override beats the separate administrator default');
    sf_image_gen_save_user_cap(1,null);
    verify(sf_image_gen_user_quota(1)['daily_cap']===2,'Removing an admin override restores the current administrator default');

    sf_image_gen_save_user_cap(3,0);
    verify(sf_image_gen_user_quota(3)['daily_cap_override']===0,'An inactive account can be assigned a limit before activation');
    $db->exec("UPDATE users SET status='active' WHERE id=3");
    rejects(static fn()=>sf_agent_canvas_image_claim(3,$id,true),'image_account_disabled');
    sf_image_gen_save_user_cap(3,1);
    sf_agent_canvas_image_claim(3,$id,true);
    rejects(static fn()=>sf_agent_canvas_image_claim(3,str_repeat('d',32),true),'image_daily_limit');
    verify(sf_image_gen_user_quota(3)['today_attempts']===1,'A second listener receives an independent allowance');

    $retained=$db->prepare('INSERT INTO image_generation_canvas_attempts(attempt_key,started_at,user_id) VALUES(?,?,?)');
    $retained->execute(['yesterday',gmdate('Y-m-d',time()-86400).'T23:59:59+00:00',2]);
    $retained->execute(['tomorrow',gmdate('Y-m-d',time()+86400).'T00:00:00+00:00',2]);
    $retained->execute(['expired',gmdate('c',time()-31*86400),2]);
    verify(sf_image_gen_user_quota(2)['today_attempts']===3,'Quota counts the UTC day from midnight to midnight');
    sf_image_gen_save_user_cap(1,3);
    $db->beginTransaction();$erased=sf_image_gen_clear_user_quota($db,1);$db->commit();
    verify($erased===['image_quota_overrides'=>1,'canvas_image_attempts'=>2,'studio_image_attempts'=>1]&&
        sf_image_gen_job((int)$studio['id'])['started_by']===null&&sf_image_gen_user_quota(2)['today_attempts']===3,
        'Quota erasure removes account attribution while preserving Studio job history and other users’ attempts');

    sf_image_gen_save_settings(['enabled'=>true,'canvas_listeners_enabled'=>true,'model'=>'gpt-image-2.5-flare','daily_cap'=>16,'admin_daily_cap'=>null]);
    $batchId=str_repeat('e',32);$events=[];
    $record=static function(string $state,?array $image,int $index,int $total)use(&$events):void{$events[]=[$state,$index,$total,$image['id']??null];};
    $batch=sf_agent_canvas_image_generate_batch($fan,$batchId,array_fill(0,4,$plan),null,$record,$provider);
    $ids=sf_agent_canvas_image_batch_ids($batchId);
    verify(count(array_unique($ids))===4&&$ids[0]===$batchId&&array_column($batch['generated_images'],'id')===$ids&&
        $batch['generated_images']===sf_agent_canvas_image_saved_batch(2,$batchId)&&sf_agent_canvas_image_saved_batch(1,$batchId)===[]&&
        $batch['active_tokens_used']===4*544&&$batch['image_total']===4&&sf_token_balance(2)===14456-4*544,
        'Four independently charged images are saved in order with owner-scoped receipt recovery');
    verify($events===[['generating',1,4,null],['saved',1,4,$ids[0]],['generating',2,4,null],['saved',2,4,$ids[1]],
        ['generating',3,4,null],['saved',3,4,$ids[2]],['generating',4,4,null],['saved',4,4,$ids[3]]],
        'Every batch item has progress and a saved event in request order');
    rejects(static fn()=>sf_agent_canvas_image_generate_batch($fan,$batchId,[$plan,$plan],null,null,$provider),'image_already_started');
    verify($calls===6&&sf_token_balance(2)===14456-4*544,'Batch replay cannot trigger additional provider charges');

    $failedId=str_repeat('f',32);$attempts=0;$balance=sf_token_balance(2);
    $partial=static function(array $payload)use(&$attempts,$provider):array {
        if(++$attempts===2)return ['data'=>[['b64_json'=>'invalid']]];
        return $provider($payload);
    };
    rejects(static fn()=>sf_agent_canvas_image_generate_batch($fan,$failedId,[$plan,$plan,$plan],null,null,$partial),'image_generation_failed');
    verify($attempts===2&&array_column(sf_agent_canvas_image_saved_batch(2,$failedId),'id')===[$failedId]&&sf_token_balance(2)===$balance-544,
        'A partial failure keeps completed private images, releases the failed hold and stops the remainder');

    $cancelId=str_repeat('1',32);$cancel=false;$callsBefore=$calls;
    try{
        sf_agent_canvas_image_generate_batch($fan,$cancelId,[$plan,$plan],function()use(&$cancel):bool{return $cancel;},
            function(string $state)use(&$cancel):void{if($state==='saved')$cancel=true;},$provider);
        throw new LogicException('Expected batch cancellation.');
    }catch(SfAgentSceneBuildCanceled){
        verify($calls===$callsBefore+1&&array_column(sf_agent_canvas_image_saved_batch(2,$cancelId),'id')===[$cancelId],
            'Stopping a batch retains the completed image and prevents the next paid call');
    }

    $other=$ids[1];
    $original=sf_agent_scene_history_save(2,[
        'canvas_kind'=>'custom','track_id'=>'','title'=>'Forest study','agent_design'=>'square',
        'html'=>'<section><figure class="hero sf-image-'.$id.'"></figure><figure class="sf-image-'.$other.'"></figure>'
            .'<p>Reference sf-image-'.$id.' stays as copy.</p></section>',
        'css'=>'.hero.sf-image-'.$id.'{color:#eee;background:var(--sf-image-'.$id.',none)}.sf-image-'.$other.'{opacity:.8}',
    ]);
    $editId=str_repeat('2',32);
    $edit=['previous_id'=>$original['id'],'image_id'=>$id,'request_id'=>$editId,'brief'=>'Warm the light.'];
    $editCalls=0;$editEvents=[];$balance=sf_token_balance(2);
    $editProvider=static function(array $payload)use(&$editCalls,$png,$edit):array {
        $editCalls++;
        verify($payload['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($png)]]&&$payload['n']===1&&
            $payload['size']==='1024x1024'&&str_ends_with($payload['prompt'],$edit['brief']),
            'The edit provider receives the saved image bytes and the exact user edit instruction');
        return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>32,'output_tokens'=>512]];
    };
    foreach([[$admin,$edit],[$fan,array_replace($edit,['image_id'=>$ids[2]])]] as [$owner,$invalidEdit]){
        $invalidEdit['request_id']=bin2hex(random_bytes(16));
        try{sf_agent_scene_edit_image($owner,$invalidEdit,null,$editProvider);throw new LogicException('Expected an invalid edit.');}
        catch(InvalidArgumentException){verify($editCalls===0,'An image edit requires both canvas ownership and a placed image');}
    }
    $edited=sf_agent_scene_edit_image($fan,$edit,
        static function(string $state,?array $image,int $index,int $total)use(&$editEvents):void {$editEvents[]=[$state,$index,$total,$image['id']??null];},$editProvider);
    $editedScene=$edited['scene'];
    $expectedHtml=str_replace('class="hero sf-image-'.$id.'"','class="hero sf-image-'.$editId.'"',$original['html']);
    verify($editedScene['id']!==$original['id']&&$editedScene['previous_id']===$original['id']&&
        $editedScene['html']===$expectedHtml&&$editedScene['css']===str_replace($id,$editId,$original['css'])&&
        $editedScene['title']===$original['title']&&$editedScene['agent_design']==='square'&&
        sf_agent_scene_history_get(2,$original['id'])===$original&&sf_agent_canvas_image_get(2,$id)!==null&&
        array_column(sf_agent_canvas_image_assets(2,$editedScene),'id')===[$editId,$other],
        'The edited image replaces only its bindings in a new revision and keeps the original canvas and both assets');
    $replayed=sf_agent_scene_edit_image($fan,$edit,null,$editProvider);
    verify($editCalls===1&&$edited['active_tokens_used']===544&&sf_token_balance(2)===$balance-544&&
        $editEvents===[['generating',1,1,null],['saved',1,1,$editId]]&&$replayed['state']==='ready'&&
        $replayed['scene']===$editedScene&&$replayed['generated_images']===$edited['generated_images'],
        'A completed image edit settles once and can be replayed without another provider call');

    $balance=sf_token_balance(2);$failedEditId=str_repeat('3',32);
    $db->exec("CREATE TRIGGER reject_fixture_edit BEFORE INSERT ON agent_scene_history BEGIN SELECT RAISE(ABORT,'fixture revision failure'); END");
    rejects(static fn()=>sf_agent_scene_edit_image($fan,array_replace($edit,['request_id'=>$failedEditId]),null,$editProvider),'image_storage_failed');
    $db->exec('DROP TRIGGER reject_fixture_edit');
    verify(sf_agent_scene_request_result(2,$failedEditId)===['state'=>'failed']&&sf_agent_canvas_image_get(2,$failedEditId)===null&&
        sf_token_balance(2)===$balance&&count(sf_agent_scene_history_list(2))===2,
        'A failed canvas revision rolls back the new image and token settlement');

    $canceledEditId=str_repeat('4',32);
    $canceled=sf_agent_scene_edit_image($fan,array_replace($edit,['request_id'=>$canceledEditId]),null,
        static function(array $payload)use($editProvider,$canceledEditId):array {
            sf_agent_scene_request_cancel(2,$canceledEditId);
            return $editProvider($payload);
        });
    verify($canceled===['state'=>'canceled']&&sf_agent_canvas_image_get(2,$canceledEditId)===null&&
        sf_token_balance(2)===$balance&&count(sf_agent_scene_history_list(2))===2,
        'A canceled edit leaves the previous revision and its image available without a charge');
    $quota=sf_image_gen_user_quota(2);
    sf_image_gen_save_user_cap(2,$quota['today_attempts']);
    $editCallsBefore=$editCalls;
    rejects(static fn()=>sf_agent_scene_edit_image($fan,array_replace($edit,['request_id'=>str_repeat('5',32)]),null,$editProvider),'image_daily_limit');
    verify($editCalls===$editCallsBefore&&$quota['today_attempts']===13&&sf_token_balance(2)===$balance&&
        !$db->query("SELECT 1 FROM image_generation_canvas_attempts WHERE attempt_key='expired'")->fetchColumn(),
        'Image edits share the same quota as generations and expired attempt receipts are pruned');

    require_once __DIR__.'/../api/agent-canvas-image-references.php';
    sf_image_gen_save_user_cap(2,50);
    sf_token_adjust(2,50000,'admin_grant','image-reference-fixture');
    mkdir(SF_ROOT.'/storage/media',0700,true);
    file_put_contents(SF_ROOT.'/storage/media/artist.png',$png);
    $artistAsset=sf_media_register_existing_file('storage/media/artist.png','artist.png',1);
    $artist=sf_agent_canvas_reference_set((int)$artistAsset['id'],true)['reference_image_id'];
    $sourceIds=[$id,$artist];
    $context=sf_agent_canvas_image_skill_context($fan,'Create two original compositions.',[],$sourceIds);
    verify(array_column($context['selected_references'],'id')===$sourceIds&&
        !in_array($id,array_column($context['saved_images'],'id'),true)&&
        !in_array($artist,array_column($context['saved_images'],'id'),true)&&
        !str_contains(json_encode($context),'storage/')&&!str_contains(json_encode($context),'agent-canvas-image.php'),
        'Selected visual references are text-only context and stay separate from source placement');
    $placement=sf_agent_canvas_image_skill_context($fan,'Display {image-'.$artist.'}.',[],$sourceIds);
    verify(in_array($artist,array_column($placement['saved_images'],'id'),true)&&
        sf_agent_canvas_image_bindings(['html'=>'<figure class="sf-image-'.$artist.'"></figure>','css'=>''])===[$artist],
        'An approved artist reference can also be explicitly placed through the existing image binding');
    $derived=sf_agent_canvas_image_plan($plan,$sourceIds);
    verify($derived['reference_ids']===$sourceIds&&sf_agent_canvas_image_plan($plan+['reference_ids'=>[$artist]],$sourceIds)['reference_ids']===[$artist],
        'Each generated image defaults to selected references and can use an explicitly selected subset');
    try{sf_agent_canvas_image_plan($plan+['reference_ids'=>[str_repeat('6',32)]],$sourceIds);throw new LogicException('Expected an unselected reference failure.');}
    catch(SfAgentSceneInvalidOutput){verify(true,'The model cannot use an unselected reference');}
    foreach([[3,[$id]],[2,[str_repeat('6',32)]],[2,[$artist,str_repeat('7',32)]] ] as [$owner,$invalidSources]){
        try{sf_agent_canvas_image_reference_inputs($owner,$invalidSources);throw new LogicException('Expected invalid visual reference.');}
        catch(InvalidArgumentException){verify(true,'References must be selected, available and owned or currently approved');}
    }
    verify(sf_agent_canvas_requested_image_count('Create three poster concepts')===3&&
        sf_agent_canvas_requested_image_count('Generate two cover variations')===2,
        'Explicit image counts apply to general concepts and variations');
    $variationCode=['html'=>'<figure class="sf-image-new-1"></figure>','css'=>''];
    verify(count(sf_agent_canvas_image_plans($variationCode,[$plan,$plan],[],2,$sourceIds,true))===2,
        'Variation review plans share one placement while retaining separate image prompts');
    $invalidReview=['html'=>'<figure class="sf-image-new-1"></figure><figure class="sf-image-new-2"></figure>','css'=>''];
    try{sf_agent_canvas_image_plans($invalidReview,[$plan,$plan],[],2,$sourceIds,true);throw new LogicException('Expected invalid review placements.');}
    catch(SfAgentSceneInvalidOutput){verify(true,'A variation review cannot place every candidate on the canvas');}
    $referenceCalls=0;$referenceId=str_repeat('6',32);
    $referenceProvider=static function(array $payload)use(&$referenceCalls,$png):array {
        $referenceCalls++;
        verify($payload['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($png)],
            ['image_url'=>'data:image/png;base64,'.base64_encode($png)]]&&$payload['n']===1&&
            !isset($payload['reference_ids']),
            'The provider receives authorized private and artist pixels without internal reference identifiers');
        return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>32,'output_tokens'=>512]];
    };
    $sourceBefore=sf_agent_canvas_image_get(2,$id);
    $newImage=sf_agent_canvas_image_generate($fan,$referenceId,$derived,null,$referenceProvider);
    verify($referenceCalls===1&&$newImage['image']['id']===$referenceId&&sf_agent_canvas_image_get(2,$id)===$sourceBefore,
        'Reference-based generation creates a separate private asset and preserves the source image');
    sf_image_gen_save_settings(['enabled'=>true,'canvas_listeners_enabled'=>true,'model'=>'gpt-image-1.5','daily_cap'=>16]);
    sf_agent_canvas_image_generate($fan,str_repeat('7',32),$derived,null,
        static function(array $payload)use($referenceProvider):array {
            verify(($payload['input_fidelity']??null)==='high','The existing high-fidelity reference option follows the configured model');
            return $referenceProvider($payload);
        });
    $artistScene=sf_agent_scene_history_save(2,['canvas_kind'=>'custom','track_id'=>'','title'=>'Artist study',
        'html'=>'<figure class="sf-image-'.$artist.'"></figure>','css'=>'.sf-image-'.$artist.'{background:var(--sf-image-'.$artist.',none)}']);
    $artistEdit=sf_agent_scene_edit_image($fan,['previous_id'=>$artistScene['id'],'image_id'=>$artist,'request_id'=>str_repeat('9',32),'brief'=>'Shift the light to dusk.'],null,
        static function(array $payload)use($png):array {
            verify($payload['images']===[['image_url'=>'data:image/png;base64,'.base64_encode($png)]],
                'Editing a placed artist image uses its currently authorized pixels');
            return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>32,'output_tokens'=>512]];
        });
    verify(sf_agent_canvas_image_bindings($artistEdit['scene'])===[str_repeat('9',32)]&&
        sf_agent_canvas_reference_get($artist)!==null&&sf_agent_scene_history_get(2,$artistScene['id'])===$artistScene,
        'An in-place artist image edit creates an owned revision while preserving the shared source and original canvas');
    verify(count(sf_agent_canvas_image_reference_inputs(2,[$id,$ids[1],$ids[2],$artist]))===4,
        'Four explicitly selected visual inputs can be used together');

    $fixtureInsert=$db->prepare('INSERT INTO agent_canvas_images(user_id,id,storage_key,title,mime,size_bytes,width,height,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
    foreach([['b',8],['c',8],['d',11]] as [$letter,$mebibytes]){
        $storageKey=str_repeat($letter,48);$fixtureId=str_repeat($letter,32);
        $large=$png.str_repeat("\xff",$mebibytes*1024*1024-strlen($png));
        file_put_contents(sf_agent_canvas_image_dir(2).'/'.$storageKey.'.bin',$large);
        $fixtureInsert->execute([2,$fixtureId,$storageKey,'Large fixture','image/png',strlen($large),128,128,gmdate('c')]);
    }
    $beforeLimits=sf_image_gen_user_quota(2)['today_attempts'];
    foreach([[str_repeat('b',32),str_repeat('c',32)],[str_repeat('d',32)]] as $oversize){
        try{sf_agent_canvas_image_reference_inputs(2,$oversize);throw new LogicException('Expected oversized reference inputs.');}
        catch(InvalidArgumentException){verify(true,'Reference inputs enforce both raw byte and encoded JSON payload limits');}
    }
    verify(sf_image_gen_user_quota(2)['today_attempts']===$beforeLimits,'Reference validation failures do not consume a paid attempt');
    $beforeAttempts=sf_image_gen_user_quota(2)['today_attempts'];$partialReferenceCalls=0;$partialId=str_repeat('8',32);
    try{
        sf_agent_canvas_image_generate_batch($fan,$partialId,[$derived,$derived],null,null,
            static function(array $payload)use(&$partialReferenceCalls,$referenceProvider,$artistAsset):array {
                $partialReferenceCalls++;
                $result=$referenceProvider($payload);
                sf_agent_canvas_reference_set((int)$artistAsset['id'],false);
                return $result;
            });
        throw new LogicException('Expected withdrawn reference.');
    }catch(InvalidArgumentException){
        verify($partialReferenceCalls===1&&sf_image_gen_user_quota(2)['today_attempts']===$beforeAttempts+1&&
            count(sf_agent_canvas_image_saved_batch(2,$partialId))===1,
            'Withdrawing an artist reference blocks later batch inputs before another paid attempt while retaining the saved result');
    }
    require_once dirname(__DIR__).'/api/agent-canvas-image-preflight.php';
    $musicTokens=[];
    for($index=0;$index<200;$index++)$musicTokens[]='{music-track:'.str_pad('image-verse-'.$index,180,'x').'}';
    $musicTokens=implode(' ',$musicTokens);
    $quotaBeforeMusic=sf_image_gen_user_quota(2);$callsBeforeMusic=$calls;
    $audioLoadedBeforeMusic=function_exists('sf_canvas_audio_catalog');
    $musicPreview=sf_agent_canvas_image_preflight($fan,'Build a coded music canvas '.$musicTokens);
    verify(strlen($musicTokens)>1800&&$musicPreview['requested_count']===null&&$musicPreview['review_images']===false,
        'Long music resource selections and image-like song IDs do not become image requests or consume the prose budget');
    $imagesWithMusic=sf_agent_canvas_image_preflight($fan,'Create 3 images and choose one before applying. '.$musicTokens);
    verify($imagesWithMusic['requested_count']===3&&$imagesWithMusic['review_images']===true,
        'Image count and variation review still follow the actual description alongside a large music selection');
    verify(sf_agent_canvas_image_preflight($fan,'Create 2 images and choose one (/music-all).')['requested_count']===2&&
        sf_agent_canvas_image_preflight($fan,'Build a coded canvas {music-all}')['requested_count']===null,
        'Raw and canonical all-music operators are stripped before image preflight interpretation');
    verify(sf_image_gen_user_quota(2)===$quotaBeforeMusic&&$calls===$callsBeforeMusic&&
        function_exists('sf_canvas_audio_catalog')===$audioLoadedBeforeMusic,
        'Music-aware image preflight performs no source inventory scan, provider request or quota mutation');
    $preview=sf_agent_canvas_image_preflight($fan,'Create 3 images and choose one before applying.');
    $quota=sf_image_gen_user_quota(2);
    verify($preview['requested_count']===3&&$preview['review_images']===true&&
        $preview['today_remaining']===$quota['today_remaining']&&$preview['daily_cap']===$quota['daily_cap'],
        'Image confirmation reports the requested count and the current account allowance without reserving a generation');
    verify(sf_agent_canvas_image_preflight($fan,'A coded animated canvas')['requested_count']===null&&
        sf_agent_canvas_image_preflight($fan,'Recolor this image',true)['requested_count']===1,
        'Code-only builds skip image confirmation and a selected image edit reports one request');
    verify(sf_agent_canvas_image_preflight($fan,'Make three poster concepts, then place the one I choose.')['review_images']===true&&
        sf_agent_canvas_image_preflight($fan,'Generate 3 images and compare variations.')['review_images']===true,
        'Image confirmation recognizes a request to choose one later or compare variations');
    sf_image_gen_save_user_cap(1,null);
    sf_ai_meta_set('ai.image_admin_daily_cap','');
    $unlimitedPreview=sf_agent_canvas_image_preflight($admin,'Create four poster concepts.');
    verify($unlimitedPreview['requested_count']===4&&$unlimitedPreview['daily_cap']===null&&$unlimitedPreview['today_remaining']===null,
        'Administrator image confirmation preserves an unlimited allowance');
    verify(sf_agent_canvas_image_plans(['html'=>'<div></div>','css'=>''],null,[],0)===[],
        'Choosing saved images only allows a canvas with no paid image plan');
}finally{
    $tree=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($tree as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}
    rmdir($root);
}
