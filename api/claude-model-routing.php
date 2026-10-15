<?php
declare(strict_types=1);
/**
 * Explicit Claude API routing; no LLM model may grant agent/tool authority.
 * The caller chooses the workload from server-side policy, never user-supplied
 * model text. Existing pinned custom models remain available in manual mode.
 */
const SF_CLAUDE_FAST_MODEL = 'claude-haiku-5-5';
const SF_CLAUDE_REASONING_MODEL = 'claude-sonnet-5-5';

function sf_claude_model_choice(string $model): string {
    if(!in_array($model,[SF_CLAUDE_FAST_MODEL,SF_CLAUDE_REASONING_MODEL],true))
        throw new InvalidArgumentException('Choose a supported Claude API model.');
    return $model;
}
function sf_claude_effective_mode(string $savedMode,string $configuredModel):string {
    if(in_array($savedMode,['auto','manual'],true))return $savedMode;
    // Upgrade-safe: keep existing custom Anthropic models until owner opts in.
    return trim($configuredModel)!==''?'manual':'auto';
}
function sf_claude_workload_for_plan(array $plan):string {
    $route=(string)($plan['route']??'');
    $profile=(string)($plan['context_profile']??'');
    // Only this allowlisted, server-generated route/profile can promote a
    // customer question to the reasoning tier. A user cannot submit a tier.
    if($route==='knowledge_question'||in_array($profile,['knowledge','commerce','crm','account','research'],true))
        return 'reasoning';
    return 'chat';
}
function sf_claude_choose_model(array $provider,array $settings,string $workload):array {
    if(($provider['provider']??'')!=='anthropic')return $provider;
    $configured=trim((string)($provider['model']??''));
    $mode=sf_claude_effective_mode((string)($settings['claude_mode']??''),$configured);
    if($mode==='manual'){
        $provider['model']=$configured!==''?$configured:SF_CLAUDE_REASONING_MODEL;
        $provider['model_tier']='manual';
    }else{
        $fast=sf_claude_model_choice((string)($settings['claude_fast_model']??SF_CLAUDE_FAST_MODEL));
        $reasoning=sf_claude_model_choice((string)($settings['claude_reasoning_model']??SF_CLAUDE_REASONING_MODEL));
        $complex=in_array($workload,['reasoning','agent_brain'],true);
        $provider['model']=$complex?$reasoning:$fast;
        $provider['model_tier']=$complex?'reasoning':'chat';
    }
    return $provider;
}
