<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/agent-runtime.php';
require_once __DIR__.'/agent-song-analyze-core.php';
require_once __DIR__.'/agent-song-annotations-core.php';
require_once __DIR__.'/agent-song-catalog-core.php';
require_once __DIR__.'/song-section-context.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')sf_json_response(['ok'=>false,'error'=>'method_not_allowed'],405);
$user=sf_require_user(false,true);
header('Cache-Control: no-store');
try{
    $raw=sf_request_json();
    $catalog=sf_agent_public_catalog();
    $request=sf_song_analysis_request($raw,$catalog);
    if(array_key_exists('source_fingerprint',$raw)){
        $fingerprint=$raw['source_fingerprint'];
        if(!is_string($fingerprint)||!preg_match('/\A[a-f0-9]{64}\z/',$fingerprint))
            throw new InvalidArgumentException('Choose a valid saved lyric range.');
        $source=sf_song_catalog_source($request['track_id'],$catalog,$user);
        if(!hash_equals($source['fingerprint'],$fingerprint))
            sf_song_catalog_error('song_source_changed',409,'The song changed. Select a new lyric section before interpreting.');
    }
    $request=sf_song_analysis_saved_lyrics($request,$raw,($user['role']??'')==='admin');
    $userId=(int)$user['id'];
    $request['section_context']=sf_song_section_public_context($request['track_id'],$request['start_ms'],$request['end_ms']);
    $request['shared_notes']=sf_song_annotation_section_context($userId,$request['track_id'],$request['start_ms'],$request['end_ms']);
    // The listener authorizes this report per request. Personal context also
    // requires the account's current preferences and each note's sharing grant.
    $context=sf_agent_context($request['title'].' '.$request['release'].' '.$request['question'],'knowledge',$userId);
    $knowledgeIndex=SF_ROOT.'/storage/knowledge/index.json';
    $knowledge=is_file($knowledgeIndex)?json_decode((string)file_get_contents($knowledgeIndex),true):[];
    $writer=sf_song_analysis_writer_context($request,$catalog,
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
}catch(SFSongCatalogError $e){
    sf_json_response(['ok'=>false,'error'=>$e->error,'message'=>$e->getMessage()],$e->httpStatus);
}catch(InvalidArgumentException $e){
    sf_json_response(['ok'=>false,'error'=>'invalid_lyric_analysis','message'=>$e->getMessage()],422);
}catch(Throwable $ignored){
    sf_json_response(['ok'=>false,'error'=>'analysis_failed','message'=>'Lyric analysis could not complete. Your browser draft is unchanged.'],503);
}
