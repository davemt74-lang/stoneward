<?php
declare(strict_types=1);

require_once __DIR__.'/image-generation-core.php';
require_once __DIR__.'/agent-canvas-images.php';
require_once __DIR__.'/agent-canvas-image-inputs.php';
require_once __DIR__.'/ai-token-reservations.php';
require_once __DIR__.'/agent-scene-stream.php';

const SF_AGENT_CANVAS_IMAGE_TOKEN_RESERVE=8000;
const SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE='image-generation';

final class SfAgentCanvasImageUnavailable extends RuntimeException {
    public function __construct(public readonly string $reason) {
        parent::__construct(match($reason){
            'image_generation_disabled'=>'Image generation for canvases is disabled in AI Image Studio.',
            'image_provider_unavailable'=>'Image generation needs an enabled OpenAI provider and image model.',
            'image_account_disabled'=>'Image generation is disabled for your account. You can still reuse saved images.',
            'image_daily_limit'=>'You’ve reached your daily image generation limit. It resets at midnight UTC. Saved images can still be reused.',
            'image_already_started'=>'Image generation already started for this canvas build.',
            'image_storage_failed'=>'The generated image could not be saved to your canvas image folder.',
            default=>'The image provider could not complete the image.',
        });
    }
}

function sf_agent_canvas_image_config(array $user): array {
    $settings=sf_image_gen_settings();
    if((int)($user['id']??0)<1||!$settings['enabled']||(($user['role']??'')!=='admin'&&!$settings['canvas_listeners_enabled']))
        throw new SfAgentCanvasImageUnavailable('image_generation_disabled');
    $openai=sf_ai_provider_row('openai');
    if(empty($openai['enabled'])||empty($openai['secret_payload'])||!in_array($settings['model'],sf_image_gen_models(),true))
        throw new SfAgentCanvasImageUnavailable('image_provider_unavailable');
    return $settings;
}

function sf_agent_canvas_image_available(array $user): bool {
    try{sf_agent_canvas_image_config($user);return true;}
    catch(SfAgentCanvasImageUnavailable){return false;}
}

