<?php
declare(strict_types=1);
require_once __DIR__.'/ops-http.php';

/** Provider suggestions for the Admin chooser. Public allowlists are saved separately. */
function sf_ai_coding_catalog(mixed $provider): array {
    if(!in_array($provider,['openai','anthropic'],true))throw new InvalidArgumentException('Choose OpenAI or Anthropic.');
    $candidate=sf_ai_llm_candidate(sf_ai_provider_row($provider));
    if($candidate===null)throw new InvalidArgumentException('Enable this provider with a saved API key first.');
    $key=$candidate['api_key'];
    if($key===''||strpbrk($key,"\r\n")!==false)throw new InvalidArgumentException('Save a valid API key for this provider first.');

    $isClaude=$provider==='anthropic';
    $url=$isClaude?'https://api.anthropic.com/v1/models?limit=100':'https://api.openai.com/v1/models';
    $headers=$isClaude?['x-api-key: '.$key,'anthropic-version: 2023-06-01']:['Authorization: Bearer '.$key];
    $models=[];$cursor='';$deadline=microtime(true)+8;
    for($page=0;$page<3;$page++){
        $remaining=(int)ceil($deadline-microtime(true));
        if($remaining<1)break;
        $data=sf_ops_http_get_json($url.($cursor!==''?'&after_id='.rawurlencode($cursor):''),$headers,$remaining)['json'];
        if(!is_array($data['data']??null)||!array_is_list($data['data']))throw new RuntimeException('Invalid model catalog response.');
        foreach($data['data'] as $model){
            $id=$model['id']??null;
            if(!sf_ai_coding_model_id_valid($id))continue;
            // The OpenAI list has no capability metadata. Omit known non-text families only.
            if(!$isClaude&&preg_match('/\A(?:text-embedding-|(?:omni-|text-)moderation-|gpt-(?:image-|audio(?:-|\z)|realtime(?:-|\z)|4o-(?:mini-)?(?:audio|realtime|transcribe|tts)(?:-|\z))|dall-e-|whisper-|tts-|sora-)/i',$id))continue;
            $name=$model['display_name']??null;
            $models[$id]=['id'=>$id,'label'=>$isClaude&&is_string($name)?(sf_clean_text($name,160)?:$id):$id];
            if(count($models)>=300)return array_values($models);
        }
        $next=$data['last_id']??null;
        if(!$isClaude||empty($data['has_more'])||!sf_ai_coding_model_id_valid($next)||$next===$cursor)break;
        $cursor=$next;
    }
    return array_values($models);
}
