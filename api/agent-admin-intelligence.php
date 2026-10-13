<?php
declare(strict_types=1);
/**
 * Section 47E: explainable, bounded operational insights from already
 * authorized Admin aggregates. Never accepts private Customer Brain records.
 */
require_once __DIR__.'/agent-orchestration.php';

function sf_admin_agent_decision_brief(array $aggregates): array {
    // Strict field allowlist: ignore all unknown keys, strings and nested
    // customer details. Unknown metrics stay unknown, never assumed zero.
    $number=static function(array $row,string $key): ?int {
        if(!array_key_exists($key,$row))return null;
        $value=$row[$key];
        if(!is_int($value) && !is_float($value) && !(is_string($value)&&preg_match('/^[0-9]{1,12}$/D',$value)))return null;
        if(!is_finite((float)$value) || (float)$value<0 || (float)$value>1000000000)return null;
        return (int)$value;
    };
    $contacts=$number($aggregates,'crm_contacts');
    $opted=$number($aggregates,'newsletter_opted_in');
    $active=$number($aggregates,'campaigns_active');
    $journey=is_array($aggregates['journey_counts']??null)?$aggregates['journey_counts']:[];
    $failed=$number($journey,'journeys_failed');
    $insights=[];
    if($failed!==null && $failed>0){
        $insights[]=[
          'id'=>'journey_failures',
          'title'=>'Review failed journey runs',
          'reason'=>'An existing 30-day aggregate reports failed journeys. Inspect the original records before retrying.',
          'evidence'=>['source'=>'journey_aggregate_30d','failed_runs'=>$failed],
          'next_intent'=>'review_actions',
          'requires_exact_approval'=>false
        ];
    }
    if($contacts!==null && $opted!==null && $contacts>=10 && $contacts>$opted){
        $insights[]=[
          'id'=>'newsletter_consent_review',
          'title'=>'Review newsletter consent coverage',
          'reason'=>'Some CRM contacts are not counted in opted-in newsletter subscriptions. Do not contact them without consent.',
          'evidence'=>['source'=>'crm_aggregate','contacts'=>$contacts,'newsletter_opted_in'=>$opted],
          'next_intent'=>'crm_report',
          'requires_exact_approval'=>false
        ];
    }
    if($opted!==null && $opted>0 && $active===0){
        $intent='prepare_campaign';$contract=sf_agent_orchestration_contract('admin',$intent);
        if($contract['may_prepare_reviewed_proposal'] && !$contract['may_execute_directly']){
            $insights[]=[
              'id'=>'campaign_draft_opportunity',
              'title'=>'Consider a reviewed campaign draft',
              'reason'=>'An opted-in audience exists and no active campaigns were reported. This is a draft suggestion only.',
              'evidence'=>['source'=>'crm_campaign_aggregates','newsletter_opted_in'=>$opted,'active_campaigns'=>0],
              'next_intent'=>$intent,
              'requires_exact_approval'=>true
            ];
        }
    }
    return array_slice($insights,0,3);
}