/** One paid call per image, counted alongside the account's Studio attempts. */
function sf_agent_canvas_image_claim(int $userId,string $requestId,bool $listenersEnabled): void {
    $key=hash_hmac('sha256','canvas-image:'.$userId.':'.$requestId,sf_ai_secret_key());
    sf_image_gen_schema();$pdo=sf_db();$pdo->beginTransaction();
    try{
        $account=sf_image_gen_account($pdo,$userId,true);
        if($account['status']!=='active'||($account['role']!=='admin'&&!$listenersEnabled))
            throw new SfAgentCanvasImageUnavailable('image_generation_disabled');
        $now=gmdate('c');$day=substr($now,0,10);
        $q=$pdo->prepare('SELECT 1 FROM image_generation_canvas_attempts WHERE attempt_key=?');
        $q->execute([$key]);$existing=$q->fetchColumn();$q->closeCursor();
        if($existing)throw new SfAgentCanvasImageUnavailable('image_already_started');
        $cap=sf_image_gen_quota_limits($pdo,$userId,$account['role'])['daily_cap'];
        if($cap===0)throw new SfAgentCanvasImageUnavailable('image_account_disabled');
        if($cap!==null&&sf_image_gen_daily_attempts($pdo,$day,$userId)>=$cap)
            throw new SfAgentCanvasImageUnavailable('image_daily_limit');
        $q=$pdo->prepare('INSERT INTO image_generation_canvas_attempts(attempt_key,started_at,user_id) VALUES(?,?,?)');
        $q->execute([$key,$now,$userId]);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    // Pruning the global retention range outside the account lock keeps claims for different accounts independent.
    try{
        $q=$pdo->prepare('DELETE FROM image_generation_canvas_attempts WHERE started_at<?');
        $q->execute([gmdate('c',time()-30*86400)]);
    }catch(PDOException $error){error_log('[Stonefellow canvas image] attempt cleanup failed '.get_class($error));}
}

function sf_agent_canvas_image_release_stale_holds(array $user): void {
    if(($user['role']??'')==='admin')return;
    sf_ai_reservation_schema();
    $q=sf_db()->prepare("SELECT request_key FROM ai_token_reservations WHERE user_id=? AND source=? AND status='held' AND created_at<?");
    $q->execute([(int)$user['id'],SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE,gmdate('c',time()-900)]);
    $keys=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
    foreach($keys as $key){
        try{sf_ai_finish_reservation($user,$key,0,[],SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE,false);}
        catch(RuntimeException $error){error_log('[Stonefellow canvas image escrow] stale release skipped '.get_class($error));}
    }
}

/** The optional edit callback saves the scene in the same transaction as the asset. */
function sf_agent_canvas_image_generate(array $user,string $requestId,array $plan,?callable $checkCanceled=null,?callable $transport=null,
    ?string $editSourceId=null,?callable $afterSave=null): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$requestId)||!is_string($plan['prompt']??null)||
        trim($plan['prompt'])===''||strlen($plan['prompt'])>3000||!is_string($plan['title']??null)||
        !in_array($plan['size']??null,['1024x1024','1536x1024','1024x1536'],true)||
        !in_array($plan['background']??null,['auto','opaque','transparent'],true))
        throw new InvalidArgumentException('The canvas image plan is invalid.');
    $settings=sf_agent_canvas_image_config($user);
    $userId=(int)$user['id'];$admin=($user['role']??'')==='admin';
    $payload=sf_image_gen_payload([
        'model'=>$settings['model'],'prompt'=>$plan['prompt'],'size'=>$plan['size'],'quality'=>'medium',
    ],$userId,$plan['background']);
    $endpoint=SF_IMAGE_GEN_GENERATE_ENDPOINT;
    $referenceIds=sf_agent_canvas_image_reference_ids($plan['reference_ids']??[]);
    if($editSourceId!==null){
        if($referenceIds)throw new InvalidArgumentException('Choose image references when creating a new image.');
        $referenceIds=[$editSourceId];
    }
    if($referenceIds){
        $payload['images']=sf_agent_canvas_image_reference_inputs($userId,$referenceIds);
        $endpoint='https://api.openai.com/v1/images/edits';
        if($payload['model']==='gpt-image-1.5')$payload['input_fidelity']='high';
    }
    $poll=$checkCanceled===null?null:static function(bool $force=false)use($checkCanceled):void {
        static $nextCheck=0;
        $now=hrtime(true);
        if(!$force&&$now<$nextCheck)return;
        $nextCheck=$now+1_000_000_000;
        if($checkCanceled())throw new SfAgentSceneBuildCanceled();
    };
    if($poll!==null)$poll(true);
    sf_agent_canvas_image_ensure_schema();
    if(sf_agent_canvas_image_get($userId,$requestId)!==null)throw new SfAgentCanvasImageUnavailable('image_already_started');
    sf_agent_canvas_image_release_stale_holds($user);
    $reservation=sf_ai_reserve_tokens($user,SF_AGENT_CANVAS_IMAGE_TOKEN_RESERVE,SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE);
    $createdFile=null;
    try{
        sf_agent_canvas_image_claim($userId,$requestId,$settings['canvas_listeners_enabled']);
        if($poll!==null)$poll(true);
        if($referenceIds)sf_agent_canvas_image_reference_metadata($userId,$referenceIds);
        try{
            $result=sf_image_gen_provider_request($endpoint,$payload,$transport,$poll);
            $decoded=sf_image_gen_image_bytes($result);
        }catch(SfAgentSceneBuildCanceled $error){throw $error;}
        catch(Throwable $error){
            error_log('[Stonefellow canvas image] provider failure '.get_class($error).' request='.$requestId);
            throw new SfAgentCanvasImageUnavailable('image_generation_failed');
        }
        if($poll!==null)$poll(true);
        $usage=['provider'=>'openai','model'=>$settings['model'],
            'input_tokens'=>max(0,min(SF_AGENT_CANVAS_IMAGE_TOKEN_RESERVE,(int)($result['usage']['input_tokens']??0))),
            'output_tokens'=>max(0,min(SF_AGENT_CANVAS_IMAGE_TOKEN_RESERVE,(int)($result['usage']['output_tokens']??0)))];
        $amount=min(SF_AGENT_CANVAS_IMAGE_TOKEN_RESERVE,max(1,$usage['input_tokens']+$usage['output_tokens']));
        try{
            $stored=[];
            $balance=sf_ai_finish_reservation($user,$reservation,$amount,$usage,SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE,true,
                static function(int $remaining)use($userId,$requestId,$plan,$decoded,$afterSave,$admin,$amount,&$stored,&$createdFile):void {
                    $stored=sf_agent_canvas_image_store($userId,$requestId,$plan['title'],$decoded,$createdFile);
                    if($afterSave!==null)$afterSave($admin?0:$amount,$admin?null:$remaining);
                });
        }catch(Throwable $error){
            error_log('[Stonefellow canvas image] storage failure '.get_class($error).' request='.$requestId);
            throw new SfAgentCanvasImageUnavailable('image_storage_failed');
        }
    }catch(Throwable $error){
        if($createdFile!==null)@unlink($createdFile);
        if($reservation!==null){
            try{sf_ai_finish_reservation($user,$reservation,0,[],SF_AGENT_CANVAS_IMAGE_TOKEN_SOURCE,false);}
            catch(Throwable $releaseError){error_log('[Stonefellow canvas image escrow] release failed '.get_class($releaseError));}
        }
        throw $error;
    }
    return ['image'=>$stored,'active_tokens_used'=>$admin?0:$amount,'active_tokens_remaining'=>$admin?null:$balance];
}

