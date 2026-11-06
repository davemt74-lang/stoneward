<?php
declare(strict_types=1);

require_once __DIR__.'/agent-scene-core.php';

function sf_agent_scene_edit_image(array $user,array $request,?callable $onImage=null,?callable $transport=null): array {
    $userId=(int)$user['id'];
    $previousId=$request['previous_id']??null;
    $imageId=$request['image_id']??null;
    $brief=is_string($request['brief']??null)?trim($request['brief']):'';
    if(!is_int($previousId)||$previousId<1||!sf_agent_canvas_image_asset_id($imageId))
        throw new InvalidArgumentException('Choose an image placed on a saved canvas.');
    if($brief===''||strlen($brief)>1800||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$brief)!==0)
        throw new InvalidArgumentException('Describe the image edit in 1 to 1800 characters.');
    $requestId=sf_agent_scene_request_id($request['request_id']??null);
    $fingerprint=hash('sha256',serialize(['edit_image',$previousId,$imageId,$brief]));
    return sf_agent_scene_run_request($userId,$requestId,$fingerprint,
        static function(int $epoch)use($user,$userId,$previousId,$imageId,$brief,$requestId,$onImage,$transport):array {
            $original=sf_agent_scene_history_get($userId,$previousId);
            if(($original['canvas_kind']??null)!=='custom'||sf_agent_scene_code($original)===null||
                !in_array($imageId,sf_agent_canvas_image_bindings($original),true))
                throw new InvalidArgumentException('Choose an image placed on your saved canvas.');
            $source=sf_agent_canvas_image_asset_get($userId,$imageId);
            if($source===null)throw new InvalidArgumentException('That image is unavailable for editing.');
            $scene=sf_agent_canvas_image_apply($original,$requestId,$imageId);
            $view=sf_agent_scene_display($scene);
            $designSource=sf_agent_scene_request_design_source($userId,$previousId);
            $size=match($source['width']<=>$source['height']){1=>'1536x1024',-1=>'1024x1536',default=>'1024x1024'};
            // Keep a useful source title through repeated revisions and within the storage limit.
            $title=preg_replace('/\s+\(edited\)$/D','',$source['title']);
            preg_match('/\A.{1,171}/us',$title,$prefix);
            $plan=['title'=>($prefix[0]??'Canvas image').' (edited)','size'=>$size,'background'=>'auto',
                'prompt'=>"Edit the supplied image according to the instruction below. Preserve its framing, composition, and details outside the requested changes.\n\n".$brief];
            $nextTouch=time()+60;
            $poll=sf_agent_canvas_image_progress(static function()use($userId,$requestId,&$nextTouch):bool {
                if((sf_agent_scene_request_get($userId,$requestId)['state']??null)!=='pending')return true;
                $now=time();
                if($now>=$nextTouch){
                    sf_agent_scene_touch_progress($userId,$requestId,null,$now);
                    $nextTouch=$now+60;
                }
                return false;
            },$onImage,1,1);
            if($poll())throw new SfAgentSceneBuildCanceled();
            if($onImage!==null)$onImage('generating',null,1,1);
            $stored=[];
            $result=sf_agent_canvas_image_generate($user,$requestId,$plan,$poll,$transport,$imageId,
                static function(int $used,?int $remaining)use(&$stored,$userId,$scene,$previousId,$epoch,$requestId,$designSource):void {
                    $stored=sf_agent_scene_history_save($userId,$scene,$previousId,$epoch);
                    sf_agent_scene_request_finish($userId,$requestId,$stored['id'],$used,$remaining,$designSource);
                });
            $asset=array_intersect_key($result['image'],['id'=>true,'title'=>true]);
            if($onImage!==null)$onImage('saved',$asset,1,1);
            return ['scene'=>array_intersect_key($stored,['id'=>true,'previous_id'=>true,'created_at'=>true])+$view,
                'active_tokens_used'=>$result['active_tokens_used'],'active_tokens_remaining'=>$result['active_tokens_remaining'],
                'design_source'=>$designSource,'generated_images'=>[$asset+['index'=>1]],'image_total'=>1];
        });
}
