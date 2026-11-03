<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once SF_ROOT.'/api/image-generation-core.php';
require_once SF_ROOT.'/api/image-studio-presets.php';

$write=($_SERVER['REQUEST_METHOD']??'GET')!=='GET';
$me=sf_admin_require_auth($write);
header('Cache-Control: no-store');
try{
    if(!$write){
        $jobId=max(0,(int)($_GET['job_id']??0));
        if($jobId){
            $job=sf_image_gen_job($jobId);
            if(!$job)sf_json_response(['ok'=>false,'message'=>'Image-generation job not found.'],404);
            require_once SF_ROOT.'/api/media-core.php';
            $asset=(int)($job['media_asset_id']??0)>0?sf_media_asset((int)$job['media_asset_id']):null;
            sf_json_response(['ok'=>true,'job'=>['id'=>(int)$job['id'],'status'=>(string)$job['status'],
                'asset_uuid'=>(string)($asset['uuid']??'')]]);
        }
        sf_json_response(['ok'=>true,'settings'=>sf_image_gen_settings(),
            'openai'=>sf_ai_provider_public('openai'),'models'=>sf_image_gen_models(),
            'jobs'=>sf_image_gen_jobs(),'presets'=>sf_image_studio_presets(),
            'dashboard'=>sf_image_studio_dashboard(),'references'=>sf_image_gen_available_references()]);
    }
    $body=sf_request_json();$action=(string)($body['action']??'');
    if($action==='save_settings'){
        $settings=sf_image_gen_save_settings($body);
        sf_log_admin_action((int)$me['id'],'image_generation_settings','ai_image','settings',$settings);
        sf_json_response(['ok'=>true,'settings'=>$settings]);
    }
    if($action==='save_preset'){
        $preset=sf_image_studio_save_preset((int)$me['id'],$body);
        sf_log_admin_action((int)$me['id'],'image_preset_created','image_studio_preset',(string)$preset['id'],
            ['slug'=>$preset['slug'],'category'=>$preset['category']]);
        sf_json_response(['ok'=>true,'preset'=>$preset]);
    }
    if($action==='archive_preset'){
        if(empty($body['confirmed']))sf_json_response(['ok'=>false,'message'=>'Confirm archival of this custom visual preset.'],422);
        $presetId=(int)($body['preset_id']??0);
        if($presetId<1)throw new InvalidArgumentException('Choose a custom preset.');
        sf_image_studio_disable_preset($presetId);
        sf_log_admin_action((int)$me['id'],'image_preset_archived','image_studio_preset',(string)$presetId);
        sf_json_response(['ok'=>true]);
    }
    if($action==='local_prompt'){
        sf_json_response(['ok'=>true,'suggestion'=>sf_image_studio_local_prompt((string)($body['concept']??''),(string)($body['preset_slug']??''))]);
    }
    if($action==='compose_prompt'){
        if(empty($body['confirmed']))sf_json_response(['ok'=>false,'message'=>'Confirm sending this creative brief to your configured text model.'],422);
        require_once SF_ROOT.'/api/agent-runtime.php';
        try{
            $suggestion=sf_image_studio_agent_prompt($me,(string)($body['concept']??''),(string)($body['preset_slug']??''));
        }catch(InvalidArgumentException $e){throw $e;}
        catch(Throwable $e){
            error_log('[Stonefellow image prompt composer] '.get_class($e));
            sf_json_response(['ok'=>false,'message'=>'Agent prompt refinement did not complete. It will not retry automatically; you can use the local preset instead.'],503);
        }
        sf_log_admin_action((int)$me['id'],'image_prompt_agent_suggested','image_studio_preset',(string)$suggestion['preset']['id'],
            ['source'=>'text_model','provider'=>$suggestion['usage']['provider'],'model'=>$suggestion['usage']['model']]);
        sf_json_response(['ok'=>true,'suggestion'=>$suggestion]);
    }
    if($action==='save_draft'){
        $job=sf_image_gen_draft((int)$me['id'],$body);
        $reference=sf_image_gen_reference((int)$job['id']);
        $options=sf_image_gen_options((int)$job['id']);
        sf_log_admin_action((int)$me['id'],'image_prompt_saved','image_generation_job',(string)$job['id'],
            ['reference_asset_id'=>$reference?(int)$reference['source_asset_id']:null,
             'reference_consent_recorded'=>(bool)$reference,
             'background'=>(string)$options['background'],
             'mask_asset_id'=>$options['mask_asset_id']===null?null:(int)$options['mask_asset_id'],
             'mask_consent_recorded'=>(int)($options['mask_asset_id']??0)>0]);
        sf_json_response(['ok'=>true,'job'=>$job]);
    }
    $id=(int)($body['id']??0);
    if($id<1)sf_json_response(['ok'=>false,'message'=>'Choose a saved image prompt.'],422);
    if($action==='reconcile_cost'){
        if(empty($body['confirmed']))throw new InvalidArgumentException('Confirm you checked the provider invoice before entering a cost.');
        $amount=(string)($body['amount_usd']??'');
        if(!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?$/',$amount))
            throw new InvalidArgumentException('Enter a verified USD amount with up to two decimals.');
        $parts=explode('.',$amount,2);$cents=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
        $result=sf_image_gen_reconcile_cost($id,(int)$me['id'],$cents,(string)($body['invoice_reference']??''));
        sf_log_admin_action((int)$me['id'],'image_cost_reconciled','image_generation_job',(string)$id,
            ['amount_cents'=>$cents,'currency'=>'USD','provider_invoice_checked'=>true]);
        sf_json_response(['ok'=>true,'result'=>$result]);
    }
    if($action==='approve_prompt'){
        if(empty($body['confirmed']))sf_json_response(['ok'=>false,'message'=>'Confirm the exact prompt and generation settings first.'],422);
        sf_image_gen_approve_prompt($id,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'image_prompt_approved','image_generation_job',(string)$id);
        sf_json_response(['ok'=>true]);
    }
    if($action==='generate'){
        if(empty($body['confirmed']))sf_json_response(['ok'=>false,'message'=>'Paid image generation requires explicit confirmation.'],422);
        $job=sf_image_gen_reserve_job($id,(int)$me['id']);
        try{
            // No automatic remote retry: an uncertain outcome must be
            // resolved by an administrator, not charged twice.
            $result=sf_image_gen_request($job,(int)$me['id']);
            $usage=is_array($result['usage']??null)?$result['usage']:[];
            sf_consume_ai($me,max(0,(int)($usage['input_tokens']??0)+(int)($usage['output_tokens']??0)),
                ['provider'=>'openai','model'=>(string)$job['model'],
                 'input_tokens'=>(int)($usage['input_tokens']??0),
                 'output_tokens'=>(int)($usage['output_tokens']??0)],'image-generation');
            $complete=sf_image_gen_complete($id,(int)$me['id'],$result);
            sf_log_admin_action((int)$me['id'],'image_generated','image_generation_job',(string)$id,
                ['asset_id'=>$complete['asset_id'],'model'=>$job['model'],'size'=>$job['size'],'quality'=>$job['quality']]);
            sf_json_response(['ok'=>true,'result'=>$complete]);
        }catch(Throwable $e){
            sf_image_gen_mark_failed($id,'generation_or_storage_failed');
            error_log('[Stonefellow image generation] job='.$id.' '.get_class($e).' '.$e->getMessage());
            sf_json_response(['ok'=>false,'error'=>'image_generation_failed',
                'message'=>'Image generation did not complete. This job will not be retried automatically; check your API billing and try a new approved draft.'],503);
        }
    }
    if($action==='approve_asset'){
        if(empty($body['confirmed']))sf_json_response(['ok'=>false,'message'=>'Inspect and confirm this private image before approving.'],422);
        sf_image_gen_approve_asset($id,(int)$me['id']);
        sf_log_admin_action((int)$me['id'],'generated_image_approved','image_generation_job',(string)$id);
        sf_json_response(['ok'=>true]);
    }
    if($action==='stage_placement'){
        // A placement is deliberately a PRIVATE Media Library attachment, never
        // a publication or a replacement of the existing primary image.
        if(empty($body['confirmed']))throw new InvalidArgumentException('Confirm the private image placement.');
        $job=sf_image_gen_job($id);
        if(!$job||($job['status']??'')!=='ready'||(int)($job['asset_approved_by']??0)<1)
            throw new RuntimeException('Only reviewed and approved generated images can be placed.');
        $assetId=(int)($job['media_asset_id']??0);
        require_once SF_ROOT.'/api/media-core.php';
        $asset=sf_media_asset($assetId);
        if(!$asset||($asset['category']??'')!=='image')throw new RuntimeException('The approved image is unavailable.');
        $type=sf_clean_text($body['entity_type']??'',40);
        $entityId=sf_clean_text($body['entity_id']??'',160);
        $role=sf_clean_text($body['role']??'',60);
        $roles=[
            'track'=>['artwork','photo'],
            'release'=>['cover','social_square','social_story'],
            'story_entry'=>['hero','inline','gallery'],
            'campaign'=>['hero','background','offer_artwork'],
            'site'=>['hero','background','social_share'],
        ];
        if(!isset($roles[$type])||!in_array($role,$roles[$type],true)||$entityId==='')
            throw new InvalidArgumentException('Choose a supported image placement.');
        if($type==='site'){
            if($entityId!=='public')throw new InvalidArgumentException('Site images must use the public site workspace.');
        }elseif($type==='track'){
            $found=false;foreach(sf_catalog() as $track)if((string)($track['id']??'')===$entityId){$found=true;break;}
            if(!$found)throw new InvalidArgumentException('Song was not found.');
        }elseif($type==='release'){
            $found=false;foreach(sf_admin_releases() as $release)if((string)($release['id']??'')===$entityId){$found=true;break;}
            if(!$found)throw new InvalidArgumentException('Release was not found.');
        }elseif($type==='story_entry'){
            require_once SF_ROOT.'/api/story-core.php';
            if(!ctype_digit($entityId)||!sf_story_get((int)$entityId,false))throw new InvalidArgumentException('Story was not found.');
        }elseif($type==='campaign'){
            require_once SF_ROOT.'/api/campaign-core.php';
            if(!ctype_digit($entityId)||!sf_campaign_get((int)$entityId))throw new InvalidArgumentException('Campaign was not found.');
        }
        $existing=sf_db()->prepare('SELECT public_visible,download_allowed FROM media_links WHERE asset_id=? AND entity_type=? AND entity_id=? AND role=? LIMIT 1');
        $existing->execute([$assetId,$type,$entityId,$role]);
        $already=$existing->fetch();
        if($already){
            if(!empty($already['public_visible'])||!empty($already['download_allowed']))
                throw new RuntimeException('This image is already public at that placement; staging will not change its visibility.');
            sf_json_response(['ok'=>true,'private'=>true,'already_staged'=>true]);
        }
        $links=sf_media_attach($assetId,$type,$entityId,$role,
            ['featured'=>false,'public_visible'=>false,'download_allowed'=>false]);
        sf_log_admin_action((int)$me['id'],'generated_image_placement_staged','image_generation_job',(string)$id,
            ['asset_id'=>$assetId,'entity_type'=>$type,'entity_id'=>$entityId,'role'=>$role,'published'=>false]);
        sf_json_response(['ok'=>true,'links'=>$links,'private'=>true]);
    }
    sf_json_response(['ok'=>false,'message'=>'Unsupported image-generation action.'],422);
}catch(InvalidArgumentException $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){sf_json_response(['ok'=>false,'message'=>$e->getMessage()],409);}