function sf_agent_canvas_image_progress(?callable $checkCanceled,?callable $onImage,int $index,int $total): Closure {
    $nextHeartbeat=hrtime(true)+10_000_000_000;
    return static function()use($checkCanceled,$onImage,$index,$total,&$nextHeartbeat):bool {
        if($checkCanceled!==null&&$checkCanceled())return true;
        if($onImage!==null&&hrtime(true)>=$nextHeartbeat){
            $onImage('working',null,$index,$total);
            $nextHeartbeat=hrtime(true)+10_000_000_000;
        }
        return false;
    };
}

/** Finish each durable image before starting the next paid attempt. */
function sf_agent_canvas_image_generate_batch(array $user,string $requestId,array $plans,?callable $checkCanceled=null,?callable $onImage=null,?callable $transport=null): array {
    $total=count($plans);
    if($total<1||$total>SF_AGENT_CANVAS_IMAGE_LIMIT)throw new InvalidArgumentException('Ask for one to four new images per build.');
    $ids=sf_agent_canvas_image_batch_ids($requestId);
    $images=[];$tokens=0;
    foreach(array_values($plans) as $offset=>$plan){
        $index=$offset+1;
        $poll=sf_agent_canvas_image_progress($checkCanceled,$onImage,$index,$total);
        if($poll())throw new SfAgentSceneBuildCanceled();
        if($onImage!==null)$onImage('generating',null,$index,$total);
        if($offset===0){
            sf_agent_canvas_image_config($user);
            $quota=sf_image_gen_user_quota((int)$user['id']);
            if($quota['daily_cap']===0)throw new SfAgentCanvasImageUnavailable('image_account_disabled');
            if($quota['today_remaining']!==null&&$total>$quota['today_remaining'])
                throw new SfAgentCanvasImageUnavailable('image_daily_limit');
        }
        $generated=sf_agent_canvas_image_generate($user,$ids[$offset],$plan,$poll,$transport);
        $tokens+=$generated['active_tokens_used'];
        $asset=array_intersect_key($generated['image'],['id'=>true,'title'=>true]);
        $images[]=$asset+['index'=>$index];
        if($onImage!==null)$onImage('saved',$asset,$index,$total);
    }
    return ['generated_images'=>$images,'image_total'=>$total,'active_tokens_used'=>$tokens];
}
