<?php
declare(strict_types=1);

/** A bounded diagnostic for live Agent failures; never surface upstream payloads or credentials. */
function sf_agent_chat_failure_code(Throwable $error): string {
    $message=strtolower($error->getMessage());
    if(str_contains($message,'active_tokens_required'))return 'active_tokens_required';
    if(str_contains($message,'stored api key')||str_contains($message,'ai encryption key')||str_contains($message,'config/app-secret.php'))return 'credential_storage';
    if(str_contains($message,'no language provider is enabled')||str_contains($message,'no configured language provider')&&str_contains($message,'no language provider'))return 'provider_not_configured';
    if(str_contains($message,'provider connection failed')||str_contains($message,'curl error')||str_contains($message,'timed out')||str_contains($message,'could not resolve host'))return 'provider_connection';
    if(str_contains($message,'invalid_api_key')||str_contains($message,'authentication_error')||str_contains($message,'incorrect api key')||preg_match('/\b(?:401|403)\b/',$message))return 'provider_auth';
    if(str_contains($message,'credit balance')||str_contains($message,'insufficient credits')||str_contains($message,'billing')||str_contains($message,'quota')||preg_match('/\b429\b/',$message))return 'provider_quota';
    if(str_contains($message,'model_not_found')||str_contains($message,'model not found')||str_contains($message,'unsupported model')||str_contains($message,'does not exist'))return 'provider_model';
    if(str_contains($message,'sqlstate')||str_contains($message,'table')&&str_contains($message,'doesn\'t exist'))return 'database';
    return 'agent_unavailable';
}

function sf_agent_chat_failure_message(string $code,string $reference): string {
    $messages=[
        'provider_not_configured'=>'No conversational AI provider is enabled for Stonefellow. Administrator: check Anthropic / Claude under Admin → AI Providers.',
        'credential_storage'=>'Stonefellow cannot unlock its saved AI credentials. Administrator: restore the original config/app-secret.php or re-enter the Claude key in Admin → AI Providers.',
        'provider_connection'=>'Stonefellow could not reach the conversational AI provider. Administrator: test Anthropic under Admin → Operations.',
        'provider_auth'=>'The conversational AI provider rejected its credentials. Administrator: test and update Anthropic / Claude under Admin → AI Providers.',
        'provider_quota'=>'The conversational AI provider could not accept this request. Administrator: check its API credit balance and rate limits.',
        'provider_model'=>'The configured AI model was not accepted. Administrator: review Claude model routing in Admin → AI Providers.',
        'database'=>'Stonefellow could not complete this chat turn. Administrator: check database readiness and migrations in Admin → Operations.',
    ];
    return ($messages[$code]??'The Stonefellow Agent is temporarily unavailable. Please try again.').' (Reference '.$reference.')';
}

function sf_agent_chat_report_failure(Throwable $error): never {
    $code=sf_agent_chat_failure_code($error);
    $reference=bin2hex(random_bytes(4));
    // No raw upstream message: HTTP/provider errors occasionally contain sensitive data.
    error_log('Stonefellow agent-chat '.$reference.' '.$code.' '.get_class($error));
    if(function_exists('sf_log_site_event')){
        try{sf_log_site_event('error','agent_chat',$code.' (ref '.$reference.')');}
        catch(Throwable $ignored){/* A logging outage must not obscure the original failure. */}
    }
    sf_json_response([
        'ok'=>false,'error'=>$code,'reference'=>$reference,
        'message'=>sf_agent_chat_failure_message($code,$reference),
    ],503);
}
