<?php
declare(strict_types=1);
/**
 * Section 45F: evidence-backed, read-only operational guidance.
 * No per-fan data is returned and recommendations never execute changes.
 */
function sf_fan_journey_recommendation_rules(array $journey,array $campaigns,array $automation): array {
    $c=(array)($journey['counts']??[]);$out=[];
    $record=static function(string $level,string $title,string $reason,string $view)use(&$out):void {
        $out[]=['level'=>$level,'title'=>$title,'evidence'=>$reason,'review_view'=>$view,'execution'=>'manual_review_only'];
    };
    $started=max(0,(int)($c['journeys_started']??0));$failed=max(0,(int)($c['journeys_failed']??0));
    if($failed>0){
        $rate=$started?round(100*$failed/$started,1):null;
        $record('warning','Investigate lifecycle failures',$failed.' of '.$started.' journey runs failed'.($rate!==null?' ('.$rate.'%).':'.').' Check the Automation run history before changing the journey.','automations');
    }
    foreach((array)($campaigns['campaigns']??[]) as $campaign){
        $participants=max(0,(int)($campaign['participants']??0));
        $converted=max(0,(int)($campaign['converted']??0));
        if($participants<10||$converted*100>=$participants*5)continue;
        $title=trim((string)($campaign['name']??'Campaign'));
        $title=function_exists('mb_substr')?mb_substr($title,0,100):substr($title,0,100);
        $record('watch','Review low-converting campaign: '.$title,$converted.' conversions from '.$participants.' entries in this period. Review the approved campaign copy, eligibility and landing experience.','campaigns');
        if(count($out)>=8)break;
    }
    $contacts=max(0,(int)($c['known_contacts']??0));$optIn=max(0,(int)($c['newsletter_opted_in']??0));
    if($contacts>=20&&$optIn*5<$contacts){
        $record('info','Review voluntary newsletter signup', $optIn.' of '.$contacts.' known CRM contacts currently have newsletter permission. Check opt-in form clarity; do not send to unconsented fans.','crm');
    }
    $active=max(0,(int)($c['active_contacts']??0));$listening=max(0,(int)($c['listening_contacts']??0));
    if($active>0&&$listening===0)$record('info','Check linked listening measurement','No listening activity is linked to CRM contacts during this period. Anonymous listening may still occur; review tracking coverage before inferring fan inactivity.','automations');
    if(!$out)$record('info','Review journey performance','No recommendation threshold was triggered by the available aggregate metrics. This is not proof that every journey performs optimally.','automations');
    return array_slice($out,0,10);
}
function sf_fan_journey_recommendations(int $days=30): array {
    $days=max(7,min(365,$days));
    return [
      'period_days'=>$days,
      'recommendations'=>sf_fan_journey_recommendation_rules(
          sf_fan_journey_counts($days),sf_growth_campaigns($days),sf_growth_automation($days)
      ),
      'permissions'=>'Advisory only. Publishing, sending and CRM edits require separate administrator review.'
    ];
}
