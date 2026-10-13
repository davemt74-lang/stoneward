<?php
declare(strict_types=1);
/**
 * Section 47F — passive research/suggestion surfaces from existing evidence.
 * No dispatcher, external request, publication or new engagement ledger.
 */
require_once __DIR__.'/agent-admin-intelligence.php';

function sf_agent_proactive_admin_research(array $aggregateFacts): array {
    $brief=sf_admin_agent_decision_brief($aggregateFacts);
    $out=[];
    foreach($brief as $item){
        $out[]=[
          'id'=>(string)$item['id'],
          'title'=>(string)$item['title'],
          'why'=>(string)$item['reason'],
          'source'=>(string)($item['evidence']['source']??'admin_aggregate'),
          'evidence'=>(array)($item['evidence']??[]),
          'next_intent'=>(string)$item['next_intent'],
          'requires_exact_approval'=>(bool)$item['requires_exact_approval'],
          'delivery'=>'in_app_read_only'
        ];
    }
    return $out;
}

function sf_agent_proactive_customer_suggestion(int $userId): ?array {
    if($userId<1 || !function_exists('sf_privacy_personalization_enabled')
        || !sf_privacy_personalization_enabled($userId)
        || !function_exists('sf_crm_sync_user')
        || !function_exists('sf_agent_recommend_track'))return null;
    // Read the owner's stored opt-in. Defaults/guest state never authorize
    // proactive engagement. Do not invoke the engagement dispatcher here.
    try{
        $contact=sf_crm_sync_user($userId);
        if(!is_array($contact)||empty($contact['agent_auto_engage']))return null;
        $track=sf_agent_recommend_track('Suggest a discovery from the public Stonefellow catalog',null,$userId);
    }catch(Throwable $e){return null;}
    if(!is_array($track)||trim((string)($track['id']??''))==='')return null;
    return [
      'kind'=>'published_track_recommendation',
      'track_id'=>(string)$track['id'],
      'title'=>(string)($track['title']??'Stonefellow track'),
      'reason'=>'A published-catalog discovery matched to your opted-in music preferences.',
      'source'=>'published_catalog',
      'delivery'=>'in_app_preview_only',
      'requires_confirmation'=>false,
      'may_execute_directly'=>false
    ];
}
