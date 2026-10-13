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
        || !function_exists('sf_agent_customer_memory_list') || !function_exists('sf_db')
        || !function_exists('sf_agent_recommend_track'))return null;
    // Require BOTH a stored CRM opt-in and an explicit owner-saved preference.
    // Defaults and guest state are insufficient; never dispatch from this read.
    try{
        $q=sf_db()->prepare('SELECT agent_auto_engage FROM fan_contacts WHERE user_id=? LIMIT 1');
        $q->execute([$userId]);
        if((int)($q->fetchColumn()?:0)!==1)return null;
        $explicit=false;
        foreach(sf_agent_customer_memory_list($userId) as $row){
            $key=strtolower(trim((string)($row['memory_key']??'')));
            $value=strtolower(trim((string)($row['memory_text']??'')));
            if(in_array($key,['proactive suggestions','proactive recommendations'],true)
                && in_array($value,['yes','on','enabled'],true)){$explicit=true;break;}
        }
        if(!$explicit)return null;
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
