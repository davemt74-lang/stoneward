<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-analyze-core.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,true);
header('Cache-Control: no-store');
try{
    $request=sf_song_analysis_request(sf_request_json(),sf_agent_public_catalog());
    $userId=(int)$user['id'];
    // The listener explicitly authorizes this report per request. Only public
    // knowledge plus THIS account's consented listening summary may be sent.
    $context=sf_agent_context($request['title'].' '.$request['release'].' '.$request['question'],'knowledge',$userId);
    $knowledgeIndex=SF_ROOT.'/storage/knowledge/index.json';
    $knowledge=is_file($knowledgeIndex)?json_decode((string)file_get_contents($knowledgeIndex),true):[];
    $writer=sf_song_analysis_writer_context($request,sf_agent_public_catalog(),
        function_exists('sf_story_list')?sf_story_list(true,'',80):[],
        (array)($knowledge['files']??[]),
        function_exists('sf_songbook_public_payload')?(array)(sf_songbook_public_payload($request['track_id'])??[]):[]);
    $listening=sf_agent_listening_context(sf_agent_listening_insights($userId,$request['timezone']));
    $system=sf_song_analysis_system($request,$context,$listening,$writer['text']);
    $userPrompt=sf_song_analysis_user_prompt($request);
    $estimate=max(120,(int)ceil((strlen($system)+strlen($userPrompt))/4)+1500);
    $allow=sf_can_use_ai($user,$estimate);
    if(!$allow['ok'])sf_json_response(['ok'=>false,'error'=>'active_tokens_required','message'=>'More Active Tokens are required for this lyric analysis.'],402);
    $providerError=false;
    foreach(sf_ai_llm_candidates('chat') as $provider){
        try{
            $response=$provider['provider']==='openai'
                ?sf_openai_call($provider,$system,[],$userPrompt,1300)
                :sf_anthropic_call($provider,$system,[],$userPrompt,1300);
            $report=sf_song_analysis_report($request,$response,$writer['sources']);
            $used=max(1,(int)($response['input_tokens']??0)+(int)($response['output_tokens']??0));
            $balance=sf_consume_ai($user,$used,$response,'song-section-analysis');
            sf_json_response(['ok'=>true,'analysis'=>$report,'active_tokens_remaining'=>($user['role']??'')==='admin'?null:$balance]);
        }catch(Throwable $ignored){$providerError=true;/* Configured fallback may work; keep secrets private. */}
    }
    sf_json_response(['ok'=>false,'error'=>'analysis_provider_unavailable','message'=>$providerError
        ?'The configured language providers could not complete lyric analysis. Please retry.'
        :'Enable a conversational AI provider in Admin → AI Providers to analyze this draft.'],503);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_lyric_analysis','message'=>$e->getMessage()],422);
}catch(Throwable $ignored){
    sf_json_response(['ok'=>false,'error'=>'analysis_failed','message'=>'Lyric analysis could not complete. Your browser draft is unchanged.'],503);
}
