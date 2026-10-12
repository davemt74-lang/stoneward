<?php
declare(strict_types=1);
/** Aggregate fan eligibility without retaining or exposing identifiable records. */
function sf_agent_audience_aggregate(array $contacts,int $cap=5000): array {
    $matched=count($contacts);$eligible=0;
    foreach($contacts as $row)if(is_array($row)&&!empty($row['marketing_opt_in']))$eligible++;
    return ['matched'=>$matched,'email_eligible'=>$eligible,'suppressed'=>$matched-$eligible,'capped'=>$matched>=$cap,'consent_required'=>true];
}
