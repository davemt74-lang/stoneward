<?php
declare(strict_types=1);
/**
 * Section 45B — Privacy-minimized read-only fan journey measurements.
 * Counts are independent cohorts, NOT a sequential conversion funnel.
 * No names, contact IDs, email addresses, tokens, or individual activity.
 */
function sf_fan_journey_counts(int $days=30): array {
    $days=max(7,min(365,$days));
    sf_crm_ensure_schema();sf_campaign_ensure_schema();sf_automation_ensure_schema();
    $db=sf_db();$since=gmdate('c',time()-$days*86400);
    $count=static function(string $sql,array $params=[]) use ($db):int {
        $q=$db->prepare($sql);$q->execute($params);return (int)$q->fetchColumn();
    };
    $counts=[
      'known_contacts'=>$count('SELECT COUNT(*) FROM fan_contacts'),
      'active_contacts'=>$count('SELECT COUNT(DISTINCT contact_id) FROM fan_crm_events WHERE created_at>=?',[$since]),
      'listening_contacts'=>$count("SELECT COUNT(DISTINCT contact_id) FROM fan_crm_events WHERE created_at>=? AND event_type IN ('listen_complete','listen_start')",[$since]),
      'newsletter_opted_in'=>$count('SELECT COUNT(*) FROM fan_contacts WHERE marketing_opt_in=1'),
      'purchasing_contacts'=>$count("SELECT COUNT(DISTINCT contact_id) FROM fan_crm_events WHERE created_at>=? AND event_type IN ('purchase','merch_purchase','campaign_purchase_attributed')",[$since]),
      'campaign_participants'=>$count('SELECT COUNT(*) FROM campaign_participants WHERE entered_at>=?',[$since]),
      'campaign_conversions'=>$count('SELECT COUNT(*) FROM campaign_participants WHERE entered_at>=? AND converted_at IS NOT NULL AND converted_at>=?',[$since,$since]),
      'journeys_started'=>$count('SELECT COUNT(*) FROM lifecycle_automation_runs WHERE started_at>=?',[$since]),
      'journeys_completed'=>$count("SELECT COUNT(*) FROM lifecycle_automation_runs WHERE started_at>=? AND status='completed'",[$since]),
      'journeys_failed'=>$count("SELECT COUNT(*) FROM lifecycle_automation_runs WHERE started_at>=? AND status='failed'",[$since]),
    ];
    $safeRate=static fn(int $n,int $d):?float=>$d>0?round(100*$n/$d,1):null;
    return [
      'period_days'=>$days,'since'=>$since,'counts'=>$counts,
      'rates'=>[
        'campaign_conversion_pct'=>$safeRate($counts['campaign_conversions'],$counts['campaign_participants']),
        'journey_completion_pct'=>$safeRate($counts['journeys_completed'],$counts['journeys_started']),
        'journey_failure_pct'=>$safeRate($counts['journeys_failed'],$counts['journeys_started'])
      ],
      'measurement_notes'=>[
        'Counts are independent cohorts; they are not a person-by-person sequential funnel.',
        'Subscriber count is current opted-in contacts, not newsletter signups during the period.',
        'CRM listening data requires linked accounts; anonymous listeners are not counted as contacts.',
        'Campaign conversion rate uses the same entry cohort and requires conversion within the selected period.'
      ]
    ];
}
